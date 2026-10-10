<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu baris payroll = slip gaji satu karyawan untuk satu bulan. Nilai dihitung PayrollService::hitung(). */
class PayrollItem extends Model
{
    protected $fillable = [
        'payroll_run_id', 'employee_id', 'employee_name', 'position', 'ptkp_status', 'ter_category', 'cost_group',
        'bpjs_kesehatan', 'bpjs_tk', 'bpjs_jp', 'base_salary', 'fixed_allowance', 'overtime', 'bonus', 'kasbon',
        'kes_company', 'kes_employee', 'jht_company', 'jht_employee', 'jp_company', 'jp_employee', 'jkk', 'jkm',
        'bruto', 'ter_rate', 'annual', 'tax_detail', 'pph21_auto', 'pph21_override', 'pph21', 'net_pay', 'notes',
    ];

    protected function casts(): array
    {
        $angka = ['base_salary', 'fixed_allowance', 'overtime', 'bonus', 'kasbon', 'kes_company', 'kes_employee',
            'jht_company', 'jht_employee', 'jp_company', 'jp_employee', 'jkk', 'jkm', 'bruto', 'ter_rate',
            'pph21_auto', 'pph21_override', 'pph21', 'net_pay'];

        return array_fill_keys($angka, 'integer') + [
            'bpjs_kesehatan' => 'boolean', 'bpjs_tk' => 'boolean', 'bpjs_jp' => 'boolean',
            'annual' => 'boolean', 'tax_detail' => 'array',
        ];
    }

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /** Gaji pokok + tunjangan + lembur + bonus. */
    public function penghasilan(): int
    {
        return $this->base_salary + $this->fixed_allowance + $this->overtime + $this->bonus;
    }

    /** Iuran BPJS yang dipotong dari gaji karyawan (Kesehatan 1%, JHT 2%, JP 1%). */
    public function bpjsKaryawan(): int
    {
        return $this->kes_employee + $this->jht_employee + $this->jp_employee;
    }

    /** Iuran BPJS yang ditanggung perusahaan (Kesehatan 4%, JHT 3,7%, JP 2%, JKK, JKM). */
    public function bpjsPerusahaan(): int
    {
        return $this->kes_company + $this->jht_company + $this->jp_company + $this->jkk + $this->jkm;
    }

    /** Iuran pensiun/hari tua bagian karyawan — pengurang penghasilan neto setahun (JHT 2% + JP 1%). */
    public function iuranPensiun(): int
    {
        return $this->jht_employee + $this->jp_employee;
    }

    public function totalPotongan(): int
    {
        return $this->bpjsKaryawan() + $this->pph21 + $this->kasbon;
    }
}
