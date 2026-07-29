<?php

namespace App\Services;

use App\Models\Product;
use App\Support\SpreadsheetReader;

/**
 * Laporan Income TikTok (Fase 1, jalur UPLOAD) — migrasi report "Tiktok income"
 * dari bot n8n. Gabungkan "Semua pesanan" (.csv: Order ID + Seller SKU + qty)
 * dengan "income" (.xlsx sheet-1: ID Pesanan + settlement/fee) by Order ID, lalu
 * kelompokkan qty per ITEM-BESAR (kategori produk, via TikTokOrderService::resolve
 * yang dukung bundle "1 SKU = N pcs"). Report-only — TIDAK menyentuh stok.
 * Lihat TIKTOK_INCOME_SPEC.md.
 */
class TikTokIncomeReportService
{
    // Indeks kolom file "Semua pesanan" (.csv, 65 kolom).
    private const C_ORDER_ID = 0;

    private const C_SKU_ID = 5;

    private const C_SELLER_SKU = 6;

    private const C_QTY = 9;

    // Indeks kolom file "income" (.xlsx sheet "Detail pesanan").
    private const I_ORDER_ID = 0;

    private const I_TYPE = 1;

    private const I_TIME = 3;   // Waktu pembayaran pesanan

    private const I_SETTLEMENT = 5;

    private const I_REVENUE = 6;

    private const I_FEE = 14;

    public function __construct(private TikTokOrderService $orders) {}

    /** Baca 2 file (csv + xlsx) lalu bangun laporan. */
    public function fromFiles(string $ordersCsvPath, string $incomeXlsxPath): array
    {
        return $this->build(
            SpreadsheetReader::rows($ordersCsvPath, 'csv'),
            SpreadsheetReader::rows($incomeXlsxPath, 'xlsx'),
        );
    }

    /**
     * Inti (bisa diuji dengan array). Baris 0 = header di kedua sumber.
     *
     * @param  array<int,array<int,string>>  $orderRows  "Semua pesanan"
     * @param  array<int,array<int,string>>  $incomeRows  "income" (Detail pesanan)
     * @return array{summary:array,columns:array<int,string>,rows:array,unmapped:array<int,string>}
     */
    public function build(array $orderRows, array $incomeRows): array
    {
        // 1) Kumpulkan baris pesanan + peta SKU ID → Seller SKU (buat auto-isi kosong).
        $raw = [];
        $skuIdSellers = [];
        foreach (array_slice($orderRows, 1) as $r) {
            $oid = trim($r[self::C_ORDER_ID] ?? '');
            if ($oid === '') {
                continue;
            }
            $sellerSku = trim($r[self::C_SELLER_SKU] ?? '');
            $skuId = trim($r[self::C_SKU_ID] ?? '');
            $raw[] = [$oid, $sellerSku, $skuId, max(0, (int) ($r[self::C_QTY] ?? 0))];
            if ($sellerSku !== '' && $skuId !== '') {
                $skuIdSellers[$skuId][$sellerSku] = true;
            }
        }
        $csvRead = count($raw);

        // Seller SKU efektif: kalau KOSONG, isi dari SKU ID yang sama HANYA bila SKU ID
        // itu punya TEPAT SATU Seller SKU (tak ambigu). Kalau ambigu / tak ada bukti →
        // pakai nomor SKU ID mentah (jadi "belum dikenal", bukan salah tebak barang).
        $orderSkus = [];
        $backfilled = 0;
        foreach ($raw as [$oid, $sellerSku, $skuId, $qty]) {
            $sku = $sellerSku;
            if ($sku === '') {
                $cand = array_keys($skuIdSellers[$skuId] ?? []);
                if (count($cand) === 1) {
                    $sku = $cand[0];
                    $backfilled++;
                } else {
                    $sku = $skuId;
                }
            }
            $orderSkus[$oid][] = ['sku' => $sku, 'qty' => $qty];
        }

        // 2) Resolve tiap SKU unik → komponen (kategori × qty per 1 unit SKU). Deteksi SKU tak dikenal.
        $skuComp = [];
        $unmapped = [];
        $seen = [];
        foreach ($orderSkus as $lines) {
            foreach ($lines as $l) {
                $sku = $l['sku'];
                if ($sku === '' || isset($seen[$sku])) {
                    continue;
                }
                $seen[$sku] = true;
                $comps = $this->orders->resolve($sku);
                if (! $comps) {
                    $unmapped[] = $sku;

                    continue;
                }
                $skuComp[$sku] = array_map(fn ($c) => ['cat' => $this->categoryOf($c['product']), 'qty' => (int) $c['qty']], $comps);
            }
        }

        // 3) Iterasi order di file income → gabung → qty per item-besar.
        $columns = [];
        $rows = [];
        $incomeOrders = 0;
        $matched = 0;
        foreach (array_slice($incomeRows, 1) as $r) {
            $oid = trim($r[self::I_ORDER_ID] ?? '');
            if ($oid === '') {
                continue;
            }
            $incomeOrders++;
            $lines = $orderSkus[$oid] ?? null;
            $catQty = [];
            if ($lines) {
                $matched++;
                foreach ($lines as $l) {
                    foreach ($skuComp[$l['sku']] ?? [] as $comp) {
                        $catQty[$comp['cat']] = ($catQty[$comp['cat']] ?? 0) + $comp['qty'] * $l['qty'];
                        $columns[$comp['cat']] = true;
                    }
                }
            }
            $rows[] = [
                'order_id' => $oid,
                'type' => trim($r[self::I_TYPE] ?? ''),
                'time' => trim($r[self::I_TIME] ?? ''),
                'settlement' => $this->num($r[self::I_SETTLEMENT] ?? 0),
                'revenue' => $this->num($r[self::I_REVENUE] ?? 0),
                'fee' => $this->num($r[self::I_FEE] ?? 0),
                'matched' => (bool) $lines,
                'cat_qty' => $catQty,
            ];
        }

        ksort($columns);

        return [
            'summary' => [
                'csv_read' => $csvRead,
                'unique_orders' => count($orderSkus),
                'income_orders' => $incomeOrders,
                'matched' => $matched,
                'unmatched' => $incomeOrders - $matched,
                'unmapped_count' => count(array_unique($unmapped)),
                'backfilled' => $backfilled,
            ],
            'columns' => array_keys($columns),
            'rows' => $rows,
            'unmapped' => array_values(array_unique($unmapped)),
        ];
    }

    private function categoryOf(Product $p): string
    {
        return filled($p->category) ? $p->category : 'Lainnya';
    }

    private function num(mixed $v): float
    {
        $v = trim((string) $v);

        return is_numeric($v) ? (float) $v : 0.0;
    }
}
