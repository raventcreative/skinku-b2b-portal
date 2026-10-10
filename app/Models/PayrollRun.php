<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Payroll satu bulan (menu HR → Payroll). Draf = masih bisa diubah & dihitung ulang; dikunci = angka beku, jurnal
 * Akuntansi dibuat (journal_id), slip final. Buka kunci → jurnal di-void, kembali draf.
 */
class PayrollRun extends Model
{
    public const STATUSES = ['draf' => 'Draf', 'dikunci' => 'Dikunci'];

    protected $fillable = ['period', 'status', 'locked_at', 'locked_by', 'journal_id', 'created_by'];

    protected function casts(): array
    {
        return ['period' => 'date', 'locked_at' => 'datetime'];
    }

    public function items()
    {
        return $this->hasMany(PayrollItem::class)->orderBy('employee_name');
    }

    public function journal()
    {
        return $this->belongsTo(AccJournal::class, 'journal_id');
    }

    public function locker()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function dikunci(): bool
    {
        return $this->status === 'dikunci';
    }

    /** Mis. "Oktober 2026". */
    public function label(): string
    {
        return $this->period->translatedFormat('F Y');
    }
}
