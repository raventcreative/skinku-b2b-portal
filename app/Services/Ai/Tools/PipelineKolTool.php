<?php

namespace App\Services\Ai\Tools;

use App\Models\KolPipelineCard;
use App\Models\User;

/**
 * Alat BACA: Pipeline KOL (menu KOL → Pipeline), izin kol.view sama dgn halamannya. Dua papan: kol (scouting) &
 * affiliate (pembinaan). Angka header dari KolPipelineCard::statistik → sama dgn papan. Catatan & catatan nego
 * tidak dikirim; rate diminta/final ikut (tampil di kartu untuk semua yang bisa buka papan).
 */
class PipelineKolTool extends BaseTool
{
    public function name(): string
    {
        return 'pipeline_kol';
    }

    public function permission(): ?string
    {
        return 'kol.view';
    }

    public function description(): string
    {
        return 'Papan Pipeline KOL (menu KOL → Pipeline). Dua papan: jalur kol = scouting KOL (Kandidat → Dihubungi → '
            .'Nego → Deal → Sampel dikirim → Posting → Evaluasi → Repeat/Drop), jalur affiliate = pembinaan affiliate '
            .'(Prospek → Diajak → Aktif → Berkembang → Champion/Churn). Isi: jumlah kartu per tahap, kartu aktif, '
            .'terlambat, next action hari ini/besok, tanpa next action, dan daftar kartu (kreator, tahap, next action + '
            .'tanggal, rate diminta → final). Isi tahap untuk daftar kartu di tahap itu. Untuk daftar tugas follow-up '
            .'hari ini lintas papan (+ sampel, deadline posting, tagihan) pakai reminder_kol.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'jalur' => ['type' => 'string', 'enum' => KolPipelineCard::TRACKS, 'description' => 'kol (scouting) atau affiliate (pembinaan). Default kol.'],
                'tahap' => ['type' => 'string', 'description' => 'Opsional: nama tahap (mis. Nego, Sampel dikirim, Champion) → daftar kartu di tahap itu.'],
                'limit' => ['type' => 'integer', 'description' => 'Jumlah kartu di daftar, 1-30. Default 10.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $jalurDiisi = in_array($args['jalur'] ?? null, KolPipelineCard::TRACKS, true);
        $jalur = $jalurDiisi ? $args['jalur'] : KolPipelineCard::TRACK_KOL;
        $tahap = null;
        $q = mb_strtolower(trim((string) ($args['tahap'] ?? '')));
        if ($q !== '') {
            $tahap = $this->cariTahap($q, $jalur);
            // Tahap milik papan lain (mis. "champion" tanpa jalur) → pindah papan otomatis.
            if ($tahap === null && ! $jalurDiisi) {
                $lain = $jalur === KolPipelineCard::TRACK_KOL ? KolPipelineCard::TRACK_AFFILIATE : KolPipelineCard::TRACK_KOL;
                if ($tahap = $this->cariTahap($q, $lain)) {
                    $jalur = $lain;
                }
            }
            if ($tahap === null) {
                return ['error' => "Tahap \"{$args['tahap']}\" tidak ada di papan {$this->namaPapan($jalur)}.",
                    'tahap_tersedia' => array_values(KolPipelineCard::labelsFor($jalur))];
            }
        }

        $labels = KolPipelineCard::labelsFor($jalur);
        $cards = KolPipelineCard::track($jalur)->with('kol')->get();
        $today = now()->startOfDay();
        $stat = KolPipelineCard::statistik($cards, $today);
        $daftar = ($tahap ? $cards->where('stage', $tahap) : $cards->filter->isActive())
            // Yang paling mendesak dulu (tanggal next action terlama), tanpa tanggal di belakang.
            ->sortBy(fn (KolPipelineCard $c) => $c->next_action_at?->timestamp ?? PHP_INT_MAX);

        return array_filter([
            'papan' => $this->namaPapan($jalur),
            'jumlah_kartu_per_papan' => [
                'kol' => KolPipelineCard::track(KolPipelineCard::TRACK_KOL)->count(),
                'affiliate' => KolPipelineCard::track(KolPipelineCard::TRACK_AFFILIATE)->count(),
            ],
            'ringkasan' => [
                'total_kartu' => $cards->count(),
                'aktif' => $stat['aktif'],
                'terlambat' => $stat['terlambat'],
                'next_action_hari_ini_atau_besok' => $stat['dekat'],
                'tanpa_next_action' => $stat['tanpa_aksi'],
            ],
            'per_tahap' => collect($labels)->map(fn ($label, $key) => array_filter([
                'tahap' => $label,
                'jumlah' => $cards->where('stage', $key)->count(),
                'tahap_akhir' => KolPipelineCard::isTerminalStage($key) ?: null,
            ], fn ($v) => $v !== null))->values()->all(),
            'filter_tahap' => $tahap ? $labels[$tahap] : null,
            'catatan' => $tahap ? null : 'Daftar kartu = kartu AKTIF (bukan tahap akhir), yang next action-nya paling mendesak dulu.',
            'jumlah_kartu_di_daftar' => $daftar->count(),
            'kartu' => $daftar->take(max(1, min(30, (int) ($args['limit'] ?? 10))))->map(fn (KolPipelineCard $c) => array_filter([
                'username' => $c->kol ? '@'.$c->kol->handle() : '?',
                'nama' => $c->kol?->name,
                'tahap' => $c->stageLabel(),
                'next_action' => $c->next_action ? mb_substr($c->next_action, 0, 100) : null,
                'tanggal_next_action' => $c->next_action_at?->toDateString(),
                'terlambat_hari' => $c->isActive() && $c->next_action_at?->lt($today) ? (int) $c->next_action_at->diffInDays($today) : null,
                'follow_up_ke' => $c->followup_count ?: null,
                'rate_diminta' => $c->ask_rate,
                'rate_final' => $c->final_rate,
            ], fn ($v) => $v !== null))->values()->all(),
        ], fn ($v) => $v !== null);
    }

    /** Kunci tahap dari kunci/label yang diketik (tak peka huruf besar), null bila tak ada di papan itu. */
    private function cariTahap(string $q, string $jalur): ?string
    {
        $key = collect(KolPipelineCard::labelsFor($jalur))
            ->search(fn ($label, $key) => $q === $key || $q === mb_strtolower($label) || str_replace(' ', '_', $q) === $key);

        return $key === false ? null : $key;
    }

    private function namaPapan(string $jalur): string
    {
        return $jalur === KolPipelineCard::TRACK_AFFILIATE ? 'Affiliate (pembinaan affiliate)' : 'KOL (scouting)';
    }
}
