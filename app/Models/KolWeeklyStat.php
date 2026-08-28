<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Statistik mingguan manual per KOL (GMV/order/komisi/konten/views). */
class KolWeeklyStat extends Model
{
    protected $fillable = ['kol_id', 'week_start', 'gmv', 'orders', 'commission', 'content_count', 'views', 'created_by'];

    protected function casts(): array
    {
        return ['week_start' => 'date', 'gmv' => 'integer', 'orders' => 'integer',
            'commission' => 'integer', 'content_count' => 'integer', 'views' => 'integer'];
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }
}
