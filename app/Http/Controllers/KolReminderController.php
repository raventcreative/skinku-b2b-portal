<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Models\KolAffiliateTransaction;
use App\Models\KolDeal;
use App\Models\KolPipelineCard;
use App\Models\KolSample;
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
        $besokDate = $today->copy()->addDay();
        $cards = KolPipelineCard::active()->with('kol')->get();

        $late = $cards->filter(fn ($c) => $c->next_action_at?->lt($today))->sortBy('next_action_at');
        $due = $cards->filter(fn ($c) => $c->next_action_at?->isSameDay($today));
        // Lead time H-1: next action besok — supaya bisa disiapkan dari sekarang.
        $besok = $cards->filter(fn ($c) => $c->next_action_at?->isSameDay($besokDate));
        $none = $cards->filter(fn ($c) => ! $c->next_action_at);

        // Sampel tertahan: pending ≥ 3 hari, atau dikirim ≥ 7 hari belum diterima.
        $stuckSamples = KolSample::with(['kol', 'deal'])
            ->where(function ($q) {
                $q->where(fn ($w) => $w->where('status', 'pending')->where('created_at', '<=', now()->subDays(3)))
                    ->orWhere(fn ($w) => $w->where('status', 'shipped')->whereDate('shipped_at', '<=', now()->subDays(7)));
            })
            ->orderBy('created_at')->get();

        // Deadline posting: deal berjalan yang tenggatnya ≤ 3 hari lagi & belum ada
        // konten. Tenggat = deadline posting khusus bila diisi, jika tidak periode_selesai.
        $postingDue = KolDeal::with('kol')->where('status', 'berjalan')
            ->where(fn ($q) => $q->whereNotNull('posting_deadline')->orWhereNotNull('periode_selesai'))
            ->whereRaw('date(COALESCE(posting_deadline, periode_selesai)) <= ?', [now()->addDays(3)->toDateString()])
            ->whereDoesntHave('contents')
            ->orderByRaw('COALESCE(posting_deadline, periode_selesai) asc')->get();

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
            'rows' => $late->concat($due)->concat($besok)->concat($none)->values(),
            'lateCount' => $late->count(),
            'dueCount' => $due->count(),
            'besokCount' => $besok->count(),
            'noneCount' => $none->count(),
            'today' => $today,
            // Tagihan deal belum lunas — finance only (uang).
            'payments' => $u->canDo('kol.deal.finance') ? $budget->unpaid() : collect(),
            'stuckSamples' => $u->canDo('kol.deal.manage') ? $stuckSamples : collect(),
            'postingDue' => $postingDue,
            'churn' => $churn,
        ]);
    }
}
