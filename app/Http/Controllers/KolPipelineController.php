<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Models\KolPipelineCard;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pipeline KOL — dua papan kanban: 'kol' (scouting, 9 tahap) & 'affiliate'
 * (pembinaan affiliate aktif, 6 tahap). Satu kartu per KOL per papan; tiap
 * perpindahan tahap dicatat append-only di kol_pipeline_events.
 */
class KolPipelineController extends Controller
{
    public function index(Request $request)
    {
        $track = $this->track($request);
        $stages = KolPipelineCard::stagesFor($track);

        $cards = KolPipelineCard::track($track)->with('kol')->orderBy('next_action_at')->get();
        $today = now()->startOfDay();
        $besok = $today->copy()->addDay()->endOfDay();

        $taken = KolPipelineCard::where('track', $track)->pluck('kol_id');

        return view('kols.pipeline', [
            'track' => $track,
            'byStage' => $cards->groupBy('stage'),
            'stages' => $stages,
            'labels' => KolPipelineCard::labelsFor($track),
            'terminals' => KolPipelineCard::TERMINAL_STAGES,
            'today' => $today,
            'statAktif' => $cards->filter->isActive()->count(),
            'statTerlambat' => $cards->filter(fn ($c) => $c->isActive() && $c->next_action_at?->lt($today))->count(),
            'statDekat' => $cards->filter(fn ($c) => $c->isActive() && $c->next_action_at?->between($today, $besok))->count(),
            'statTanpaAksi' => $cards->filter(fn ($c) => $c->isActive() && ! $c->next_action_at)->count(),
            'countKol' => KolPipelineCard::track(KolPipelineCard::TRACK_KOL)->active()->count(),
            'countAffiliate' => KolPipelineCard::track(KolPipelineCard::TRACK_AFFILIATE)->active()->count(),
            'kolsTanpaKartu' => Kol::whereNotIn('id', $taken)->orderBy('tiktok_username')->get(['id', 'tiktok_username']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $track = $this->track($request);
        $data = $request->validate([
            'kol_id' => ['required', 'integer', 'exists:kols,id',
                Rule::unique('kol_pipeline_cards', 'kol_id')->where('track', $track)],
            'stage' => ['required', Rule::in(KolPipelineCard::stagesFor($track))],
            'next_action' => ['nullable', 'string', 'max:255'],
            'next_action_at' => ['nullable', 'date'],
            'ask_rate' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['kol_id.unique' => 'KOL ini sudah punya kartu di papan '.$this->trackLabel($track).'.']);

        // Tahap non-terminal wajib punya next action + tanggal.
        if (! KolPipelineCard::isTerminalStage($data['stage']) && (empty($data['next_action']) || empty($data['next_action_at']))) {
            return back()->withErrors(['next_action' => 'Tahap aktif wajib punya next action + tanggal.'])->withInput();
        }

        $card = KolPipelineCard::create([
            'kol_id' => $data['kol_id'], 'track' => $track, 'stage' => $data['stage'],
            'next_action' => $data['next_action'] ?? null, 'next_action_at' => $data['next_action_at'] ?? null,
            'ask_rate' => $data['ask_rate'] ?? null, 'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        $card->events()->create(['from_stage' => null, 'to_stage' => $card->stage,
            'note' => $this->addNote($data['note'] ?? null), 'created_by' => $request->user()->id]);

        AuditService::log(action: 'create_kol_pipeline_card', targetType: 'kol_pipeline_card', targetId: $card->id,
            after: ['kol' => $card->kol->tiktok_username, 'track' => $track, 'stage' => $card->stage]);

        return redirect()->route('kol-pipeline.index', ['kind' => $track])
            ->with('status', 'Kartu ditambahkan ke '.$card->stageLabel().'.');
    }

    /**
     * Pindah tahap (dari DnD atau form). Guardrail: tahap aktif WAJIB berakhir
     * dengan next action — pakai yang dikirim, atau yang sudah ada di kartu.
     * Tahap terminal mengosongkan next action.
     */
    public function moveStage(Request $request, KolPipelineCard $card): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'stage' => ['required', Rule::in(KolPipelineCard::stagesFor($card->track))],
            'next_action' => ['nullable', 'string', 'max:255'],
            'next_action_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $from = $card->stage;
        $terminal = KolPipelineCard::isTerminalStage($data['stage']);

        if ($terminal) {
            $card->next_action = null;
            $card->next_action_at = null;
        } else {
            $action = $data['next_action'] ?? $card->next_action;
            $at = $data['next_action_at'] ?? optional($card->next_action_at)->toDateString();
            if (empty($action) || empty($at)) {
                return $this->fail($request, 'Kartu butuh next action sebelum masuk tahap aktif.');
            }
            $card->next_action = $action;
            $card->next_action_at = $at;
        }
        $card->stage = $data['stage'];
        $card->save();

        $card->events()->create(['from_stage' => $from, 'to_stage' => $data['stage'],
            'note' => $data['note'] ?? null, 'created_by' => $request->user()->id]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('kol-pipeline.index', ['kind' => $card->track])
            ->with('status', $card->kol->tiktok_username.' → '.$card->stageLabel().'.');
    }

    /** Set next action manual (tanpa mengubah tahap). */
    public function nextAction(Request $request, KolPipelineCard $card): RedirectResponse
    {
        $data = $request->validate([
            'next_action' => ['required', 'string', 'max:255'],
            'next_action_at' => ['required', 'date'],
        ]);

        $card->update(['next_action' => $data['next_action'], 'next_action_at' => $data['next_action_at']]);

        return redirect()->route('kol-pipeline.index', ['kind' => $card->track])->with('status', 'Next action disimpan.');
    }

    /**
     * Catat 1 follow-up: followup_count+1, next action baru dijadwalkan +2 hari
     * (SLA). Setelah 3× → next action jadi keputusan parkir/drop. Tahap terminal
     * tak perlu follow-up.
     */
    public function followUp(Request $request, KolPipelineCard $card): RedirectResponse
    {
        if (! $card->isActive()) {
            return back()->withErrors(['followup' => 'Kartu di tahap akhir tak perlu follow-up.']);
        }

        $count = $card->followup_count + 1;
        $nextDate = now()->addDays(KolPipelineCard::FOLLOW_UP_SLA_DAYS)->toDateString();
        $nextAction = $count >= KolPipelineCard::FOLLOW_UP_LIMIT
            ? "Putuskan: parkir atau drop (sudah {$count}× follow-up tanpa hasil)"
            : 'Follow-up ke-'.($count + 1);

        $card->update(['followup_count' => $count, 'next_action' => $nextAction, 'next_action_at' => $nextDate]);
        $card->events()->create(['from_stage' => $card->stage, 'to_stage' => $card->stage,
            'note' => "Follow-up ke-{$count} dilakukan.", 'created_by' => $request->user()->id]);

        return redirect()->route('kol-pipeline.index', ['kind' => $card->track])
            ->with('status', "Follow-up ke-{$count} dicatat — next action dijadwalkan ".now()->addDays(KolPipelineCard::FOLLOW_UP_SLA_DAYS)->format('d M').'.');
    }

    /** Halaman detail kartu: info, rate ask→final + %turun, catatan nego, riwayat tahap. */
    public function show(KolPipelineCard $card)
    {
        $card->load(['kol', 'events.creator']);
        $turun = ($card->ask_rate && $card->final_rate && $card->ask_rate > 0)
            ? round(($card->ask_rate - $card->final_rate) / $card->ask_rate * 100, 1) : null;

        return view('kols.pipeline_show', [
            'card' => $card,
            'labels' => KolPipelineCard::labelsFor($card->track),
            'turun' => $turun,
            'isTerminal' => KolPipelineCard::isTerminalStage($card->stage),
        ]);
    }

    /** Edit dari halaman detail: next action, rate diminta/final, catatan nego. */
    public function update(Request $request, KolPipelineCard $card): RedirectResponse
    {
        $data = $request->validate([
            'next_action' => ['nullable', 'string', 'max:255'],
            'next_action_at' => ['nullable', 'date'],
            'ask_rate' => ['nullable', 'integer', 'min:0'],
            'final_rate' => ['nullable', 'integer', 'min:0'],
            'negotiation_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        // Tahap aktif wajib punya next action; terminal boleh kosong.
        if (! KolPipelineCard::isTerminalStage($card->stage) && (empty($data['next_action']) || empty($data['next_action_at']))) {
            return back()->withErrors(['next_action' => 'Kartu di tahap aktif wajib punya next action + tanggal.']);
        }

        $card->update([
            'next_action' => $data['next_action'] ?? null,
            'next_action_at' => $data['next_action_at'] ?? null,
            'ask_rate' => $data['ask_rate'] ?? null,
            'final_rate' => $data['final_rate'] ?? null,
            'negotiation_notes' => $data['negotiation_notes'] ?? null,
        ]);

        return redirect()->route('kol-pipeline.show', $card)->with('status', 'Kartu diperbarui.');
    }

    /** Hapus permanen = super_admin saja; jalur normal cukup geser ke tahap akhir. */
    public function destroy(Request $request, KolPipelineCard $card): RedirectResponse
    {
        abort_unless($request->user()->role === User::ROLE_SUPER_ADMIN, 403);

        $track = $card->track;
        AuditService::log(action: 'delete_kol_pipeline_card', targetType: 'kol_pipeline_card', targetId: $card->id,
            before: ['kol' => $card->kol->tiktok_username, 'stage' => $card->stage]);
        $card->delete();

        return redirect()->route('kol-pipeline.index', ['kind' => $track])->with('status', 'Kartu dihapus.');
    }

    // ---- internal ----

    private function track(Request $request): string
    {
        $t = (string) $request->input('kind', KolPipelineCard::TRACK_KOL);

        return in_array($t, KolPipelineCard::TRACKS, true) ? $t : KolPipelineCard::TRACK_KOL;
    }

    private function trackLabel(string $track): string
    {
        return $track === KolPipelineCard::TRACK_AFFILIATE ? 'Affiliate' : 'KOL';
    }

    private function addNote(?string $note): string
    {
        return $note ? "Ditambahkan ke pipeline.\n{$note}" : 'Ditambahkan ke pipeline.';
    }

    private function fail(Request $request, string $msg): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'error' => $msg], 422);
        }

        return back()->withErrors(['stage' => $msg]);
    }
}
