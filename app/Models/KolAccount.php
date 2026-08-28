<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Akun platform tambahan milik satu KOL (akun utama tetap di kols). */
class KolAccount extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['kol_id', 'platform', 'username', 'followers', 'profile_link'];

    protected function casts(): array
    {
        return ['followers' => 'integer'];
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }

    public function platformLabel(): string
    {
        return config("kol.platforms.{$this->platform}.label", ucfirst((string) $this->platform));
    }
}
