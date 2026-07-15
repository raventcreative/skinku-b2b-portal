<?php

namespace App\Services;

use App\Models\AccAccount;
use App\Models\AccBranch;
use App\Models\AccJournal;
use App\Models\TiktokConnection;
use App\Models\TiktokOrder;
use App\Models\TiktokSettlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Jurnal akuntansi integrasi TikTok — "Opsi C" (akrual, matching benar):
 *
 *  1. BARANG KELUAR (potong stok)  → Dr Persediaan Dalam Perjalanan / Cr Persediaan Barang Jadi
 *     Cuma pindah aset. NOL dampak ke laba — barang di jalan masih milik kita.
 *
 *  2. ORDER SAMPAI (DELIVERED)     → Dr Piutang TikTok      / Cr Penjualan   (bruto)
 *                                    Dr Beban HPP           / Cr Persediaan Dalam Perjalanan
 *     Omzet & HPP diakui BARENG → laba kotor akurat.
 *
 *  3. DANA CAIR (settlement)       → Dr Kas TikTok (net) + Dr Beban Fee / Cr Piutang TikTok (bruto)
 *     Bukan omzet baru — cuma penagihan. Piutang dipotong pakai `revenue_amount` dari
 *     TikTok, jadi TIDAK perlu mencocokkan order ID satu per satu (control account).
 *     Pencairan potongan (iklan/ongkir) → Dr Beban terkait / Cr Kas.
 *
 * Semua idempoten: sudah berjurnal → dilewati. HPP dikunci di `hpp_amount` saat
 * langkah 1 supaya akun transit bersih saat dilepas di langkah 2.
 */
class TikTokAccountingService
{
    public function __construct(private AccountingService $accounting) {}

    /** @return array<string, AccAccount> */
    public function accounts(): array
    {
        return [
            'kas' => $this->acc('1003', 'Kas TikTok', 'asset', 'cash', 'debit'),
            'piutang' => $this->acc('1103', 'Piutang TikTok', 'asset', 'receivable', 'debit'),
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
    public function previewTransit(TiktokOrder $order): array
    {
        $a = $this->accounts();
        $hpp = (float) $order->hpp_amount;

        return $hpp <= 0 ? [] : [
            ['account' => $a['transit'], 'debit' => $hpp, 'credit' => 0.0, 'memo' => "Barang keluar TikTok {$order->tiktok_order_id}"],
            ['account' => $a['persediaan'], 'debit' => 0.0, 'credit' => $hpp, 'memo' => 'Keluar dari gudang'],
        ];
    }

    public function postTransit(TiktokOrder $order): ?AccJournal
    {
        if ($order->transit_journal_id || (float) $order->hpp_amount <= 0) {
            return null; // sudah dijurnal / tak ada nilai HPP
        }
        $journal = $this->record(
            $this->previewTransit($order),
            date: ($order->deducted_at ?? now())->toDateString(),
            reference: "TT-KELUAR {$order->tiktok_order_id}",
            description: 'Barang keluar gudang (belum diakui penjualan)',
            sourceType: 'tiktok_order_transit',
            sourceId: $order->id,
            type: 'inventory',   // cuma pindah aset, bukan penjualan
        );
        $order->update(['transit_journal_id' => $journal->id]);

        return $journal;
    }

    // ---------- 2. Order sampai → penjualan + HPP ----------

    /** @return array<int, array{account: AccAccount, debit: float, credit: float, memo: string}> */
    public function previewSale(TiktokOrder $order): array
    {
        $a = $this->accounts();
        $bruto = (float) $order->total_amount;
        $hpp = (float) $order->hpp_amount;
        $lines = [];

        if ($bruto > 0) {
            $lines[] = ['account' => $a['piutang'], 'debit' => $bruto, 'credit' => 0.0, 'memo' => "Order sampai {$order->tiktok_order_id}"];
            $lines[] = ['account' => $a['penjualan'], 'debit' => 0.0, 'credit' => $bruto, 'memo' => 'Penjualan TikTok (bruto)'];
        }
        if ($hpp > 0) {
            $lines[] = ['account' => $a['hpp'], 'debit' => $hpp, 'credit' => 0.0, 'memo' => 'HPP terjual'];
            $lines[] = ['account' => $a['transit'], 'debit' => 0.0, 'credit' => $hpp, 'memo' => 'Lepas dari perjalanan'];
        }

        return $lines;
    }

    public function postSale(TiktokOrder $order): ?AccJournal
    {
        if ($order->sale_journal_id || ! $order->isDelivered()) {
            return null;
        }
        $lines = $this->previewSale($order);
        if (! $lines) {
            return null;
        }
        $journal = $this->record(
            $lines,
            date: now()->toDateString(),
            reference: "TT-JUAL {$order->tiktok_order_id}",
            description: 'Order sampai — akui penjualan & HPP',
            sourceType: 'tiktok_order_sale',
            sourceId: $order->id,
            type: 'sales',
        );
        $order->update(['sale_journal_id' => $journal->id]);

        return $journal;
    }

    // ---------- 3. Dana cair → kas + fee, potong piutang ----------

    /** @return array<int, array{account: AccAccount, debit: float, credit: float, memo: string}> */
    public function previewSettlement(TiktokSettlement $s): array
    {
        $a = $this->accounts();
        $revenue = (float) $s->revenue_amount;
        $fee = (float) $s->fee_amount;
        $adj = (float) $s->adjustment_amount;
        $net = (float) $s->settlement_amount;
        $lines = [];

        if ($revenue > 0) {
            // Penjualan cair: kas + fee (+ penyesuaian) menutup piutang sebesar bruto.
            $lines[] = ['account' => $a['kas'], 'debit' => $net, 'credit' => 0.0, 'memo' => 'Dana cair bersih'];
            $lines[] = ['account' => $a['fee'], 'debit' => $fee, 'credit' => 0.0, 'memo' => 'Fee marketplace'];
            if ($adj < 0) {
                $lines[] = ['account' => $a['fee'], 'debit' => -$adj, 'credit' => 0.0, 'memo' => 'Penyesuaian TikTok'];
            } elseif ($adj > 0) {
                $lines[] = ['account' => $a['pendapatan_lain'], 'debit' => 0.0, 'credit' => $adj, 'memo' => 'Penyesuaian TikTok'];
            }
            $lines[] = ['account' => $a['piutang'], 'debit' => 0.0, 'credit' => $revenue, 'memo' => 'Piutang TikTok tertagih'];
        } else {
            // Potongan (iklan/ongkir): beban vs kas.
            $beban = $s->kind === 'Ongkir / logistik' ? $a['ongkir'] : $a['iklan'];
            $out = -$net;
            if ($out >= 0) {
                $lines[] = ['account' => $beban, 'debit' => $out, 'credit' => 0.0, 'memo' => $s->kind ?? 'Potongan TikTok'];
                $lines[] = ['account' => $a['kas'], 'debit' => 0.0, 'credit' => $out, 'memo' => 'Kas keluar'];
            } else {
                $lines[] = ['account' => $a['kas'], 'debit' => -$out, 'credit' => 0.0, 'memo' => 'Kas masuk'];
                $lines[] = ['account' => $beban, 'debit' => 0.0, 'credit' => -$out, 'memo' => ($s->kind ?? 'Potongan').' (pengembalian)'];
            }
        }

        return array_values(array_filter($lines, fn ($l) => round($l['debit'], 2) != 0.0 || round($l['credit'], 2) != 0.0));
    }

    public function postSettlement(TiktokSettlement $s): ?AccJournal
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
            date: ($s->statement_time ?? now())->toDateString(),
            reference: "TT-CAIR {$s->tiktok_statement_id}",
            description: 'Pencairan TikTok — '.($s->kind ?? 'pencairan'),
            sourceType: 'tiktok_settlement',
            sourceId: $s->id,
            type: (float) $s->settlement_amount >= 0 ? 'cash_in' : 'cash_out',
        );
        $s->update([
            'posting_status' => TiktokSettlement::POST_POSTED,
            'journal_id' => $journal->id,
            'posted_at' => now(),
        ]);

        return $journal;
    }

    /**
     * Pratinjau pencairan + cek balance (dipakai halaman rincian).
     *
     * @return array{lines: array, balanced: bool}
     */
    public function preview(TiktokSettlement $s): array
    {
        $lines = $this->previewSettlement($s);
        $d = round(array_sum(array_column($lines, 'debit')), 2);
        $c = round(array_sum(array_column($lines, 'credit')), 2);

        return ['lines' => $lines, 'balanced' => abs($d - $c) < 0.005];
    }

    // ---------- pass posting (idempoten, bisa dijalankan berulang) ----------

    /**
     * Jurnalkan semua yang belum: barang keluar → order sampai → dana cair.
     * Hormati batas tanggal (deduct_from) supaya periode pra-opname yang sudah
     * dibukukan lewat impor Excel tidak dobel.
     *
     * @return array{transit:int, sale:int, settlement:int, failed:int}
     */
    public function postPending(): array
    {
        $cut = $this->cutoff();
        $transit = 0;
        $sale = 0;
        $settlement = 0;
        $failed = 0;

        // 1. Barang keluar yang belum dijurnal
        $q = TiktokOrder::where('stock_status', TiktokOrder::STATUS_DEDUCTED)->whereNull('transit_journal_id');
        foreach ($this->withCutoff($q, $cut, 'order_created_at')->get() as $o) {
            try {
                $this->postTransit($o) && $transit++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error("[tiktok-jurnal] transit order {$o->tiktok_order_id} gagal: ".$e->getMessage());
            }
        }

        // 2. Order sampai yang belum diakui penjualannya (transit harus sudah dijurnal)
        $q = TiktokOrder::whereIn('status', TiktokOrder::DELIVERED_STATUSES)
            ->whereNotNull('transit_journal_id')->whereNull('sale_journal_id');
        foreach ($this->withCutoff($q, $cut, 'order_created_at')->get() as $o) {
            try {
                $this->postSale($o) && $sale++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error("[tiktok-jurnal] penjualan order {$o->tiktok_order_id} gagal: ".$e->getMessage());
            }
        }

        // 3. Pencairan yang belum dijurnal
        $q = TiktokSettlement::where('posting_status', TiktokSettlement::POST_PENDING);
        foreach ($this->withCutoff($q, $cut, 'statement_time')->get() as $s) {
            try {
                $this->postSettlement($s) && $settlement++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error("[tiktok-jurnal] pencairan {$s->tiktok_statement_id} gagal: ".$e->getMessage());
            }
        }

        return compact('transit', 'sale', 'settlement', 'failed');
    }

    /** Batas tanggal pembukuan TikTok = batas mulai potong stok. */
    public function cutoff(): ?Carbon
    {
        $c = TiktokConnection::latest('id')->first();

        return $c?->deduct_from ? Carbon::parse($c->deduct_from)->startOfDay() : null;
    }

    private function withCutoff($query, ?Carbon $cut, string $column)
    {
        return $cut ? $query->where($column, '>=', $cut) : $query;
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
