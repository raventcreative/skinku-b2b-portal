<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Alias username affiliate → KOL. Dipakai KolAffiliateService::import untuk
 * mencocokkan username asing yang pernah ditautkan manual.
 */
class KolUsernameAlias extends Model
{
    protected $fillable = ['username', 'kol_id', 'created_by'];

    /** Normalisasi: tanpa '@', lowercase, trim. */
    public static function norm(string $u): string
    {
        return mb_strtolower(ltrim(trim($u), '@'));
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }
}
