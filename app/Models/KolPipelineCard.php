<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class KolPipelineCard extends Model
{
    public const TRACK_KOL = 'kol';

    public const TRACK_AFFILIATE = 'affiliate';

    public const TRACKS = [self::TRACK_KOL, self::TRACK_AFFILIATE];

    /** Urutan = urutan kolom kanban. Papan KOL (scouting). */
    public const STAGES = ['kandidat', 'dihubungi', 'nego', 'deal', 'sampel_dikirim', 'posting', 'evaluasi', 'repeat', 'drop'];

    public const STAGE_LABELS = [
        'kandidat' => 'Kandidat', 'dihubungi' => 'Dihubungi', 'nego' => 'Nego', 'deal' => 'Deal',
        'sampel_dikirim' => 'Sampel dikirim', 'posting' => 'Posting', 'evaluasi' => 'Evaluasi',
        'repeat' => 'Repeat', 'drop' => 'Drop',
    ];

    /** Papan Affiliate (pembinaan affiliate yang sudah jalan). */
    public const STAGES_AFFILIATE = ['prospek', 'diajak', 'aktif', 'berkembang', 'champion', 'churn'];

    public const STAGE_LABELS_AFFILIATE = [
        'prospek' => 'Prospek', 'diajak' => 'Diajak', 'aktif' => 'Aktif',
        'berkembang' => 'Berkembang', 'champion' => 'Champion', 'churn' => 'Churn',
    ];

    /** Tahap akhir — tak butuh next action & keluar dari reminder/hitungan aktif. */
    public const TERMINAL_STAGES = ['repeat', 'drop', 'champion', 'churn'];

    /** SLA follow-up: jadwalkan next action +2 hari; maks 3× lalu diputuskan parkir/drop. */
    public const FOLLOW_UP_SLA_DAYS = 2;

    public const FOLLOW_UP_LIMIT = 3;

    protected $fillable = ['kol_id', 'track', 'stage', 'next_action', 'next_action_at', 'followup_count',
        'ask_rate', 'final_rate', 'negotiation_notes', 'note', 'created_by'];

    /** Default in-memory agar $card->track terisi tanpa refresh dari DB. */
    protected $attributes = ['track' => self::TRACK_KOL, 'followup_count' => 0];

    protected function casts(): array
    {
        return ['next_action_at' => 'date', 'ask_rate' => 'integer', 'final_rate' => 'integer'];
    }

    /** Daftar stage untuk sebuah track. */
    public static function stagesFor(string $track): array
    {
        return $track === self::TRACK_AFFILIATE ? self::STAGES_AFFILIATE : self::STAGES;
    }

    /** Label stage untuk sebuah track. */
    public static function labelsFor(string $track): array
    {
        return $track === self::TRACK_AFFILIATE ? self::STAGE_LABELS_AFFILIATE : self::STAGE_LABELS;
    }

    public static function isTerminalStage(string $stage): bool
    {
        return in_array($stage, self::TERMINAL_STAGES, true);
    }

    public static function isValidStage(string $track, string $stage): bool
    {
        return in_array($stage, self::stagesFor($track), true);
    }

    public function stageLabel(): string
    {
        return self::labelsFor($this->track)[$this->stage] ?? $this->stage;
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }

    public function events()
    {
        return $this->hasMany(KolPipelineEvent::class, 'card_id')->latest('id');
    }

    /** Aktif = bukan tahap akhir (dasar reminder & hitungan header). */
    public function isActive(): bool
    {
        return ! self::isTerminalStage($this->stage);
    }

    /**
     * Angka header papan (halaman Pipeline & alat AI pipeline_kol): kartu aktif, terlambat, next action hari ini/besok,
     * tanpa next action. Kartu tahap akhir tak dihitung.
     *
     * @return array{aktif:int,terlambat:int,dekat:int,tanpa_aksi:int}
     */
    public static function statistik(Collection $cards, ?Carbon $today = null): array
    {
        $today ??= now()->startOfDay();
        $besok = $today->copy()->addDay()->endOfDay();
        $aktif = $cards->filter->isActive();

        return [
            'aktif' => $aktif->count(),
            'terlambat' => $aktif->filter(fn ($c) => $c->next_action_at?->lt($today))->count(),
            'dekat' => $aktif->filter(fn ($c) => $c->next_action_at?->between($today, $besok))->count(),
            'tanpa_aksi' => $aktif->filter(fn ($c) => ! $c->next_action_at)->count(),
        ];
    }

    public function scopeActive($q)
    {
        return $q->whereNotIn('stage', self::TERMINAL_STAGES);
    }

    public function scopeTrack($q, string $track)
    {
        return $q->where('track', $track);
    }
}
