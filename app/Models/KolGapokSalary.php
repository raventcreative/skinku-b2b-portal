<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Gaji pokok bulanan satu anggota Tim Affiliate Gapok (per bulan → riwayat). */
class KolGapokSalary extends Model
{
    protected $fillable = ['kol_id', 'period', 'monthly_salary', 'note', 'created_by'];

    // period sengaja TIDAK di-cast 'date': disimpan & dicocokkan sbg string
    // 'Y-m-d' (tanggal 1 bulan). Cast 'date' bikin nilai tersimpan 'Y-m-d 00:00:00'
    // → WHERE period='Y-m-d' meleset (SQLite & updateOrCreate). String konsisten.
    protected function casts(): array
    {
        return ['monthly_salary' => 'integer'];
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }
}
