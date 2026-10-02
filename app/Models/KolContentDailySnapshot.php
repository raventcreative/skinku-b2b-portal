<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Potret harian (kumulatif bulan) satu video SKINKU kreator. Lihat migrasi 000158. */
class KolContentDailySnapshot extends Model
{
    protected $fillable = ['kol_id', 'content_id', 'title', 'period', 'captured_on', 'posted_at', 'views', 'gmv', 'items_sold'];

    // period & captured_on sengaja TIDAK di-cast 'date' (disimpan & dicocokkan string 'Y-m-d',
    // sama seperti kol_creator_content_stats) — hindari mismatch 'Y-m-d 00:00:00' → baris dobel.
    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'views' => 'integer',
            'gmv' => 'integer',
            'items_sold' => 'integer',
        ];
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }
}
