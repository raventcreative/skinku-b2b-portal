<?php

namespace App\Support;

/**
 * Ekstraktor teks PDF yang memakai CMap ToUnicode tiap font — tanpa dependency composer. Melengkapi PdfTextExtractor
 * untuk PDF ber-font CID / Identity-H (Canva, Google Docs, Word) yang teksnya berupa kode glyph 2-byte: tiap string di
 * content stream diterjemahkan lewat tabel ToUnicode font yang aktif (operator Tf). Mendukung object stream (/ObjStm),
 * string literal & hex, operator Tj / TJ / ' / ", baris baru dari posisi vertikal sebenarnya (cm / Tm / Td / T*), dan
 * melewati stream gambar / font tertanam (byte JPEG lampiran scan bisa kebetulan memuat "BT … Tj").
 *
 * ponytail: heuristik ringan, bukan parser PDF lengkap — nama font dipetakan global (bentrok antarhalaman jarang di CV),
 * rotasi diabaikan, spasi antar kata dari glyph spasi / jarak TJ besar / blok BT baru di baris yang sama. Upgrade: parser
 * per-halaman bila dibutuhkan dokumen kompleks.
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
        ksort($objek);
        $cmapId = self::cmapPerId($objek);
        $global = self::petaNama(self::semuaKamusFont($objek), $cmapId);   // cadangan bila Resources tak ditemukan
        $teks = [];
        $dibaca = [];

        // Halaman & Form XObject: font dicari dari Resources MASING-MASING — nama /F1 bisa menunjuk font berbeda
        // antarhalaman (PDF Word / multi-font); peta global membuat huruf hilang.
        foreach ($objek as $id => $isi) {
            $kamus = self::kamusObjek($isi);
            $form = (bool) preg_match('/\/Subtype\s*\/Form\b/', $kamus);
            if (! $form && ! preg_match('/\/Type\s*\/Page\b/', $kamus)) {
                continue;
            }
            $kamusFont = self::kamusDalam(self::resources($kamus, $objek) ?? '', 'Font', $objek);
            $peta = $kamusFont === null ? $global : self::petaNama([$kamusFont], $cmapId, true);
            foreach ($form ? [$id] : self::isiHalaman($kamus, $objek) as $sid) {
                $stream = isset($dibaca[$sid]) ? null : self::stream($objek[$sid] ?? '');
                $dibaca[$sid] = true;
                if ($stream !== null && preg_match('/\bBT\b/', $stream)) {
                    $teks[] = self::dariKonten($stream, $peta);
                }
            }
        }
        // Stream konten tanpa induk yang dikenali (struktur tak lazim) → peta font global.
        foreach ($objek as $id => $isi) {
            $stream = isset($dibaca[$id]) || self::bukanKonten($isi) ? null : self::stream($isi);
            if ($stream !== null && preg_match('/\bBT\b/', $stream) && preg_match('/T[jJ]/', $stream)) {
                $teks[] = self::dariKonten($stream, $global);
            }
        }

        $hasil = preg_replace(["/[ \t]+/", "/ *\n */", "/\n{3,}/"], [' ', "\n", "\n\n"], implode("\n", $teks));

        return trim((string) $hasil);
    }

    /** Bagian kamus objek (sebelum kata "stream" bila objek stream). */
    private static function kamusObjek(string $isi): string
    {
        $kamus = strstr($isi, 'stream', true);

        return $kamus === false ? $isi : $kamus;
    }

    /** Resources halaman/form — boleh diwarisi dari node /Parent (pohon /Pages). */
    private static function resources(string $kamus, array $objek): ?string
    {
        for ($naik = 0; $naik < 6; $naik++) {
            if (($res = self::kamusDalam($kamus, 'Resources', $objek)) !== null) {
                return $res;
            }
            if (! preg_match('/\/Parent\s+(\d+)\s+\d+\s+R/', $kamus, $p) || ! isset($objek[(int) $p[1]])) {
                return null;
            }
            $kamus = self::kamusObjek($objek[(int) $p[1]]);
        }

        return null;
    }

    /** "/Kunci N 0 R" → isi objek N; "/Kunci << … >>" → kamus itu (kurung bersarang seimbang). */
    private static function kamusDalam(string $teks, string $kunci, array $objek): ?string
    {
        if (preg_match('/\/'.$kunci.'\s+(\d+)\s+\d+\s+R/', $teks, $m)) {
            return $objek[(int) $m[1]] ?? null;
        }
        if (! preg_match('/\/'.$kunci.'\s*<</', $teks, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $mulai = $m[0][1] + strlen($m[0][0]) - 2;
        $dalam = 0;
        for ($i = $mulai, $n = strlen($teks); $i < $n - 1; $i++) {
            $dua = $teks[$i].$teks[$i + 1];
            if ($dua === '<<') {
                $dalam++;
                $i++;
            } elseif ($dua === '>>') {
                $dalam--;
                $i++;
                if ($dalam === 0) {
                    return substr($teks, $mulai, $i - $mulai + 1);
                }
            }
        }

        return substr($teks, $mulai);
    }

    /** Id stream isi halaman: "/Contents N 0 R", larik "[N 0 R …]", atau objek larik tak langsung. */
    private static function isiHalaman(string $kamus, array $objek): array
    {
        $daftar = preg_match('/\/Contents\s*\[([^\]]*)\]/', $kamus, $m) ? $m[1]
            : (preg_match('/\/Contents\s+(\d+)\s+\d+\s+R/', $kamus, $m) ? $m[1].' 0 R' : '');
        preg_match_all('/(\d+)\s+\d+\s+R/', $daftar, $ref);
        $id = array_map('intval', $ref[1]);
        if (count($id) === 1 && self::stream($objek[$id[0]] ?? '') === null
            && preg_match_all('/(\d+)\s+\d+\s+R/', (string) ($objek[$id[0]] ?? ''), $dalam)) {
            return array_map('intval', $dalam[1]);
        }

        return $id;
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
     * Id objek font ber-ToUnicode → tabelnya: ['lebar' => byte per kode, 'peta' => [kode => teks]].
     *
     * @param  array<int, string>  $objek
     * @return array<int, array{lebar:int, peta:array<int,string>}>
     */
    private static function cmapPerId(array $objek): array
    {
        $perId = [];
        foreach ($objek as $id => $isi) {
            if (preg_match('/\/Type\s*\/Font\b/', $isi) && preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $isi, $t)
                && ($cmap = self::stream($objek[(int) $t[1]] ?? '')) !== null) {
                $perId[$id] = self::bacaCmap($cmap);
                if (! preg_match('/\/Subtype\s*\/Type0\b/', $isi)) {
                    $perId[$id]['lebar'] = 1;   // font sederhana (TrueType/Type1) selalu 1 byte per kode
                }
            }
        }

        return $perId;
    }

    /**
     * Semua kamus font di dokumen: inline "/Font << /F4 79 0 R >>" atau objek terpisah "/Font 56 0 R".
     *
     * @param  array<int, string>  $objek
     * @return array<int, string>
     */
    private static function semuaKamusFont(array $objek): array
    {
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

        return $kamus;
    }

    /**
     * Nama resource font (/F4) → tabel ToUnicode-nya. $denganKosong: font tanpa ToUnicode dicatat null (dibaca Latin-1)
     * supaya tak "meminjam" CMap font lain yang kebetulan bernama sama.
     *
     * @param  array<int, string>  $kamusFont
     * @param  array<int, array{lebar:int, peta:array<int,string>}>  $cmapId
     * @return array<string, array{lebar:int, peta:array<int,string>}|null>
     */
    private static function petaNama(array $kamusFont, array $cmapId, bool $denganKosong = false): array
    {
        $peta = [];
        foreach ($kamusFont as $isi) {
            preg_match_all('/\/([A-Za-z0-9_.+\-]+)\s+(\d+)\s+\d+\s+R/', $isi, $pasang, PREG_SET_ORDER);
            foreach ($pasang as [, $nama, $id]) {
                if (isset($cmapId[(int) $id])) {
                    $peta[$nama] ??= $cmapId[(int) $id];
                } elseif ($denganKosong && ! array_key_exists($nama, $peta)) {
                    $peta[$nama] = null;
                }
            }
        }

        return $peta;
    }

    /** @return array{lebar:int, peta:array<int,string>} */
    private static function bacaCmap(string $cmap): array
    {
        // Lebar kode dari isi tabel (Word menulis codespacerange <0000> <FFFF> walau kodenya 1 byte), baru codespacerange.
        $lebar = preg_match('/begin(?:bfchar|bfrange)\s*<([0-9A-Fa-f]+)>/', $cmap, $c)
            || preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $cmap, $c) ? max(1, intdiv(strlen($c[1]), 2)) : 2;
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

    /**
     * Stream gambar / font tertanam / metadata — byte biner-nya bisa kebetulan memuat "BT" & "Tj" (mis. JPEG lampiran
     * scan) → bukan teks, lewati.
     */
    private static function bukanKonten(string $isi): bool
    {
        $kamus = strstr($isi, 'stream', true);

        return (bool) preg_match('/\/Subtype\s*\/(?:Image|XML|Type1C|CIDFontType0C|OpenType)\b|\/Length[123]\b'
            .'|\/(?:DCT|JPX|CCITTFax|JBIG2)Decode\b|\/Type\s*\/(?:Metadata|XRef|ObjStm|EmbeddedFile)\b/', $kamus === false ? $isi : $kamus);
    }

    /**
     * Content stream → teks. Baris baru dari posisi vertikal sebenarnya (CTM `cm` + matriks teks `Tm`/`Td`, `q`/`Q`):
     * teks segaris digabung — tanpa spasi bila masih di blok BT yang sama (Canva menaruh tiap huruf dengan Td), dengan
     * spasi bila blok BT baru (pembuat PDF yang menulis tiap kata di blok sendiri).
     *
     * @param  array<string, array{lebar:int, peta:array<int,string>}>  $cmap
     */
    private static function dariKonten(string $konten, array $cmap): string
    {
        $konten = (string) preg_replace('/\bBI\b.*?\bEI\b/s', ' ', $konten);   // gambar inline
        $n = '[-+]?(?:\d+\.?\d*|\.\d+)';
        $pola = '/\/([A-Za-z0-9_.+\-]+)\s+'.$n.'\s+Tf'                                     // 1 font aktif
            .'|\[((?:\((?:[^()\\\\]|\\\\.)*\)|<[0-9A-Fa-f\s]*>|[^\]])*)\]\s*TJ'              // 2 larik TJ
            .'|\(((?:[^()\\\\]|\\\\.)*)\)\s*(Tj|\'|")'                                       // 3 literal, 4 operator
            .'|<([0-9A-Fa-f\s]*)>\s*Tj'                                                       // 5 hex Tj
            .'|'.$n.'\s+('.$n.')\s+T[dD]\b'                                                   // 6 Td (geser y)
            .'|'.$n.'\s+'.$n.'\s+'.$n.'\s+('.$n.')\s+'.$n.'\s+('.$n.')\s+(Tm|cm)\b'         // 7 d, 8 f, 9 operator
            .'|\b(BT|T\*|q|Q)\b/s';                                                           // 10 penanda
        if (! preg_match_all($pola, $konten, $token, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
            return '';
        }

        $font = null;
        $out = '';
        $sy = 1.0;          // CTM sumbu y: y mutlak = $sy × y lokal + $ty (rotasi diabaikan)
        $ty = 0.0;
        $tumpuk = [];
        $garisY = 0.0;      // y matriks garis teks
        $yAkhir = null;     // y teks terakhir yang ditulis
        $blokBaru = true;
        $barisBaru = false;
        foreach ($token as $t) {
            $tanda = $t[10] ?? null;
            if ($t[1] !== null) {
                $font = $cmap[$t[1]] ?? null;
            } elseif ($tanda !== null) {
                if ($tanda === 'BT') {
                    [$garisY, $blokBaru] = [0.0, true];
                } elseif ($tanda === 'T*') {
                    $barisBaru = true;
                } elseif ($tanda === 'q') {
                    $tumpuk[] = [$sy, $ty];
                } else {
                    [$sy, $ty] = array_pop($tumpuk) ?? [1.0, 0.0];
                }
            } elseif ($t[6] !== null) {
                $garisY += (float) $t[6];
            } elseif ($t[9] !== null) {
                if ($t[9] === 'Tm') {
                    $garisY = (float) $t[8];
                } else {
                    $ty += $sy * (float) $t[8];
                    $sy *= (float) $t[7] != 0.0 ? (float) $t[7] : 1.0;
                }
            } else {
                $teks = $t[2] !== null ? self::larikTJ($t[2], $font) : self::terjemah($t[3] !== null ? self::literal($t[3]) : self::hex((string) $t[5]), $font);
                $y = $sy * $garisY + $ty;
                if ($yAkhir !== null && ($barisBaru || in_array($t[4] ?? '', ["'", '"'], true) || abs($y - $yAkhir) > 1.0)) {
                    $out .= "\n";
                } elseif ($yAkhir !== null && $blokBaru && ! preg_match('/\s$/u', $out) && ! preg_match('/^\s/u', $teks)) {
                    $out .= ' ';
                }
                $out .= $teks;
                [$yAkhir, $blokBaru, $barisBaru] = [$y, false, false];
            }
        }

        return $out;
    }

    /** Isi larik TJ: string literal/hex diterjemahkan, jarak besar (< −250) = spasi antar kata. */
    private static function larikTJ(string $larik, ?array $font): string
    {
        preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)|<([0-9A-Fa-f\s]*)>|(-?[\d.]+)/s', $larik, $isi, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $out = '';
        foreach ($isi as $i) {
            if ($i[3] !== null) {
                $out .= (float) $i[3] < -250 ? ' ' : '';
            } else {
                $out .= self::terjemah($i[1] !== null ? self::literal($i[1]) : self::hex((string) $i[2]), $font);
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
