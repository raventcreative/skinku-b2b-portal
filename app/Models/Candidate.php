<?php

namespace App\Models;

use App\Models\Concerns\HasFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kandidat rekrutmen (menu HR → Rekrutmen). Tahap lamar → psikotes → interview → diterima / ditolak; CV = file
 * koleksi "hr_cv" di disk privat (ikut backup dokumen karyawan). Diterima → "Jadikan karyawan" (employee_id terisi).
 */
class Candidate extends Model
{
    use HasFiles, SoftDeletes;

    public const STAGES = [
        'lamar' => 'Lamar',
        'psikotes' => 'Psikotes',
        'interview' => 'Interview',
        'diterima' => 'Diterima',
        'ditolak' => 'Ditolak',
    ];

    protected $fillable = ['job_opening_id', 'name', 'phone', 'email', 'source', 'stage', 'interview_at', 'notes', 'employee_id', 'created_by'];

    protected function casts(): array
    {
        return ['interview_at' => 'datetime'];
    }

    public function opening()
    {
        return $this->belongsTo(JobOpening::class, 'job_opening_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function psychotests()
    {
        return $this->hasMany(PsychotestSession::class)->latest('id');
    }

    /** Sesi psikotes terbaru (yang ditampilkan di detail kandidat). */
    public function psikotesTerakhir(): ?PsychotestSession
    {
        return $this->psychotests()->first();
    }

    public function stageLabel(): string
    {
        return self::STAGES[$this->stage] ?? $this->stage;
    }

    /** Link WhatsApp berisi pesan (08xx → 62xx); tanpa nomor HP → HR memilih kontak sendiri di WhatsApp. */
    public function whatsappUrl(string $pesan): string
    {
        $d = preg_replace('/\D/', '', (string) $this->phone);
        if (str_starts_with($d, '0')) {
            $d = '62'.substr($d, 1);
        }

        return 'https://wa.me/'.$d.'?text='.rawurlencode($pesan);
    }
}
