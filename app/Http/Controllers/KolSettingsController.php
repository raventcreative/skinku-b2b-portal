<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\KolMonthlyTarget;
use App\Services\AuditService;
use App\Services\KolBudgetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Setelan KOL terpusat (finance-only) — satu layar untuk semua "angka acuan"
 * yang sebelumnya tersebar: budget bulanan, CPM anchor, target views/GMV,
 * margin kotor, HPP sampel default, urutan tanggal import. Plus tabel override
 * target per-bulan (KolMonthlyTarget) yang menimpa setelan global untuk bulan
 * tertentu.
 */
class KolSettingsController extends Controller
{
    public function index()
    {
        return view('kols.settings', [
            'budget' => (int) AppSetting::get(KolBudgetService::KEY_BUDGET, '0'),
            'anchor' => (int) AppSetting::get(KolBudgetService::KEY_ANCHOR, '5000'),
            'viewsTarget' => (int) AppSetting::get('kol_views_target', '1000000'),
            'gmvTarget' => (int) AppSetting::get('kol_gmv_target', '0'),
            'marginPct' => (int) round(AppSetting::float('kol_gross_margin', 0.3) * 100),
            'shareLimitPct' => (int) round(AppSetting::float('kol_share_limit', 0.4) * 100),
            'sampleHpp' => (int) AppSetting::get('kol_sample_hpp', '0'),
            'dateOrder' => AppSetting::get('kol_import_date_order', 'auto'),
            'targets' => KolMonthlyTarget::orderByDesc('month')->get(),
            'thisMonth' => now()->format('Y-m'),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'budget' => ['required', 'integer', 'min:0'],
            'anchor' => ['required', 'integer', 'min:0'],
            'views_target' => ['required', 'integer', 'min:0'],
            'gmv_target' => ['required', 'integer', 'min:0'],
            'margin_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'share_limit_pct' => ['required', 'integer', 'min:1', 'max:100'],
            'sample_hpp' => ['required', 'integer', 'min:0'],
            'date_order' => ['required', 'in:auto,dmy,mdy'],
        ]);

        AppSetting::put(KolBudgetService::KEY_BUDGET, (string) $d['budget']);
        AppSetting::put(KolBudgetService::KEY_ANCHOR, (string) $d['anchor']);
        AppSetting::put('kol_views_target', (string) $d['views_target']);
        AppSetting::put('kol_gmv_target', (string) $d['gmv_target']);
        AppSetting::put('kol_gross_margin', (string) round($d['margin_pct'] / 100, 2));
        AppSetting::put('kol_share_limit', (string) round($d['share_limit_pct'] / 100, 2));
        AppSetting::put('kol_sample_hpp', (string) $d['sample_hpp']);
        AppSetting::put('kol_import_date_order', $d['date_order']);

        AuditService::log(action: 'update_kol_settings', targetType: 'kol_settings', targetId: 0, after: $d);

        return back()->with('status', 'Setelan KOL disimpan.');
    }

    /** Simpan/ubah override target satu bulan (isi ulang bulan sama = perbarui). */
    public function monthlyStore(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'budget' => ['nullable', 'integer', 'min:0'],
            'views_target' => ['nullable', 'integer', 'min:0'],
            'gmv_target' => ['nullable', 'integer', 'min:0'],
            'margin_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);

        KolMonthlyTarget::updateOrCreate(
            ['month' => $d['month']],
            [
                'budget' => $d['budget'] ?? null,
                'views_target' => $d['views_target'] ?? null,
                'gmv_target' => $d['gmv_target'] ?? null,
                'margin' => isset($d['margin_pct']) ? round($d['margin_pct'] / 100, 2) : null,
                'notes' => $d['notes'] ?? null,
            ],
        );

        return back()->with('status', "Target bulan {$d['month']} disimpan.");
    }

    public function monthlyDestroy(KolMonthlyTarget $target): RedirectResponse
    {
        $month = $target->month;
        $target->delete();

        return back()->with('status', "Override bulan {$month} dihapus (kembali ke setelan global).");
    }

    /** Dipakai controller lain: nilai efektif sebuah field pada bulan tertentu. */
    public static function effectiveBudget(Carbon $month): int
    {
        return KolMonthlyTarget::forMonth($month)?->budget
            ?? (int) AppSetting::get(KolBudgetService::KEY_BUDGET, '0');
    }
}
