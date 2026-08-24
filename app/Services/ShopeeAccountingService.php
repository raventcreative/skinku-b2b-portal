<?php

namespace App\Services;

use App\Models\AccAccount;
use App\Models\AccBranch;
use App\Models\AccJournal;
use App\Models\ShopeeConnection;
use App\Models\ShopeeOrder;
use App\Models\ShopeeSettlement;
use App\Models\ShopeeWalletTransaction;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Jurnal akuntansi integrasi Shopee — "Opsi C" (akrual, matching benar), meniru
 * `TikTokAccountingService` yang sudah terbukti live:
 *
 *  1. BARANG KELUAR (potong stok)  → Dr Persediaan Dalam Perjalanan / Cr Persediaan Barang Jadi
 *     Cuma pindah aset. NOL dampak ke laba — barang di jalan masih milik kita.
 *
 *  2. ORDER SAMPAI (COMPLETED)     → Dr Piutang Shopee       / Cr Penjualan   (bruto)
 *                                    Dr Beban HPP           / Cr Persediaan Dalam Perjalanan
 *     Omzet & HPP diakui BARENG → laba kotor akurat.
 *
 * Semua idempoten: sudah berjurnal → dilewati. HPP dikunci di `hpp_amount` saat
 * langkah 1 supaya akun transit bersih saat dilepas di langkah 2.
 */
class ShopeeAccountingService
{
    public function __construct(
        private AccountingService $accounting,
        private ShopeeOrderService $orders,
    ) {}

    /** @return array<string, AccAccount> */
    public function accounts(): array
    {
        return [
            'kas' => $this->acc('1001', 'Kas Shopee', 'asset', 'cash', 'debit'),
            'bank' => $this->acc('1002', 'Bank', 'asset', 'cash', 'debit'),
            'piutang' => $this->acc('1104', 'Piutang Shopee', 'asset', 'receivable', 'debit'),
            'transit' => $this->acc('1203', 'Persediaan Dalam Perjalanan', 'asset', 'inventory', 'debit'),
            'persediaan' => $this->acc('1202', 'Persediaan Barang Jadi', 'asset', 'inventory', 'debit'),
            'penjualan' => $this->acc('4001', 'Penjualan', 'revenue', 'sales', 'credit'),
            'pendapatan_lain' => $this->acc('4002', 'Pendapatan Lain-lain', 'revenue', 'other', 'credit'),
            'hpp' => $this->acc('5003', 'Beban HPP', 'expense', 'cogs', 'debit'),
            'fee' => $this->acc('6005', 'Beban Biaya E-commerce', 'expense', 'operating', 'debit'),
            'iklan' => $this->acc('6001', 'Beban Iklan / Promosi', 'expense', 'operating', 'debit'),
            'ongkir' => $this->acc('6007', 'Beban Ongkos Kirim', 'expense', 'operating', 'debit'),
        ];
    }

    // ---------- 1. Barang keluar → transit ----------

    /** @return array<int, array{account: AccAccount, debit: float, credit: float, memo: string}> */
    public function previewTransit(ShopeeOrder $order): array
    {
        $a = $this->accounts();
        $hpp = (float) $order->hpp_amount;

        return $hpp <= 0 ? [] : [
            ['account' => $a['transit'], 'debit' => $hpp, 'credit' => 0.0, 'memo' => "Barang keluar Shopee {$order->order_sn}"],
            ['account' => $a['persediaan'], 'debit' => 0.0, 'credit' => $hpp, 'memo' => 'Keluar dari gudang'],
        ];
    }

    public function postTransit(ShopeeOrder $order): ?AccJournal
    {
        if ($order->transit_journal_id || (float) $order->hpp_amount <= 0) {
            return null; // sudah dijurnal / tak ada nilai HPP
        }
        $journal = $this->record(
            $this->previewTransit($order),
            date: ($order->deducted_at ?? now())->toDateString(),
            reference: "SHP-KELUAR {$order->order_sn}",
            description: 'Barang keluar gudang (belum diakui penjualan)',
            sourceType: 'shopee_order_transit',
            sourceId: $order->id,
            type: 'inventory',   // cuma pindah aset, bukan penjualan
        );
        $order->update(['transit_journal_id' => $journal->id]);

        return $journal;
    }

    // ---------- 2. Order sampai → penjualan + HPP ----------

    /** @return array<int, array{account: AccAccount, debit: float, credit: float, memo: string}> */
    public function previewSale(ShopeeOrder $order): array
    {
        $a = $this->accounts();
        $bruto = (float) $order->total_amount;
        $hpp = (float) $order->hpp_amount;
        $lines = [];

        if ($bruto > 0) {
            $lines[] = ['account' => $a['piutang'], 'debit' => $bruto, 'credit' => 0.0, 'memo' => "Order sampai {$order->order_sn}"];
            $lines[] = ['account' => $a['penjualan'], 'debit' => 0.0, 'credit' => $bruto, 'memo' => 'Penjualan Shopee (bruto)'];
        }
        // HPP hanya dilepas kalau memang pernah masuk ke akun transit — kalau tidak,
        // akun transit malah jadi negatif. Omzet tetap diakui tanpa menunggu HPP.
        if ($hpp > 0 && $order->transit_journal_id) {
            $lines[] = ['account' => $a['hpp'], 'debit' => $hpp, 'credit' => 0.0, 'memo' => 'HPP terjual'];
            $lines[] = ['account' => $a['transit'], 'debit' => 0.0, 'credit' => $hpp, 'memo' => 'Lepas dari perjalanan'];
        }

        return $lines;
    }

    public function postSale(ShopeeOrder $order): ?AccJournal
    {
        if ($order->sale_journal_id || ! in_array($order->status, ShopeeOrder::DELIVERED_STATUSES, true)) {
            return null;
        }
        $lines = $this->previewSale($order);
        if (! $lines) {
            return null;
        }
        $journal = $this->record(
            $lines,
            date: now()->toDateString(),
            reference: "SHP-JUAL {$order->order_sn}",
            description: 'Order sampai — akui penjualan & HPP',
            sourceType: 'shopee_order_sale',
            sourceId: $order->id,
            type: 'sales',
        );
        $order->update(['sale_journal_id' => $journal->id]);

        return $journal;
    }

    // ---------- 3. Dana cair (settlement) → kas + ongkir + iklan + fee catch-all, potong piutang ----------

    /**
     * `feeOther` adalah baris penutup selisih (buyer_total - escrow - ongkir - iklan) yang
     * membuat jurnal ini SELALU balance terhadap piutang (dicatat sebesar bruto saat sale).
     * feeOther positif → beban fee e-commerce tambahan; negatif → penyesuaian pendapatan lain.
     *
     * @return array<int, array{account: AccAccount, debit: float, credit: float, memo: string}>
     */
    public function previewSettlement(ShopeeSettlement $s): array
    {
        $a = $this->accounts();
        $net = (float) $s->escrow_amount;
        $buyer = (float) $s->buyer_total_amount;
        $shipping = (float) $s->actual_shipping_fee;
        $campaign = (float) $s->campaign_fee;
        $feeOther = round($buyer - $net - $shipping - $campaign, 2);
        $lines = [];

        if ($net != 0) {
            $lines[] = ['account' => $a['kas'], 'debit' => $net, 'credit' => 0.0, 'memo' => "Escrow cair {$s->order_sn}"];
        }
        if ($shipping > 0) {
            $lines[] = ['account' => $a['ongkir'], 'debit' => $shipping, 'credit' => 0.0, 'memo' => 'Ongkir'];
        }
        if ($campaign > 0) {
            $lines[] = ['account' => $a['iklan'], 'debit' => $campaign, 'credit' => 0.0, 'memo' => 'Iklan'];
        }
        if ($feeOther > 0) {
            $lines[] = ['account' => $a['fee'], 'debit' => $feeOther, 'credit' => 0.0, 'memo' => 'Fee e-commerce'];
        } elseif ($feeOther < 0) {
            $lines[] = ['account' => $a['pendapatan_lain'], 'debit' => 0.0, 'credit' => -$feeOther, 'memo' => 'Penyesuaian'];
        }
        if ($buyer != 0) {
            $lines[] = ['account' => $a['piutang'], 'debit' => 0.0, 'credit' => $buyer, 'memo' => 'Piutang Shopee lunas'];
        }

        return $lines;
    }

    public function postSettlement(ShopeeSettlement $s): ?AccJournal
    {
        if ($s->isPosted()) {
            return null;
        }
        $lines = $this->previewSettlement($s);
        if (! $lines) {
            return null;
        }
        $journal = $this->record(
            $lines,
            date: ($s->escrow_release_time ?? now())->toDateString(),
            reference: "SHP-CAIR {$s->order_sn}",
            description: 'Dana cair Shopee (escrow) — lunasi piutang',
            sourceType: 'shopee_settlement',
            sourceId: $s->id,
            type: 'cash_in',
        );
        $s->update([
            'posting_status' => ShopeeSettlement::POST_POSTED,
            'journal_id' => $journal->id,
            'posted_at' => now(),
        ]);

        return $journal;
    }

    // ---------- 4. Wallet (withdrawal/iklan/adjustment) — escrow di-skip, sudah di settlement ----------

    /**
     * ESCROW_VERIFIED_ADD/MINUS sengaja SKIP (return []) — dana escrow sudah dijurnal lewat
     * `previewSettlement()`; kalau ikut dijurnal di sini akan dobel hitung. WITHDRAWAL_CREATED/
     * CANCELLED juga skip (belum/batal jadi uang riil), begitu juga tipe yang tak dikenal.
     *
     * @return array<int, array{account: AccAccount, debit: float, credit: float, memo: string}>
     */
    public function previewWallet(ShopeeWalletTransaction $w): array
    {
        $a = $this->accounts();
        $amt = abs((float) $w->amount);
        $t = (string) $w->transaction_type;
        $L = fn ($acc, $dr, $cr, $m) => ['account' => $acc, 'debit' => $dr, 'credit' => $cr, 'memo' => $m];

        return match (true) {
            $t === 'WITHDRAWAL_COMPLETED' => [$L($a['bank'], $amt, 0, 'Cair ke bank'), $L($a['kas'], 0, $amt, 'Saldo Shopee keluar')],
            in_array($t, ['PAID_ADS_CHARGE', 'AFFILIATE_ADS_SELLER_FEE', 'AFFILIATE_FEE_DEDUCT'], true) => [$L($a['iklan'], $amt, 0, 'Biaya iklan'), $L($a['kas'], 0, $amt, 'Saldo keluar')],
            in_array($t, ['PAID_ADS_REFUND', 'AFFILIATE_ADS_SELLER_FEE_REFUND'], true) => [$L($a['kas'], $amt, 0, 'Refund iklan'), $L($a['iklan'], 0, $amt, 'Balik iklan')],
            in_array($t, ['ADJUSTMENT_ADD', 'ADJUSTMENT_CENTER_ADD', 'FBS_ADJUSTMENT_ADD'], true) => [$L($a['kas'], $amt, 0, 'Penyesuaian masuk'), $L($a['pendapatan_lain'], 0, $amt, 'Pendapatan lain')],
            in_array($t, ['ADJUSTMENT_MINUS', 'ADJUSTMENT_CENTER_DEDUCT', 'FBS_ADJUSTMENT_MINUS', 'FSF_COST_PASSING_DEDUCT'], true) => [$L($a['fee'], $amt, 0, 'Penyesuaian keluar'), $L($a['kas'], 0, $amt, 'Saldo keluar')],
            default => [], // ESCROW_* (sudah di settlement), WITHDRAWAL_CREATED/CANCELLED, tak dikenal → SKIP
        };
    }

    public function postWallet(ShopeeWalletTransaction $w): ?AccJournal
    {
        if ($w->isPosted()) {
            return null;
        }
        $lines = $this->previewWallet($w);
        if (! $lines) {
            return null; // di-skip (escrow/withdrawal-created/tak dikenal) — TAK ubah posting_status, tetap pending
        }

        // Arah kas menentukan tipe jurnal: kas di-debit → uang masuk, kas di-kredit → uang keluar.
        $kasId = $this->accounts()['kas']->id;
        $kasDebit = 0.0;
        foreach ($lines as $l) {
            if ($l['account']->id === $kasId) {
                $kasDebit = $l['debit'];
                break;
            }
        }

        $journal = $this->record(
            $lines,
            date: ($w->transaction_time ?? now())->toDateString(),
            reference: "SHP-WALLET {$w->transaction_id}",
            description: $w->kind ?? 'Transaksi wallet Shopee',
            sourceType: 'shopee_wallet',
            sourceId: $w->id,
            type: $kasDebit > 0 ? 'cash_in' : 'cash_out',
        );
        $w->update([
            'posting_status' => ShopeeWalletTransaction::POST_POSTED,
            'journal_id' => $journal->id,
            'posted_at' => now(),
        ]);

        return $journal;
    }

    /** Saklar pembukuan Shopee (default MATI — buku produksi tak tersentuh). */
    public function enabled(): bool
    {
        return (bool) ShopeeConnection::latest('id')->first()?->journal_enabled;
    }

    /** Batas tanggal pembukuan Shopee = batas mulai potong stok. */
    public function cutoff(): ?Carbon
    {
        $c = ShopeeConnection::latest('id')->first();

        return $c?->deduct_from ? Carbon::parse($c->deduct_from)->startOfDay() : null;
    }

    /** Saldo akun (debit - credit) dari jurnal POSTED — delegasi ke AccountingService. */
    public function balanceOf(int $accountId, ?string $period = null): float
    {
        return $this->accounting->balanceOf($accountId, $period);
    }

    // ---------- helper ----------

    /**
     * @param  array<int, array{account: AccAccount, debit: float, credit: float, memo: string}>  $lines
     * @param  string  $type  wajib salah satu enum acc_journals.type
     */
    private function record(array $lines, string $date, string $reference, string $description, string $sourceType, int $sourceId, string $type): AccJournal
    {
        $branch = AccBranch::active()->orderBy('id')->first();
        if (! $branch) {
            throw new RuntimeException('Belum ada cabang (acc_branches) — jurnal tidak bisa dibuat.');
        }

        return $this->accounting->record(
            [
                'branch_id' => $branch->id,
                'date' => $date,
                'reference' => $reference,
                'description' => $description,
                'type' => $type,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ],
            array_map(fn ($l) => [
                'account_id' => $l['account']->id,
                'debit' => $l['debit'],
                'credit' => $l['credit'],
                'memo' => $l['memo'],
            ], $lines),
        );
    }

    private function acc(string $code, string $name, string $type, string $subtype, string $normal): AccAccount
    {
        return AccAccount::firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'type' => $type, 'subtype' => $subtype, 'normal_balance' => $normal, 'is_active' => true],
        );
    }
}
