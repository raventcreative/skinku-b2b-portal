<?php

namespace App\Models;

use App\Models\Concerns\HasFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Karyawan SKINKU (menu HR). Data identitas (SENSITIF) terenkripsi, tersembunyi dari serialisasi, hanya tampil utk
 * izin hr.manage & tak pernah dicatat nilainya di audit. Dokumen onboarding = file koleksi "hr_{item}" di disk privat.
 */
class Employee extends Model
{
    use HasFiles, SoftDeletes;

    public const TYPES = [
        'percobaan' => 'Masa percobaan',
        'kontrak' => 'Kontrak (PKWT)',
        'tetap' => 'Tetap (PKWTT)',
        'magang' => 'Magang',
        'harian' => 'Harian / freelance',
    ];

    public const STATUSES = ['aktif' => 'Aktif', 'keluar' => 'Keluar'];

    public const GENDERS = ['L' => 'Laki-laki', 'P' => 'Perempuan'];

    /** Status PTKP (dasar kategori PPh 21 TER di payroll). */
    public const PTKP = ['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3'];

    /** Checklist onboarding (urutan tampil). Dokumen tiap item: koleksi file "hr_{key}". */
    public const ONBOARDING = [
        'ktp_kk' => 'KTP & KK',
        'npwp' => 'NPWP',
        'rekening' => 'Rekening gaji',
        'bpjs' => 'BPJS Kesehatan & Ketenagakerjaan',
        'kontrak' => 'Kontrak ditandatangani',
    ];

    /** Kolom identitas: terenkripsi, hanya izin hr.manage, audit hanya mencatat namanya. */
    public const SENSITIF = ['nik', 'npwp', 'address', 'bank_account', 'bank_account_name', 'bpjs_kesehatan', 'bpjs_ketenagakerjaan', 'emergency_contact'];

    /** Dokumen HR disimpan di disk privat (storage/app/private), diunduh lewat route berizin — bukan URL publik. */
    public const DISK = 'local';

    /** Pengingat masa percobaan / kontrak: berakhir dalam sekian hari (atau sudah lewat). */
    public const HARI_PENGINGAT = 30;

    protected $fillable = [
        'user_id', 'name', 'position', 'department', 'employment_type', 'status', 'join_date', 'probation_end',
        'contract_end', 'resign_date', 'resign_reason', 'phone', 'email', 'birth_date', 'gender', 'ptkp_status',
        'nik', 'npwp', 'address', 'bank_name', 'bank_account', 'bank_account_name', 'bpjs_kesehatan',
        'bpjs_ketenagakerjaan', 'emergency_contact', 'onboarding', 'notes', 'created_by',
    ];

    protected $hidden = self::SENSITIF;

    protected function casts(): array
    {
        return array_fill_keys(self::SENSITIF, 'encrypted') + [
            'join_date' => 'date',
            'probation_end' => 'date',
            'contract_end' => 'date',
            'resign_date' => 'date',
            'birth_date' => 'date',
            'onboarding' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Kode karyawan dari id → unik & berurutan tanpa tabel penomoran.
        static::created(function (Employee $e) {
            $e->forceFill(['kode' => 'KRY-'.str_pad((string) $e->id, 4, '0', STR_PAD_LEFT)])->saveQuietly();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function aktif(): bool
    {
        return $this->status === 'aktif';
    }

    /** @return array{selesai:string,oleh:?int}|null */
    public function itemOnboarding(string $item): ?array
    {
        return $this->onboarding[$item] ?? null;
    }

    public function onboardingSelesai(): int
    {
        return count(array_intersect_key((array) $this->onboarding, self::ONBOARDING));
    }

    public function onboardingLengkap(): bool
    {
        return $this->onboardingSelesai() === count(self::ONBOARDING);
    }

    /** Masa percobaan perlu ditinjau: masih percobaan & berakhir ≤ 30 hari lagi (atau sudah lewat). */
    public function percobaanPerluDitinjau(): bool
    {
        return $this->aktif() && $this->employment_type === 'percobaan' && $this->probation_end
            && $this->probation_end->lte(now()->startOfDay()->addDays(self::HARI_PENGINGAT));
    }

    /** Kontrak berakhir ≤ 30 hari lagi (atau sudah lewat) dan karyawan masih aktif. */
    public function kontrakSegeraBerakhir(): bool
    {
        return $this->aktif() && $this->contract_end
            && $this->contract_end->lte(now()->startOfDay()->addDays(self::HARI_PENGINGAT));
    }
}
