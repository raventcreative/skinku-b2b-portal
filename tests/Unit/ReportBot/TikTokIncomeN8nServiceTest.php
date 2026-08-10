<?php

namespace Tests\Unit\ReportBot;

use App\Services\ReportBot\TikTokIncomeN8nService;
use PHPUnit\Framework\TestCase;

/**
 * Task 11: port VERBATIM node n8n "Cache Order CSV" (parseOrderCsv) + "Code
 * Parse income" (build) — lihat dokblok kelas TikTokIncomeN8nService utk
 * rasional lengkap (termasuk resolusi 2 SKU_MAP berbeda di sumber n8n).
 *
 * Fixture CSV "Semua pesanan" dipakai di semua test (lihat ORDER_CSV const):
 *   header (dilewati) lalu 4 baris data:
 *   - ORDER-001 baris#1: SKU bundling "1734056032984729514" (Sabun 1 + Lotion 1
 *     per unit), qty=1 -> Sabun+=1, Lotion+=1. Kolom "Product Name" SENGAJA
 *     berisi field ber-quote dgn koma di dalamnya ("Sabun Wangi, Edisi
 *     Spesial") -> membuktikan parser quote-aware TIDAK menggeser posisi
 *     kolom SKU ID (5)/Quantity (9) setelahnya.
 *   - ORDER-001 baris#2: SKU varian "N Pcs" "1734056004809426858" (Scrub 3
 *     Pcs, artinya 3 UNIT FISIK per 1 SKU), qty=2 -> Scrub += 3*2 = 6
 *     (membuktikan unit fisik x qty baris, bukan cuma salah satunya).
 *   - ORDER-002 baris#1: SKU tunggal "1734056024555227050" (Sabun 1 Pcs),
 *     qty=3 -> Sabun += 1*3 = 3.
 *   - ORDER-002 baris#2: SKU TAK DIKENAL (bukan di SKU_MAP), qty=5 -> harus
 *     diabaikan (tak throw, tak mengotori index ORDER-002 yang sudah ada dari
 *     baris#1).
 *   Teks CSV diawali BOM UTF-8 (\xEF\xBB\xBF) -> harus dibuang. Baris terakhir
 *   SENGAJA tanpa newline penutup (uji flush EOF). Satu baris pakai CRLF
 *   (\r\n) -> uji \r diabaikan bukan ikut jadi bagian field.
 *
 * ORDER-003 SENGAJA TIDAK muncul di CSV sama sekali (hanya di income xlsx) ->
 * uji jalur "order tak ke-index" di build() (default 0 semua kategori, BUKAN
 * exception).
 */
class TikTokIncomeN8nServiceTest extends TestCase
{
    private const ORDER_CSV = "\xEF\xBB\xBFOrder ID,Order Status,Product Name,Variation,Buyer Username,SKU ID,Seller SKU,Original Price,SKU Subtotal Before Discount,Quantity\r\n"
        ."ORDER-001,Selesai,\"Sabun Wangi, Edisi Spesial\",Reguler,buyer01,1734056032984729514,SELLER-BUNDLE-1,50000,50000,1\n"
        ."ORDER-001,Selesai,Scrub Mangga 3 Pcs,Reguler,buyer01,1734056004809426858,SELLER-SCRUB3,30000,60000,2\n"
        ."ORDER-002,Selesai,Sabun Klasik,Reguler,buyer02,1734056024555227050,SELLER-SABUN1,10000,30000,3\n"
        .'ORDER-002,Selesai,Produk Misterius,Reguler,buyer02,9999999999999999999,SELLER-UNKNOWN,1000,5000,5';

    /** 42 kolom output PERSIS OUTPUT_COLUMNS node n8n "Code Parse income" (urutan terkunci). */
    private const EXPECTED_HEADERS = [
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

    public function test_parse_order_csv_splits_bundling_and_counts_n_pcs_physical_units(): void
    {
        $index = TikTokIncomeN8nService::parseOrderCsv(self::ORDER_CSV);

        $this->assertSame(['ORDER-001', 'ORDER-002'], array_keys($index));

        // ORDER-001: bundling (Sabun 1 + Lotion 1, qty 1) + Scrub-3-Pcs (qty 2) -> Scrub = 3*2 = 6.
        $this->assertSame(1, $index['ORDER-001']['Sabun']);
        $this->assertSame(1, $index['ORDER-001']['Lotion']);
        $this->assertSame(6, $index['ORDER-001']['Scrub']);
        $this->assertSame(0, $index['ORDER-001']['Serum wajah']);
        $this->assertSame(0, $index['ORDER-001']['Face Mist']);

        // ORDER-002: SKU tunggal qty 3 -> Sabun = 3; SKU tak dikenal (qty 5) diabaikan, tak nambah apa pun.
        $this->assertSame(3, $index['ORDER-002']['Sabun']);
        $this->assertSame(0, $index['ORDER-002']['Scrub']);
    }

    public function test_order_csv_summary_hitung_baris_order_unik_dan_sku_tak_dikenal(): void
    {
        // ORDER_CSV: 4 baris data (semua oid+sku terisi) -> lineCount 4;
        // 2 order punya >=1 SKU dikenal (ORDER-001, ORDER-002) -> orders 2;
        // SKU "9999999999999999999" tak ada di SKU_MAP -> unmapped 1 (utk pesan
        // "SKU belum dikenal" ala n8n).
        $summary = TikTokIncomeN8nService::orderCsvSummary(self::ORDER_CSV);

        $this->assertSame(4, $summary['lineCount']);
        $this->assertSame(2, $summary['orders']);
        $this->assertSame(['9999999999999999999'], $summary['unmapped']);
    }

    public function test_parse_order_csv_index_has_all_ten_categories_defaulted_to_zero(): void
    {
        $index = TikTokIncomeN8nService::parseOrderCsv(self::ORDER_CSV);

        $expectedCats = ['Sabun', 'Lotion', 'Scrub', 'Serum wajah', 'Sabun Cair', 'UnderArm', 'Retinol', 'BB cream', 'Mouth Spray', 'Face Mist'];
        $this->assertSame($expectedCats, array_keys($index['ORDER-001']));
        $this->assertSame($expectedCats, array_keys($index['ORDER-002']));
    }

    /**
     * Regresi: endRecord() n8n cuma flush field tertunda kalau
     * `field !== "" || col > 0` (baris kosong murni -> field TAK di-push sama
     * sekali, bukan cuma "di-push sbg string kosong"). Baris kosong (\n\n
     * beruntun) harus jadi no-op senyap, bukan bikin entri Order ID "" atau
     * merusak parsing baris SESUDAHNYA.
     */
    public function test_parse_order_csv_ignores_truly_blank_lines(): void
    {
        $csv = "Order ID,a,b,c,d,SKU ID,f,g,h,Quantity\n"
            ."\n"                                                          // baris kosong murni
            ."ORDER-009,Selesai,x,x,x,1734055986286921642,x,x,x,1\n";

        $index = TikTokIncomeN8nService::parseOrderCsv($csv);

        $this->assertSame(['ORDER-009'], array_keys($index));
        $this->assertSame(1, $index['ORDER-009']['Lotion']);
    }

    public function test_parse_order_csv_ignores_empty_order_id_or_sku(): void
    {
        $csv = "Order ID,a,b,c,d,SKU ID,f,g,h,Quantity\n"
            .",Selesai,x,x,x,1734055986286921642,x,x,x,1\n"           // Order ID kosong -> diabaikan
            ."ORDER-EMPTY-SKU,Selesai,x,x,x,,x,x,x,1\n";               // SKU kosong -> diabaikan

        $index = TikTokIncomeN8nService::parseOrderCsv($csv);

        $this->assertSame([], $index);
    }

    public function test_build_joins_income_rows_to_order_index_by_order_id(): void
    {
        $orderIndex = TikTokIncomeN8nService::parseOrderCsv(self::ORDER_CSV);

        $incomeRows = [
            [
                // Order ID sengaja diberi tab trailing -> cleanText harus membuangnya
                // supaya tetap match key 'ORDER-001' hasil parseOrderCsv.
                'ID Pesanan/Penyesuaian' => "ORDER-001\t",
                'Jenis transaksi' => 'Pesanan',
                'Waktu pemesanan' => '2026-08-01 10:00:00',
                'Waktu pembayaran pesanan' => '2026-08-01 10:05:00',
                'Total Pendapatan' => '150,000',
                'Total Biaya' => '(5,000)',
                'Jumlah penyelesaian pembayaran' => '145000',
                // Currency / Shopping center items / Order Source SENGAJA kosong -> default.
            ],
            [
                // Header versi INGGRIS -> uji fallback pick() bilingual.
                'Order/adjustment ID' => 'ORDER-002',
                'Type' => 'GMV',
                'Total Revenue' => '30000',
                'Total Fees' => '1000',
                'Total settlement amount' => '29000',
                'Currency' => 'USD',
                'Shopping center items' => 'Sabun Klasik x3',
                'Order Source' => 'TikTok Ads',
            ],
            [
                // ORDER-003 TIDAK ada di CSV sama sekali -> harus tetap muncul di output
                // (baris income tetap diproses), tapi SEMUA kategori = 0 (bukan exception).
                // "Detail produk terjual" = string "0" SENGAJA (bukan "") -> uji default
                // hanya berlaku utk string KOSONG, bukan string "0" (beda `||` JS vs `?:` PHP).
                'ID Pesanan/Penyesuaian' => 'ORDER-003',
                'Jenis transaksi' => 'Pesanan',
                'Total Pendapatan' => '75000',
                'Total Biaya' => '2000',
                'Jumlah penyelesaian pembayaran' => '73000',
                'Detail produk terjual' => '0',
            ],
            [
                // Baris kosong total -> isEmptyRow() -> dilewati, TIDAK muncul di output.
                'ID Pesanan/Penyesuaian' => '',
                'Jenis transaksi' => '',
                'Total Pendapatan' => '',
            ],
            [
                // Tidak kosong (ada data lain) TAPI tak punya orderId MAUPUN type sama
                // sekali -> tetap dilewati (guard `!orderId && !rawType`, beda dari isEmptyRow).
                'Waktu pemesanan' => '2026-08-01 09:00:00',
                'Total Pendapatan' => '10000',
            ],
        ];

        $sheets = TikTokIncomeN8nService::build($incomeRows, $orderIndex);

        $this->assertArrayHasKey('Income', $sheets);
        $this->assertSame(self::EXPECTED_HEADERS, $sheets['Income']['headers']);
        $this->assertCount(3, $sheets['Income']['rows'], 'baris kosong & baris tanpa orderId/type harus dilewati');

        $headers = $sheets['Income']['headers'];
        $rowsByOrder = [];
        foreach ($sheets['Income']['rows'] as $row) {
            $byHeader = array_combine($headers, $row);
            $rowsByOrder[$byHeader['Order/adjustment ID  ']] = $byHeader;
        }

        $this->assertSame(['ORDER-001', 'ORDER-002', 'ORDER-003'], array_keys($rowsByOrder));

        // ORDER-001: join sukses -> kategori dari orderIndex; type diterjemahkan; angka di-parse.
        $o1 = $rowsByOrder['ORDER-001'];
        $this->assertSame('Order', $o1['Type ']);
        $this->assertSame(1, $o1['Sabun']);
        $this->assertSame(1, $o1['Lotion']);
        $this->assertSame(6, $o1['Scrub']);
        $this->assertSame(150000, $o1['Total Revenue']);
        $this->assertSame(-5000, $o1['Total Fees']);
        $this->assertSame(145000, $o1['Total settlement amount']);
        $this->assertSame('IDR', $o1['Currency'], 'Currency kosong harus default ke IDR');
        $this->assertSame('/', $o1['Shopping center items'], 'Shopping center items kosong harus default ke "/"');
        $this->assertSame('TikTok Shop', $o1['Order Source'], 'Order Source kosong harus default ke TikTok Shop');

        // ORDER-002: header Inggris (fallback pick), type BUKAN pesanan/order -> passthrough apa adanya.
        $o2 = $rowsByOrder['ORDER-002'];
        $this->assertSame('GMV', $o2['Type ']);
        $this->assertSame(3, $o2['Sabun']);
        $this->assertSame(30000, $o2['Total Revenue']);
        $this->assertSame('USD', $o2['Currency']);
        $this->assertSame('Sabun Klasik x3', $o2['Shopping center items']);
        $this->assertSame('TikTok Ads', $o2['Order Source']);

        // ORDER-003: tak ke-index di CSV -> semua 10 kategori 0, TAPI baris income tetap ada.
        $o3 = $rowsByOrder['ORDER-003'];
        foreach (['Sabun', 'Lotion', 'Scrub', 'Serum wajah', 'Sabun Cair', 'UnderArm', 'Retinol', 'BB cream', 'Mouth Spray', 'Face Mist'] as $cat) {
            $this->assertSame(0, $o3[$cat], "kategori {$cat} pada ORDER-003 (tak ada di CSV) harus 0");
        }
        $this->assertSame(75000, $o3['Total Revenue']);
        $this->assertSame('0', $o3['Shopping center items'], 'string "0" BUKAN kosong -> jangan ketuker default "/" (beda `||` JS vs `?:` PHP)');
    }

    public function test_build_returns_empty_rows_for_no_income_input(): void
    {
        $sheets = TikTokIncomeN8nService::build([], []);

        $this->assertSame(self::EXPECTED_HEADERS, $sheets['Income']['headers']);
        $this->assertSame([], $sheets['Income']['rows']);
    }

    /**
     * SKU_MAP HARUS verbatim dari versi OPERATIF n8n (node "Cache Order CSV" —
     * yang benar2 dipakai membangun index kategori; SKU_MAP di node "Code
     * Parse income" adalah sisa kode MATI, tak pernah dibaca lagi setelah
     * didefinisikan di sana, dan justru lebih usang: 25 entri vs 29, dan utk
     * SKU "1736405151798560682" masih menandai "SEMENTARA -> Serum wajah"
     * padahal versi operatif sudah mengoreksinya ke "Face Mist", cocok dgn
     * nama produknya sendiri "HANA Glow Face Mist"). Lihat dokblok const
     * SKU_MAP di kelas untuk rincian lengkap resolusi ini.
     */
    public function test_sku_map_matches_operative_n8n_source_verbatim(): void
    {
        $this->assertCount(29, TikTokIncomeN8nService::SKU_MAP);

        // Bundling.
        $this->assertSame(['Sabun' => 1, 'Lotion' => 1], TikTokIncomeN8nService::SKU_MAP['1734056032984729514']);
        $this->assertSame(['Scrub' => 1, 'Sabun' => 1, 'Lotion' => 1], TikTokIncomeN8nService::SKU_MAP['1734056077596329898']);

        // Varian "N Pcs" (unit fisik > 1 utk 1 SKU).
        $this->assertSame(['Scrub' => 3], TikTokIncomeN8nService::SKU_MAP['1734056004809426858']);
        $this->assertSame(['Sabun' => 3], TikTokIncomeN8nService::SKU_MAP['1734447451020036010']);
        $this->assertSame(['UnderArm' => 3], TikTokIncomeN8nService::SKU_MAP['1736331159132276650']);

        // SKU yang HANYA ada di versi operatif (Cache Order CSV), tak ada di SKU_MAP mati satunya.
        $this->assertSame(['Mouth Spray' => 1], TikTokIncomeN8nService::SKU_MAP['1736405844219627434']);
        $this->assertSame(['BB cream' => 1, 'Retinol' => 1], TikTokIncomeN8nService::SKU_MAP['1736470209629947818']);

        // SKU yang NILAINYA BEDA antara 2 sumber -> pakai versi operatif (Face Mist, bukan Serum wajah).
        $this->assertSame(['Face Mist' => 1], TikTokIncomeN8nService::SKU_MAP['1736405151798560682']);
    }
}
