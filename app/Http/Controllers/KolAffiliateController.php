<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Services\AuditService;
use App\Services\KolAffiliateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Affiliate & GMV (Fase 3a): ranking GMV per creator + layar "Belum Cocok"
 * (tautkan username asing ke KOL). Angka uang → gated kol.affiliate.view.
 */
class KolAffiliateController extends Controller
{
    public function index(Request $request, KolAffiliateService $svc)
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('bulan'))
            ? (string) $request->query('bulan') : now()->format('Y-m');
        $m = Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        $ranking = $svc->monthly($m);
        $unmatched = $svc->unmatched();

        return view('kols.affiliate.index', [
            'month' => $month,
            'ranking' => $ranking,
            'summary' => [
                'gmv' => (int) $ranking->sum('gmv'),
                'commission' => (int) $ranking->sum('commission'),
                'orders' => (int) $ranking->sum('orders'),
                'affiliates' => $ranking->count(),
            ],
            'unmatched' => $unmatched,
            'canManage' => $request->user()->canDo('kol.affiliate.manage'),
            'kols' => $request->user()->canDo('kol.affiliate.manage')
                ? Kol::orderBy('tiktok_username')->get(['id', 'tiktok_username']) : collect(),
            'prevMonth' => $m->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $m->copy()->addMonth()->format('Y-m'),
        ]);
    }

    /** Tautkan semua transaksi sebuah username ke KOL (dari layar Belum Cocok). */
    public function match(Request $request, KolAffiliateService $svc): RedirectResponse
    {
        $data = $request->validate([
            'raw_username' => ['required', 'string', 'max:150'],
            'kol_id' => ['required', 'integer', 'exists:kols,id'],
        ]);

        $n = $svc->matchUsername($data['raw_username'], $data['kol_id']);

        AuditService::log(action: 'match_kol_affiliate', targetType: 'kol', targetId: $data['kol_id'],
            after: ['username' => $data['raw_username'], 'orders' => $n]);

        return back()->with('status', "{$n} order ditautkan ke KOL.");
    }
}
