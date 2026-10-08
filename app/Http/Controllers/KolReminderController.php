<?php

namespace App\Http\Controllers;

use App\Services\KolReminderService;
use Illuminate\Http\Request;

/**
 * Reminder KOL — pipeline (terlambat → hari ini → tanpa next action) + tagihan
 * deal belum lunas (finance) + deadline posting deal + affiliate berhenti posting.
 */
class KolReminderController extends Controller
{
    public function index(Request $request, KolReminderService $reminder)
    {
        $r = $reminder->untuk($request->user());

        return view('kols.reminder', [
            'rows' => $r['late']->concat($r['due'])->concat($r['besok'])->concat($r['none'])->values(),
            'lateCount' => $r['late']->count(),
            'dueCount' => $r['due']->count(),
            'besokCount' => $r['besok']->count(),
            'noneCount' => $r['none']->count(),
            'today' => $r['today'],
            // Bagian tanpa izin sudah berupa koleksi kosong (lihat KolReminderService).
            'payments' => $r['payments'],
            'stuckSamples' => $r['stuckSamples'],
            'postingDue' => $r['postingDue'],
            'churn' => $r['churn'],
        ]);
    }
}
