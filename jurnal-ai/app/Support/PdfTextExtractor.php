<?php

namespace App\Support;

/**
 * Ekstraktor teks PDF murni PHP — tanpa dependency composer. Dekompresi stream
 * FlateDecode pakai zlib bawaan, lalu tarik teks dari operator PDF `(…)Tj` dan
 * `[…]TJ`.
 *
 * PDF "bersih" (teks asli, bukan hasil scan) terekstrak rapi dan hemat token:
 * tidak perlu kirim gambar ke model. PDF hasil scan atau ber-font CID custom
 * menghasilkan teks kosong/berantakan — looksUnreadable() mendeteksinya sebagai
 * sinyal untuk fallback ke pembacaan multimodal.
 */
class PdfTextExtractor
{
    public static function fromBytes(string $data): string
    {
        if ($data === '') {
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
     * true bila teks kosong ATAU porsi karakter non-teks tinggi — sinyal bahwa
     * ekstraksi gagal (PDF scan / font CID) dan pemanggil sebaiknya pakai AI
     * multimodal.
     *
     * Whitespace struktural dibuang dulu: ekstraktor menambah "\n" per token,
     * jadi PDF tabular yang bersih bisa punya porsi "\n" tinggi tanpa jadi sampah.
     */
    public static function looksUnreadable(string $text): bool
    {
        $text = trim($text);
        if (mb_strlen($text) < 20) {
            return true;
        }

        $body = preg_replace('/[\s]+/u', '', $text) ?? '';
        if ($body === '') {
            return true;
        }

        $junk = preg_match_all('/\p{C}/u', $body) ?: 0;

        return ($junk / max(mb_strlen($body), 1)) > 0.3;
    }

    private static function textFromContentStream(string $stream): string
    {
        $out = '';

        // [ (a) -200 (b) ] TJ  — array string + kerning
        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $stream, $arrays)) {
            foreach ($arrays[1] as $array) {
                if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)/s', $array, $chunks)) {
                    $out .= implode('', array_map(self::unescape(...), $chunks[1]))."\n";
                }
            }
        }

        // (teks) Tj — string tunggal
        if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)\s*Tj/s', $stream, $singles)) {
            foreach ($singles[1] as $chunk) {
                $out .= self::unescape($chunk)."\n";
            }
        }

        return $out;
    }

    /** Lepas escape PDF: \( \) \\ \n \r \t dan oktal \053. */
    private static function unescape(string $s): string
    {
        $s = preg_replace_callback('/\\\\([0-7]{1,3})/', fn ($m) => chr(octdec($m[1])), $s) ?? $s;

        return str_replace(
            ['\\(', '\\)', '\\\\', '\\n', '\\r', '\\t'],
            ['(', ')', '\\', "\n", "\r", "\t"],
            $s,
        );
    }
}
