<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Pembaca .xlsx & .csv mini TANPA dependency — pelengkap XlsxWriter (yang cuma
 * menulis). xlsx hanyalah zip berisi XML; ZipArchive + SimpleXML bawaan PHP
 * cukup. Sengaja tak memakai phpspreadsheet: deploy produksi cuma `git pull`.
 *
 * Menangani yang bikin file Excel asli rewel:
 *   - sharedStrings (yang ditulis Excel saat menyimpan ulang) DAN inlineStr
 *     (yang ditulis XlsxWriter),
 *   - sel kosong yang dilewati (sparse) — dinormalkan ke posisi kolomnya,
 *   - CSV dengan pemisah ',' atau ';' (locale ID sering ';') + BOM.
 *
 * Kembalian: array baris, tiap baris = array sel string ter-index 0..maxCol.
 * Baris 0 lazim jadi header — pemetaan kolom diserahkan ke pemanggil.
 */
class SpreadsheetReader
{
    /** @return array<int, array<int, string>> */
    public static function rows(string $path, ?string $ext = null): array
    {
        $ext = strtolower($ext ?? pathinfo($path, PATHINFO_EXTENSION));

        return $ext === 'csv' ? self::csvRows($path) : self::xlsxRows($path);
    }

    /* ---------------- CSV ---------------- */

    /** @return array<int, array<int, string>> */
    private static function csvRows(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Tak bisa membaca file CSV.');
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);   // buang BOM UTF-8

        // Deteksi pemisah dari baris pertama: ';' (locale ID) vs ',' vs tab.
        $firstLine = strtok($raw, "\r\n") ?: '';
        $delim = ',';
        foreach ([';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")] as $d => $n) {
            if ($n > substr_count($firstLine, $delim)) {
                $delim = $d;
            }
        }

        $rows = [];
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        while (($data = fgetcsv($fh, 0, $delim)) !== false) {
            if ($data === [null]) {
                continue;   // baris kosong
            }
            $rows[] = array_map(fn ($v) => trim((string) ($v ?? '')), $data);
        }
        fclose($fh);

        return $rows;
    }

    /* ---------------- XLSX ---------------- */

    /** @return array<int, array<int, string>> */
    private static function xlsxRows(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Tak bisa membuka file .xlsx (rusak / bukan xlsx?).');
        }

        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $shared = self::parseSharedStrings($ss);
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException('Sheet pertama tak ditemukan dalam file .xlsx.');
        }

        return self::parseSheet($sheetXml, $shared);
    }

    /** @return array<int, string> */
    private static function parseSharedStrings(string $xml): array
    {
        $doc = @simplexml_load_string(self::stripNs($xml));
        if ($doc === false) {
            return [];
        }

        $out = [];
        foreach ($doc->si as $si) {
            if (isset($si->t)) {
                $out[] = (string) $si->t;                 // <si><t>..</t></si>
            } else {
                $s = '';                                   // rich text: <si><r><t>..</t></r>..
                foreach ($si->r as $r) {
                    $s .= (string) $r->t;
                }
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $shared
     * @return array<int, array<int, string>>
     */
    private static function parseSheet(string $xml, array $shared): array
    {
        $doc = @simplexml_load_string(self::stripNs($xml));
        if ($doc === false || ! isset($doc->sheetData)) {
            return [];
        }

        $rows = [];
        foreach ($doc->sheetData->row as $row) {
            $cells = [];
            $maxCol = -1;
            foreach ($row->c as $c) {
                $col = self::colIndex((string) $c['r']);
                $type = (string) ($c['t'] ?? '');
                $val = match ($type) {
                    's' => $shared[(int) $c->v] ?? '',           // shared string
                    'inlineStr' => isset($c->is->t) ? (string) $c->is->t : '',
                    'str' => (string) $c->v,                      // string hasil formula
                    default => isset($c->v) ? (string) $c->v : '', // angka/boolean
                };
                $cells[$col] = trim($val);
                $maxCol = max($maxCol, $col);
            }

            $norm = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $norm[$i] = $cells[$i] ?? '';
            }
            $rows[] = $norm;
        }

        return $rows;
    }

    /** "B5" → 1, "AA1" → 26. */
    private static function colIndex(string $ref): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
        $n = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }

        return $n - 1;   // A → 0
    }

    /** Buang namespace default agar akses SimpleXML tak perlu prefix. */
    private static function stripNs(string $xml): string
    {
        return preg_replace('/\sxmlns="[^"]*"/', '', $xml, 1) ?? $xml;
    }
}
