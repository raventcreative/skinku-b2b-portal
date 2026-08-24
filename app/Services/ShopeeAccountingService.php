<?php

namespace App\Services;

use App\Models\AccAccount;
use App\Models\AccBranch;
use App\Models\AccJournal;
use App\Models\ShopeeConnection;
use App\Models\ShopeeOrder;
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
