<?php

namespace App\Support;

/**
 * Ekstraktor teks PDF yang memakai CMap ToUnicode tiap font — tanpa dependency composer. Melengkapi PdfTextExtractor
 * untuk PDF ber-font CID / Identity-H (Canva, Google Docs, Word) yang teksnya berupa kode glyph 2-byte: tiap string di
 * content stream diterjemahkan lewat tabel ToUnicode font yang aktif (operator Tf). Mendukung object stream (/ObjStm),
 * string literal & hex, operator Tj / TJ / ' / ", dan baris baru dari BT / Tm / T* / Td-vertikal.
 *
 * ponytail: heuristik ringan, bukan parser PDF lengkap — nama font dipetakan global (bentrok antarhalaman jarang di CV),
 * spasi antar kata hanya dari glyph spasi / jarak TJ besar. Upgrade: parser per-halaman bila dibutuhkan dokumen kompleks.
 */
final class PdfUnicodeText
{
    public static function extract(string $path): string
    {
        $data = @file_get_contents($path);
        if ($data === false || $data === '') {
            return '';
        }

        $objek = self::objek($data);
        $cmap = self::cmapPerFont($objek);
        $teks = [];
        foreach ($objek as $isi) {
            $stream = self::stream($isi);
            if ($stream !== null && preg_match('/\bBT\b/', $stream) && preg_match('/T[jJ]/', $stream)) {
                $teks[] = self::dariKonten($stream, $cmap);
            }
        }

        $hasil = preg_replace(["/[ \t]+/", "/ *\n */", "/\n{3,}/"], [' ', "\n", "\n\n"], implode("\n", $teks));

        return trim((string) $hasil);
    }

    /**
     * Semua objek "N G obj … endobj" + isi object stream (/ObjStm) → [id => isi mentah].
     *
     * @return array<int, string>
     */
    private static function objek(string $data): array
    {
        $objek = [];
        if (preg_match_all('/(\d+)\s+\d+\s+obj\b(.*?)endobj/s', $data, $m, PREG_SET_ORDER)) {
            foreach ($m as $o) {
                $objek[(int) $o[1]] = $o[2];
            }
        }
        foreach ($objek as $isi) {
            if (! preg_match('/\/Type\s*\/ObjStm/', $isi) || ! preg_match('/\/First\s+(\d+)/', $isi, $first)) {
                continue;
            }
            $stream = self::stream($isi);
            if ($stream === null) {
                continue;
            }
            preg_match_all('/(\d+)\s+(\d+)/', substr($stream, 0, (int) $first[1]), $pasang, PREG_SET_ORDER);
            foreach ($pasang as $i => [, $id, $offset]) {
                $akhir = isset($pasang[$i + 1]) ? (int) $pasang[$i + 1][2] : strlen($stream) - (int) $first[1];
                $objek[(int) $id] ??= substr($stream, (int) $first[1] + (int) $offset, $akhir - (int) $offset);
            }
        }

        return $objek;
    }

    /** Isi stream (didekompres bila FlateDecode), null bila objek bukan stream / tak bisa dibaca. */
    private static function stream(string $isi): ?string
    {
        if (! preg_match('/stream(?:\r\n|\r|\n)(.*?)(?:\r\n|\r|\n)?endstream/s', $isi, $s)) {
            return null;
        }
        if (! str_contains($isi, 'FlateDecode')) {
            return $s[1];
        }
        $hasil = @gzuncompress($s[1]);
        if ($hasil === false) {
            $hasil = @gzinflate($s[1]);
        }

        return $hasil === false ? null : $hasil;
    }

    /**
     * Nama resource font (/F4) → tabel ToUnicode-nya: ['lebar' => byte per kode, 'peta' => [kode => teks]].
     *
     * @param  array<int, string>  $objek
     * @return array<string, array{lebar:int, peta:array<int,string>}>
     */
    private static function cmapPerFont(array $objek): array
    {
        $perId = [];
        foreach ($objek as $id => $isi) {
            if (preg_match('/\/Type\s*\/Font\b/', $isi) && preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $isi, $t)
                && ($cmap = self::stream($objek[(int) $t[1]] ?? '')) !== null) {
                $perId[$id] = self::bacaCmap($cmap);
            }
        }

        // Kamus font: inline "/Font << /F4 79 0 R >>" atau objek terpisah "/Font 56 0 R".
        $kamus = [];
        foreach ($objek as $isi) {
            if (preg_match_all('/\/Font\s*<<(.*?)>>/s', $isi, $inline)) {
                array_push($kamus, ...$inline[1]);
            }
            if (preg_match_all('/\/Font\s+(\d+)\s+\d+\s+R/', $isi, $ref)) {
                foreach ($ref[1] as $id) {
                    $kamus[] = (string) ($objek[(int) $id] ?? '');
                }
            }
        }
        $perNama = [];
        foreach ($kamus as $isi) {
            preg_match_all('/\/([A-Za-z0-9_.+\-]+)\s+(\d+)\s+\d+\s+R/', $isi, $pasang, PREG_SET_ORDER);
            foreach ($pasang as [, $nama, $id]) {
                if (isset($perId[(int) $id])) {
                    $perNama[$nama] ??= $perId[(int) $id];
                }
            }
        }

        return $perNama;
    }

    /** @return array{lebar:int, peta:array<int,string>} */
    private static function bacaCmap(string $cmap): array
    {
        $lebar = preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $cmap, $c) ? max(1, intdiv(strlen($c[1]), 2)) : 2;
        $peta = [];
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blok)) {
            foreach ($blok[1] as $b) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $b, $p, PREG_SET_ORDER);
                foreach ($p as [, $src, $dst]) {
                    $peta[hexdec($src)] = self::utf16($dst);
                }
            }
        }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blok)) {
            foreach ($blok[1] as $b) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])/', $b, $p, PREG_SET_ORDER);
                foreach ($p as [, $lo, $hi, $dst]) {
                    $lo = hexdec($lo);
                    $hi = min(hexdec($hi), $lo + 65535);
                    if ($dst[0] === '[') {
                        preg_match_all('/<([0-9A-Fa-f]*)>/', $dst, $d);
                        foreach ($d[1] as $i => $h) {
                            $peta[$lo + $i] = self::utf16($h);
                        }

                        continue;
                    }
                    $awal = hexdec(trim($dst, '<>'));
                    for ($kode = $lo; $kode <= $hi; $kode++) {
                        $peta[$kode] = (string) mb_chr($awal + $kode - $lo, 'UTF-8');
                    }
                }
            }
        }

        return ['lebar' => $lebar, 'peta' => $peta];
    }

    private static function utf16(string $hex): string
    {
        if (strlen($hex) % 4 !== 0) {
            $hex = str_pad($hex, (int) ceil(strlen($hex) / 4) * 4, '0', STR_PAD_LEFT);
        }

        return $hex === '' ? '' : (string) mb_convert_encoding((string) hex2bin($hex), 'UTF-8', 'UTF-16BE');
    }

    /** @param  array<string, array{lebar:int, peta:array<int,string>}>  $cmap */
    private static function dariKonten(string $konten, array $cmap): string
    {
        $pola = '/\/([A-Za-z0-9_.+\-]+)\s+[-\d.]+\s+Tf'                                  // 1 font aktif
            .'|\[((?:\((?:[^()\\\\]|\\\\.)*\)|<[0-9A-Fa-f\s]*>|[^\]])*)\]\s*TJ'          // 2 larik TJ
            .'|\(((?:[^()\\\\]|\\\\.)*)\)\s*(?:Tj|\'|")'                                 // 3 literal Tj
            .'|<([0-9A-Fa-f\s]*)>\s*Tj'                                                   // 4 hex Tj
            .'|[-\d.]+\s+([-\d.]+)\s+T[dD]\b'                                             // 5 geser (y)
            .'|\b(BT|Tm|T\*)\b/s';                                                        // 6 baris baru
        if (! preg_match_all($pola, $konten, $token, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
            return '';
        }

        $font = null;
        $out = '';
        foreach ($token as $t) {
            if ($t[1] !== null) {
                $font = $cmap[$t[1]] ?? null;
            } elseif ($t[2] !== null) {
                preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)|<([0-9A-Fa-f\s]*)>|(-?[\d.]+)/s', $t[2], $isi, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
                foreach ($isi as $i) {
                    if ($i[3] !== null) {
                        $out .= (float) $i[3] < -250 ? ' ' : '';   // jarak besar di TJ = spasi antar kata
                    } else {
                        $out .= self::terjemah($i[1] !== null ? self::literal($i[1]) : self::hex((string) $i[2]), $font);
                    }
                }
            } elseif ($t[3] !== null) {
                $out .= self::terjemah(self::literal($t[3]), $font);
            } elseif ($t[4] !== null) {
                $out .= self::terjemah(self::hex($t[4]), $font);
            } elseif (($t[5] !== null && (float) $t[5] != 0.0) || $t[6] !== null) {
                $out .= $out === '' || str_ends_with($out, "\n") ? '' : "\n";   // satu baris baru per perpindahan baris
            }
        }

        return $out;
    }

    /** Byte string → teks: lewat ToUnicode font aktif bila ada, selain itu Latin-1 (font sederhana). */
    private static function terjemah(string $bytes, ?array $font): string
    {
        if ($font === null) {
            return (string) mb_convert_encoding((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $bytes), 'UTF-8', 'ISO-8859-1');
        }
        $out = '';
        foreach (str_split($bytes, $font['lebar']) as $potong) {
            $out .= $font['peta'][hexdec(bin2hex($potong))] ?? '';
        }

        return $out;
    }

    private static function hex(string $hex): string
    {
        $hex = (string) preg_replace('/\s+/', '', $hex);

        return (string) hex2bin(strlen($hex) % 2 ? $hex.'0' : $hex);
    }

    /** Balikkan escape string literal PDF (\n \r \t \b \f \( \) \\ \ddd, sambung baris). */
    private static function literal(string $s): string
    {
        return (string) preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3}|\r\n|\r|\n)/', function (array $m): string {
            return match ($m[1]) {
                'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C",
                '(' => '(', ')' => ')', '\\' => '\\',
                "\r\n", "\r", "\n" => '',
                default => chr(octdec($m[1]) & 0xFF),
            };
        }, $s);
    }
}
