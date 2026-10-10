<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Services\BacaCvService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Baca CV dengan AI" di BACKGROUND (pola GenerateOkrDraftJob): request web cuma menyimpan CV & menjadwalkan job ini,
 * form Tambah kandidat menunggu hasilnya lewat polling. Proses AI yang lama (berkas besar, pindah ke AI cadangan) tak
 * lagi memutus koneksi web (ERR_HTTP2_PROTOCOL_ERROR di hosting). Hasil di cache "baca-cv:{token}". Hanya lewat
 * antrean (worker scheduler tiap 15 detik) — dispatchAfterResponse tetap menahan browser di LiteSpeed hosting.
 */
class BacaCvJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** AI (termasuk rantai cadangan) boleh lama; jangan di-retry otomatis (hindari biaya dobel). */
    public int $timeout = 300;

    public int $tries = 1;

    /** Lama hasil disimpan di cache. */
    public const BERLAKU_MENIT = 120;

    public function __construct(public string $token, public string $path, public string $mime) {}

    public static function kunci(string $token): string
    {
        return "baca-cv:{$token}";
    }

    /** Tandai "antri" saat CV baru diterima (sebelum worker mengambil job). */
    public static function antri(string $token): void
    {
        Cache::put(self::kunci($token), ['status' => 'antri', 'hasil' => null, 'pesan' => null], now()->addMinutes(self::BERLAKU_MENIT));
    }

    public function handle(BacaCvService $baca): void
    {
        // Hanya proses selama masih "antri" — sudah selesai/gagal, atau sudah dibuang (kandidat disimpan, CV lain dibaca,
        // kedaluwarsa) → lewati, tak perlu memanggil AI.
        if ((Cache::get(self::kunci($this->token))['status'] ?? null) !== 'antri') {
            return;
        }

        $disk = Storage::disk(Employee::DISK);
        if (! $disk->exists($this->path)) {
            $this->simpan('gagal', pesan: 'File CV sudah tidak ada.');

            return;
        }

        try {
            $hasil = $baca->baca($disk->path($this->path), $this->mime);
        } catch (Throwable $e) {
            $this->simpan('gagal', pesan: 'AI belum bisa membaca CV ('.Str::limit($e->getMessage(), 300, '').').');

            return;
        }

        array_filter($hasil)
            ? $this->simpan('selesai', $hasil)
            : $this->simpan('gagal', pesan: 'AI tidak menemukan data kandidat di file ini.');
    }

    public function failed(Throwable $e): void
    {
        $this->simpan('gagal', pesan: 'AI belum bisa membaca CV ('.Str::limit($e->getMessage(), 300, '').').');
    }

    /** @param  array<string,mixed>|null  $hasil */
    private function simpan(string $status, ?array $hasil = null, ?string $pesan = null): void
    {
        Cache::put(self::kunci($this->token), ['status' => $status, 'hasil' => $hasil, 'pesan' => $pesan], now()->addMinutes(self::BERLAKU_MENIT));
    }
}
