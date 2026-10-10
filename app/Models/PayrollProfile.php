<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Komponen gaji tetap satu karyawan (menu HR → Payroll → Data gaji). Terpisah dari tabel employees supaya angka gaji
 * hanya bisa diakses izin payroll.*, bukan hr.view. opening_* = saldo awal tahun (bulan yang digaji di luar portal).
 */
class PayrollProfile extends Model
{
    /** Kelompok biaya → akun beban & utang gaji di jurnal (lihat PayrollService::AKUN). */
    public const COST_GROUPS = ['operasional' => 'Operasional', 'produksi' => 'Produksi (HPP)'];

    protected $fillable = [
        'employee_id', 'base_salary', 'fixed_allowance', 'bpjs_kesehatan', 'bpjs_tk', 'bpjs_jp', 'cost_group',
        'opening_year', 'opening_months', 'opening_bruto', 'opening_iuran', 'opening_pph21',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'integer', 'fixed_allowance' => 'integer',
            'bpjs_kesehatan' => 'boolean', 'bpjs_tk' => 'boolean', 'bpjs_jp' => 'boolean',
            'opening_year' => 'integer', 'opening_months' => 'integer', 'opening_bruto' => 'integer',
            'opening_iuran' => 'integer', 'opening_pph21' => 'integer',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
