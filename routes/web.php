<?php

use App\Http\Controllers\AccAccountController;
use App\Http\Controllers\AccountingController;
use App\Http\Controllers\AccTemplateController;
use App\Http\Controllers\AiAssistantController;
use App\Http\Controllers\AiDiscoveryController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackdatedSaleController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DownlineOrderController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HqStockReportController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\JaringanSayaController;
use App\Http\Controllers\JoinPackageController;
use App\Http\Controllers\KanbanController;
use App\Http\Controllers\KolAffiliateController;
use App\Http\Controllers\KolAgentController;
use App\Http\Controllers\KolCampaignController;
use App\Http\Controllers\KolContentController;
use App\Http\Controllers\KolController;
use App\Http\Controllers\KolDashboardController;
use App\Http\Controllers\KolDealController;
use App\Http\Controllers\KolImportController;
use App\Http\Controllers\KolPipelineController;
use App\Http\Controllers\KolReminderController;
use App\Http\Controllers\KolSampleController;
use App\Http\Controllers\KolScoringController;
use App\Http\Controllers\KolScreeningController;
use App\Http\Controllers\KolSettingsController;
use App\Http\Controllers\LearningController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\MindmapController;
use App\Http\Controllers\OkrController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PartnerHierarchyController;
use App\Http\Controllers\PartnerSaleController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\RecruitController;
use App\Http\Controllers\ReportBotAdminController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReturController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ShopeeController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\StockOpnameController;
use App\Http\Controllers\StockReceiptController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\TikTokController;
use App\Http\Controllers\TikTokIncomeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WithdrawalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest routes (authentication)
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);

    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');
});

/*
|--------------------------------------------------------------------------
| Report Bot Telegram webhook (publik — Telegram tidak login)
|--------------------------------------------------------------------------
| Keamanan dijaga oleh verifikasi X-Telegram-Bot-Api-Secret-Token di
| TelegramWebhookController, bukan oleh middleware auth. Rute ini juga
| dikecualikan dari CSRF di bootstrap/app.php.
*/
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])->name('telegram.webhook');

/*
|--------------------------------------------------------------------------
| Agen scraper KOL (Fase 3c) — app lokal setor transaksi affiliate
|--------------------------------------------------------------------------
| Auth via header X-Agent-Token (config services.kol_agent.token), bukan sesi
| web. CSRF dikecualikan di bootstrap/app.php (api/kol-agent/*).
*/
Route::post('/api/kol-agent/affiliate', [KolAgentController::class, 'affiliate'])->name('kol-agent.affiliate');

Route::get('/', fn () => redirect()->route('dashboard'));

/*
|--------------------------------------------------------------------------
| Authenticated routes (active account enforced by RoleMiddleware)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Change own password (any authenticated user)
    Route::get('/account/password', [AuthController::class, 'showChangePassword'])->name('account.password');
    Route::post('/account/password', [AuthController::class, 'changePassword']);

    // Rekening bank milik sendiri (any authenticated user)
    Route::get('/account/rekening', [AuthController::class, 'showBankAccount'])->name('account.rekening');
    Route::post('/account/rekening', [AuthController::class, 'updateBankAccount']);

    /* ---------------- Purchase Orders ---------------- */
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');

    // Create PO — gated by the configurable "create_po" capability
    Route::middleware('permission:create_po')->group(function () {
        Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('purchase-orders.create');
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
    });

    // Export SEBELUM {purchaseOrder}: tanpa ini, "export" tertelan model binding.
    Route::get('/purchase-orders/export', [ExportController::class, 'purchaseOrders'])->name('purchase-orders.export');
    // Mini-detail JSON untuk popup di daftar PO (isi item + sisa bisa retur).
    Route::get('/purchase-orders/{purchaseOrder}/quick', [PurchaseOrderController::class, 'quick'])->name('purchase-orders.quick');
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');

    // Edit isi PO (item & qty) — hanya selagi pending & belum bayar. Otorisasi
    // (owner mitra pemegang stok ATAU admin update_po_status) dicek inline di
    // controller, jadi route ini cukup auth (bukan di grup permission).
    Route::get('/purchase-orders/{purchaseOrder}/edit', [PurchaseOrderController::class, 'edit'])->name('purchase-orders.edit');
    Route::put('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->name('purchase-orders.update');

    // Buyer uploads transfer proof for their own PO
    Route::post('/purchase-orders/{purchaseOrder}/payment-proof', [PurchaseOrderController::class, 'uploadPayment'])->name('purchase-orders.payment-proof');

    Route::middleware('permission:update_po_status')->group(function () {
        // Mass approve / ubah status banyak PO sekaligus (sebelum route {purchaseOrder}).
        Route::post('/purchase-orders/bulk-status', [PurchaseOrderController::class, 'bulkStatus'])->name('purchase-orders.bulk-status');
        Route::post('/purchase-orders/{purchaseOrder}/status', [PurchaseOrderController::class, 'updateStatus'])->name('purchase-orders.status');
        Route::post('/purchase-orders/{purchaseOrder}/shipping', [PurchaseOrderController::class, 'setShipping'])->name('purchase-orders.shipping');
        Route::post('/purchase-orders/{purchaseOrder}/verify-payment', [PurchaseOrderController::class, 'verifyPayment'])->name('purchase-orders.verify-payment');
        Route::post('/purchase-orders/{purchaseOrder}/tempo', [PurchaseOrderController::class, 'setTempo'])->name('purchase-orders.tempo');
        Route::post('/purchase-orders/{purchaseOrder}/payments', [PurchaseOrderController::class, 'storePayment'])->name('purchase-orders.payments');
    });

    Route::middleware('permission:delete_po')->group(function () {
        Route::delete('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->name('purchase-orders.destroy');
        Route::delete('/purchase-orders/{purchaseOrder}/force', [PurchaseOrderController::class, 'forceDestroy'])->name('purchase-orders.force-destroy');
    });

    // Pesanan Downline (Model A) — upline (penjual di PO inter-partner) memproses
    // PO yang dia sendiri jadi penjualnya. Guard seller_id===auth di tiap aksi.
    Route::middleware('permission:process_downline_po')->group(function () {
        Route::get('/pesanan-downline', [DownlineOrderController::class, 'index'])->name('pesanan-downline.index');
        Route::get('/pesanan-downline/{purchaseOrder}', [DownlineOrderController::class, 'show'])->name('pesanan-downline.show');
        Route::post('/pesanan-downline/{purchaseOrder}/verify-payment', [DownlineOrderController::class, 'verifyPayment'])->name('pesanan-downline.verify-payment');
        Route::post('/pesanan-downline/{purchaseOrder}/fulfill', [DownlineOrderController::class, 'fulfill'])->name('pesanan-downline.fulfill');
        Route::post('/pesanan-downline/{purchaseOrder}/reject', [DownlineOrderController::class, 'reject'])->name('pesanan-downline.reject');
    });

    // Retur PO — mitra ajukan (kepemilikan PO), HQ (process_return) proses/acc.
    Route::get('/retur', [ReturController::class, 'index'])->name('retur.index');
    Route::get('/purchase-orders/{purchaseOrder}/retur', [ReturController::class, 'create'])->name('retur.create');
    Route::post('/retur', [ReturController::class, 'store'])->name('retur.store');
    Route::post('/retur/{retur}/void', [ReturController::class, 'void'])->name('retur.void'); // super_admin (cek di controller)
    Route::delete('/retur/{retur}/force', [ReturController::class, 'forceDestroy'])->name('retur.force-destroy'); // super_admin (cek di controller)
    Route::post('/join-transactions/{joinTransaction}/cancel', [ReturController::class, 'cancelJoin'])->name('join-transactions.cancel'); // manage_users (cek di controller)
    Route::middleware('permission:process_return')->group(function () {
        Route::post('/retur/{retur}/approve', [ReturController::class, 'approve'])->name('retur.approve');
        Route::post('/retur/{retur}/reject', [ReturController::class, 'reject'])->name('retur.reject');
    });

    /* ---------------- Inventory & Stock Movements ---------------- */
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('/inventory/partner-adjust', [InventoryController::class, 'adjustPartner'])->name('inventory.partner-adjust');
    Route::post('/inventory/partner-set', [InventoryController::class, 'setPartner'])->name('inventory.partner-set');
    // Penyesuaian stok multi-baris (halaman sendiri, mirip nota penjualan).
    Route::get('/inventory/adjust', [InventoryController::class, 'adjustForm'])->name('inventory.adjust');
    Route::post('/inventory/adjust', [InventoryController::class, 'adjustBulk'])->name('inventory.adjust.store');

    // Penjualan mitra ke customer akhir (barang keluar bentuk nota). Di bawah
    // menu Stok, bukan menu sidebar baru.
    Route::get('/inventory/sales', [PartnerSaleController::class, 'index'])->name('partner-sales.index');
    Route::get('/inventory/sales/export', [ExportController::class, 'partnerSales'])->name('partner-sales.export');
    Route::post('/inventory/sales', [PartnerSaleController::class, 'store'])->name('partner-sales.store');

    // "Jaringan Saya" — mitra upline pantau subtree (read-only). Gate isPartner di controller.
    Route::get('/jaringan-saya', [JaringanSayaController::class, 'index'])->name('jaringan-saya.index');
    // "Rekrutan Saya" — perekrut (sponsor/GD/distri) lihat lead + earning. Gate isPartner di controller.
    Route::get('/rekrutan-saya', [RecruitController::class, 'index'])->name('rekrutan-saya.index');
    Route::post('/inventory/minimum', [InventoryController::class, 'setMinimum'])->name('inventory.minimum');

    // "Saldo Komisi" — mitra lihat saldo tersedia + ajukan penarikan. Gate isPartner di controller.
    Route::get('/komisi-saya', [CommissionController::class, 'index'])->name('commissions.index');
    Route::post('/komisi-saya/tarik', [CommissionController::class, 'withdraw'])->name('commissions.withdraw');
    Route::post('/komisi-saya/tarik/{withdrawal}/batal', [CommissionController::class, 'cancel'])->name('commissions.withdraw-cancel');

    // "Penarikan" — HQ proses antrean penarikan komisi mitra (setujui/tolak/cairkan).
    Route::middleware('permission:process_withdrawal')->group(function () {
        Route::get('/penarikan', [WithdrawalController::class, 'index'])->name('withdrawals.index');
        Route::post('/penarikan/{withdrawal}/proses', [WithdrawalController::class, 'process'])->name('withdrawals.process');
    });

    Route::middleware('permission:manage_hq_stock')->group(function () {
        Route::post('/inventory/hq-adjust', [InventoryController::class, 'adjustHq'])->name('inventory.hq-adjust');
        Route::get('/stock-movements', [StockMovementController::class, 'index'])->name('stock-movements.index');

        // Stok Opname (set saldo awal) + Laporan Mutasi Stok HQ
        Route::get('/stok-opname', [StockOpnameController::class, 'index'])->name('stok-opname.index');
        Route::post('/stok-opname', [StockOpnameController::class, 'store'])->name('stok-opname.store');
        Route::get('/laporan-stok-hq', [HqStockReportController::class, 'index'])->name('hq-stock.report');
        Route::get('/laporan-stok-hq/export', [ExportController::class, 'stokHq'])->name('hq-stock.export');

        // Catat penjualan distributor yang sudah terjadi (back-date, dari Excel)
        Route::get('/penjualan-backdate', [BackdatedSaleController::class, 'index'])->name('backdated-sales.index');
        Route::post('/penjualan-backdate', [BackdatedSaleController::class, 'store'])->name('backdated-sales.store');
        Route::post('/penjualan-backdate/batas', [BackdatedSaleController::class, 'setCutoff'])->name('backdated-sales.cutoff');
        Route::patch('/penjualan-backdate/{purchaseOrder}/tanggal', [BackdatedSaleController::class, 'updateDate'])->name('backdated-sales.date');
    });

    /* ---------------- Stock receipts (incoming stock + HPP average) ---------------- */
    Route::middleware('permission:receive_stock')->group(function () {
        Route::get('/stock-receipts', [StockReceiptController::class, 'index'])->name('stock-receipts.index');
        Route::get('/stock-receipts/create', [StockReceiptController::class, 'create'])->name('stock-receipts.create');
        Route::post('/stock-receipts', [StockReceiptController::class, 'store'])->name('stock-receipts.store');
        Route::get('/stock-receipts/{stockReceipt}', [StockReceiptController::class, 'show'])->name('stock-receipts.show');
    });

    /* ---------------- Materials & Production (HPP produksi) ---------------- */
    Route::middleware('permission:manage_production')->group(function () {
        // Raw materials master + purchases
        Route::get('/materials', [MaterialController::class, 'index'])->name('materials.index');
        Route::post('/materials', [MaterialController::class, 'store'])->name('materials.store');
        Route::post('/materials/quick', [MaterialController::class, 'quickStore'])->name('materials.quick');
        Route::put('/materials/{material}', [MaterialController::class, 'update'])->name('materials.update');
        Route::delete('/materials/{material}', [MaterialController::class, 'destroy'])->name('materials.destroy');
        Route::post('/materials/purchase', [MaterialController::class, 'purchase'])->name('materials.purchase');

        // Supplier master
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');

        // Production batches
        Route::get('/productions', [ProductionController::class, 'index'])->name('productions.index');
        Route::get('/productions/create', [ProductionController::class, 'create'])->name('productions.create');
        Route::post('/productions', [ProductionController::class, 'store'])->name('productions.store');
        Route::get('/productions/{production}', [ProductionController::class, 'show'])->name('productions.show');

        // Per-product HPP history (cost trend over time)
        Route::get('/products/{product}/hpp', [ProductionController::class, 'hppHistory'])->name('products.hpp-history');
    });

    /* ---------------- Reports ---------------- */
    Route::middleware('permission:view_reports')->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/penjualan-downline', [ReportController::class, 'downlineSales'])->name('reports.downline-sales'); // mitra stockist (cek di controller)
        Route::get('/reports/omzet-mitra', [ReportController::class, 'omzetMitra'])->name('reports.omzet-mitra');
        Route::get('/reports/export', [ExportController::class, 'penjualan'])->name('reports.export');
        Route::get('/reports/chart-data', [ReportController::class, 'chartData'])->name('reports.chart-data');
    });

    // Laporan Komisi: izin sendiri (view_commission_report), TERPISAH dari
    // view_reports — data payout mitra lebih sensitif daripada laporan penjualan.
    Route::middleware('permission:view_commission_report')->group(function () {
        Route::get('/reports/komisi', [ReportController::class, 'komisi'])->name('reports.komisi');
        Route::get('/reports/komisi/{mitra}', [ReportController::class, 'komisiDetail'])->name('reports.komisi-detail');
    });

    /* ---------------- Accounting (laporan keuangan) ---------------- */
    Route::middleware('permission:view_accounting')->group(function () {
        Route::get('/accounting', fn () => redirect()->route('accounting.report'))->name('accounting.index');
        Route::get('/accounting/laporan', [AccountingController::class, 'report'])->name('accounting.report');
        Route::get('/accounting/laba-rugi', [AccountingController::class, 'incomeStatement'])->name('accounting.income-statement');
        Route::get('/accounting/neraca', [AccountingController::class, 'balanceSheet'])->name('accounting.balance-sheet');
        Route::get('/accounting/arus-kas', [AccountingController::class, 'cashFlow'])->name('accounting.cash-flow');
        Route::get('/accounting/banding', [AccountingController::class, 'comparison'])->name('accounting.comparison');
        Route::get('/accounting/tren', [AccountingController::class, 'trend'])->name('accounting.trend');
        Route::get('/accounting/neraca-saldo', [AccountingController::class, 'trialBalance'])->name('accounting.trial-balance');

        // Jurnal Umum (input manual)
        Route::get('/accounting/jurnal', [AccountingController::class, 'journals'])->name('accounting.journals');
        Route::get('/accounting/jurnal/baru', [AccountingController::class, 'journalCreate'])->name('accounting.journals.create');
        Route::post('/accounting/jurnal', [AccountingController::class, 'journalStore'])->name('accounting.journals.store');
        Route::post('/accounting/jurnal/{journal}/void', [AccountingController::class, 'journalVoid'])->name('accounting.journals.void');
        Route::delete('/accounting/jurnal/{journal}', [AccountingController::class, 'journalDestroy'])
            ->middleware('permission:delete_accounting')->name('accounting.journals.destroy');

        // Impor Mutasi Bank
        Route::get('/accounting/impor', [AccountingController::class, 'importForm'])->name('accounting.import');
        Route::post('/accounting/impor', [AccountingController::class, 'importStore'])->name('accounting.import.store');
        Route::post('/accounting/impor/cek', [AccountingController::class, 'importCheck'])->name('accounting.import.check');

        // Impor Jurnal dari Excel (.xlsx)
        Route::get('/accounting/impor-excel', [AccountingController::class, 'excelImportForm'])->name('accounting.excel-import');
        Route::post('/accounting/impor-excel', [AccountingController::class, 'excelImportStore'])->name('accounting.excel-import.store');
        Route::post('/accounting/impor-excel/hapus', [AccountingController::class, 'excelImportPurge'])
            ->middleware('permission:delete_accounting')->name('accounting.excel-import.purge');

        // Master COA (Data COA)
        Route::get('/accounting/coa', [AccAccountController::class, 'index'])->name('accounting.accounts');
        Route::post('/accounting/coa', [AccAccountController::class, 'store'])->name('accounting.accounts.store');
        Route::put('/accounting/coa/{account}', [AccAccountController::class, 'update'])->name('accounting.accounts.update');
        Route::delete('/accounting/coa/{account}', [AccAccountController::class, 'destroy'])
            ->middleware('permission:delete_accounting')->name('accounting.accounts.destroy');

        // Template Transaksi (preset jurnal)
        Route::get('/accounting/template', [AccTemplateController::class, 'index'])->name('accounting.templates');
        Route::post('/accounting/template', [AccTemplateController::class, 'store'])->name('accounting.templates.store');
        Route::put('/accounting/template/{template}', [AccTemplateController::class, 'update'])->name('accounting.templates.update');
        Route::delete('/accounting/template/{template}', [AccTemplateController::class, 'destroy'])
            ->middleware('permission:delete_accounting')->name('accounting.templates.destroy');
    });

    /* ---------------- Modul KOL (Fase 1: kurasi & deal) ---------------- */
    // Seluruh modul di balik kol.view — mitra/afiliator tak melihat apa pun.
    Route::middleware('permission:kol.view')->group(function () {
        Route::get('/kols', [KolController::class, 'index'])->name('kols.index');
        // Ekspor "Listing KOL" (satu baris per screening, format Excel) — arsip
        // riwayat per-bulan. DIPARKIR: tombolnya dilepas dari Database KOL (redundan
        // saat tiap KOL baru 1 screening); endpoint dipertahankan agar mudah
        // dihidupkan lagi bila kelak butuh ekspor histori bulanan lintas-KOL.
        Route::get('/kols/listing/export', [ExportController::class, 'listingKol'])->name('kols.listing.export');
        Route::get('/kols/export', [ExportController::class, 'databaseKol'])->name('kols.export');
        Route::get('/kols/{kol}', [KolController::class, 'show'])->whereNumber('kol')->name('kols.show');

        Route::middleware('permission:kol.screening.manage')->group(function () {
            Route::post('/kols', [KolController::class, 'store'])->name('kols.store');
            Route::put('/kols/{kol}', [KolController::class, 'update'])->name('kols.update');
            Route::delete('/kols/{kol}', [KolController::class, 'destroy'])->name('kols.destroy');
            Route::post('/kols/{kol}/contact-log', [KolController::class, 'contactLogStore'])->name('kols.contact-log.store');
            Route::delete('/kol-contact-logs/{log}', [KolController::class, 'contactLogDestroy'])->name('kols.contact-log.destroy');
            Route::post('/kols/{kol}/accounts', [KolController::class, 'accountStore'])->name('kols.accounts.store');
            Route::delete('/kol-accounts/{account}', [KolController::class, 'accountDestroy'])->name('kols.accounts.destroy');
            Route::post('/kols/{kol}/rate-cards', [KolController::class, 'rateCardStore'])->name('kols.rate-cards.store');
            Route::delete('/kol-rate-cards/{rateCard}', [KolController::class, 'rateCardDestroy'])->name('kols.rate-cards.destroy');
            Route::get('/kol-screenings/create', [KolScreeningController::class, 'create'])->name('kol-screenings.create');
            Route::post('/kol-screenings', [KolScreeningController::class, 'store'])->name('kol-screenings.store');
            Route::patch('/kol-screenings/{screening}/ratecard', [KolScreeningController::class, 'updateRatecard'])->name('kol-screenings.ratecard');
            Route::get('/kol-screenings/{screening}/edit', [KolScreeningController::class, 'edit'])->name('kol-screenings.edit');
            Route::put('/kol-screenings/{screening}', [KolScreeningController::class, 'update'])->name('kol-screenings.update');

            // Impor massal KOL dari template (.xlsx/.csv) — dua tahap preview→commit.
            Route::get('/kols-import', [KolImportController::class, 'form'])->name('kols.import');
            Route::get('/kols-import/template', [KolImportController::class, 'template'])->name('kols.import.template');
            Route::post('/kols-import/preview', [KolImportController::class, 'preview'])->name('kols.import.preview');
            Route::post('/kols-import/commit', [KolImportController::class, 'commit'])->name('kols.import.commit');
        });

        // Dashboard KOL — ringkasan 1-layar (merangkai service yang ada).
        Route::get('/kol-dashboard', [KolDashboardController::class, 'index'])->name('kol-dashboard.index');

        // Fase 1 sub-menu KOL: pipeline scouting (spec 2026-08-27). Baca di balik
        // kol.view; tulis di balik kol.pipeline.manage. Hapus = super_admin (controller).
        Route::get('/kol-pipeline', [KolPipelineController::class, 'index'])->name('kol-pipeline.index');
        Route::get('/kol-pipeline/{card}', [KolPipelineController::class, 'show'])->name('kol-pipeline.show');
        Route::middleware('permission:kol.pipeline.manage')->group(function () {
            Route::post('/kol-pipeline', [KolPipelineController::class, 'store'])->name('kol-pipeline.store');
            Route::patch('/kol-pipeline/{card}/stage', [KolPipelineController::class, 'moveStage'])->name('kol-pipeline.stage');
            Route::patch('/kol-pipeline/{card}/next-action', [KolPipelineController::class, 'nextAction'])->name('kol-pipeline.next-action');
            Route::post('/kol-pipeline/{card}/follow-up', [KolPipelineController::class, 'followUp'])->name('kol-pipeline.follow-up');
            Route::patch('/kol-pipeline/{card}', [KolPipelineController::class, 'update'])->name('kol-pipeline.update');
        });
        Route::delete('/kol-pipeline/{card}', [KolPipelineController::class, 'destroy'])->name('kol-pipeline.destroy');

        // Reminder KOL — agregat pipeline (baca-saja).
        Route::get('/kol-reminder', [KolReminderController::class, 'index'])->name('kol-reminder.index');

        // Konten & Views KOL — arsip konten + snapshot views bertanggal.
        Route::get('/kol-konten', [KolContentController::class, 'index'])->name('kol-konten.index');
        Route::middleware('permission:kol.content.manage')->group(function () {
            Route::get('/kol-konten/create', [KolContentController::class, 'create'])->name('kol-konten.create');
            Route::post('/kol-konten', [KolContentController::class, 'store'])->name('kol-konten.store');
            Route::post('/kol-konten/oembed', [KolContentController::class, 'oembed'])->name('kol-konten.oembed');
            Route::post('/kol-konten/target', [KolContentController::class, 'updateTarget'])->name('kol-konten.target');
            // 'grid' sebelum {content} agar tak tertangkap model binding.
            Route::get('/kol-konten/grid', [KolContentController::class, 'grid'])->name('kol-konten.grid');
            Route::post('/kol-konten/grid', [KolContentController::class, 'gridSave'])->name('kol-konten.grid.save');
            Route::post('/kol-konten/{content}/snapshots', [KolContentController::class, 'snapshotStore'])->name('kol-konten.snapshot.store');
            Route::delete('/kol-content-snapshots/{snapshot}', [KolContentController::class, 'snapshotDestroy'])->name('kol-konten.snapshot.destroy');
            Route::get('/kol-konten/{content}/edit', [KolContentController::class, 'edit'])->name('kol-konten.edit');
            Route::put('/kol-konten/{content}', [KolContentController::class, 'update'])->name('kol-konten.update');
            Route::delete('/kol-konten/{content}', [KolContentController::class, 'destroy'])->name('kol-konten.destroy');
        });
        // Detail konten (view-level) — DIDAFTAR SETELAH create/grid agar tak menangkapnya.
        Route::get('/kol-konten/{content}', [KolContentController::class, 'show'])->name('kol-konten.show');

        // Affiliate & GMV (Fase 3a) — angka uang di balik kol.affiliate.view.
        Route::middleware('permission:kol.affiliate.view')->group(function () {
            Route::get('/kol-affiliate', [KolAffiliateController::class, 'index'])->name('kol-affiliate.index');
            Route::get('/kol-affiliate/transaksi', [KolAffiliateController::class, 'transactions'])->name('kol-affiliate.transactions');
            Route::middleware('permission:kol.affiliate.manage')->group(function () {
                Route::post('/kol-affiliate/match', [KolAffiliateController::class, 'match'])->name('kol-affiliate.match');
                Route::post('/kol-affiliate/promote', [KolAffiliateController::class, 'promote'])->name('kol-affiliate.promote');
                Route::get('/kol-affiliate/import', [KolAffiliateController::class, 'importForm'])->name('kol-affiliate.import');
                Route::post('/kol-affiliate/import', [KolAffiliateController::class, 'importStore'])->name('kol-affiliate.import.store');
                // Wizard pemetaan kolom (preview → commit) — mapping tersimpan + dateOrder.
                Route::post('/kol-affiliate/import/preview', [KolAffiliateController::class, 'importPreview'])->name('kol-affiliate.import.preview');
                Route::post('/kol-affiliate/import/commit', [KolAffiliateController::class, 'importCommit'])->name('kol-affiliate.import.commit');
                Route::post('/kol-affiliate/gmv-target', [KolAffiliateController::class, 'saveGmvTarget'])->name('kol-affiliate.gmv-target');
                Route::post('/kol-affiliate/weekly-stats', [KolAffiliateController::class, 'weeklyStatStore'])->name('kol-affiliate.weekly.store');
                Route::delete('/kol-affiliate/weekly-stats/{stat}', [KolAffiliateController::class, 'weeklyStatDestroy'])->name('kol-affiliate.weekly.destroy');
            });
        });

        // Skor — kalkulator KSS (Fase 3b). Kalkulator murni, gated kol.view.
        Route::match(['get', 'post'], '/kol-skor/kss', [KolScoringController::class, 'kss'])->name('kol-skor.kss');
        Route::post('/kol-skor/aps-snapshot', [KolScoringController::class, 'snapshotAps'])->name('kol-skor.aps-snapshot');

        // Setelan KOL terpusat (angka acuan + override target per-bulan) — finance-only.
        Route::middleware('permission:kol.deal.finance')->group(function () {
            Route::get('/kol-settings', [KolSettingsController::class, 'index'])->name('kol-settings.index');
            Route::post('/kol-settings', [KolSettingsController::class, 'save'])->name('kol-settings.save');
            Route::post('/kol-settings/monthly', [KolSettingsController::class, 'monthlyStore'])->name('kol-settings.monthly.store');
            Route::delete('/kol-settings/monthly/{target}', [KolSettingsController::class, 'monthlyDestroy'])->name('kol-settings.monthly.destroy');
        });
    });

    // Deal KOL: gated kol.deal.manage SAJA (bukan kol.view) — penyetuju (admin)
    // perlu akses ini tanpa harus lihat database kurasi KOL. Acc/Tolak dijaga
    // lagi oleh kol.deal.approve di controller (pengaju != penyetuju).
    Route::middleware('permission:kol.deal.manage')->group(function () {
        Route::get('/kol-deals', [KolDealController::class, 'index'])->name('kol-deals.index');
        Route::get('/kol-deals/laporan', [KolDealController::class, 'laporan'])->name('kol-deals.laporan');
        Route::get('/kol-deals/create', [KolDealController::class, 'create'])->name('kol-deals.create');
        Route::post('/kol-deals', [KolDealController::class, 'store'])->name('kol-deals.store');
        Route::post('/kol-deals/bulk-status', [KolDealController::class, 'bulkStatus'])->name('kol-deals.bulk-status');
        // Budget bulanan + CPM anchor (Fase 2) — finance-sensitive.
        Route::post('/kol-deals/budget', [KolDealController::class, 'saveBudget'])->middleware('permission:kol.deal.finance')->name('kol-deals.budget');
        // Pengeluaran budget tambahan (boost/hadiah/dll) — finance-sensitive.
        Route::middleware('permission:kol.deal.finance')->group(function () {
            Route::post('/kol-deals/budget-tx', [KolDealController::class, 'budgetTxStore'])->name('kol-deals.budget-tx.store');
            Route::delete('/kol-deals/budget-tx/{tx}', [KolDealController::class, 'budgetTxDestroy'])->name('kol-deals.budget-tx.destroy');
        });
        Route::get('/kol-deals/{deal}', [KolDealController::class, 'show'])->name('kol-deals.show');
        Route::get('/kol-deals/{deal}/edit', [KolDealController::class, 'edit'])->name('kol-deals.edit');
        Route::put('/kol-deals/{deal}', [KolDealController::class, 'update'])->name('kol-deals.update');
        Route::post('/kol-deals/{deal}/hasil', [KolDealController::class, 'saveHasil'])->name('kol-deals.hasil');
        Route::delete('/kol-deals/{deal}', [KolDealController::class, 'destroy'])->name('kol-deals.destroy');
        // Campaign KOL (payung beberapa deal).
        Route::get('/kol-campaigns', [KolCampaignController::class, 'index'])->name('kol-campaigns.index');
        Route::post('/kol-campaigns', [KolCampaignController::class, 'store'])->name('kol-campaigns.store');
        Route::patch('/kol-campaigns/{campaign}', [KolCampaignController::class, 'update'])->name('kol-campaigns.update');
        Route::delete('/kol-campaigns/{campaign}', [KolCampaignController::class, 'destroy'])->name('kol-campaigns.destroy');
        // Sampel produk per-deal.
        Route::post('/kol-deals/{deal}/samples', [KolSampleController::class, 'store'])->name('kol-samples.store');
        Route::patch('/kol-samples/{sample}', [KolSampleController::class, 'updateStatus'])->name('kol-samples.status');
        Route::delete('/kol-samples/{sample}', [KolSampleController::class, 'destroy'])->name('kol-samples.destroy');
    });

    /* ---------------- Kanban (papan tugas tim ala Trello) ---------------- */
    // 'internal' = blokir keras mitra, DI ATAS permission: kanban.view yang
    // keliru tercentang untuk role mitra di matriks tetap tak membuka apa pun.
    Route::middleware(['permission:kanban.view', 'internal'])->group(function () {
        Route::get('/kanban', [KanbanController::class, 'index'])->name('kanban.index');
        Route::post('/kanban', [KanbanController::class, 'store'])->name('kanban.store');
        Route::get('/kanban/{board}', [KanbanController::class, 'show'])->name('kanban.show');
        Route::put('/kanban/{board}', [KanbanController::class, 'update'])->name('kanban.update');
        Route::delete('/kanban/{board}', [KanbanController::class, 'destroy'])->name('kanban.destroy');
        Route::post('/kanban/{board}/columns', [KanbanController::class, 'storeColumn'])->name('kanban.columns.store');
        Route::post('/kanban/{board}/columns/reorder', [KanbanController::class, 'reorderColumns'])->name('kanban.columns.reorder');
        Route::put('/kanban-columns/{column}', [KanbanController::class, 'updateColumn'])->name('kanban.columns.update');
        Route::delete('/kanban-columns/{column}', [KanbanController::class, 'destroyColumn'])->name('kanban.columns.destroy');
        Route::post('/kanban-columns/{column}/cards', [KanbanController::class, 'storeCard'])->name('kanban.cards.store');
        Route::put('/kanban-cards/{card}', [KanbanController::class, 'updateCard'])->name('kanban.cards.update');
        Route::delete('/kanban-cards/{card}', [KanbanController::class, 'destroyCard'])->name('kanban.cards.destroy');
        Route::post('/kanban-cards/{card}/move', [KanbanController::class, 'moveCard'])->name('kanban.cards.move');
        Route::post('/kanban-cards/{card}/comments', [KanbanController::class, 'storeComment'])->name('kanban.comments.store');
        Route::delete('/kanban-comments/{comment}', [KanbanController::class, 'destroyComment'])->name('kanban.comments.destroy');
        Route::post('/kanban-cards/{card}/attachments', [KanbanController::class, 'storeAttachment'])->name('kanban.cards.attachments.store');
        Route::delete('/kanban-attachments/{file}', [KanbanController::class, 'destroyAttachment'])->name('kanban.attachments.destroy');
    });

    /* ---------------- Mindmaps (kanvas ide/diagram internal) ---------------- */
    // 'internal' blokir mitra KERAS di atas permission:mindmap.view. Akses
    // per-papan (owner/anggota) dicek di controller, bukan di route.
    Route::middleware(['permission:mindmap.view', 'internal'])->group(function () {
        Route::get('/mindmaps', [MindmapController::class, 'index'])->name('mindmaps.index');
        Route::post('/mindmaps', [MindmapController::class, 'store'])->name('mindmaps.store');
        Route::get('/mindmaps/{mindmap}', [MindmapController::class, 'show'])->name('mindmaps.show');
        Route::patch('/mindmaps/{mindmap}', [MindmapController::class, 'update'])->name('mindmaps.update');
        Route::delete('/mindmaps/{mindmap}', [MindmapController::class, 'destroy'])->name('mindmaps.destroy');
        Route::post('/mindmaps/{mindmap}/members', [MindmapController::class, 'addMember'])->name('mindmaps.members.store');
        Route::delete('/mindmaps/{mindmap}/members/{user}', [MindmapController::class, 'removeMember'])->name('mindmaps.members.destroy');

        // Kanvas: state (poll) + node CRUD (JSON, auto-save per elemen).
        Route::get('/mindmaps/{mindmap}/state', [MindmapController::class, 'state'])->name('mindmaps.state');
        Route::post('/mindmaps/{mindmap}/nodes', [MindmapController::class, 'storeNode'])->name('mindmaps.nodes.store');
        Route::patch('/mindmaps/{mindmap}/nodes/{node}', [MindmapController::class, 'updateNode'])->name('mindmaps.nodes.update');
        Route::delete('/mindmaps/{mindmap}/nodes/{node}', [MindmapController::class, 'destroyNode'])->name('mindmaps.nodes.destroy');
        Route::post('/mindmaps/{mindmap}/edges', [MindmapController::class, 'storeEdge'])->name('mindmaps.edges.store');
        Route::patch('/mindmaps/{mindmap}/edges/{edge}', [MindmapController::class, 'updateEdge'])->name('mindmaps.edges.update');
        Route::delete('/mindmaps/{mindmap}/edges/{edge}', [MindmapController::class, 'destroyEdge'])->name('mindmaps.edges.destroy');
    });

    /* ---------------- OKR (draf AI -> persetujuan -> kartu Kanban) ---------------- */
    Route::middleware(['permission:okr.view', 'internal'])->group(function () {
        Route::get('/okr', [OkrController::class, 'index'])->name('okr.index');
        Route::get('/okr/{okr}/status', [OkrController::class, 'generationStatus'])->name('okr.status');
        Route::get('/okr/{okr}', [OkrController::class, 'show'])->name('okr.show');

        Route::middleware('permission:okr.manage')->group(function () {
            Route::get('/okr-baru', [OkrController::class, 'create'])->name('okr.create');
            Route::post('/okr/generate', [OkrController::class, 'generate'])->name('okr.generate');
            Route::put('/okr/{okr}', [OkrController::class, 'update'])->name('okr.update');
            Route::post('/okr/{okr}/approve', [OkrController::class, 'approve'])->name('okr.approve');
            Route::delete('/okr/{okr}', [OkrController::class, 'destroy'])->name('okr.destroy');
        });
    });

    /* ---------------- Integrasi TikTok Shop ---------------- */
    Route::middleware('permission:manage_tiktok')->group(function () {
        Route::get('/tiktok', [TikTokController::class, 'index'])->name('tiktok.index');
        Route::get('/tiktok/connect', [TikTokController::class, 'connect'])->name('tiktok.connect');
        Route::get('/tiktok/callback', [TikTokController::class, 'callback'])->name('tiktok.callback');
        Route::post('/tiktok/sync-orders', [TikTokController::class, 'syncOrders'])->name('tiktok.sync-orders');
        Route::get('/tiktok/orders', [TikTokController::class, 'orderList'])->name('tiktok.orders');
        Route::get('/tiktok/stok', [TikTokController::class, 'stockFunnel'])->name('tiktok.stock');

        // Laporan Income TikTok (Fase 1: upload CSV pesanan + xlsx income → gabung).
        Route::get('/tiktok/income', [TikTokIncomeController::class, 'form'])->name('tiktok.income');
        Route::post('/tiktok/income', [TikTokIncomeController::class, 'process'])->name('tiktok.income.process');
        Route::get('/tiktok/income/unduh', [TikTokIncomeController::class, 'download'])->name('tiktok.income.download');
        Route::post('/tiktok/income/reset', [TikTokIncomeController::class, 'reset'])->name('tiktok.income.reset');
        Route::post('/tiktok/sku-map', [TikTokController::class, 'saveSkuMap'])->name('tiktok.sku-map');
        Route::delete('/tiktok/sku-map/{map}', [TikTokController::class, 'removeSkuMap'])->name('tiktok.sku-map.remove');
        Route::post('/tiktok/orders/{order}/deduct', [TikTokController::class, 'deductStock'])->name('tiktok.deduct');
        Route::post('/tiktok/deduct-all', [TikTokController::class, 'deductAll'])->name('tiktok.deduct-all');
        Route::post('/tiktok/toggle-auto', [TikTokController::class, 'toggleAuto'])->name('tiktok.toggle-auto');
        Route::post('/tiktok/deduct-from', [TikTokController::class, 'setDeductFrom'])->name('tiktok.deduct-from');
        // Retur
        Route::get('/tiktok/returns', [TikTokController::class, 'returnList'])->name('tiktok.returns');
        Route::post('/tiktok/returns/sync', [TikTokController::class, 'syncReturns'])->name('tiktok.returns.sync');
        Route::post('/tiktok/returns/{ret}/restock', [TikTokController::class, 'restockReturn'])->name('tiktok.returns.restock');
        Route::post('/tiktok/returns/{ret}/reject', [TikTokController::class, 'rejectReturn'])->name('tiktok.returns.reject');
        Route::post('/tiktok/returns/{ret}/reset', [TikTokController::class, 'resetReturn'])->name('tiktok.returns.reset');
        Route::post('/tiktok/orders/{order}/reverse', [TikTokController::class, 'reverseStock'])->name('tiktok.reverse');
        // Dana cair / settlement (M3)
        Route::get('/tiktok/settlements', [TikTokController::class, 'settlementList'])->name('tiktok.settlements');
        Route::post('/tiktok/settlements/sync', [TikTokController::class, 'syncSettlements'])->name('tiktok.settlements.sync');
        Route::post('/tiktok/settlements/describe', [TikTokController::class, 'describeSettlements'])->name('tiktok.settlements.describe');
        Route::post('/tiktok/post-journals', [TikTokController::class, 'postJournals'])->name('tiktok.post-journals');
        Route::post('/tiktok/unpost-journals', [TikTokController::class, 'unpostJournals'])->name('tiktok.unpost-journals');
        Route::post('/tiktok/toggle-journal', [TikTokController::class, 'toggleJournal'])->name('tiktok.toggle-journal');
        Route::get('/tiktok/settlements/{settlement}/detail', [TikTokController::class, 'settlementDetail'])->name('tiktok.settlements.detail');
        Route::delete('/tiktok/disconnect', [TikTokController::class, 'disconnect'])->name('tiktok.disconnect');
    });

    Route::middleware('permission:manage_shopee')->group(function () {
        Route::get('/shopee', [ShopeeController::class, 'index'])->name('shopee.index');
        Route::get('/shopee/connect', [ShopeeController::class, 'connect'])->name('shopee.connect');
        Route::get('/shopee/callback', [ShopeeController::class, 'callback'])->name('shopee.callback');
        Route::post('/shopee/sync-orders', [ShopeeController::class, 'syncOrders'])->name('shopee.sync-orders');
        Route::get('/shopee/orders', [ShopeeController::class, 'orderList'])->name('shopee.orders');
        Route::get('/shopee/stok', [ShopeeController::class, 'stockFunnel'])->name('shopee.stock');
        Route::post('/shopee/sku-map', [ShopeeController::class, 'saveSkuMap'])->name('shopee.sku-map');
        Route::delete('/shopee/sku-map/{map}', [ShopeeController::class, 'removeSkuMap'])->name('shopee.sku-map.remove');
        Route::post('/shopee/orders/{order}/deduct', [ShopeeController::class, 'deductStock'])->name('shopee.deduct');
        Route::post('/shopee/deduct-all', [ShopeeController::class, 'deductAll'])->name('shopee.deduct-all');
        Route::post('/shopee/settings', [ShopeeController::class, 'settings'])->name('shopee.settings');
        Route::get('/shopee/returns', [ShopeeController::class, 'returnList'])->name('shopee.returns');
        Route::post('/shopee/returns/sync', [ShopeeController::class, 'syncReturns'])->name('shopee.returns.sync');
        Route::post('/shopee/returns/{ret}/restock', [ShopeeController::class, 'restockReturn'])->name('shopee.returns.restock');
        Route::post('/shopee/returns/{ret}/reject', [ShopeeController::class, 'rejectReturn'])->name('shopee.returns.reject');
        Route::post('/shopee/returns/{ret}/reset', [ShopeeController::class, 'resetReturn'])->name('shopee.returns.reset');
        Route::get('/shopee/settlements', [ShopeeController::class, 'settlementList'])->name('shopee.settlements');
        Route::post('/shopee/settlements/sync', [ShopeeController::class, 'syncSettlements'])->name('shopee.settlements.sync');
        Route::get('/shopee/settlements/{settlement}/detail', [ShopeeController::class, 'settlementDetail'])->name('shopee.settlements.detail');
        Route::post('/shopee/post-journals', [ShopeeController::class, 'postJournals'])->name('shopee.post-journals');
        Route::post('/shopee/unpost-journals', [ShopeeController::class, 'unpostJournals'])->name('shopee.unpost-journals');
        Route::post('/shopee/toggle-journal', [ShopeeController::class, 'toggleJournal'])->name('shopee.toggle-journal');
    });

    /* ---------------- Product management ---------------- */
    Route::middleware('permission:manage_products')->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
    });

    /* ---------------- Katalog Paket Join (Onboarding) ---------------- */
    Route::middleware('permission:manage_join_packages')->group(function () {
        Route::resource('join-packages', JoinPackageController::class)->except('show');
    });

    /* ---------------- User management ---------------- */
    Route::middleware('permission:manage_users')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');

        Route::get('/struktur-jaringan', [PartnerHierarchyController::class, 'index'])->name('struktur-jaringan.index');
        Route::post('/struktur-jaringan/{user}/place', [PartnerHierarchyController::class, 'place'])->name('struktur-jaringan.place');
        Route::post('/struktur-jaringan/{user}/tier', [PartnerHierarchyController::class, 'changeTier'])->name('struktur-jaringan.tier');

        Route::get('/onboarding', [OnboardingController::class, 'create'])->name('onboarding.create');
        Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
    });

    Route::middleware('permission:delete_users')->group(function () {
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    /* ---------------- Masuk sebagai (impersonation) ---------------- */
    // Mulai: hanya super admin (dijaga lagi di ImpersonationService).
    Route::middleware('role:super_admin')->group(function () {
        Route::post('/users/{user}/impersonate', [ImpersonationController::class, 'start'])->name('users.impersonate');
    });

    // Berhenti: SENGAJA di luar semua gerbang peran/permission. Yang memanggilnya
    // adalah pengguna yang sedang disamari (mis. reseller) — menaruhnya di dalam
    // gerbang super admin akan menjebak admin di akun orang tanpa jalan pulang.
    // Pengamannya bukan peran, tapi kunci sesi yang hanya bisa dipasang start().
    Route::post('/impersonate/stop', [ImpersonationController::class, 'stop'])->name('impersonate.stop');

    Route::middleware('permission:view_audit_log')->group(function () {
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    });

    Route::middleware('permission:system_settings')->group(function () {
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::post('/settings/ai', [SettingController::class, 'saveAi'])->name('settings.ai.save');
        Route::post('/settings/komisi', [SettingController::class, 'saveKomisi'])->name('settings.komisi.save');
        Route::post('/settings/volume-tier', [SettingController::class, 'storeVolumeTier'])->name('settings.volume-tier.store');
        Route::delete('/settings/volume-tier/{tier}', [SettingController::class, 'destroyVolumeTier'])->name('settings.volume-tier.destroy');
        // Backup DB: jalankan manual + unduh (simpan di LUAR server).
        Route::post('/settings/backup', [SettingController::class, 'backupNow'])->name('settings.backup');
        Route::get('/settings/backup/{file}', [SettingController::class, 'backupDownload'])->name('settings.backup.download');

        // Report Bot Telegram: rotasi kode akses global + cabut akses per-chat.
        Route::post('/settings/report-bot/rotate', [ReportBotAdminController::class, 'rotate'])->name('report-bot.rotate');
        Route::post('/settings/report-bot/chats/{chat}/revoke', [ReportBotAdminController::class, 'revokeChat'])->name('report-bot.chat.revoke');
        // Peta SKU parser (SKU ID → kategori × qty) — editable tanpa deploy.
        Route::get('/settings/report-bot/sku-map', [ReportBotAdminController::class, 'skuMap'])->name('report-bot.sku-map');
        Route::post('/settings/report-bot/sku-map', [ReportBotAdminController::class, 'skuStore'])->name('report-bot.sku-map.store');
        Route::delete('/settings/report-bot/sku-map/{map}', [ReportBotAdminController::class, 'skuDestroy'])->name('report-bot.sku-map.destroy');
    });

    // Pengumuman dashboard per role (box catatan + popup banner).
    Route::middleware('permission:manage_announcements')->group(function () {
        Route::get('/pengumuman', [AnnouncementController::class, 'manage'])->name('announcements.manage');
        Route::post('/pengumuman', [AnnouncementController::class, 'save'])->name('announcements.save');
        Route::delete('/pengumuman/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');
        // Komunitas WA per role (panel di halaman Pengumuman yang sama).
        Route::post('/pengumuman/komunitas', [AnnouncementController::class, 'saveCommunity'])->name('announcements.community.save');
    });

    // Asisten AI (chat + konfirmasi aksi tulis). Lihat AI_ASSISTANT_SPEC.md.
    Route::middleware('permission:use_ai_assistant')->group(function () {
        Route::get('/asisten', [AiAssistantController::class, 'index'])->name('ai.index');
        Route::get('/asisten/state', [AiAssistantController::class, 'state'])->name('ai.state');
        Route::post('/asisten/kirim', [AiAssistantController::class, 'send'])->name('ai.send');
        Route::post('/asisten/konfirmasi', [AiAssistantController::class, 'confirm'])->name('ai.confirm');
        Route::post('/asisten/reset', [AiAssistantController::class, 'reset'])->name('ai.reset');

        // "Pengetahuan AI" (memori/strategi internal) — EDIT hanya staf internal.
        // 'internal' memblokir mitra walau punya use_ai_assistant.
        Route::middleware('internal')->group(function () {
            Route::get('/asisten/pengetahuan', [AiAssistantController::class, 'knowledge'])->name('ai.knowledge');
            Route::post('/asisten/pengetahuan', [AiAssistantController::class, 'saveKnowledge'])->name('ai.knowledge.save');
        });
    });

    // Rekomendasi AI (Discovery web): cari KOL & tren produk via Tavily + AI.
    // 'internal' blokir mitra KERAS di atas permission. Tambah kandidat ke DB KOL
    // dijaga lagi kol.screening.manage (sama dengan input KOL).
    Route::middleware(['permission:use_ai_discovery', 'internal'])->group(function () {
        Route::get('/rekomendasi', [AiDiscoveryController::class, 'index'])->name('discovery.index');
        Route::post('/rekomendasi/kol', [AiDiscoveryController::class, 'searchKol'])->name('discovery.kol');
        Route::post('/rekomendasi/produk', [AiDiscoveryController::class, 'searchProduct'])->name('discovery.produk');
        Route::middleware('permission:kol.screening.manage')->group(function () {
            Route::post('/rekomendasi/kol/tambah', [AiDiscoveryController::class, 'addKol'])->name('discovery.kol.add');
            Route::post('/rekomendasi/kol/massal', [AiDiscoveryController::class, 'bulkAddKol'])->name('discovery.kol.bulk');
        });
    });

    /* ---------------- Learning / LMS ---------------- */
    Route::middleware('permission:view_learning')->group(function () {
        Route::get('/learning', [LearningController::class, 'index'])->name('learning.index');
        Route::get('/learning/{lesson}', [LearningController::class, 'show'])->name('learning.show');
    });
    Route::middleware('permission:manage_learning')->group(function () {
        Route::post('/learning', [LearningController::class, 'store'])->name('learning.store');
        Route::put('/learning/{lesson}', [LearningController::class, 'update'])->name('learning.update');
        Route::delete('/learning/{lesson}', [LearningController::class, 'destroy'])->name('learning.destroy');

        Route::post('/learning-modules', [LearningController::class, 'storeModule'])->name('learning.modules.store');
        Route::put('/learning-modules/{module}', [LearningController::class, 'updateModule'])->name('learning.modules.update');
        Route::delete('/learning-modules/{module}', [LearningController::class, 'destroyModule'])->name('learning.modules.destroy');
    });

    /* ---------------- Permission management (super_admin) ---------------- */
    Route::middleware('permission:manage_permissions')->group(function () {
        Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index');
        Route::post('/permissions', [PermissionController::class, 'update'])->name('permissions.update');
        Route::post('/roles/reorder', [PermissionController::class, 'reorder'])->name('roles.reorder');
        Route::post('/roles', [PermissionController::class, 'storeRole'])->name('roles.store');
        Route::delete('/roles/{role}', [PermissionController::class, 'destroyRole'])->name('roles.destroy');
    });

    /* Stock movements visible to partners too (their own) */
    Route::get('/my-stock-movements', [StockMovementController::class, 'index'])->name('stock-movements.mine');
});
