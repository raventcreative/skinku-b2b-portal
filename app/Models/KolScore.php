<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Snapshot skor KOL (aps/kss) — jejak historis, satu baris per KOL per type per hari. */
class KolScore extends Model
{
    public const TYPE_APS = 'aps';

    public const TYPE_KSS = 'kss';

    protected $fillable = ['kol_id', 'type', 'score', 'label', 'meta', 'captured_on', 'created_by'];

    protected function casts(): array
    {
        return ['captured_on' => 'date', 'meta' => 'array', 'score' => 'float'];
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }
}
