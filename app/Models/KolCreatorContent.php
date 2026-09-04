<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu konten (video/LIVE) satu kreator dalam satu bulan (detail Tim Gapok). */
class KolCreatorContent extends Model
{
    protected $fillable = [
        'kol_id', 'period', 'type', 'content_id', 'title',
        'views', 'gmv', 'items_sold', 'sku_orders', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'views' => 'integer', 'gmv' => 'integer',
            'items_sold' => 'integer', 'sku_orders' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }

    /** Link ke konten di TikTok — video punya URL; LIVE arahkan ke profil. */
    public function url(): ?string
    {
        $handle = $this->kol?->handle();
        if (! $handle) {
            return null;
        }

        return $this->type === 'video' && $this->content_id
            ? "https://www.tiktok.com/@{$handle}/video/{$this->content_id}"
            : "https://www.tiktok.com/@{$handle}";
    }
}
