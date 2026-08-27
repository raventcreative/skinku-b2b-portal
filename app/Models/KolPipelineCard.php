<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KolPipelineCard extends Model
{
    public const TRACK_KOL = 'kol';

    /** Urutan = urutan kolom kanban. */
    public const STAGES = ['kandidat', 'dihubungi', 'nego', 'deal', 'sampel_dikirim', 'posting', 'evaluasi', 'repeat', 'drop'];

    public const STAGE_LABELS = [
        'kandidat' => 'Kandidat', 'dihubungi' => 'Dihubungi', 'nego' => 'Nego', 'deal' => 'Deal',
        'sampel_dikirim' => 'Sampel dikirim', 'posting' => 'Posting', 'evaluasi' => 'Evaluasi',
        'repeat' => 'Repeat', 'drop' => 'Drop',
    ];

    protected $fillable = ['kol_id', 'track', 'stage', 'next_action', 'next_action_at', 'followup_count', 'note', 'created_by'];

    /** Default in-memory agar $card->track terisi tanpa refresh dari DB. */
    protected $attributes = ['track' => self::TRACK_KOL, 'followup_count' => 0];

    protected function casts(): array
    {
        return ['next_action_at' => 'date'];
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }

    public function events()
    {
        return $this->hasMany(KolPipelineEvent::class, 'card_id')->latest('id');
    }

    /** Aktif = semua stage kecuali drop (dasar reminder & hitungan header). */
    public function isActive(): bool
    {
        return $this->stage !== 'drop';
    }

    public function scopeActive($q)
    {
        return $q->where('stage', '!=', 'drop');
    }
}
