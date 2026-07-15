<?php

namespace App\Services;

use App\Models\AccAccount;
use App\Models\TiktokSettlement;

/**
 * Susun jurnal akuntansi dari pencairan TikTok (M3b preview, M3c posting).
 *
 * Peta akun (pakai COA yang ada; auto-buat kalau belum ada — opsi "B" user):
 *   1003 Kas TikTok         — kas penerima payout
 *   4001 Penjualan          — omzet bruto
 *   6005 Beban Biaya E-commerce — fee marketplace
 *   6001 Beban Iklan/Promosi    — potongan iklan TikTok Ads
 *   6007 Beban Ongkos Kirim     — potongan ongkir
 *   4002 Pendapatan Lain-lain   — penyesuaian positif
 *
 * Rumus penjualan: Debit Bank(net) + Debit Fee (+ penyesuaian) = Kredit Penjualan(bruto).
 * HPP (Debit 5003 / Kredit 1202) menyusul di M3c (butuh daftar order per pencairan).
 */
class SettlementJournalService
{
    /** @return array<string, AccAccount> */
    public function accounts(): array
    {
        return [
            'bank' => $this->acc('1003', 'Kas TikTok', 'asset', 'cash', 'debit'),
            'revenue' => $this->acc('4001', 'Penjualan', 'revenue', 'sales', 'credit'),
            'fee' => $this->acc('6005', 'Beban Biaya E-commerce', 'expense', 'operating', 'debit'),
            'ads' => $this->acc('6001', 'Beban Iklan / Promosi', 'expense', 'operating', 'debit'),
            'ship' => $this->acc('6007', 'Beban Ongkos Kirim', 'expense', 'operating', 'debit'),
            'other_income' => $this->acc('4002', 'Pendapatan Lain-lain', 'revenue', 'other', 'credit'),
        ];
    }

    /**
     * Baris jurnal yang akan dibuat untuk 1 pencairan.
     *
     * @return array{lines: array<int, array{account: AccAccount, debit: float, credit: float, memo: string}>, balanced: bool, hpp_pending: bool}
     */
    public function preview(TiktokSettlement $s): array
    {
        $acc = $this->accounts();
        $revenue = (float) $s->revenue_amount;
        $fee = (float) $s->fee_amount;            // positif
        $adj = (float) $s->adjustment_amount;     // bertanda
        $net = (float) $s->settlement_amount;     // bertanda (jualan +, potongan −)

        $lines = [];
        $add = function (AccAccount $a, float $debit, float $credit, string $memo) use (&$lines) {
            if (round($debit, 2) == 0.0 && round($credit, 2) == 0.0) {
                return;
            }
            $lines[] = ['account' => $a, 'debit' => round(max($debit, 0), 2), 'credit' => round(max($credit, 0), 2), 'memo' => $memo];
        };

        $hppPending = false;

        if ($revenue > 0) {
            // Penjualan: kas net + fee (+ penyesuaian) = pendapatan bruto
            $add($acc['bank'], $net, -$net, 'Dana cair bersih');
            $add($acc['fee'], $fee, 0, 'Fee marketplace');
            if ($adj < 0) {
                $add($acc['fee'], -$adj, 0, 'Penyesuaian TikTok');
            } elseif ($adj > 0) {
                $add($acc['other_income'], 0, $adj, 'Penyesuaian TikTok');
            }
            $add($acc['revenue'], 0, $revenue, 'Penjualan TikTok (bruto)');
            $hppPending = true;
        } else {
            // Potongan (iklan/ongkir/penyesuaian): beban vs kas
            $expense = $s->kind === 'Ongkir / logistik' ? $acc['ship'] : $acc['ads'];
            $out = -$net; // positif = uang keluar
            if ($out >= 0) {
                $add($expense, $out, 0, $s->kind ?? 'Potongan TikTok');
                $add($acc['bank'], 0, $out, 'Kas keluar');
            } else {
                // pengembalian (jarang): kas masuk, kurangi beban
                $add($acc['bank'], -$out, 0, 'Kas masuk');
                $add($expense, 0, -$out, ($s->kind ?? 'Potongan').' (pengembalian)');
            }
        }

        $totalD = round(array_sum(array_column($lines, 'debit')), 2);
        $totalC = round(array_sum(array_column($lines, 'credit')), 2);

        return ['lines' => $lines, 'balanced' => abs($totalD - $totalC) < 0.005, 'hpp_pending' => $hppPending];
    }

    private function acc(string $code, string $name, string $type, string $subtype, string $normal): AccAccount
    {
        return AccAccount::firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'type' => $type, 'subtype' => $subtype, 'normal_balance' => $normal, 'is_active' => true],
        );
    }
}
