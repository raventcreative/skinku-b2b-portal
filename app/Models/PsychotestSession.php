<?php

namespace App\Models;

use App\Services\PsikotesService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Satu sesi psikotes kandidat: link publik /tes/{token} (tanpa login), berlaku 7 hari, sekali pakai (selesai = tak
 * bisa diulang). progress = {tes: {mulai, selesai, lewat_waktu?}}, answers = jawaban mentah per tes, results = skor.
 */
class PsychotestSession extends Model
{
    public const BERLAKU_HARI = 7;

    protected $fillable = ['candidate_id', 'token', 'tests', 'expires_at', 'started_at', 'finished_at', 'progress', 'answers', 'results', 'created_by'];

    protected function casts(): array
    {
        return [
            'tests' => 'array',
            'progress' => 'array',
            'answers' => 'array',
            'results' => 'array',
            'expires_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public static function buatUntuk(Candidate $candidate, ?int $oleh): self
    {
        return self::create([
            'candidate_id' => $candidate->id,
            'token' => Str::random(48),
            'tests' => array_keys(PsikotesService::TES),
            'expires_at' => now()->addDays(self::BERLAKU_HARI),
            'created_by' => $oleh,
        ]);
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    public function selesai(): bool
    {
        return $this->finished_at !== null;
    }

    public function kedaluwarsa(): bool
    {
        return ! $this->selesai() && $this->expires_at->isPast();
    }

    public function tesSelesai(string $tes): bool
    {
        return isset($this->progress[$tes]['selesai']);
    }

    /** Tes berikutnya yang belum dikerjakan (urutan PsikotesService::TES), null bila semua selesai. */
    public function tesBerikutnya(): ?string
    {
        foreach ((array) $this->tests as $tes) {
            if (! $this->tesSelesai($tes)) {
                return $tes;
            }
        }

        return null;
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->selesai() => 'Selesai',
            $this->kedaluwarsa() => 'Kedaluwarsa',
            $this->started_at !== null => 'Sedang dikerjakan',
            default => 'Belum dibuka',
        };
    }

    public function link(): string
    {
        return route('psikotes.publik', $this->token);
    }

    /** Ringkasan hasil satu baris, mis. "ENFP · DISC I/S · Logika 75" ('' bila belum ada tes selesai). */
    public function ringkasan(): string
    {
        $r = (array) $this->results;

        return collect([
            $r['kepribadian']['tipe'] ?? null,
            isset($r['disc']) ? 'DISC '.$r['disc']['utama'].'/'.$r['disc']['kedua'] : null,
            isset($r['logika']) ? 'Logika '.$r['logika']['skor'] : null,
        ])->filter()->implode(' · ');
    }
}
