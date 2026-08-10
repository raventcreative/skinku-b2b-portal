<?php

namespace App\Services\ReportBot;

/**
 * Task 11: port VERBATIM dari 2 node n8n (sumber:
 * scratchpad/n8n_income_nodes.txt, 378 baris) — "Cache Order CSV"
 * (parseOrderCsv) + "Code Parse income" (build). Dipakai flow bot TikTok
 * Income (Task 12) SEBAGAI GANTI `App\Services\TikTokIncomeReportService`
 * lama (form web `/tiktok/income`) — JANGAN reuse itu: kategorisasinya lewat
 * `TikTokOrderService`/kolom DB `Product::category`, bukan SKU_MAP n8n yang
 * sudah dibenerin manual per item. Web `/tiktok/income` dibiarkan apa adanya;
 * upgrade web di luar cakupan task ini.
 *
 * Method PURE: array in -> array out. Tidak baca file/DB — Task 12 yang
 * bertanggung jawab: (1) baca CSV Order via file_get_contents() lalu panggil
 * parseOrderCsv(), (2) baca xlsx Income via SpreadsheetReader::rows() DAN
 * petakan baris grid (header di baris-0) jadi array asosiatif header=>value
 * (PERSIS bentuk `item.json` n8n) sebelum dioper ke build().
 *
 * ================================================================
 * CATATAN PENTING — 2 SKU_MAP BERBEDA DI SUMBER N8N (baca sebelum ubah SKU_MAP)
 * ================================================================
 * n8n_income_nodes.txt memuat SKU_MAP di KEDUA node, dan isinya TIDAK SAMA:
 *   - Node "Cache Order CSV" (baris ~281-313): 29 entri. Ini yang OPERATIF —
 *     dipakai SAAT ITU JUGA (`SKU_MAP[sku]`) utk membangun index kategori yg
 *     disimpan & dipakai node lain lewat JOIN by Order ID.
 *   - Node "Code Parse income" (baris ~30-63): 25 entri, DIDEFINISIKAN tapi
 *     TAK PERNAH DIPAKAI LAGI di node itu (node itu hanya baca index yg SUDAH
 *     JADI lewat `loadOrderIndex()`/`$getWorkflowStaticData`) — kode mati,
 *     sisa refactor. Versinya lebih usang: tak punya 4 SKU baru (Mouth Spray,
 *     ASA Glow Day Cream, bundling 3pcs REINA UnderArm, Complete Day & Night
 *     Care), dan utk SKU "1736405151798560682" masih menandai
 *     "SEMENTARA -> Serum wajah" (lihat komentar sumbernya) padahal versi
 *     operatif SUDAH mengoreksinya -> "Face Mist" (cocok nama produknya
 *     sendiri: "HANA Glow Face Mist").
 * Keputusan: SKU_MAP di bawah = VERBATIM node "Cache Order CSV" (yang
 * operatif), BUKAN gabungan/rata-rata keduanya. Diverifikasi otomatis
 * (extract_verify.php, dijalankan sekali saat porting) — bukan transkripsi
 * manual by-eye. Lihat test_sku_map_matches_operative_n8n_source_verbatim di
 * TikTokIncomeN8nServiceTest utk kuncian per-entri.
 *
 * ================================================================
 * CATATAN DESAIN — bentuk return parseOrderCsv() BEDA dari representasi
 * ANTARA n8n (bukan dari logika bisnisnya)
 * ================================================================
 * n8n memisahkan pekerjaan ini jadi 2 LANGKAH lintas-EKSEKUSI (constraint
 * n8n: node "Cache Order CSV" jalan saat user kirim CSV, node "Code Parse
 * income" jalan BELAKANGAN saat user kirim xlsx; keduanya cuma bisa "ngobrol"
 * lewat `$getWorkflowStaticData`, yang makanya index disimpan RINGKAS sbg
 * array posisional `{ orderId: [10 angka] }`, lalu `loadOrderIndex()` di
 * "Code Parse income" baru mengubahnya jadi object per-kategori
 * `{ orderId: { "Sabun": n, ... } }` sebelum dipakai JOIN). PHP tidak punya
 * constraint itu (kedua method static, dipanggil berurutan dlm 1 request oleh
 * Task 12) — jadi parseOrderCsv() DI SINI langsung mengembalikan bentuk
 * OBJECT PER-KATEGORI (hasil AKHIR loadOrderIndex(), bukan representasi
 * antara array-posisional-nya) supaya build() bisa pakai LANGSUNG tanpa
 * transform tambahan. TIDAK ADA logika bisnis yang diubah oleh keputusan ini
 * — cuma melewati satu representasi-antara yang murni artefak penyimpanan
 * n8n.
 */
class TikTokIncomeN8nService
{
    /**
     * PERSIS union `PRODUCT_CATEGORIES` (node "Code Parse income") & `CATS`
     * (node "Cache Order CSV") — di sumber n8n ada 2 const terpisah (2 node =
     * 2 execution context), isinya identik (diverifikasi otomatis), jadi di
     * sini disatukan jadi 1 const dipakai kedua method.
     */
    private const CATEGORIES = [
        'Sabun', 'Lotion', 'Scrub', 'Serum wajah', 'Sabun Cair',
        'UnderArm', 'Retinol', 'BB cream', 'Mouth Spray', 'Face Mist',
    ];

    /** Posisi kolom (0-based) di CSV "Semua pesanan" TikTok — PERSIS COL_OID/COL_SKU/COL_QTY node "Cache Order CSV". */
    private const COL_OID = 0;

    private const COL_SKU = 5;

    private const COL_QTY = 9;

    /**
     * VERBATIM node n8n "Cache Order CSV" (versi OPERATIF — baca dokblok
     * kelas di atas utk kenapa BUKAN versi di node "Code Parse income").
     * SKU -> {kategori => unit fisik per 1 SKU}. Bundling dipecah ke tiap
     * kategori; varian "N Pcs" sudah dihitung unit fisiknya (mis. "Scrub 3
     * Pcs" = 3, bukan 1) — dikalikan lagi dgn qty baris CSV saat dipakai
     * (lihat commitCsvRecord()).
     *
     * @var array<string, array<string, int>>
     */
    public const SKU_MAP = [
        // --- Produk tunggal ---
        '1734055986286921642' => ['Lotion' => 1],                 // Body Serum (1 Pcs)
        '1734056004809426858' => ['Scrub' => 3],                  // Scrub 3 Pcs
        '1734056004809492394' => ['Scrub' => 1],                  // Scrub 1 Pcs
        '1734056024555161514' => ['Sabun' => 3],                  // Soap 3 Pcs
        '1734056024555227050' => ['Sabun' => 1],                  // Soap 1 Pcs
        '1734056044044912554' => ['BB cream' => 1],               // BB Cream
        '1734143183837038506' => ['Serum wajah' => 1],            // Niacinamide (serum wajah)
        '1734167882844112810' => ['Retinol' => 1],                // Night Cream Booster
        '1734170327741925290' => ['Lotion' => 1],                 // Body Serum (Default)
        '1735591817826764714' => ['Sabun Cair' => 1],             // Mizu Body Wash
        '1736015074925578154' => ['UnderArm' => 1],               // REINA Underarm

        // --- Bundling (dipecah ke tiap kategori) ---
        '1734056032984729514' => ['Sabun' => 1, 'Lotion' => 1],                          // Soap + Body Serum
        '1734447605186201514' => ['Sabun' => 1, 'Lotion' => 1],                          // Soap + Body Serum
        '1735950994213406634' => ['Sabun' => 1, 'Serum wajah' => 1],                     // Soap + Face Serum
        '1734444648213415850' => ['Sabun' => 2],                                         // 2 Soap
        '1734447451020036010' => ['Sabun' => 3],                                         // 3 Soap
        '1734056077596329898' => ['Scrub' => 1, 'Sabun' => 1, 'Lotion' => 1],            // Scrub + Soap + Body Serum
        '1734439426577106858' => ['Sabun' => 1, 'Scrub' => 1],                           // Soap + Scrub
        '1735342863885043626' => ['Sabun' => 1, 'BB cream' => 1, 'Serum wajah' => 1],    // Soap + BB + Face Serum

        // --- SKU baru (TikTok recreate listing produk lama, kategori sama) ---
        '1736331394090370986' => ['Sabun Cair' => 1],     // Mizu Body Wash (listing baru)
        '1736331387470317482' => ['Lotion' => 1],         // Body Lotion/Serum (listing baru)
        '1736331327684773802' => ['Serum wajah' => 1],    // Niacinamide (listing baru)
        '1736331393097303978' => ['UnderArm' => 1],       // REINA Underarm (listing baru)

        // --- Produk baru ---
        '1736405151798560682' => ['Face Mist' => 1],                                     // HANA Glow Face Mist (versi operatif — lihat dokblok kelas)
        '1734056061834856362' => ['Sabun' => 1, 'Serum wajah' => 1, 'BB cream' => 1],    // Complete Set (lama)
        '1736405844219627434' => ['Mouth Spray' => 1],                                   // HAKKA Mouth Spray
        '1736469482811459498' => ['BB cream' => 1],                                      // ASA Glow Day Cream (= BB cream)
        '1736331159132276650' => ['UnderArm' => 3],                                      // BUNDLING 3 pcs REINA UnderArm
        '1736470209629947818' => ['BB cream' => 1, 'Retinol' => 1],                      // Complete Day & Night Care (set)
    ];

    /** OUTPUT_COLUMNS node n8n "Code Parse income" — urutan TERKUNCI, persis sumbernya (termasuk trailing-space pada 4 nama kolom). */
    private const OUTPUT_COLUMNS = [
        'Order/adjustment ID  ', 'Type ', 'Order created time', 'Order settled time',
        'Currency', 'Subtotal before discounts', 'Seller discounts',
        'Subtotal after seller discounts', 'Refund subtotal after seller discounts',
        'Total Revenue', 'Total Fees', 'Total settlement amount',
        'Sabun', 'Lotion', 'Scrub', 'Serum wajah', 'Sabun Cair', 'UnderArm', 'Retinol', 'BB cream', 'Mouth Spray', 'Face Mist',
        'Platform commission fee', 'Pre-order service fee', 'Shipping cost',
        'Affiliate Commission', 'Dynamic commission', 'Order processing fee',
        'Related order ID  ', 'Customer payment', 'Customer refund',
        'Refund of seller co-funded voucher discount', 'Platform discounts',
        'Refund of platform discounts', 'Platform co-funded voucher discounts',
        'Refund of platform co-funded voucher discounts', 'Seller shipping cost discount',
        'Estimated package weight (g)', 'Estimated package weight (g) ',
        'Actual package weight (g)', 'Shopping center items', 'Order Source',
    ];

    /**
     * Port VERBATIM node n8n "Cache Order CSV": buang BOM UTF-8, parse CSV
     * manual quote-aware (state machine karakter-per-karakter PERSIS
     * sumbernya), lewati baris header (baris pertama), ambil HANYA 3 kolom by
     * POSISI (COL_OID/COL_SKU/COL_QTY — kolom lain diabaikan, sesuai desain
     * n8n "hemat memori": nama node aslinya "CACHE ORDER CSV (versi hemat
     * memori / anti-502)"). Baris dgn Order ID atau SKU kosong -> diabaikan.
     * SKU tak ada di SKU_MAP -> diabaikan (TAK throw — n8n cuma mencatatnya
     * ke `unmapped` utk pesan diagnostik Telegram, di luar cakupan method
     * pure ini). Qty di-parseInt longgar (non-angka -> default 1, PERSIS
     * `if (isNaN(qty)) qty = 1;`).
     *
     * Lihat dokblok kelas ("CATATAN DESAIN") utk kenapa return-nya SUDAH
     * berupa object per-kategori (bukan array posisional 10-angka mentah yg
     * n8n simpan ke static data).
     *
     * @return array<string, array<string, int>> orderId => [kategori => unit fisik total]
     */
    public static function parseOrderCsv(string $csv): array
    {
        return self::parseOrderCsvFull($csv)['index'];
    }

    /**
     * Ringkasan diagnostik CSV Order — port pesan Telegram node n8n "Cache
     * Order CSV" (baris ~368-377 sumber): jumlah baris data valid (oid+sku
     * terisi), jumlah Order unik (yang punya >=1 SKU dikenal), & daftar SKU di
     * luar SKU_MAP. Dipakai TikTokIncomeFlow untuk balasan saat CSV diterima
     * (parity n8n: "Baris CSV terbaca / Order unik / SKU dikenali").
     *
     * @return array{lineCount:int, orders:int, unmapped:array<int,string>}
     */
    public static function orderCsvSummary(string $csv): array
    {
        $full = self::parseOrderCsvFull($csv);

        return [
            'lineCount' => $full['lineCount'],
            'orders' => count($full['index']),
            'unmapped' => array_keys($full['unmapped']),
        ];
    }

    /**
     * Parser inti dipakai parseOrderCsv() (ambil ['index']) & orderCsvSummary()
     * (ambil statistik). Selain $index (orderId => [kategori => unit]), juga
     * menghitung $lineCount & mengumpulkan $unmapped — PERSIS lineCount/unmapped
     * node n8n "Cache Order CSV". Logika pembangunan $index TIDAK berubah dari
     * versi sebelumnya (statistik murni tambahan).
     *
     * @return array{index:array<string,array<string,int>>, lineCount:int, unmapped:array<string,bool>}
     */
    private static function parseOrderCsvFull(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv; // buang BOM UTF-8

        $index = [];
        $unmapped = [];        // SKU di luar SKU_MAP (PERSIS `unmapped` n8n)
        $lineCount = 0;        // baris data dgn oid+sku terisi (PERSIS `lineCount` n8n)
        $field = '';
        $col = 0;
        $inQuotes = false;
        $rec = [];             // hanya index COL_OID/COL_SKU/COL_QTY yang pernah diisi
        $headerSkipped = false;
        $len = strlen($csv);

        for ($k = 0; $k < $len; $k++) {
            $ch = $csv[$k];

            if ($inQuotes) {
                if ($ch === '"') {
                    if ($k + 1 < $len && $csv[$k + 1] === '"') {
                        $field .= '"';   // "" di dalam quote -> " literal
                        $k++;
                    } else {
                        $inQuotes = false;
                    }
                } else {
                    $field .= $ch;       // termasuk koma/newline literal di dalam quote
                }

                continue;
            }

            if ($ch === '"') {
                $inQuotes = true;
            } elseif ($ch === ',') {
                [$field, $col] = self::pushCsvField($rec, $field, $col);
            } elseif ($ch === "\n") {
                // Flush field pending HANYA bila ada sesuatu tertunda — PERSIS
                // `if (field !== "" || col > 0) endField();` di awal endRecord()
                // sumber n8n (baris kosong murni -> tak ada field yg di-push).
                if ($field !== '' || $col > 0) {
                    [$field, $col] = self::pushCsvField($rec, $field, $col);
                }
                $headerSkipped = self::commitCsvRecord($rec, $headerSkipped, $index, $lineCount, $unmapped);
                $rec = [];
                $col = 0;
            } elseif ($ch === "\r") {
                // lewati (CRLF)
            } else {
                $field .= $ch;
            }
        }

        // Baris terakhir tanpa newline penutup -> flush manual, PERSIS
        // `if (field !== "" || col > 0) endRecord();` di akhir sumber n8n.
        if ($field !== '' || $col > 0) {
            [$field, $col] = self::pushCsvField($rec, $field, $col);
            self::commitCsvRecord($rec, $headerSkipped, $index, $lineCount, $unmapped);
        }

        return ['index' => $index, 'lineCount' => $lineCount, 'unmapped' => $unmapped];
    }

    /**
     * Port `endField()`: simpan $field ke $rec[$col] HANYA bila $col adalah
     * salah satu dari 3 kolom yang dipantau, lalu reset $field & majukan
     * $col. Dipanggil dari 3 titik (koma, newline, EOF) PERSIS sumbernya.
     *
     * @param  array<int,string>  $rec
     * @return array{0:string,1:int} [$field baru (selalu ''), $col baru]
     */
    private static function pushCsvField(array &$rec, string $field, int $col): array
    {
        if ($col === self::COL_OID || $col === self::COL_SKU || $col === self::COL_QTY) {
            $rec[$col] = $field;
        }

        return ['', $col + 1];
    }

    /**
     * Port sisa `endRecord()` SETELAH field terakhir di-flush: baris pertama
     * (header) dilewati tanpa diproses; baris selanjutnya -> validasi oid/sku
     * tak kosong, lookup SKU_MAP, akumulasi unit fisik x qty ke $index by
     * Order ID (kategori lain default 0 lewat array_fill_keys saat entri
     * Order ID baru dibuat).
     *
     * @param  array<int,string>  $rec
     * @param  array<string,array<string,int>>  $index
     * @param  array<string,bool>  $unmapped  akumulasi SKU di luar SKU_MAP (utk pesan diagnostik n8n)
     * @return bool $headerSkipped baru (selalu true setelah baris pertama)
     */
    private static function commitCsvRecord(array $rec, bool $headerSkipped, array &$index, int &$lineCount, array &$unmapped): bool
    {
        if (! $headerSkipped) {
            return true;
        }

        $oid = str_replace("\t", '', trim($rec[self::COL_OID] ?? ''));
        $sku = str_replace("\t", '', trim($rec[self::COL_SKU] ?? ''));
        $qty = self::parseIntLoose($rec[self::COL_QTY] ?? '');

        if ($oid === '' || $sku === '') {
            return true;
        }

        $lineCount++;   // PERSIS n8n: dihitung SETELAH cek oid/sku, SEBELUM cek SKU_MAP

        $comp = self::SKU_MAP[$sku] ?? null;
        if ($comp === null) {
            $unmapped[$sku] = true;   // PERSIS n8n: catat SKU tak dikenal (tak throw)

            return true;
        }

        if (! isset($index[$oid])) {
            $index[$oid] = array_fill_keys(self::CATEGORIES, 0);
        }
        foreach ($comp as $category => $unitQty) {
            $index[$oid][$category] += $unitQty * $qty;
        }

        return true;
    }

    /**
     * Port `parseInt(str, 10)` + fallback `isNaN(qty) ? 1 : qty` node n8n:
     * ambil digit (dgn tanda opsional) di AWAL string, abaikan sisanya (mis.
     * "3.5" -> 3); tak ada digit valid di awal -> default 1 (BUKAN 0).
     */
    private static function parseIntLoose(string $v): int
    {
        $s = trim(str_replace("\t", '', $v));

        return preg_match('/^[+-]?\d+/', $s, $m) === 1 ? (int) $m[0] : 1;
    }

    /**
     * Port VERBATIM node n8n "Code Parse income": iterasi tiap baris Income
     * (satu elemen = satu baris xlsx, header => value — PERSIS `item.json`
     * n8n; lihat dokblok kelas soal siapa yang menyiapkan bentuk ini), JOIN
     * ke $orderIndex (hasil parseOrderCsv()) by Order ID, susun 42 kolom
     * OUTPUT_COLUMNS. Baris kosong total (isEmptyRow) ATAU baris tanpa Order
     * ID **dan** tanpa Type sama sekali -> dilewati (2 guard TERPISAH, PERSIS
     * sumber — bukan cuma satu kondisi gabungan).
     *
     * Order ID yang tak ketemu di $orderIndex (order tak pernah muncul di CSV
     * / semua SKU-nya tak dikenal) -> `$orderIndex[$orderId] ?? []` -> 10
     * kolom kategori default 0 (BUKAN baris income-nya di-skip — baris tetap
     * muncul di output, PERSIS `ORDER_INDEX[orderId] || {}` sumbernya).
     *
     * @param  array<int, array<string, mixed>>  $incomeRows  satu elemen = satu baris Income (xlsx), key = nama kolom header (Indonesia/Inggris, lihat pick())
     * @param  array<string, array<string, int>>  $orderIndex  hasil parseOrderCsv(): orderId => [kategori => unit fisik]
     * @return array{Income: array{headers: array<int,string>, rows: array<int, array<int, mixed>>}} siap dioper ke App\Support\XlsxWriter::write()
     */
    public static function build(array $incomeRows, array $orderIndex): array
    {
        $rows = [];

        foreach ($incomeRows as $row) {
            if (! is_array($row) || self::isEmptyRow($row)) {
                continue;
            }

            $orderId = self::cleanText(self::pick($row, [
                'ID Pesanan/Penyesuaian', 'Order/adjustment ID', 'Order/adjustment ID  ',
            ]));
            $rawType = self::cleanText(self::pick($row, ['Jenis transaksi', 'Type', 'Type ']));
            if ($orderId === '' && $rawType === '') {
                continue;
            }

            $type = self::translateType($rawType);
            $productQty = $orderIndex[$orderId] ?? [];

            $data = [
                'Order/adjustment ID  ' => $orderId,
                'Type ' => $type,
                'Order created time' => self::cleanText(self::pick($row, ['Waktu pemesanan', 'Order created time'])),
                'Order settled time' => self::cleanText(self::pick($row, ['Waktu pembayaran pesanan', 'Order settled time'])),
                'Currency' => self::orDefault(self::cleanText(self::pick($row, ['Mata uang', 'Currency'])), 'IDR'),
                'Subtotal before discounts' => self::num(self::pick($row, ['Subtotal sebelum diskon', 'Subtotal before discounts'])),
                'Seller discounts' => self::num(self::pick($row, ['Diskon penjual', 'Seller discounts'])),
                'Subtotal after seller discounts' => self::num(self::pick($row, ['Subtotal setelah diskon penjual', 'Subtotal after seller discounts'])),
                'Refund subtotal after seller discounts' => self::num(self::pick($row, ['Subtotal pengembalian dana setelah diskon penjual', 'Refund subtotal after seller discounts'])),
                'Total Revenue' => self::num(self::pick($row, ['Total Pendapatan', 'Total Revenue'])),
                'Total Fees' => self::num(self::pick($row, ['Total Biaya', 'Total Fees'])),
                'Total settlement amount' => self::num(self::pick($row, ['Jumlah penyelesaian pembayaran', 'Total settlement amount'])),

                // 10 kategori — nilai dari JOIN order index (parseOrderCsv()), BUKAN dari baris income.
                'Sabun' => $productQty['Sabun'] ?? 0,
                'Lotion' => $productQty['Lotion'] ?? 0,
                'Scrub' => $productQty['Scrub'] ?? 0,
                'Serum wajah' => $productQty['Serum wajah'] ?? 0,
                'Sabun Cair' => $productQty['Sabun Cair'] ?? 0,
                'UnderArm' => $productQty['UnderArm'] ?? 0,
                'Retinol' => $productQty['Retinol'] ?? 0,
                'BB cream' => $productQty['BB cream'] ?? 0,
                'Mouth Spray' => $productQty['Mouth Spray'] ?? 0,
                'Face Mist' => $productQty['Face Mist'] ?? 0,

                'Platform commission fee' => self::num(self::pick($row, ['Biaya komisi platform', 'Platform commission fee'])),
                'Pre-order service fee' => self::num(self::pick($row, ['Biaya layanan pre-order', 'Pre-order service fee'])),
                'Shipping cost' => self::num(self::pick($row, ['Ongkir', 'Shipping cost'])),
                'Affiliate Commission' => self::num(self::pick($row, ['Komisi Afiliasi', 'Affiliate Commission'])),
                'Dynamic commission' => self::num(self::pick($row, ['Komisi dinamis', 'Dynamic commission'])),
                'Order processing fee' => self::num(self::pick($row, ['Biaya pemrosesan pesanan', 'Order processing fee'])),
                'Related order ID  ' => self::cleanText(self::pick($row, ['ID pesanan terkait', 'Related order ID', 'Related order ID  '])),
                'Customer payment' => self::num(self::pick($row, ['Pembayaran oleh pembeli', 'Customer payment'])),
                'Customer refund' => self::num(self::pick($row, ['Pengembalian dana pembeli', 'Customer refund'])),
                'Refund of seller co-funded voucher discount' => self::num(self::pick($row, ['Pengembalian dana diskon voucher yang ditanggung penjual', 'Refund of seller co-funded voucher discount'])),
                'Platform discounts' => self::num(self::pick($row, ['Diskon platform', 'Platform discounts'])),
                'Refund of platform discounts' => self::num(self::pick($row, ['Pengembalian dana diskon platform', 'Refund of platform discounts'])),
                'Platform co-funded voucher discounts' => self::num(self::pick($row, ['Diskon voucher yang ditanggung platform', 'Platform co-funded voucher discounts'])),
                'Refund of platform co-funded voucher discounts' => self::num(self::pick($row, ['Pengembalian dana diskon voucher yang ditanggung platform', 'Refund of platform co-funded voucher discounts'])),
                'Seller shipping cost discount' => self::num(self::pick($row, ['Diskon ongkir dari penjual', 'Seller shipping cost discount'])),
                'Estimated package weight (g)' => self::num(self::pick($row, ['Perkiraan berat paket', 'Estimated package weight (g)'])),
                'Estimated package weight (g) ' => self::num(self::pick($row, ['Berat paket yang bisa dikenai biaya', 'Chargeable package weight (g)', 'Estimated package weight (g) '])),
                'Actual package weight (g)' => self::num(self::pick($row, ['Berat paket aktual', 'Actual package weight (g)'])),
                'Shopping center items' => self::orDefault(self::cleanText(self::pick($row, ['Detail produk terjual', 'Shopping center items'])), '/'),
                'Order Source' => self::orDefault(self::cleanText(self::pick($row, ['Sumber pesanan', 'Order Source'])), 'TikTok Shop'),
            ];

            $rows[] = array_map(static fn (string $col) => $data[$col] ?? '', self::OUTPUT_COLUMNS);
        }

        return [
            'Income' => [
                'headers' => self::OUTPUT_COLUMNS,
                'rows' => $rows,
            ],
        ];
    }

    /**
     * Port `pick(row, keys)`: coba tiap kandidat key BERURUTAN — exact match
     * dulu, lalu match ternormalisasi (whitespace dirapikan + lower-case)
     * lewat buildKeyIndex(); key/nilai kosong dianggap "tak ketemu", lanjut
     * ke kandidat berikutnya (BUKAN ke row key lain utk kandidat yang sama).
     * Semua kandidat gagal -> "".
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $keys
     */
    private static function pick(array $row, array $keys): mixed
    {
        $idx = self::buildKeyIndex($row);

        foreach ($keys as $key) {
            $direct = $row[$key] ?? null;
            if ($direct !== null && $direct !== '') {
                return $direct;
            }

            $rk = $idx[self::normalizeKey($key)] ?? null;
            if ($rk !== null) {
                $viaNormalized = $row[$rk] ?? null;
                if ($viaNormalized !== null && $viaNormalized !== '') {
                    return $viaNormalized;
                }
            }
        }

        return '';
    }

    /** Port `buildKeyIndex(row)`: normalizeKey(key asli) => key asli, utk semua key di $row. @param array<string,mixed> $row @return array<string,string> */
    private static function buildKeyIndex(array $row): array
    {
        $idx = [];
        foreach (array_keys($row) as $k) {
            $idx[self::normalizeKey((string) $k)] = (string) $k;
        }

        return $idx;
    }

    /** Port `normalizeKey(k)`: cleanText -> rapikan whitespace jadi 1 spasi -> trim -> lowercase. */
    private static function normalizeKey(string $k): string
    {
        $s = preg_replace('/\s+/', ' ', self::cleanText($k)) ?? self::cleanText($k);

        return mb_strtolower(trim($s));
    }

    /** Port `cleanText(v)`: null/undefined -> "", selain itu String(v) dgn tab dibuang + trim. */
    private static function cleanText(mixed $v): string
    {
        if ($v === null) {
            return '';
        }

        return trim(str_replace("\t", '', (string) $v));
    }

    /**
     * Port `num(v)`: beberapa nilai "kosong" (termasuk "/" & "-", pemisah
     * kolom kosong lazim di export TikTok) -> 0. Selain itu buang
     * whitespace+koma (pemisah ribuan), tangani "(1.234)" sbg negatif
     * (konvensi akuntansi), lalu parse sbg angka — bukan angka valid -> 0.
     */
    private static function num(mixed $v): int|float
    {
        if ($v === null || $v === '' || $v === '/' || $v === "/\t" || $v === '-') {
            return 0;
        }

        $t = (string) $v;
        $t = preg_replace('/\s/', '', $t) ?? $t;   // buang semua whitespace (termasuk tab)
        $t = str_replace(',', '', $t);              // buang koma pemisah ribuan

        if (str_starts_with($t, '(') && str_ends_with($t, ')')) {
            $t = '-'.substr($t, 1, -1);
        }

        return is_numeric($t) ? $t + 0 : 0;
    }

    /** Port `translateType(type)`: "pesanan"/"order" (case-insensitive) -> "Order"; selain itu apa adanya (mis. GMV, Ads). */
    private static function translateType(mixed $type): string
    {
        $raw = self::cleanText($type);

        return in_array(mb_strtolower($raw), ['pesanan', 'order'], true) ? 'Order' : $raw;
    }

    /**
     * Port pola `x || "default"` sumber n8n (dipakai utk Currency/Shopping
     * center items/Order Source) — sengaja BUKAN operator Elvis `?:` PHP:
     * `?:` menganggap STRING "0" falsy juga (kekhususan PHP), padahal `||`
     * JS cuma menganggap string KOSONG "" yang falsy (string non-kosong
     * apa pun, termasuk "0", tetap truthy di JS). $s di sini SELALU string
     * (hasil cleanText()), jadi cukup bandingkan ke "" secara eksplisit.
     */
    private static function orDefault(string $s, string $default): string
    {
        return $s === '' ? $default : $s;
    }

    /** Port `isEmptyRow(row)`: true bila SEMUA value (setelah cleanText) kosong — termasuk row tanpa key sama sekali. @param array<string,mixed> $row */
    private static function isEmptyRow(array $row): bool
    {
        foreach ($row as $v) {
            if (self::cleanText($v) !== '') {
                return false;
            }
        }

        return true;
    }
}
