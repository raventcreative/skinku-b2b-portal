<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu pembayaran (cicilan) gaji Tim Gapok — banyak baris per (kol, bulan). */
class KolGapokPayment extends Model
{
    protected $fillable = ['kol_id', 'period', 'amount', 'paid_at', 'note', 'created_by'];

    // period disimpan & dicocokkan sbg string 'Y-m-d' (tanggal 1 bulan), sama
    // seperti KolGapokSalary — jangan di-cast 'date' agar WHERE tak meleset.
    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'date'];
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }
}
