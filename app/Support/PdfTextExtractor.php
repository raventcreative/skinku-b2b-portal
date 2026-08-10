<?php

namespace App\Support;

/**
 * Ekstraktor teks PDF murni PHP — tanpa dependency composer. Memakai zlib
 * bawaan PHP (gzuncompress/gzinflate) untuk dekompresi stream FlateDecode,
 * lalu regex untuk menarik teks dari operator PDF `(...)Tj` dan `[...]TJ`.
 *
 * PDF "bersih" (teks asli tersimpan sebagai string, bukan hasil scan atau
 * font CID custom) akan terekstrak rapi — contoh: laporan Creator List
 * TikTok Shop. PDF ber-font CID/custom encoding (mis. laporan Leads/Ads)
 * menghasilkan teks kosong atau penuh byte non-printable; looksUnreadable()
 * mendeteksi kasus itu sebagai sinyal untuk fallback ke AI multimodal.
 */
class PdfTextExtractor
{
    /** Ekstrak teks dari file PDF. Bisa kosong/berantakan untuk PDF hasil scan atau ber-font CID. */
    public static function extract(string $path): string
    {
        $data = @file_get_contents($path);
        if ($data === false || $data === '') {
            return '';
        }

        $text = '';

        if (preg_match_all('/stream(?:\r\n|\r|\n)(.*?)(?:\r\n|\r|\n)?endstream/s', $data, $matches)) {
            foreach ($matches[1] as $stream) {
                $decoded = @gzuncompress($stream);
                if ($decoded === false) {
                    $decoded = @gzinflate($stream);
                }
                if ($decoded !== false) {
                    $text .= self::textFromContentStream($decoded);
                }
            }
        }

        return trim($text);
    }

    /**
     * true bila teks kosong ATAU rasio karakter control/binary tinggi (>0.3) —
     * sinyal bahwa extract() gagal membaca PDF (font CID, hasil scan, dll) dan
     * pemanggil sebaiknya fallback ke AI multimodal.
     *
     * Whitespace struktural (spasi/tab/baris baru) DIBUANG dulu sebelum dihitung —
     * extract() menambahkan satu "\n" per token/sel yang berhasil diekstrak, jadi
     * laporan yang rapi tapi sangat tabular (banyak sel pendek) bisa punya proporsi
     * "\n" tinggi walau isinya bersih sama sekali. \n sendiri masuk kategori Unicode
     * Cc (control) — kalau ikut dihitung, rasio jadi ukuran "banyaknya sel", bukan
     * "banyaknya sampah biner", dan PDF bersih-tapi-tabular bisa salah kena flag.
     *
     * Setelah whitespace struktural dibuang, sisa isi dihitung pakai kategori Unicode
     * "C" PENUH (Cc control + Cf format + Co private-use + Cs surrogate + Cn belum
     * ditetapkan) — bukan cuma Cc. Byte CID-font yang berantakan tak selalu jadi Cc;
     * bisa juga decode jadi Private-Use (Co) atau kategori C lain yang tetap UTF-8
     * valid tapi jelas bukan teks asli. Cc saja pernah bikin string murni Private-Use
     * (mis. str_repeat("\u{E000}", 20)) lolos sebagai "terbaca" (rasio 0), padahal
     * jelas sampah.
     */
    public static function looksUnreadable(string $text): bool
    {
        if (trim($text) === '') {
            return true;
        }

        // preg_replace balikin null kalau $text bukan UTF-8 valid — itu sendiri
        // pertanda kuat teksnya berantakan (byte biner/CID font), sama seperti
        // preg_match_all yang balikin false untuk alasan yang sama di bawah.
        $stripped = preg_replace('/[\t\n\r ]+/u', '', $text);
        if ($stripped === null || $stripped === '') {
            return true;
        }

        $junk = preg_match_all('/[^\P{C}]/u', $stripped);
        if ($junk === false) {
            return true;
        }

        return ($junk / strlen($stripped)) > 0.3;
    }

    /** Tarik teks dari satu content stream PDF yang sudah didekompresi. */
    private static function textFromContentStream(string $content): string
    {
        $out = '';

        preg_match_all(
            '/\[((?:[^\[\]\\\\]|\\\\.)*)\]\s*TJ|\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/s',
            $content,
            $tokens,
            PREG_SET_ORDER
        );

        foreach ($tokens as $token) {
            if (($token[1] ?? '') !== '') {
                // [ (a) -120 (b) ... ] TJ — array berisi beberapa string literal.
                if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/s', $token[1], $segments)) {
                    $out .= implode('', array_map([self::class, 'unescape'], $segments[1]));
                }
                $out .= "\n";
            } else {
                // (teks) Tj — satu string literal. token[2] bisa tak ada sama sekali
                // (bukan cuma '') kalau yang match justru cabang `[] TJ` kosong —
                // grup trailing yang tak ikut match dihilangkan PCRE dari hasil.
                $out .= self::unescape($token[2] ?? '')."\n";
            }
        }

        return $out;
    }

    /** Balikkan escape PDF string literal (\( \) \\ \n \r \t) ke karakter aslinya. */
    private static function unescape(string $s): string
    {
        return strtr($s, [
            '\\(' => '(', '\\)' => ')', '\\\\' => '\\',
            '\\n' => "\n", '\\r' => "\r", '\\t' => "\t",
        ]);
    }
}
