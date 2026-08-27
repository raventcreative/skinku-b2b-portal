<?php

namespace App\Http\Controllers;

use App\Models\KolPipelineCard;
use App\Services\KolBudgetService;
use Illuminate\Http\Request;

/** Reminder KOL — pipeline (terlambat → hari ini → tanpa next action) + tagihan deal belum lunas (finance). */
class KolReminderController extends Controller
{
    public function index(Request $request, KolBudgetService $budget)
    {
        $today = now()->startOfDay();
        $cards = KolPipelineCard::active()->with('kol')->get();

        $late = $cards->filter(fn ($c) => $c->next_action_at?->lt($today))->sortBy('next_action_at');
        $due = $cards->filter(fn ($c) => $c->next_action_at?->isSameDay($today));
        $none = $cards->filter(fn ($c) => ! $c->next_action_at);

        return view('kols.reminder', [
            'rows' => $late->concat($due)->concat($none)->values(),
            'lateCount' => $late->count(),
            'dueCount' => $due->count(),
            'noneCount' => $none->count(),
            'today' => $today,
            // Tagihan deal belum lunas — finance only (uang).
            'payments' => $request->user()->canDo('kol.deal.finance') ? $budget->unpaid() : collect(),
        ]);
    }
}
