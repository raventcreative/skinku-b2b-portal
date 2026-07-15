<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Backup database ke file .sql.gz + buang yang kedaluwarsa.
 *
 * Ini jaring pengaman utama: satu klik "hapus jurnal" atau migrasi keliru bisa
 * menghapus pembukuan permanen. Backup lokal TIDAK melindungi dari disk server
 * mati — unduh berkala lewat halaman Pengaturan Sistem, atau salin ke luar server.
 */
class DbBackupCommand extends Command
{
    protected $signature = 'db:backup {--keep=14 : Simpan N backup terakhir}';

    protected $description = 'Backup database ke storage/app/backups (.sql.gz), buang yang lama';

    public function handle(): int
    {
        $db = config('database.connections.mysql');
        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);

        // Nama file pakai waktu server; aman diurutkan secara leksikografis.
        $file = $dir.DIRECTORY_SEPARATOR.'db-'.now()->format('Y-m-d_His').'.sql.gz';

        $dump = $this->mysqldumpPath();
        if (! $dump) {
            $this->error('mysqldump tidak ditemukan — backup dibatalkan.');
            Log::error('[db:backup] mysqldump tidak ditemukan');

            return self::FAILURE;
        }

        // Password lewat env var (MYSQL_PWD), bukan argumen CLI — argumen terlihat
        // di daftar proses pada shared hosting.
        $cmd = [
            $dump,
            '--host='.($db['host'] ?? '127.0.0.1'),
            '--port='.($db['port'] ?? 3306),
            '--user='.($db['username'] ?? 'root'),
            '--single-transaction',
            '--quick',
            '--routines',
            '--no-tablespaces',   // shared hosting jarang punya izin PROCESS
            $db['database'],
        ];

        $gzip = new Process(['gzip', '-c']);
        $proc = new Process($cmd, base_path(), ['MYSQL_PWD' => (string) ($db['password'] ?? '')], null, 600);

        try {
            $proc->mustRun();
            $sql = $proc->getOutput();
            if (trim($sql) === '') {
                throw new \RuntimeException('mysqldump menghasilkan output kosong');
            }
            // gzip via PHP supaya tak bergantung biner gzip di server.
            $gz = gzencode($sql, 6);
            File::put($file, $gz);
        } catch (\Throwable $e) {
            $this->error('Backup gagal: '.$e->getMessage());
            Log::error('[db:backup] gagal: '.$e->getMessage());
            @unlink($file);

            return self::FAILURE;
        }

        $size = round(filesize($file) / 1048576, 2);
        $pruned = $this->prune($dir, (int) $this->option('keep'));

        $msg = 'Backup OK: '.basename($file)." ({$size} MB)".($pruned ? ", {$pruned} backup lama dihapus" : '');
        $this->info($msg);
        Log::info('[db:backup] '.$msg);

        return self::SUCCESS;
    }

    /** Sisakan N backup terbaru. */
    private function prune(string $dir, int $keep): int
    {
        $files = collect(File::files($dir))
            ->filter(fn ($f) => str_ends_with($f->getFilename(), '.sql.gz'))
            ->sortByDesc(fn ($f) => $f->getFilename())
            ->values();

        $old = $files->slice(max($keep, 1));
        $old->each(fn ($f) => File::delete($f->getPathname()));

        return $old->count();
    }

    /** Cari mysqldump — path berbeda antar hosting. */
    private function mysqldumpPath(): ?string
    {
        $candidates = [
            env('MYSQLDUMP_PATH'),          // override kalau hosting menaruhnya di tempat aneh
            'mysqldump',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/opt/alt/mysql/usr/bin/mysqldump',
            'C:\xampp\mysql\bin\mysqldump.exe',  // dev lokal
        ];
        foreach (array_filter($candidates) as $p) {
            $probe = new Process([$p, '--version']);
            $probe->run();
            if ($probe->isSuccessful()) {
                return $p;
            }
        }

        return null;
    }
}
