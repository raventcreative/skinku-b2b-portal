<?php

namespace Tests\Feature;

use App\Models\RolePermission;
use App\Models\User;
use App\Services\DokumenKaryawanBackup;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Dokumen karyawan (disk privat, folder hr_*) ikut ke folder backup (storage/app/backups) lewat db:backup: zip baru
 * hanya bila isinya berubah, 7 terakhir disimpan, unduh khusus izin hr.manage.
 */
class BackupDokumenKaryawanTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup-uji-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function zipDokumen(): array
    {
        return collect(File::files($this->dir))->map->getFilename()->filter(fn ($n) => str_ends_with($n, '.zip'))->sort()->values()->all();
    }

    private function isiZip(string $nama): array
    {
        $zip = new ZipArchive;
        $zip->open($this->dir.DIRECTORY_SEPARATOR.$nama);
        $isi = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $isi[] = $zip->getNameIndex($i);
        }
        $zip->close();
        sort($isi);

        return $isi;
    }

    public function test_zip_hanya_dokumen_hr_dan_hanya_bila_berubah(): void
    {
        $svc = app(DokumenKaryawanBackup::class);
        $this->assertSame('Dokumen karyawan: belum ada.', $svc->jalankan($this->dir));

        Storage::disk('local')->put('hr_ktp_kk/rina.jpg', 'foto ktp');
        Storage::disk('local')->put('hr_kontrak/rina.pdf', 'kontrak');
        Storage::disk('local')->put('kol-import/sementara.json', '{}'); // bukan dokumen HR → tak ikut

        $this->assertStringContainsString('2 file →', $svc->jalankan($this->dir));
        $zip = $this->zipDokumen();
        $this->assertCount(1, $zip);
        $this->assertStringStartsWith(DokumenKaryawanBackup::AWALAN, $zip[0]);
        $this->assertSame(['hr_kontrak/rina.pdf', 'hr_ktp_kk/rina.jpg'], $this->isiZip($zip[0]));

        // Tanpa perubahan → tak membuat zip baru.
        $this->assertStringContainsString('tidak berubah', $svc->jalankan($this->dir));
        $this->assertCount(1, $this->zipDokumen());

        // Dokumen baru → zip baru berisi semuanya.
        $this->travel(1)->seconds();
        Storage::disk('local')->put('hr_npwp/rina.pdf', 'npwp');
        $svc->jalankan($this->dir);
        $zip = $this->zipDokumen();
        $this->assertCount(2, $zip);
        $this->assertSame(['hr_kontrak/rina.pdf', 'hr_ktp_kk/rina.jpg', 'hr_npwp/rina.pdf'], $this->isiZip($zip[1]));
    }

    public function test_simpan_tujuh_zip_terakhir(): void
    {
        $svc = app(DokumenKaryawanBackup::class);
        for ($i = 1; $i <= 9; $i++) {
            $this->travel(1)->seconds();
            Storage::disk('local')->put("hr_kontrak/k{$i}.pdf", "isi {$i}");
            $svc->jalankan($this->dir);
        }
        $this->assertCount(DokumenKaryawanBackup::SIMPAN, $this->zipDokumen());
    }

    public function test_unduh_zip_dokumen_khusus_izin_kelola_karyawan(): void
    {
        $backups = storage_path('app/backups');
        File::ensureDirectoryExists($backups);
        $nama = DokumenKaryawanBackup::AWALAN.'2026-10-10_023000-abc123.zip';
        File::put($backups.DIRECTORY_SEPARATOR.$nama, 'zip uji');
        $mk = fn (string $role, string $u) => User::create(['name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE]);

        try {
            $sa = $mk(User::ROLE_SUPER_ADMIN, 'sa');
            $this->actingAs($sa)->get(route('settings.index'))->assertOk()->assertSee($nama)->assertSee('dokumen karyawan');
            $this->actingAs($sa)->get(route('settings.backup.download', $nama))->assertOk();

            // Admin diberi Pengaturan Sistem tapi bukan Kelola karyawan → zip tak tampil & unduh ditolak.
            RolePermission::create(['role' => User::ROLE_ADMIN, 'permission_key' => 'system_settings', 'allowed' => true]);
            Permissions::flushCache();
            $admin = $mk(User::ROLE_ADMIN, 'adm');
            $this->actingAs($admin)->get(route('settings.index'))->assertOk()->assertDontSee($nama);
            $this->actingAs($admin)->get(route('settings.backup.download', $nama))->assertForbidden();
        } finally {
            File::delete($backups.DIRECTORY_SEPARATOR.$nama);
        }
    }
}
