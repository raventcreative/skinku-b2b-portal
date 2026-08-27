<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Models\KolAffiliateTransaction;
use App\Models\KolDeal;
use App\Models\KolPipelineCard;
use App\Services\KolBudgetService;
use Illuminate\Http\Request;

/**
 * Reminder KOL — pipeline (terlambat → hari ini → tanpa next action) + tagihan
 * deal belum lunas (finance) + deadline posting deal + affiliate berhenti posting.
 */
class KolReminderController extends Controller
{
    public function index(Request $request, KolBudgetService $budget)
    {
        $u = $request->user();
        $today = now()->startOfDay();
        $cards = KolPipelineCard::active()->with('kol')->get();

        $late = $cards->filter(fn ($c) => $c->next_action_at?->lt($today))->sortBy('next_action_at');
        $due = $cards->filter(fn ($c) => $c->next_action_at?->isSameDay($today));
        $none = $cards->filter(fn ($c) => ! $c->next_action_at);

        // Deadline posting: deal berjalan yang tenggatnya ≤ 3 hari lagi & belum ada konten.
        $postingDue = KolDeal::with('kol')->where('status', 'berjalan')
            ->whereNotNull('periode_selesai')
            ->whereDate('periode_selesai', '<=', now()->addDays(3))
            ->whereDoesntHave('contents')
            ->orderBy('periode_selesai')->get();

        // Affiliate berhenti posting: punya order affiliate 30 hari terakhir tapi
        // tak ada konten dalam 14 hari terakhir. Finance/affiliate-view only.
        $churn = collect();
        if ($u->canDo('kol.affiliate.view')) {
            $activeIds = KolAffiliateTransaction::matched()->notCancelled()
                ->where('order_date', '>=', now()->subDays(30))->distinct()->pluck('kol_id');
            $churn = Kol::whereIn('id', $activeIds)
                ->whereDoesntHave('contents', fn ($q) => $q->where('posted_at', '>=', now()->subDays(14)))
                ->orderBy('tiktok_username')->get(['id', 'tiktok_username']);
        }

        return view('kols.reminder', [
            'rows' => $late->concat($due)->concat($none)->values(),
            'lateCount' => $late->count(),
            'dueCount' => $due->count(),
            'noneCount' => $none->count(),
            'today' => $today,
            // Tagihan deal belum lunas — finance only (uang).
            'payments' => $u->canDo('kol.deal.finance') ? $budget->unpaid() : collect(),
            'postingDue' => $postingDue,
            'churn' => $churn,
        ]);
    }
}
