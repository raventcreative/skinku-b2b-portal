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
     * true bila teks kosong ATAU rasio karakter non-printable/control tinggi (>0.3) —
     * sinyal bahwa extract() gagal membaca PDF (font CID, hasil scan, dll) dan
     * pemanggil sebaiknya fallback ke AI multimodal.
     */
    public static function looksUnreadable(string $text): bool
    {
        if (trim($text) === '') {
            return true;
        }

        $nonPrintable = preg_match_all('/[^\P{C}]/u', $text);

        // preg_match_all mengembalikan false kalau $text bukan UTF-8 valid —
        // itu sendiri pertanda kuat teksnya berantakan (byte biner/CID font).
        if ($nonPrintable === false) {
            return true;
        }

        return ($nonPrintable / strlen($text)) > 0.3;
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
                // (teks) Tj — satu string literal.
                $out .= self::unescape($token[2])."\n";
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
