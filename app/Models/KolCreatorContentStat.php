<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Jumlah video & LIVE satu kreator dalam satu bulan (agregat Analytics API). */
class KolCreatorContentStat extends Model
{
    protected $fillable = ['kol_id', 'period', 'videos', 'lives'];

    // period sengaja TIDAK di-cast 'date' (disimpan & dicocokkan string 'Y-m-d',
    // sama seperti kol_gapok_salaries — hindari mismatch 'Y-m-d 00:00:00').
    protected function casts(): array
    {
        return ['videos' => 'integer', 'lives' => 'integer'];
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }
}
