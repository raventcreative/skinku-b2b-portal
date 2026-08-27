<?php

namespace App\Http\Controllers;

use App\Models\KolPipelineCard;

/** Reminder KOL — agregat pipeline (fase 1): terlambat → hari ini → tanpa next action. */
class KolReminderController extends Controller
{
    public function index()
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
        ]);
    }
}
