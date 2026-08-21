<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use App\Services\CommissionService;
use Illuminate\Http\Request;

class RecruitController extends Controller
{
    public function __construct(private CommissionService $commissions) {}

    /**
     * "Rekrutan Saya" — perekrut (sponsor / GD / distributor yang merekrut) lihat
     * daftar lead-nya + earning (join + RO cashback). Tarik dana lewat Saldo Komisi.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isPartner(), 403);

        $recruits = $user->recruits()->orderByDesc('created_at')->get();

        // Earning per rekrutan (join + ro_cashback) dari komisi milik user ini.
        $earnByRecruit = Commission::where('user_id', $user->id)
            ->whereIn('type', ['join', 'ro_cashback'])
            ->selectRaw('source_user_id, SUM(amount) as total')
            ->groupBy('source_user_id')->pluck('total', 'source_user_id');

        return view('rekrutan_saya.index', [
            'recruits' => $recruits,
            'earnByRecruit' => $earnByRecruit,
            'totalJoin' => (float) Commission::where('user_id', $user->id)->where('type', 'join')->sum('amount'),
            'totalRo' => (float) Commission::where('user_id', $user->id)->where('type', 'ro_cashback')->sum('amount'),
            'available' => $this->commissions->availableBalance($user),
        ]);
    }
}
