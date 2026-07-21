<?php

use App\Http\Controllers\AccAccountController;
use App\Http\Controllers\AccountingController;
use App\Http\Controllers\AccTemplateController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackdatedSaleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HqStockReportController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\KanbanController;
use App\Http\Controllers\KolController;
use App\Http\Controllers\KolDealController;
use App\Http\Controllers\KolScreeningController;
use App\Http\Controllers\LearningController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\PartnerSaleController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\StockOpnameController;
use App\Http\Controllers\StockReceiptController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TikTokController;
use App\Http\Controllers\UserController;
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

    /* ---------------- Purchase Orders ---------------- */
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');

    // Create PO — gated by the configurable "create_po" capability
    Route::middleware('permission:create_po')->group(function () {
        Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('purchase-orders.create');
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
    });

    // Export SEBELUM {purchaseOrder}: tanpa ini, "export" tertelan model binding.
    Route::get('/purchase-orders/export', [ExportController::class, 'purchaseOrders'])->name('purchase-orders.export');
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');

    // Buyer uploads transfer proof for their own PO
    Route::post('/purchase-orders/{purchaseOrder}/payment-proof', [PurchaseOrderController::class, 'uploadPayment'])->name('purchase-orders.payment-proof');

    Route::middleware('permission:update_po_status')->group(function () {
        Route::post('/purchase-orders/{purchaseOrder}/status', [PurchaseOrderController::class, 'updateStatus'])->name('purchase-orders.status');
        Route::post('/purchase-orders/{purchaseOrder}/shipping', [PurchaseOrderController::class, 'setShipping'])->name('purchase-orders.shipping');
        Route::post('/purchase-orders/{purchaseOrder}/verify-payment', [PurchaseOrderController::class, 'verifyPayment'])->name('purchase-orders.verify-payment');
    });

    Route::middleware('permission:delete_po')->group(function () {
        Route::delete('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->name('purchase-orders.destroy');
        Route::delete('/purchase-orders/{purchaseOrder}/force', [PurchaseOrderController::class, 'forceDestroy'])->name('purchase-orders.force-destroy');
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
    Route::post('/inventory/minimum', [InventoryController::class, 'setMinimum'])->name('inventory.minimum');

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
        Route::get('/reports/export', [ExportController::class, 'penjualan'])->name('reports.export');
        Route::get('/reports/chart-data', [ReportController::class, 'chartData'])->name('reports.chart-data');
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
        // Replika sheet "Listing KOL" — satu baris per screening, kolom persis Excel.
        Route::get('/kols/listing', [KolController::class, 'listing'])->name('kols.listing');
        Route::get('/kols/listing/export', [ExportController::class, 'listingKol'])->name('kols.listing.export');
        Route::get('/kols/export', [ExportController::class, 'databaseKol'])->name('kols.export');
        Route::get('/kols/{kol}', [KolController::class, 'show'])->whereNumber('kol')->name('kols.show');

        Route::middleware('permission:kol.screening.manage')->group(function () {
            Route::post('/kols', [KolController::class, 'store'])->name('kols.store');
            Route::put('/kols/{kol}', [KolController::class, 'update'])->name('kols.update');
            Route::get('/kol-screenings/create', [KolScreeningController::class, 'create'])->name('kol-screenings.create');
            Route::post('/kol-screenings', [KolScreeningController::class, 'store'])->name('kol-screenings.store');
            Route::patch('/kol-screenings/{screening}/ratecard', [KolScreeningController::class, 'updateRatecard'])->name('kol-screenings.ratecard');
        });

        Route::middleware('permission:kol.deal.manage')->group(function () {
            Route::get('/kol-deals', [KolDealController::class, 'index'])->name('kol-deals.index');
            Route::get('/kol-deals/create', [KolDealController::class, 'create'])->name('kol-deals.create');
            Route::post('/kol-deals', [KolDealController::class, 'store'])->name('kol-deals.store');
            Route::get('/kol-deals/{deal}/edit', [KolDealController::class, 'edit'])->name('kol-deals.edit');
            Route::put('/kol-deals/{deal}', [KolDealController::class, 'update'])->name('kol-deals.update');
            Route::delete('/kol-deals/{deal}', [KolDealController::class, 'destroy'])->name('kol-deals.destroy');
        });
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
    });

    /* ---------------- Integrasi TikTok Shop ---------------- */
    Route::middleware('permission:manage_tiktok')->group(function () {
        Route::get('/tiktok', [TikTokController::class, 'index'])->name('tiktok.index');
        Route::get('/tiktok/connect', [TikTokController::class, 'connect'])->name('tiktok.connect');
        Route::get('/tiktok/callback', [TikTokController::class, 'callback'])->name('tiktok.callback');
        Route::post('/tiktok/sync-orders', [TikTokController::class, 'syncOrders'])->name('tiktok.sync-orders');
        Route::get('/tiktok/orders', [TikTokController::class, 'orderList'])->name('tiktok.orders');
        Route::get('/tiktok/stok', [TikTokController::class, 'stockFunnel'])->name('tiktok.stock');
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

    /* ---------------- Product management ---------------- */
    Route::middleware('permission:manage_products')->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
    });

    /* ---------------- User management ---------------- */
    Route::middleware('permission:manage_users')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
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
        // Backup DB: jalankan manual + unduh (simpan di LUAR server).
        Route::post('/settings/backup', [SettingController::class, 'backupNow'])->name('settings.backup');
        Route::get('/settings/backup/{file}', [SettingController::class, 'backupDownload'])->name('settings.backup.download');
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
        Route::post('/roles', [PermissionController::class, 'storeRole'])->name('roles.store');
        Route::delete('/roles/{role}', [PermissionController::class, 'destroyRole'])->name('roles.destroy');
    });

    /* Stock movements visible to partners too (their own) */
    Route::get('/my-stock-movements', [StockMovementController::class, 'index'])->name('stock-movements.mine');
});
