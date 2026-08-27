<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Models\KolPipelineCard;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pipeline scouting KOL (kanban 9 stage). Satu kartu aktif per KOL; tiap
 * perpindahan stage dicatat append-only di kol_pipeline_events.
 */
class KolPipelineController extends Controller
{
    public function index()
    {
        $cards = KolPipelineCard::with('kol')->orderBy('next_action_at')->get();
        $today = now()->startOfDay();
        $besok = $today->copy()->addDay()->endOfDay();

        return view('kols.pipeline', [
            'byStage' => $cards->groupBy('stage'),
            'stages' => KolPipelineCard::STAGES,
            'labels' => KolPipelineCard::STAGE_LABELS,
            'today' => $today,
            'statAktif' => $cards->filter->isActive()->count(),
            'statTerlambat' => $cards->filter(fn ($c) => $c->isActive() && $c->next_action_at?->lt($today))->count(),
            'statDekat' => $cards->filter(fn ($c) => $c->isActive() && $c->next_action_at?->between($today, $besok))->count(),
            'statTanpaAksi' => $cards->filter(fn ($c) => $c->isActive() && ! $c->next_action_at)->count(),
            'kolsTanpaKartu' => Kol::whereDoesntHave('pipelineCard')->orderBy('tiktok_username')->get(['id', 'tiktok_username']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kol_id' => ['required', 'integer', 'exists:kols,id',
                Rule::unique('kol_pipeline_cards', 'kol_id')->where('track', KolPipelineCard::TRACK_KOL)],
            'stage' => ['required', Rule::in(KolPipelineCard::STAGES)],
            'next_action' => ['nullable', 'string', 'max:255'],
            'next_action_at' => ['nullable', 'date'],
        ], ['kol_id.unique' => 'KOL ini sudah punya kartu pipeline.']);

        $card = KolPipelineCard::create($data + ['created_by' => $request->user()->id]);
        $card->events()->create(['from_stage' => null, 'to_stage' => $card->stage, 'created_by' => $request->user()->id]);

        AuditService::log(action: 'create_kol_pipeline_card', targetType: 'kol_pipeline_card', targetId: $card->id,
            after: ['kol' => $card->kol->tiktok_username, 'stage' => $card->stage]);

        return redirect()->route('kol-pipeline.index')
            ->with('status', 'Kartu ditambahkan ke '.KolPipelineCard::STAGE_LABELS[$card->stage].'.');
    }

    public function moveStage(Request $request, KolPipelineCard $card): RedirectResponse
    {
        $data = $request->validate([
            'stage' => ['required', Rule::in(KolPipelineCard::STAGES)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $from = $card->stage;
        $card->update(['stage' => $data['stage']]);
        $card->events()->create(['from_stage' => $from, 'to_stage' => $data['stage'],
            'note' => $data['note'] ?? null, 'created_by' => $request->user()->id]);

        return redirect()->route('kol-pipeline.index')
            ->with('status', $card->kol->tiktok_username.' → '.KolPipelineCard::STAGE_LABELS[$data['stage']].'.');
    }

    public function nextAction(Request $request, KolPipelineCard $card): RedirectResponse
    {
        $data = $request->validate([
            'next_action' => ['required', 'string', 'max:255'],
            'next_action_at' => ['required', 'date'],
            'is_followup' => ['nullable', 'boolean'],
        ]);

        $card->update([
            'next_action' => $data['next_action'],
            'next_action_at' => $data['next_action_at'],
            'followup_count' => $card->followup_count + (($data['is_followup'] ?? false) ? 1 : 0),
        ]);

        return redirect()->route('kol-pipeline.index')->with('status', 'Next action disimpan.');
    }

    /** Hapus permanen = super_admin saja; jalur normal cukup geser ke Drop. */
    public function destroy(Request $request, KolPipelineCard $card): RedirectResponse
    {
        abort_unless($request->user()->role === User::ROLE_SUPER_ADMIN, 403);

        AuditService::log(action: 'delete_kol_pipeline_card', targetType: 'kol_pipeline_card', targetId: $card->id,
            before: ['kol' => $card->kol->tiktok_username, 'stage' => $card->stage]);
        $card->delete();

        return redirect()->route('kol-pipeline.index')->with('status', 'Kartu dihapus.');
    }
}
