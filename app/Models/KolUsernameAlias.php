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

    /** Username TikTok → id KOL (kolom tiktok_username, lalu alias); null = bukan KOL. Dipakai sync affiliate & alat AI. */
    public static function kolId(string $username): ?int
    {
        $u = self::norm($username);
        $id = Kol::whereRaw('LOWER(tiktok_username) = ?', [$u])->value('id')
            ?? self::where('username', $u)->value('kol_id');

        return $id ? (int) $id : null;
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }
}
