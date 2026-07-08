<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LearningController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\StockReceiptController;
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
    });

    /* ---------------- Inventory & Stock Movements ---------------- */
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('/inventory/partner-adjust', [InventoryController::class, 'adjustPartner'])->name('inventory.partner-adjust');
    Route::post('/inventory/minimum', [InventoryController::class, 'setMinimum'])->name('inventory.minimum');

    Route::middleware('permission:manage_hq_stock')->group(function () {
        Route::post('/inventory/hq-adjust', [InventoryController::class, 'adjustHq'])->name('inventory.hq-adjust');
        Route::get('/stock-movements', [StockMovementController::class, 'index'])->name('stock-movements.index');
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
        Route::put('/materials/{material}', [MaterialController::class, 'update'])->name('materials.update');
        Route::post('/materials/purchase', [MaterialController::class, 'purchase'])->name('materials.purchase');

        // Production batches
        Route::get('/productions', [ProductionController::class, 'index'])->name('productions.index');
        Route::get('/productions/create', [ProductionController::class, 'create'])->name('productions.create');
        Route::post('/productions', [ProductionController::class, 'store'])->name('productions.store');
        Route::get('/productions/{production}', [ProductionController::class, 'show'])->name('productions.show');
    });

    /* ---------------- Reports ---------------- */
    Route::middleware('permission:view_reports')->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/chart-data', [ReportController::class, 'chartData'])->name('reports.chart-data');
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

    Route::middleware('permission:view_audit_log')->group(function () {
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    });

    Route::middleware('permission:system_settings')->group(function () {
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
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
