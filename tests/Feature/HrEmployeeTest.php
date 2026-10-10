<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\File;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Menu HR → Karyawan (Fase 1): hr.view = data kerja (default admin), hr.manage = kelola + identitas & dokumen
 * (default super admin), mitra diblok keras. Identitas terenkripsi, dokumen di disk privat, audit tanpa nilai sensitif.
 */
class HrEmployeeTest extends TestCase
{
    use RefreshDatabase;

    private const NIK = '3578010101900001';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function karyawan(array $extra = []): Employee
    {
        return Employee::create($extra + ['name' => 'Rina Putri', 'position' => 'Admin Gudang', 'department' => 'Gudang',
            'employment_type' => 'percobaan', 'status' => 'aktif', 'join_date' => '2026-10-01', 'nik' => self::NIK,
            'bank_name' => 'BCA', 'bank_account' => '1234567890', 'bank_account_name' => 'Rina Putri']);
    }

    public function test_akses_per_role_dan_mitra_diblok(): void
    {
        $e = $this->karyawan();
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $admin = $this->user(User::ROLE_ADMIN, 'adm');

        // Super admin: semua termasuk identitas.
        $this->actingAs($sa)->get(route('hr.employees.show', $e))->assertOk()->assertSee(self::NIK)->assertSee('1234567890');
        // Admin (hr.view): data kerja saja — identitas & tombol kelola tak tampil, aksi kelola 403.
        $this->actingAs($admin)->get(route('hr.employees.index'))->assertOk()->assertSee('Rina Putri')->assertDontSee('Tambah karyawan');
        $this->actingAs($admin)->get(route('hr.employees.show', $e))->assertOk()->assertSee('Admin Gudang')
            ->assertDontSee(self::NIK)->assertDontSee('1234567890')->assertSee('hanya untuk izin');
        $this->actingAs($admin)->get(route('hr.employees.create'))->assertForbidden();
        $this->actingAs($admin)->get(route('hr.employees.edit', $e))->assertForbidden();
        // Gudang (tanpa hr.view) & mitra diblok; mitra tetap diblok walau izinnya dicentang.
        $this->actingAs($this->user(User::ROLE_GUDANG, 'gdg'))->get(route('hr.employees.index'))->assertForbidden();
        RolePermission::create(['role' => User::ROLE_DISTRIBUTOR, 'permission_key' => 'hr.view', 'allowed' => true]);
        Permissions::flushCache();
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'dist'))->get(route('hr.employees.index'))->assertForbidden();

        // Menu sidebar mengikuti izin.
        $this->actingAs($admin)->get('/dashboard')->assertSee(route('hr.employees.index'), false);
        $this->actingAs($this->user(User::ROLE_GUDANG, 'gdg2'))->get('/dashboard')->assertDontSee(route('hr.employees.index'), false);
    }

    public function test_tambah_karyawan_identitas_terenkripsi_dan_audit_tanpa_nilai_sensitif(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $staf = $this->user(User::ROLE_GUDANG, 'rina.portal');

        $this->actingAs($sa)->post(route('hr.employees.store'), [
            'name' => 'Rina Putri', 'position' => 'Admin Gudang', 'department' => 'Gudang', 'employment_type' => 'percobaan',
            'status' => 'aktif', 'join_date' => '2026-10-20', 'probation_end' => '2027-01-20', 'user_id' => $staf->id,
            'nik' => self::NIK, 'npwp' => '12.345.678.9-012.000', 'bank_account' => '1234567890', 'ptkp_status' => 'TK/0',
        ])->assertRedirect();

        $e = Employee::sole();
        $this->assertSame(['KRY-0001', self::NIK, '1234567890', 'rina.portal'], [$e->kode, $e->nik, $e->bank_account, $e->user->username]);
        // Di database tersimpan terenkripsi, bukan teks asli; tak ikut serialisasi.
        $raw = DB::table('employees')->first();
        $this->assertNotSame(self::NIK, $raw->nik);
        $this->assertStringNotContainsString('1234567890', (string) $raw->bank_account);
        $this->assertArrayNotHasKey('nik', $e->toArray());
        // Audit mencatat data kerja + NAMA kolom identitas yang diisi, tanpa isinya.
        $log = AuditLog::where('action', 'create_employee')->sole();
        $this->assertSame('Admin Gudang', $log->after_data['position']);
        $this->assertSame(['nik', 'npwp', 'bank_account'], $log->after_data['data_identitas_diisi']);
        $this->assertStringNotContainsString(self::NIK, json_encode($log->after_data));
        $this->assertStringNotContainsString('1234567890', json_encode($log->after_data));
    }

    public function test_ubah_karyawan_audit_sebelum_sesudah_tanpa_nilai_identitas(): void
    {
        $e = $this->karyawan();
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');

        $this->actingAs($sa)->put(route('hr.employees.update', $e), [
            'name' => 'Rina Putri', 'position' => 'Kepala Gudang', 'department' => 'Gudang', 'employment_type' => 'tetap',
            'status' => 'aktif', 'join_date' => '2026-10-01', 'nik' => '3578010101900099', 'bank_name' => 'BCA',
            'bank_account' => '1234567890', 'bank_account_name' => 'Rina Putri',
        ])->assertRedirect(route('hr.employees.show', $e));

        $log = AuditLog::where('action', 'update_employee')->sole();
        $this->assertSame(['position' => 'Admin Gudang', 'employment_type' => 'percobaan'], $log->before_data);
        $this->assertSame(['position' => 'Kepala Gudang', 'employment_type' => 'tetap', 'data_identitas_diubah' => ['nik']], $log->after_data);
        $this->assertStringNotContainsString('3578010101900', json_encode([$log->before_data, $log->after_data]));
        $this->assertSame('3578010101900099', $e->fresh()->nik);

        // Status keluar wajib tanggal keluar; akun mitra tak bisa ditautkan.
        $this->actingAs($sa)->put(route('hr.employees.update', $e), ['name' => 'Rina', 'employment_type' => 'tetap', 'status' => 'keluar'])
            ->assertSessionHasErrors('resign_date');
        $mitra = $this->user(User::ROLE_RESELLER, 'mitra1');
        $this->actingAs($sa)->put(route('hr.employees.update', $e), ['name' => 'Rina', 'employment_type' => 'tetap', 'status' => 'aktif', 'user_id' => $mitra->id])
            ->assertSessionHasErrors('user_id');
    }

    public function test_onboarding_centang_dan_dokumen_privat(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $e = $this->karyawan();
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');

        $this->actingAs($sa)->post(route('hr.employees.onboarding', [$e, 'ktp_kk']))->assertRedirect();
        $this->assertSame([1, false], [$e->fresh()->onboardingSelesai(), $e->fresh()->onboardingLengkap()]);
        $this->assertSame($sa->id, $e->fresh()->itemOnboarding('ktp_kk')['oleh']);

        // Unggah dokumen → disk PRIVAT, bukan public.
        $this->actingAs($sa)->post(route('hr.employees.documents.store', [$e, 'ktp_kk']), [
            'dokumen' => UploadedFile::fake()->create('ktp-rina.pdf', 120, 'application/pdf'),
        ])->assertRedirect();
        $f = File::sole();
        $this->assertSame(['local', 'hr_ktp_kk', 'ktp-rina.pdf'], [$f->disk, $f->collection, $f->original_name]);
        Storage::disk('local')->assertExists($f->path);
        $this->assertSame([], Storage::disk('public')->allFiles());

        // Unduh hanya lewat route berizin; admin (hr.view) ditolak; file milik karyawan lain → 404.
        $this->actingAs($sa)->get(route('hr.employees.documents.show', [$e, $f]))->assertOk();
        $this->actingAs($this->user(User::ROLE_ADMIN, 'adm'))->get(route('hr.employees.documents.show', [$e, $f]))->assertForbidden();
        $lain = $this->karyawan(['name' => 'Budi']);
        $this->actingAs($sa)->get(route('hr.employees.documents.show', [$lain, $f]))->assertNotFound();
        $this->actingAs($sa)->post(route('hr.employees.onboarding', [$e, 'bukan-item']))->assertNotFound();

        // Halaman detail: dokumen tampil utk pengelola.
        $this->actingAs($sa)->get(route('hr.employees.show', $e))->assertOk()->assertSee('ktp-rina.pdf')->assertSee('1/5 selesai');

        // Hapus dokumen → berkas fisik ikut hilang; batal centang → item terbuka lagi. Semua tercatat.
        $this->actingAs($sa)->delete(route('hr.employees.documents.destroy', [$e, $f]))->assertRedirect();
        Storage::disk('local')->assertMissing($f->path);
        $this->actingAs($sa)->post(route('hr.employees.onboarding', [$e, 'ktp_kk']))->assertRedirect();
        $this->assertNull($e->fresh()->itemOnboarding('ktp_kk'));
        $this->assertSame(['employee_onboarding', 'upload_employee_document', 'view_employee_document', 'delete_employee_document', 'employee_onboarding'],
            AuditLog::orderBy('id')->pluck('action')->all());
    }

    public function test_pengingat_percobaan_dan_kontrak_di_daftar(): void
    {
        $this->karyawan(['name' => 'Percobaan Dekat', 'probation_end' => '2026-10-25']);
        $this->karyawan(['name' => 'Percobaan Jauh', 'probation_end' => '2026-12-31']);
        $this->karyawan(['name' => 'Kontrak Dekat', 'employment_type' => 'kontrak', 'contract_end' => '2026-11-05']);
        $this->karyawan(['name' => 'Sudah Keluar', 'status' => 'keluar', 'resign_date' => '2026-09-30', 'probation_end' => '2026-10-15']);

        $page = $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'sa'))->get(route('hr.employees.index'))->assertOk();
        $this->assertSame(['aktif' => 3, 'percobaan' => 1, 'kontrak' => 1, 'onboarding' => 3], $page->viewData('ringkas'));
        $page->assertSee('Percobaan berakhir 25 Okt 2026')->assertSee('Kontrak berakhir 05 Nov 2026')->assertDontSee('Sudah Keluar');

        $semua = $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'sa2'))->get(route('hr.employees.index', ['status' => 'semua', 'q' => 'keluar']))->assertOk();
        $this->assertSame(['Sudah Keluar'], $semua->viewData('employees')->pluck('name')->all());
    }
}
