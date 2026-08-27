<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KolContent extends Model
{
    protected $fillable = ['kol_id', 'kol_deal_id', 'platform', 'url', 'title', 'label', 'posted_at', 'created_by'];

    /** Default in-memory (platform TikTok, earned) agar terisi tanpa refresh DB. */
    protected $attributes = ['platform' => 'tiktok', 'label' => 'earned'];

    protected function casts(): array
    {
        return ['posted_at' => 'date'];
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }

    public function deal()
    {
        return $this->belongsTo(KolDeal::class, 'kol_deal_id');
    }

    public function snapshots()
    {
        return $this->hasMany(KolContentSnapshot::class);
    }

    /** Snapshot views terbaru — sumber kolom "views" di daftar & ringkasan. */
    public function latestSnapshot()
    {
        return $this->hasOne(KolContentSnapshot::class)->latestOfMany('captured_on');
    }
}
