<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Penulis .xlsx mini TANPA dependency — file xlsx hanyalah zip berisi XML, dan
 * ZipArchive bawaan PHP cukup. Sengaja tidak memakai phpspreadsheet/maatwebsite:
 * deploy produksi cuma `git pull` (tanpa composer install), dan menambah
 * dependency berarti mengubah alur deploy demi fitur yang bisa ditulis 150 baris.
 *
 * Dukungan: multi-sheet, string & angka (angka disimpan numerik — bisa langsung
 * di-SUM di Excel), tanpa styling. Untuk laporan, itu semua yang dibutuhkan.
 */
class XlsxWriter
{
    /**
     * @param  array<string, array{headers: array<int, string>, rows: iterable<int, array<int, mixed>>}>  $sheets
     */
    public static function download(string $filename, array $sheets): BinaryFileResponse
    {
        $path = self::write($sheets);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** @return string path file sementara berisi xlsx yang valid */
    public static function write(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Gagal membuat file xlsx sementara.');
        }

        $n = count($sheets);

        $overrides = '';
        for ($i = 1; $i <= $n; $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$overrides.'</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');

        $sheetTags = '';
        $relTags = '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $i = 0;
        foreach (array_keys($sheets) as $name) {
            $i++;
            $sheetTags .= '<sheet name="'.self::esc(self::sheetName($name, $i)).'" sheetId="'.$i.'" r:id="rIdS'.$i.'"/>';
            $relTags .= '<Relationship Id="rIdS'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheetTags.'</sheets></workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relTags.'</Relationships>');

        // styles minimal — beberapa pembaca xlsx menuntut part ini ada.
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="1"><border/></borders>'
            .'<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="1"><xf/></cellXfs></styleSheet>');

        $i = 0;
        foreach ($sheets as $sheet) {
            $i++;
            $zip->addFromString('xl/worksheets/sheet'.$i.'.xml', self::sheetXml($sheet));
        }

        $zip->close();

        return $path;
    }

    /** @param array{headers: array, rows: iterable} $sheet */
    private static function sheetXml(array $sheet): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        $r = 1;
        $xml .= self::rowXml($r++, $sheet['headers']);
        foreach ($sheet['rows'] as $row) {
            $xml .= self::rowXml($r++, $row);
        }

        return $xml.'</sheetData></worksheet>';
    }

    private static function rowXml(int $r, array $cells): string
    {
        $xml = '<row r="'.$r.'">';
        $c = 0;
        foreach ($cells as $value) {
            $ref = self::col($c++).$r;
            if ($value === null || $value === '') {
                continue;   // sel kosong dilewati saja
            }
            if (is_int($value) || is_float($value)) {
                // Angka NUMERIK asli — bisa langsung di-SUM/filter di Excel,
                // bukan teks berformat titik-ribuan yang mati.
                $xml .= '<c r="'.$ref.'" t="n"><v>'.$value.'</v></c>';
            } else {
                $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.self::esc((string) $value).'</t></is></c>';
            }
        }

        return $xml.'</row>';
    }

    /** 0 → A, 25 → Z, 26 → AA … */
    private static function col(int $i): string
    {
        $s = '';
        while ($i >= 0) {
            $s = chr(65 + ($i % 26)).$s;
            $i = intdiv($i, 26) - 1;
        }

        return $s;
    }

    /** Nama sheet: maks 31 char, tanpa karakter terlarang Excel. */
    private static function sheetName(string $name, int $i): string
    {
        $name = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name));

        return mb_substr($name !== '' ? $name : 'Sheet'.$i, 0, 31);
    }

    private static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
