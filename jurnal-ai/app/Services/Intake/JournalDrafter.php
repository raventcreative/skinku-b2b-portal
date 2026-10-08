<?php

namespace App\Services\Intake;

use App\Models\Account;
use App\Models\Document;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\JournalPoster;
use App\Support\Rupiah;

/**
 * Hasil baca AI → USULAN jurnal double-entry yang sudah balance. Keluarannya
 * bukan jurnal jadi, tapi bahan untuk layar review: user masih bisa ganti akun,
 * nominal, tanggal, dan akun pembayaran sebelum posting.
 *
 * Cara menyusun sisi jurnal (flow = expense):
 *     D  tiap baris rincian  → akun beban/HPP/aset pilihan AI
 *     D  pajak masukan       → PPN Masukan (kalau ada)
 *     K  diskon              → akun beban dominan (kalau ada)
 *     K  sisanya             → akun pembayaran (kas/bank/e-wallet) atau Hutang Usaha
 * flow = income dibalik: rincian di kredit, pembayaran di debit.
 *
 * Sisi pembayaran dihitung sebagai SISA (total debit − kredit lain), jadi draft
 * SELALU balance apa pun angka yang dibaca model. Ketidakcocokan dengan total
 * di dokumen tidak disembunyikan — dilaporkan sebagai warning oleh extractor.
 */
class JournalDrafter
{
    /** Mode bayar dari AI → subtype akun yang dicari di COA. */
    private const PAYMENT_SUBTYPE = [
        'cash' => 'cash',
        'bank' => 'bank',
        'ewallet' => 'ewallet',
    ];

    /**
     * @return array<int,array<string,mixed>> daftar usulan jurnal
     */
    public function draft(Document $document): array
    {
        $extraction = $document->extraction ?? [];
        if ($extraction === []) {
            return [];
        }

        $accounts = $document->client->accounts()->active()->get()->keyBy('code');
        if ($accounts->isEmpty()) {
            return [];
        }

        $transactions = $extraction['transactions'] ?? [];

        return $transactions !== []
            ? $this->fromTransactions($extraction, $accounts, $document)
            : $this->fromItems($extraction, $accounts, $document);
    }

    /**
     * Struk / invoice / catatan teks → satu jurnal.
     *
     * @param  \Illuminate\Support\Collection<string,Account>  $accounts
     * @return array<int,array<string,mixed>>
     */
    private function fromItems(array $extraction, $accounts, Document $document): array
    {
        $items = $extraction['items'] ?? [];
        if ($items === []) {
            return [];
        }

        $income = ($extraction['flow'] ?? 'expense') === 'income';
        $tax = (float) ($extraction['tax'] ?? 0);
        $discount = (float) ($extraction['discount'] ?? 0);
        $vendor = $extraction['vendor'] ?? null;

        $lines = [];
        $mainSide = $income ? 'credit' : 'debit';
        $otherSide = $income ? 'debit' : 'credit';

        foreach ($items as $item) {
            $account = $accounts->get($item['account_code']);
            if (! $account) {
                continue;
            }
            $lines[] = $this->line($account, $mainSide, (float) $item['amount'], $this->memo($item), $item['confidence'] ?? null, $item['account_reason'] ?? null);
        }

        if ($lines === []) {
            return [];
        }

        // PPN masukan ikut sisi rincian (pembelian) / hutang pajak (penjualan).
        if ($tax >= 0.005) {
            $taxCode = $income ? '2103' : ChartOfAccounts::DEFAULT_TAX_IN;
            if ($taxAccount = $accounts->get($taxCode)) {
                $lines[] = $this->line($taxAccount, $mainSide, $tax, 'Pajak atas dokumen ini');
            }
        }

        // Diskon mengurangi nilai transaksi → masuk sisi berlawanan, dibebankan
        // ke akun rincian terbesar supaya tidak bikin akun baru.
        if ($discount >= 0.005) {
            $dominant = $accounts->get($this->dominantCode($items));
            if ($dominant) {
                $lines[] = $this->line($dominant, $otherSide, $discount, 'Diskon');
            }
        }

        // Sisi pembayaran = sisa, supaya jurnal dijamin balance.
        $settlement = round(
            array_sum(array_column($lines, $mainSide)) - array_sum(array_column($lines, $otherSide)),
            2,
        );
        $payAccount = $this->paymentAccount($extraction, $accounts, $income);

        if ($settlement >= 0.005 && $payAccount) {
            $lines[] = $this->line($payAccount, $otherSide, $settlement, $this->settlementMemo($extraction, $income));
        }

        $date = $extraction['document_date'] ?? now()->toDateString();
        $reference = $extraction['document_number'] ?? null;
        $description = trim(implode(' — ', array_filter([
            $income ? 'Penjualan' : 'Pembelian/Beban',
            $vendor,
            $reference ? "No. {$reference}" : null,
        ])));

        return [[
            'date' => $date,
            'reference' => $reference,
            'description' => $description,
            'type' => $income ? 'sale' : ($document->kind === Document::KIND_INVOICE ? 'purchase' : 'expense'),
            'fingerprint' => JournalPoster::fingerprint(
                $document->client_id,
                $date,
                $settlement,
                implode('|', [$vendor, $reference, count($items)]),
            ),
            'lines' => $lines,
        ]];
    }

    /**
     * Mutasi rekening → SATU JURNAL PER BARIS. Dipisah supaya tiap baris punya
     * sidik jari sendiri: impor ulang screenshot yang sama tidak menggandakan,
     * dan baris yang salah bisa di-void tanpa membatalkan sebulan mutasi.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fromTransactions(array $extraction, $accounts, Document $document): array
    {
        // Akun kas sisi rekening: ikut mode bayar dokumen (bank / e-wallet).
        $bank = $this->paymentAccount($extraction, $accounts, false)
            ?? $accounts->get(ChartOfAccounts::DEFAULT_CASH);
        if (! $bank) {
            return [];
        }

        $drafts = [];
        foreach ($extraction['transactions'] as $row) {
            $counter = $accounts->get($row['account_code']);
            if (! $counter) {
                continue;
            }

            $amount = (float) $row['amount'];
            $date = $row['date'] ?? $extraction['document_date'] ?? now()->toDateString();
            $in = $row['direction'] === 'in';

            // Masuk: D rekening / K akun lawan. Keluar: D akun lawan / K rekening.
            $lines = $in
                ? [
                    $this->line($bank, 'debit', $amount, $row['description']),
                    $this->line($counter, 'credit', $amount, $row['description'], $row['confidence'] ?? null, $row['account_reason'] ?? null),
                ]
                : [
                    $this->line($counter, 'debit', $amount, $row['description'], $row['confidence'] ?? null, $row['account_reason'] ?? null),
                    $this->line($bank, 'credit', $amount, $row['description']),
                ];

            $drafts[] = [
                'date' => $date,
                'reference' => null,
                'description' => $row['description'],
                'type' => 'bank',
                'fingerprint' => JournalPoster::fingerprint(
                    $document->client_id,
                    $date,
                    $amount,
                    $bank->code.'|'.($in ? 'in' : 'out').'|'.$row['description'],
                ),
                'lines' => $lines,
            ];
        }

        return $drafts;
    }

    /**
     * Akun lawan untuk pembayaran. "credit" = belum dibayar → Hutang Usaha
     * (pembelian) / Piutang Usaha (penjualan). Selain itu cari akun dengan
     * subtype yang cocok; kalau tak ada, jatuh ke Kas.
     */
    private function paymentAccount(array $extraction, $accounts, bool $income): ?Account
    {
        $method = $extraction['payment_method'] ?? 'unknown';

        if ($method === 'credit') {
            $code = $income ? '1201' : ChartOfAccounts::DEFAULT_PAYABLE;
            if ($account = $accounts->get($code)) {
                return $account;
            }
        }

        if ($subtype = self::PAYMENT_SUBTYPE[$method] ?? null) {
            if ($account = $accounts->first(fn (Account $a) => $a->subtype === $subtype)) {
                return $account;
            }
        }

        return $accounts->get(ChartOfAccounts::DEFAULT_CASH)
            ?? $accounts->first(fn (Account $a) => in_array($a->subtype, Account::PAYMENT_SUBTYPES, true));
    }

    private function settlementMemo(array $extraction, bool $income): string
    {
        return match ($extraction['payment_method'] ?? 'unknown') {
            'credit' => $income ? 'Belum diterima (piutang)' : 'Belum dibayar (hutang)',
            'cash' => 'Dibayar tunai',
            'bank' => $income ? 'Masuk rekening bank' : 'Dibayar via transfer bank',
            'ewallet' => $income ? 'Masuk e-wallet' : 'Dibayar via e-wallet/QRIS',
            default => $income ? 'Penerimaan' : 'Pembayaran',
        };
    }

    /** Kode akun dengan nominal terbesar — sasaran pembebanan diskon. */
    private function dominantCode(array $items): string
    {
        $byCode = [];
        foreach ($items as $item) {
            $byCode[$item['account_code']] = ($byCode[$item['account_code']] ?? 0) + (float) $item['amount'];
        }
        arsort($byCode);

        return (string) array_key_first($byCode);
    }

    private function memo(array $item): string
    {
        $memo = (string) $item['description'];
        if (! empty($item['qty'])) {
            $memo .= ' ('.Rupiah::format((float) $item['qty']).($item['unit'] ? ' '.$item['unit'] : 'x').')';
        }

        return mb_substr($memo, 0, 255);
    }

    /** @return array<string,mixed> */
    private function line(Account $account, string $side, float $amount, string $memo, mixed $confidence = null, ?string $reason = null): array
    {
        return [
            'account_id' => $account->id,
            'account_code' => $account->code,
            'account_name' => $account->name,
            'debit' => $side === 'debit' ? round($amount, 2) : 0.0,
            'credit' => $side === 'credit' ? round($amount, 2) : 0.0,
            'memo' => $memo,
            'confidence' => $confidence === null ? null : (float) $confidence,
            'reason' => $reason,
        ];
    }
}
