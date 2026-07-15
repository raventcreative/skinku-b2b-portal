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

        $sql = null;
        $errors = [];
        foreach ($this->connectionAttempts($db) as $label => $args) {
            try {
                $sql = $this->dump($dump, $db, $args);
                if ($label !== 'default') {
                    $this->line("(tersambung via {$label})");
                }
                break;
            } catch (\Throwable $e) {
                $errors[] = "{$label}: ".trim($e->getMessage());
            }
        }

        if ($sql === null) {
            $why = implode(' | ', $errors);
            $this->error('Backup gagal. '.$why);
            Log::error('[db:backup] gagal: '.$why);

            return self::FAILURE;
        }

        // gzip via PHP supaya tak bergantung biner gzip di server.
        File::put($file, gzencode($sql, 6));

        $size = round(filesize($file) / 1048576, 2);
        $pruned = $this->prune($dir, (int) $this->option('keep'));

        $msg = 'Backup OK: '.basename($file)." ({$size} MB)".($pruned ? ", {$pruned} backup lama dihapus" : '');
        $this->info($msg);
        Log::info('[db:backup] '.$msg);

        return self::SUCCESS;
    }

    /**
     * Cara sambung yang dicoba berurutan. 'localhost' bisa lolos ke IPv6 (::1)
     * sedangkan hak akses user MySQL biasanya cuma untuk localhost/IPv4 →
     * "Access denied for user ...@'::1'". Maka IPv4 eksplisit dicoba duluan.
     *
     * @return array<string, array<int, string>>
     */
    private function connectionAttempts(array $db): array
    {
        $host = (string) ($db['host'] ?? '127.0.0.1');
        $port = (string) ($db['port'] ?? 3306);
        $socket = (string) ($db['unix_socket'] ?? '');
        $out = [];

        if ($host === 'localhost' || $host === '' || $host === '::1') {
            $out['IPv4 127.0.0.1'] = ['--protocol=TCP', '--host=127.0.0.1', '--port='.$port];
            $out['socket lokal'] = ['--protocol=SOCKET'];
        } else {
            $out['default'] = ['--host='.$host, '--port='.$port];
        }
        if ($socket !== '') {
            $out['socket '.$socket] = ['--protocol=SOCKET', '--socket='.$socket];
        }
        // Terakhir: apa adanya sesuai .env
        $out['apa adanya'] = ['--host='.($host ?: '127.0.0.1'), '--port='.$port];

        return $out;
    }

    /** Jalankan mysqldump dengan satu cara sambung; lempar kalau gagal. */
    private function dump(string $bin, array $db, array $connArgs): string
    {
        $cmd = array_merge([$bin], $connArgs, [
            '--user='.($db['username'] ?? 'root'),
            '--single-transaction',
            '--quick',
            '--routines',
            '--no-tablespaces',   // shared hosting jarang punya izin PROCESS
            $db['database'],
        ]);

        // Password lewat env var (MYSQL_PWD), bukan argumen CLI — argumen terlihat
        // di daftar proses pada shared hosting.
        $proc = new Process($cmd, base_path(), ['MYSQL_PWD' => (string) ($db['password'] ?? '')], null, 600);
        $proc->mustRun();

        $sql = $proc->getOutput();
        if (! str_contains($sql, 'CREATE TABLE')) {
            throw new \RuntimeException('output mysqldump tidak berisi tabel');
        }

        return $sql;
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
