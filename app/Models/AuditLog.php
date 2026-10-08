<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditLog extends Model
{
    use HasFactory;

    public $timestamps = false; // only created_at is tracked

    protected $fillable = [
        'action', 'target_type', 'target_id', 'target_user_id', 'target_email',
        'performed_by', 'performed_by_email', 'before_data', 'after_data',
        'ip_address', 'user_agent', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before_data' => 'array',
            'after_data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * Isi before/after dalam kalimat pendek utk kolom "Perubahan" halaman Audit Log:
     * berubah "gaji: 1.000.000 → 1.500.000", sama di keduanya (konteks) "bulan: 2026-10", hanya sesudah
     * "jumlah: 5", hanya sebelum "amount: 400.000 → —" (dihapus).
     *
     * @return array<int,string>
     */
    public function ringkasPerubahan(): array
    {
        $b = (array) ($this->before_data ?? []);
        $a = (array) ($this->after_data ?? []);
        $out = [];
        foreach (array_unique([...array_keys($b), ...array_keys($a)]) as $k) {
            $f = fn ($v) => self::nilaiAudit((string) $k, $v);
            $out[] = match (true) {
                ! array_key_exists($k, $b) => "{$k}: {$f($a[$k])}",
                ! array_key_exists($k, $a) => "{$k}: {$f($b[$k])} → —",
                $b[$k] == $a[$k] => "{$k}: {$f($a[$k])}",
                default => "{$k}: {$f($b[$k])} → {$f($a[$k])}",
            };
        }

        return $out;
    }

    /** Format satu nilai audit: angka pakai titik ribuan (kecuali *_id), rahasia disensor, teks/array dipotong. */
    private static function nilaiAudit(string $kunci, mixed $v): string
    {
        return match (true) {
            (bool) preg_match('/password|token|secret/i', $kunci) => '***',
            $v === null || $v === '' => '—',
            is_bool($v) => $v ? 'ya' : 'tidak',
            (is_int($v) || is_float($v)) && ! preg_match('/(^|_)id$/', $kunci) => number_format($v, is_float($v) && floor($v) != $v ? 2 : 0, ',', '.'),
            is_array($v) => Str::limit(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 80),
            default => Str::limit((string) $v, 80),
        };
    }
}
