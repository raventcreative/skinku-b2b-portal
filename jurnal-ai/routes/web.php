<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // ── Klien (database buku) ───────────────────────────────────────────────
    Route::get('/klien', [ClientController::class, 'index'])->name('clients.index');
    Route::get('/klien/{client}', [ClientController::class, 'show'])->name('clients.show');

    Route::middleware('admin')->group(function () {
        Route::get('/klien-baru', [ClientController::class, 'create'])->name('clients.create');
        Route::post('/klien', [ClientController::class, 'store'])->name('clients.store');
        Route::get('/klien/{client}/ubah', [ClientController::class, 'edit'])->name('clients.edit');
        Route::put('/klien/{client}', [ClientController::class, 'update'])->name('clients.update');
        Route::delete('/klien/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
        Route::post('/klien/{client}/coa/sinkron', [ClientController::class, 'syncCoa'])->name('clients.coa.sync');
    });

    // ── Chart of Account per klien ──────────────────────────────────────────
    Route::get('/klien/{client}/coa', [AccountController::class, 'index'])->name('accounts.index');
    Route::middleware('admin')->group(function () {
        Route::post('/klien/{client}/coa', [AccountController::class, 'store'])->name('accounts.store');
        Route::put('/klien/{client}/coa/{account}', [AccountController::class, 'update'])->name('accounts.update');
        Route::delete('/klien/{client}/coa/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');
    });

    // ── Dokumen: upload → AI baca → review → posting ────────────────────────
    Route::get('/klien/{client}/dokumen', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('/klien/{client}/dokumen/baru', [DocumentController::class, 'create'])->name('documents.create');
    Route::post('/klien/{client}/dokumen', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('/klien/{client}/dokumen/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('/klien/{client}/dokumen/{document}/berkas', [DocumentController::class, 'file'])->name('documents.file');
    Route::post('/klien/{client}/dokumen/{document}/baca-ulang', [DocumentController::class, 'reextract'])->name('documents.reextract');
    Route::post('/klien/{client}/dokumen/{document}/posting', [DocumentController::class, 'post'])->name('documents.post');
    Route::delete('/klien/{client}/dokumen/{document}', [DocumentController::class, 'destroy'])
        ->middleware('admin')->name('documents.destroy');

    // ── Jurnal ──────────────────────────────────────────────────────────────
    Route::get('/klien/{client}/jurnal', [JournalController::class, 'index'])->name('journals.index');
    Route::get('/klien/{client}/jurnal/baru', [JournalController::class, 'create'])->name('journals.create');
    Route::post('/klien/{client}/jurnal', [JournalController::class, 'store'])->name('journals.store');
    Route::post('/klien/{client}/jurnal/{journal}/void', [JournalController::class, 'void'])->name('journals.void');
    Route::delete('/klien/{client}/jurnal/{journal}', [JournalController::class, 'destroy'])
        ->middleware('admin')->name('journals.destroy');

    // ── Laporan ─────────────────────────────────────────────────────────────
    Route::get('/klien/{client}/laporan/neraca-saldo', [ReportController::class, 'trialBalance'])->name('reports.trial-balance');
    Route::get('/klien/{client}/laporan/laba-rugi', [ReportController::class, 'incomeStatement'])->name('reports.income-statement');
    Route::get('/klien/{client}/laporan/neraca', [ReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
});
