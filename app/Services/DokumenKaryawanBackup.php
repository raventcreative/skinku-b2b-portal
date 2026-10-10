<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Backup dokumen karyawan (menu HR): semua file di disk privat yang foldernya diawali "hr_" (KTP, NPWP, kontrak,
 * CV kandidat, …) dizip ke folder backup yang sama dgn backup database (storage/app/backups), dijalankan db:backup.
 * Zip baru HANYA bila isi dokumen berubah sejak zip terakhir (sidik jari di nama file) → hemat ruang hosting.
 * Unduhannya khusus izin hr.manage (lihat SettingController).
 */
class DokumenKaryawanBackup
{
    public const AWALAN = 'dokumen-karyawan-';

    /** Jumlah zip dokumen yang disimpan (zip baru hanya muncul saat dokumen berubah). */
    public const SIMPAN = 7;

    public function jalankan(string $dir): string
    {
        $disk = Storage::disk(Employee::DISK);
        $files = collect($disk->allFiles())->filter(fn (string $p) => str_starts_with($p, 'hr_'))->sort()->values();
        if ($files->isEmpty()) {
            return 'Dokumen karyawan: belum ada.';
        }

        // Sidik jari isi: path + ukuran + waktu ubah tiap file → berubah bila ada dokumen ditambah/diganti/dihapus.
        $sidik = substr(md5($files->map(fn ($p) => $p.'|'.$disk->size($p).'|'.$disk->lastModified($p))->implode("\n")), 0, 10);
        File::ensureDirectoryExists($dir);
        $terakhir = collect(File::files($dir))->map->getFilename()
            ->filter(fn ($n) => str_starts_with($n, self::AWALAN) && str_ends_with($n, '.zip'))->sort()->last();
        if ($terakhir && str_ends_with($terakhir, '-'.$sidik.'.zip')) {
            return "Dokumen karyawan: tidak berubah ({$files->count()} file), zip terakhir masih berlaku.";
        }

        $nama = self::AWALAN.now()->format('Y-m-d_His').'-'.$sidik.'.zip';
        $zip = new ZipArchive;
        if ($zip->open($dir.DIRECTORY_SEPARATOR.$nama, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('zip dokumen karyawan tak bisa dibuat');
        }
        foreach ($files as $p) {
            $zip->addFile($disk->path($p), $p);
        }
        $zip->close();
        $this->pangkas($dir);

        return "Dokumen karyawan: {$files->count()} file → {$nama}";
    }

    /** Sisakan SIMPAN zip dokumen terbaru. */
    private function pangkas(string $dir): void
    {
        collect(File::files($dir))
            ->filter(fn ($f) => str_starts_with($f->getFilename(), self::AWALAN) && str_ends_with($f->getFilename(), '.zip'))
            ->sortByDesc(fn ($f) => $f->getFilename())->values()
            ->slice(self::SIMPAN)
            ->each(fn ($f) => File::delete($f->getPathname()));
    }
}
