<?php

namespace App\Services\Intake;

use App\Models\Account;
use App\Models\Client;
use App\Models\Document;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Ai\AiException;
use App\Services\Ai\AiProviderFactory;
use App\Support\PdfTextExtractor;
use App\Support\Rupiah;
use Illuminate\Support\Carbon;

/**
 * Pembaca dokumen: foto struk / PDF invoice / screenshot mutasi / teks tempel
 * → JSON rincian biaya yang SUDAH dinormalisasi (angka jadi float, tanggal jadi
 * Y-m-d, kode akun dijamin ada di COA klien).
 *
 * Dua prinsip yang tidak boleh dilanggar:
 *   1. AI TIDAK PERNAH memposting jurnal. Hasilnya selalu mendarat di layar
 *      review untuk dikoreksi manusia. Tebakan model = usulan, bukan keputusan.
 *   2. Semua angka dari model dicuci ulang di PHP (Rupiah::parse). Model sering
 *      menulis "Rp 1.250.000" atau "1,250,000" — kalau dipercaya mentah,
 *      jurnalnya selisih.
 */
class DocumentExtractor
{
    /** Ambang keyakinan di bawah ini ditandai merah di layar review. */
    public const LOW_CONFIDENCE = 0.7;

    /**
     * Baca dokumen, simpan hasilnya ke kolom `extraction`, balikin dokumennya.
     *
     * @throws AiException
     */
    public function extract(Document $document): Document
    {
        $client = $document->client;

        // Pembuatan provider ikut di dalam try: key yang belum diisi juga sebuah
        // kegagalan pembacaan, dan alasannya harus mendarat di dokumen — bukan
        // cuma lewat sebagai flash message yang hilang saat halaman di-refresh.
        try {
            $turn = AiProviderFactory::make()->chat([
                ['role' => 'system', 'content' => $this->systemPrompt($client)],
                ['role' => 'user', 'content' => $this->userContent($document)],
            ], ['json' => true]);
        } catch (AiException $e) {
            $document->update([
                'status' => Document::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $raw = $turn->json();
        if ($raw === []) {
            $document->update([
                'status' => Document::STATUS_FAILED,
                'error' => 'Model tidak membalas JSON yang bisa dibaca. Coba ulangi, atau ganti model ke yang lebih kuat.',
            ]);

            throw new AiException('Balasan model bukan JSON valid.');
        }

        $document->update([
            'extraction' => $this->normalize($raw, $client, $document),
            'model_used' => $turn->model,
            'status' => Document::STATUS_EXTRACTED,
            'error' => null,
            'extracted_at' => now(),
        ]);

        return $document->refresh();
    }

    /**
     * Isi pesan user: teks pengarah + berkas aslinya. Gambar selalu dikirim ke
     * model (vision). PDF dicoba diekstrak teksnya dulu — kalau bersih, cukup
     * kirim teks (jauh lebih murah & akurat); kalau hasil scan, kirim berkasnya.
     *
     * @return array<int,array<string,mixed>>
     */
    private function userContent(Document $document): array
    {
        $parts = [['type' => 'text', 'text' => $this->instruction($document)]];
        $bytes = $document->bytes();

        if ($bytes === null) {
            // Dokumen teks tempel.
            $parts[] = ['type' => 'text', 'text' => "=== ISI DOKUMEN ===\n".(string) $document->raw_text];

            return $parts;
        }

        if ($document->isPdf()) {
            $text = PdfTextExtractor::fromBytes($bytes);
            if (! PdfTextExtractor::looksUnreadable($text)) {
                $parts[] = ['type' => 'text', 'text' => "=== TEKS PDF ===\n".$text];

                return $parts;
            }
            // PDF hasil scan → serahkan berkasnya ke model.
            $parts[] = [
                'type' => 'file',
                'mime' => 'application/pdf',
                'data' => base64_encode($bytes),
                'filename' => $document->original_name ?: 'dokumen.pdf',
            ];

            return $parts;
        }

        $parts[] = [
            'type' => 'image',
            'mime' => $document->mime_type ?: 'image/jpeg',
            'data' => base64_encode($bytes),
        ];

        // Teks tambahan dari user (catatan konteks) tetap ikut dikirim.
        if (filled($document->raw_text)) {
            $parts[] = ['type' => 'text', 'text' => "=== CATATAN DARI USER ===\n".$document->raw_text];
        }

        return $parts;
    }

    /** Arahan spesifik per jenis dokumen. */
    private function instruction(Document $document): string
    {
        $perJenis = match ($document->kind) {
            Document::KIND_BANK => 'Dokumen ini MUTASI REKENING / E-WALLET: banyak baris transaksi dalam satu '
                .'gambar. Isi array "transactions" (satu objek per baris mutasi), biarkan "items" kosong. '
                .'Tentukan "direction": "in" kalau saldo bertambah (uang masuk), "out" kalau berkurang. '
                .'JANGAN masukkan baris saldo awal/akhir sebagai transaksi.',
            Document::KIND_INVOICE => 'Dokumen ini INVOICE/FAKTUR dari supplier atau vendor. Isi array "items" '
                .'per baris barang/jasa. Pisahkan PPN/PPh ke field "tax". Ambil nomor invoice ke '
                .'"document_number" dan tanggal jatuh tempo ke "due_date" kalau ada.',
            Document::KIND_RECEIPT => 'Dokumen ini FOTO STRUK/NOTA belanja. Isi array "items" per baris barang. '
                .'Kalau nota tulis tangan dan tidak terbaca jelas, tetap isi yang kebaca, turunkan "confidence", '
                .'dan tulis apa yang buram di "warnings". JANGAN mengarang angka.',
            default => 'Dokumen ini CATATAN TEKS bebas (mis. pesan WhatsApp). Satu pengeluaran/pemasukan = satu '
                .'objek di "items". Contoh "bayar iklan 500rb, gaji admin 3jt" = 2 item: 500000 dan 3000000.',
        };

        return $perJenis."\n\nBalas HANYA objek JSON sesuai skema di instruksi sistem. Tanpa penjelasan di luar JSON.";
    }

    /** Prompt sistem: peran, skema keluaran, COA klien, dan aturan anti-ngarang. */
    private function systemPrompt(Client $client): string
    {
        $coa = $client->accounts()->active()->orderBy('code')
            ->get(['code', 'name', 'type', 'subtype'])
            ->map(fn ($a) => "{$a->code} | {$a->name} | {$a->type}".($a->subtype ? " | {$a->subtype}" : ''))
            ->implode("\n");

        return <<<PROMPT
        Kamu akuntan Indonesia yang teliti. Tugasmu membaca dokumen keuangan dan mengubahnya
        menjadi rincian biaya terstruktur, siap dijadikan jurnal double-entry.

        ATURAN KERAS:
        - JANGAN mengarang. Angka/tanggal/nama yang tidak ada di dokumen → null, bukan tebakan.
        - Kalau tulisan buram atau ambigu, isi sebisanya, turunkan "confidence", dan jelaskan di "warnings".
        - Semua uang dalam RUPIAH sebagai ANGKA MURNI tanpa pemisah dan tanpa "Rp" (1250000, bukan "Rp 1.250.000").
        - Tanggal format YYYY-MM-DD. Dokumen Indonesia sering menulis DD/MM/YYYY — 03/04/2026 berarti 3 April 2026, BUKAN 4 Maret.
        - "account_code" WAJIB salah satu kode dari DAFTAR AKUN di bawah. Dilarang mengarang kode baru.
        - "flow": "expense" untuk uang keluar/pembelian/beban, "income" untuk uang masuk/penjualan.
        - Jangan masukkan PPN ke dalam "amount" item. PPN/PPh taruh terpisah di "tax".
        - Pecah per baris barang. Jangan gabungkan 5 item jadi satu "pembelian".

        DAFTAR AKUN (COA) KLIEN "{$client->name}" — pilih HANYA dari sini:
        {$coa}

        CARA MEMILIH AKUN:
        - Cocokkan makna barang/jasa ke nama akun. Iklan Meta/TikTok Ads → akun Beban Iklan & Promosi.
        - Fee marketplace/admin TikTok Shop/Shopee/payment gateway → Beban Marketplace & Payment Gateway.
        - Kirim paket JNE/J&T/SiCepat/ongkir → Beban Transportasi & Ongkir.
        - Bahan baku, botol, label, kardus produk → akun HPP/Kemasan, BUKAN beban operasional.
        - Langganan ChatGPT/Claude/Canva/Figma/hosting → Beban Langganan Software & AI.
        - Fee KOL/affiliate/endorse → Beban Komisi Affiliate & KOL.
        - Admin bank, biaya transfer, materai → Beban Administrasi Bank.
        - Bunga pinjaman → Beban Bunga. Cicilan POKOK pinjaman → akun Hutang Bank (liabilitas), BUKAN beban.
        - Benar-benar tidak jelas → pakai akun Beban Operasional Lain-lain dan turunkan confidence.

        SKEMA KELUARAN (balas HANYA objek JSON ini):
        {
          "doc_type": "receipt" | "invoice" | "bank_statement" | "note",
          "flow": "expense" | "income",
          "vendor": string|null,
          "document_number": string|null,
          "document_date": "YYYY-MM-DD"|null,
          "due_date": "YYYY-MM-DD"|null,
          "payment_method": "cash" | "bank" | "ewallet" | "credit" | "unknown",
          "currency": "IDR",
          "subtotal": number|null,
          "discount": number|null,
          "tax": number|null,
          "total": number|null,
          "items": [
            {
              "description": string,
              "qty": number|null,
              "unit": string|null,
              "unit_price": number|null,
              "amount": number,
              "account_code": string,
              "account_reason": string,
              "confidence": number
            }
          ],
          "transactions": [
            {
              "date": "YYYY-MM-DD"|null,
              "description": string,
              "amount": number,
              "direction": "in" | "out",
              "account_code": string,
              "account_reason": string,
              "confidence": number
            }
          ],
          "notes": string|null,
          "warnings": [string]
        }
        PROMPT;
    }

    /**
     * Cuci hasil model: angka → float, tanggal → Y-m-d, kode akun → dijamin ada
     * di COA klien, dan tambahkan peringatan silang (rincian vs total).
     *
     * @param  array<mixed>  $raw
     * @return array<string,mixed>
     */
    public function normalize(array $raw, Client $client, ?Document $document = null): array
    {
        $codes = $client->accounts()->active()->pluck('type', 'code')->all();
        $warnings = array_values(array_filter(array_map(
            fn ($w) => is_string($w) ? trim($w) : null,
            (array) ($raw['warnings'] ?? []),
        )));

        $flow = in_array($raw['flow'] ?? null, ['expense', 'income'], true) ? $raw['flow'] : 'expense';
        $docDate = $this->date($raw['document_date'] ?? null);

        $items = [];
        foreach ((array) ($raw['items'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $amount = Rupiah::parse($row['amount'] ?? null);
            $qty = Rupiah::parse($row['qty'] ?? null);
            $unitPrice = Rupiah::parse($row['unit_price'] ?? null);

            // Model kadang isi qty+harga satuan tapi lupa totalnya.
            if (($amount === null || $amount === 0.0) && $qty && $unitPrice) {
                $amount = round($qty * $unitPrice, 2);
            }
            if ($amount === null || abs($amount) < 0.005) {
                continue;
            }

            [$code, $codeWarning] = $this->resolveCode($row['account_code'] ?? null, $codes, $flow, (string) ($row['description'] ?? ''));
            if ($codeWarning) {
                $warnings[] = $codeWarning;
            }

            $items[] = [
                'description' => trim((string) ($row['description'] ?? '')) ?: 'Tanpa keterangan',
                'qty' => $qty,
                'unit' => filled($row['unit'] ?? null) ? (string) $row['unit'] : null,
                'unit_price' => $unitPrice,
                'amount' => abs($amount),
                'account_code' => $code,
                'account_reason' => trim((string) ($row['account_reason'] ?? '')) ?: null,
                'confidence' => $this->confidence($row['confidence'] ?? null),
            ];
        }

        $transactions = [];
        foreach ((array) ($raw['transactions'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $amount = Rupiah::parse($row['amount'] ?? null);
            if ($amount === null || abs($amount) < 0.005) {
                continue;
            }

            $direction = ($row['direction'] ?? null) === 'in' ? 'in' : 'out';
            [$code, $codeWarning] = $this->resolveCode(
                $row['account_code'] ?? null,
                $codes,
                $direction === 'in' ? 'income' : 'expense',
                (string) ($row['description'] ?? ''),
            );
            if ($codeWarning) {
                $warnings[] = $codeWarning;
            }

            $transactions[] = [
                'date' => $this->date($row['date'] ?? null) ?? $docDate,
                'description' => trim((string) ($row['description'] ?? '')) ?: 'Mutasi tanpa keterangan',
                'amount' => abs($amount),
                'direction' => $direction,
                'account_code' => $code,
                'account_reason' => trim((string) ($row['account_reason'] ?? '')) ?: null,
                'confidence' => $this->confidence($row['confidence'] ?? null),
            ];
        }

        $total = Rupiah::parse($raw['total'] ?? null);
        $tax = Rupiah::parse($raw['tax'] ?? null) ?? 0.0;
        $discount = Rupiah::parse($raw['discount'] ?? null) ?? 0.0;
        $itemsSum = round(array_sum(array_column($items, 'amount')), 2);

        // Rincian kosong tapi total ada → bikin satu item payung supaya tetap bisa dijurnal.
        if ($items === [] && $transactions === [] && $total !== null && abs($total) >= 0.005) {
            $items[] = [
                'description' => trim((string) ($raw['vendor'] ?? '')) ?: 'Transaksi tanpa rincian',
                'qty' => null, 'unit' => null, 'unit_price' => null,
                'amount' => abs($total),
                'account_code' => $this->fallbackCode($codes, $flow),
                'account_reason' => 'Dokumen tidak memuat rincian per baris.',
                'confidence' => 0.4,
            ];
            $itemsSum = abs($total);
            $warnings[] = 'Rincian per baris tidak terbaca — dibuat satu baris sejumlah total dokumen. Periksa & pecah manual kalau perlu.';
        }

        // Cek silang: rincian + pajak - diskon harus mendekati total dokumen.
        if ($total !== null && $items !== []) {
            $expected = round($itemsSum + $tax - $discount, 2);
            if (abs($expected - $total) > 1.0) {
                $warnings[] = sprintf(
                    'Rincian tidak cocok total dokumen: rincian Rp%s + pajak Rp%s − diskon Rp%s = Rp%s, tapi total tertulis Rp%s (selisih Rp%s). Periksa sebelum posting.',
                    Rupiah::format($itemsSum), Rupiah::format($tax), Rupiah::format($discount),
                    Rupiah::format($expected), Rupiah::format($total), Rupiah::format(abs($expected - $total)),
                );
            }
        }

        if ($docDate === null && $transactions === []) {
            $warnings[] = 'Tanggal dokumen tidak terbaca — default ke hari ini, mohon dikoreksi.';
        }

        return [
            'doc_type' => (string) ($raw['doc_type'] ?? ($document?->kind ?? 'note')),
            'flow' => $flow,
            'vendor' => filled($raw['vendor'] ?? null) ? trim((string) $raw['vendor']) : null,
            'document_number' => filled($raw['document_number'] ?? null) ? trim((string) $raw['document_number']) : null,
            'document_date' => $docDate ?? now()->toDateString(),
            'due_date' => $this->date($raw['due_date'] ?? null),
            'payment_method' => in_array($raw['payment_method'] ?? null, ['cash', 'bank', 'ewallet', 'credit'], true)
                ? $raw['payment_method'] : 'unknown',
            'currency' => 'IDR',
            'subtotal' => Rupiah::parse($raw['subtotal'] ?? null),
            'discount' => $discount,
            'tax' => $tax,
            'total' => $total ?? round($itemsSum + $tax - $discount, 2),
            'items' => $items,
            'transactions' => $transactions,
            'notes' => filled($raw['notes'] ?? null) ? trim((string) $raw['notes']) : null,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Kode akun dari model → kode yang DIJAMIN ada di COA klien. Kode karangan
     * diganti akun default + peringatan, supaya jurnal tidak pernah gagal
     * hanya karena model salah ngarang nomor.
     *
     * @param  array<string,string>  $codes  kode => type
     * @return array{0:string,1:?string}
     */
    private function resolveCode(mixed $code, array $codes, string $flow, string $description): array
    {
        $code = is_string($code) ? trim($code) : '';
        if ($code !== '' && isset($codes[$code])) {
            return [$code, null];
        }

        $fallback = $this->fallbackCode($codes, $flow);
        $label = $code !== '' ? "\"{$code}\"" : 'kosong';

        return [$fallback, sprintf(
            'Kode akun %s dari AI tidak ada di COA klien — baris "%s" dialihkan ke %s. Pilih akun yang benar sebelum posting.',
            $label, mb_substr($description, 0, 40), $fallback,
        )];
    }

    /** Akun cadangan: beban lain-lain untuk pengeluaran, penjualan untuk pemasukan. */
    private function fallbackCode(array $codes, string $flow): string
    {
        $wanted = $flow === 'income'
            ? [ChartOfAccounts::DEFAULT_REVENUE, ChartOfAccounts::DEFAULT_EXPENSE]
            : [ChartOfAccounts::DEFAULT_EXPENSE, ChartOfAccounts::DEFAULT_REVENUE];

        foreach ($wanted as $code) {
            if (isset($codes[$code])) {
                return $code;
            }
        }

        // COA sudah diobrak-abrik total — ambil akun pertama yang tipenya cocok.
        $type = $flow === 'income' ? Account::TYPE_REVENUE : Account::TYPE_EXPENSE;
        foreach ($codes as $code => $accType) {
            if ($accType === $type) {
                return (string) $code;
            }
        }

        return (string) array_key_first($codes);
    }

    /** Tanggal apa pun → Y-m-d, atau null. Tolak tanggal yang jelas ngawur. */
    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = Carbon::parse(trim($value));
        } catch (\Throwable) {
            return null;
        }

        // Struk dari tahun 1970 atau 2090 = salah baca, bukan data.
        if ($date->year < 2000 || $date->year > (int) now()->addYear()->year) {
            return null;
        }

        return $date->toDateString();
    }

    /** Keyakinan model → float 0..1 (default 0.5 kalau tidak diisi). */
    private function confidence(mixed $value): float
    {
        $n = is_numeric($value) ? (float) $value : 0.5;
        // Model kadang menulis 85 untuk "85%".
        if ($n > 1) {
            $n /= 100;
        }

        return round(max(0.0, min(1.0, $n)), 2);
    }
}
