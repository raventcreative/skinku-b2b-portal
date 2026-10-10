<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\File;
use App\Models\JobOpening;
use App\Models\PsychotestSession;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Menu HR → Rekrutmen & Psikotes (Fase 2): izin hr.recruit (default super admin saja) + internal, "Jadikan karyawan"
 * butuh hr.manage juga. CV di disk privat, audit tanpa nomor HP / email kandidat.
 */
class HrRekrutmenTest extends TestCase
{
    use RefreshDatabase;

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

    private function izinkan(string $role, string $key): void
    {
        RolePermission::create(['role' => $role, 'permission_key' => $key, 'allowed' => true]);
        Permissions::flushCache();
    }

    private function kandidat(array $extra = []): Candidate
    {
        $lowongan = JobOpening::firstOrCreate(['title' => 'Admin Gudang'], ['department' => 'Gudang', 'status' => 'buka']);

        return Candidate::create($extra + ['job_opening_id' => $lowongan->id, 'name' => 'Rina Putri', 'phone' => '0812-3456-7890',
            'email' => 'rina@mail.test', 'source' => 'Instagram', 'stage' => 'lamar']);
    }

    public function test_akses_hanya_izin_hr_recruit_dan_mitra_diblok(): void
    {
        $c = $this->kandidat();
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $admin = $this->user(User::ROLE_ADMIN, 'adm');

        foreach (['hr.rekrutmen.index', 'hr.rekrutmen.kandidat.create', 'hr.psikotes.index', 'hr.psikotes.soal'] as $r) {
            $this->actingAs($sa)->get(route($r))->assertOk();
        }
        $this->actingAs($sa)->get(route('hr.rekrutmen.kandidat.show', $c))->assertOk()->assertSee('Jadikan karyawan');
        // Admin belum punya hr.recruit (default hanya super admin) → 403 & menu tak tampil.
        $this->actingAs($admin)->get(route('hr.rekrutmen.index'))->assertForbidden();
        $this->actingAs($admin)->get('/dashboard')->assertDontSee(route('hr.rekrutmen.index'), false);

        // Diberi hr.recruit → bisa buka; tanpa hr.manage tombol & aksi "Jadikan karyawan" ditolak.
        $this->izinkan(User::ROLE_ADMIN, 'hr.recruit');
        $this->actingAs($admin)->get('/dashboard')->assertSee(route('hr.rekrutmen.index'), false)->assertSee(route('hr.psikotes.index'), false);
        $this->actingAs($admin)->get(route('hr.rekrutmen.kandidat.show', $c))->assertOk()->assertDontSee('Jadikan karyawan');
        $this->actingAs($admin)->post(route('hr.rekrutmen.kandidat.karyawan', $c))->assertForbidden();
        $this->assertSame(0, Employee::count());

        // Mitra tetap diblok walau izinnya dicentang.
        $this->izinkan(User::ROLE_DISTRIBUTOR, 'hr.recruit');
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'dist'))->get(route('hr.rekrutmen.index'))->assertForbidden();
    }

    public function test_lowongan_kandidat_dan_tahap_tercatat_di_audit_tanpa_kontak(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');

        $this->actingAs($sa)->post(route('hr.rekrutmen.lowongan.store'), ['title' => 'Admin Gudang', 'department' => 'Gudang', 'status' => 'buka'])->assertRedirect();
        $lowongan = JobOpening::sole();
        $this->actingAs($sa)->post(route('hr.rekrutmen.kandidat.store'), [
            'name' => 'Rina Putri', 'job_opening_id' => $lowongan->id, 'phone' => '0812-3456-7890', 'email' => 'rina@mail.test', 'source' => 'Instagram',
        ])->assertRedirect(route('hr.rekrutmen.kandidat.show', Candidate::sole()));
        $c = Candidate::sole();
        $this->assertSame(['lamar', $sa->id], [$c->stage, $c->created_by]);

        $this->actingAs($sa)->post(route('hr.rekrutmen.kandidat.tahap', $c), ['stage' => 'interview'])->assertRedirect();
        $this->actingAs($sa)->post(route('hr.rekrutmen.kandidat.tahap', $c), ['stage' => 'naik-jabatan'])->assertSessionHasErrors('stage');
        $this->actingAs($sa)->put(route('hr.rekrutmen.kandidat.update', $c), [
            'name' => 'Rina Putri', 'job_opening_id' => $lowongan->id, 'phone' => '0812-3456-7890', 'email' => 'rina@mail.test',
            'interview_at' => '2026-10-12T14:30', 'notes' => 'Komunikatif, minta gaji 4,5 jt.',
        ])->assertRedirect();
        $this->actingAs($sa)->put(route('hr.rekrutmen.lowongan.update', $lowongan), ['title' => 'Admin Gudang', 'department' => 'Gudang', 'status' => 'tutup'])->assertRedirect();

        $c->refresh();
        $this->assertSame(['interview', '2026-10-12 14:30', 'Komunikatif, minta gaji 4,5 jt.'], [$c->stage, $c->interview_at->format('Y-m-d H:i'), $c->notes]);
        $this->assertSame('tutup', $lowongan->fresh()->status);
        $this->assertSame(['create_job_opening', 'create_candidate', 'candidate_stage', 'update_candidate', 'update_job_opening'],
            AuditLog::orderBy('id')->pluck('action')->all());
        $tahap = AuditLog::where('action', 'candidate_stage')->sole();
        $this->assertSame([['tahap' => 'Lamar'], ['tahap' => 'Interview']], [$tahap->before_data, $tahap->after_data]);
        // Nomor HP & email kandidat tak pernah masuk audit.
        $semua = json_encode(AuditLog::all()->map->only(['before_data', 'after_data']));
        $this->assertStringNotContainsString('3456', $semua);
        $this->assertStringNotContainsString('rina@mail.test', $semua);
    }

    public function test_daftar_kandidat_saring_tahap_dan_hitungan(): void
    {
        $this->kandidat();
        $this->kandidat(['name' => 'Budi Santoso', 'stage' => 'interview']);
        $this->kandidat(['name' => 'Citra Lestari', 'stage' => 'interview']);
        $this->kandidat(['name' => 'Dewi Ayu', 'stage' => 'ditolak']);
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');

        $page = $this->actingAs($sa)->get(route('hr.rekrutmen.index', ['tahap' => 'interview']))->assertOk();
        $this->assertSame(['Citra Lestari', 'Budi Santoso'], $page->viewData('candidates')->pluck('name')->all());
        // Hitungan per tahap tetap untuk semua kandidat (chip), bukan hanya yang tersaring.
        $this->assertSame(['ditolak' => 1, 'interview' => 2, 'lamar' => 1], $page->viewData('perTahap')->map(fn ($n) => (int) $n)->sortKeys()->all());
        $this->actingAs($sa)->get(route('hr.rekrutmen.index', ['q' => 'dewi']))->assertOk()->assertSee('Dewi Ayu')->assertDontSee('Budi Santoso');
    }

    public function test_cv_disimpan_privat_dan_hanya_lewat_route_berizin(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $c = $this->kandidat();
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');

        $this->actingAs($sa)->post(route('hr.rekrutmen.kandidat.cv.store', $c), ['cv' => UploadedFile::fake()->create('cv-rina.pdf', 300, 'application/pdf')])->assertRedirect();
        $f = File::sole();
        $this->assertSame(['local', 'hr_cv', 'cv-rina.pdf'], [$f->disk, $f->collection, $f->original_name]);
        Storage::disk('local')->assertExists($f->path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->actingAs($sa)->post(route('hr.rekrutmen.kandidat.cv.store', $c), ['cv' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('cv');

        $this->actingAs($sa)->get(route('hr.rekrutmen.kandidat.cv.show', [$c, $f]))->assertOk();
        $this->actingAs($sa)->get(route('hr.rekrutmen.kandidat.show', $c))->assertSee('cv-rina.pdf');
        $lain = $this->kandidat(['name' => 'Budi']);
        $this->actingAs($sa)->get(route('hr.rekrutmen.kandidat.cv.show', [$lain, $f]))->assertNotFound();
        $this->actingAs($this->user(User::ROLE_ADMIN, 'adm'))->get(route('hr.rekrutmen.kandidat.cv.show', [$c, $f]))->assertForbidden();

        $this->actingAs($sa)->delete(route('hr.rekrutmen.kandidat.cv.destroy', [$c, $f]))->assertRedirect();
        Storage::disk('local')->assertMissing($f->path);
        $this->assertSame(['upload_candidate_cv', 'view_candidate_cv', 'delete_candidate_cv'], AuditLog::orderBy('id')->pluck('action')->all());
    }

    public function test_buat_link_psikotes_pindah_tahap_dan_bisa_dikirim_lewat_wa(): void
    {
        $c = $this->kandidat();
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');

        $this->actingAs($sa)->post(route('hr.rekrutmen.kandidat.psikotes', $c))->assertRedirect();
        $sesi = PsychotestSession::sole();
        $this->assertSame(48, strlen($sesi->token));
        $this->assertSame(['kepribadian', 'disc', 'logika'], $sesi->tests);
        $this->assertSame('2026-10-17 10:00:00', $sesi->expires_at->toDateTimeString());
        $this->assertSame('psikotes', $c->fresh()->stage);
        $log = AuditLog::where('action', 'create_psychotest')->sole();
        $this->assertSame(['nama' => 'Rina Putri', 'berlaku_sampai' => '2026-10-17'], $log->after_data);

        $this->actingAs($sa)->get(route('hr.rekrutmen.kandidat.show', $c))->assertOk()
            ->assertSee($sesi->link())->assertSee('https://wa.me/6281234567890?text=', false)->assertSee('Belum dibuka')
            ->assertDontSee('Buat link baru');
        $this->actingAs($sa)->get(route('hr.psikotes.index'))->assertOk()->assertSee('Rina Putri')->assertSee('0/3 tes');

        // Kandidat yang sudah di tahap interview tidak mundur ke tahap Psikotes.
        $b = $this->kandidat(['name' => 'Budi', 'stage' => 'interview']);
        $this->actingAs($sa)->post(route('hr.rekrutmen.kandidat.psikotes', $b))->assertRedirect();
        $this->assertSame('interview', $b->fresh()->stage);
    }

    public function test_jadikan_karyawan_membuat_data_karyawan_dari_kandidat(): void
    {
        $c = $this->kandidat(['stage' => 'interview']);
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');

        $this->actingAs($sa)->post(route('hr.rekrutmen.kandidat.karyawan', $c))->assertRedirect(route('hr.employees.edit', Employee::sole()));
        $e = Employee::sole();
        $this->assertSame(['Rina Putri', 'Admin Gudang', 'Gudang', '0812-3456-7890', 'rina@mail.test', 'percobaan', 'aktif', '2026-10-10', 'KRY-0001'],
            [$e->name, $e->position, $e->department, $e->phone, $e->email, $e->employment_type, $e->status, $e->join_date->toDateString(), $e->kode]);
        $this->assertSame([$e->id, 'diterima'], [$c->fresh()->employee_id, $c->fresh()->stage]);
        $log = AuditLog::where('action', 'create_employee')->sole();
        $this->assertSame($c->id, $log->after_data['dari_kandidat']);

        // Klik kedua tidak membuat karyawan ganda.
        $this->actingAs($sa)->post(route('hr.rekrutmen.kandidat.karyawan', $c))->assertRedirect(route('hr.employees.show', $e));
        $this->assertSame(1, Employee::count());
        $this->actingAs($sa)->get(route('hr.rekrutmen.kandidat.show', $c))->assertSee('Karyawan KRY-0001')->assertDontSee('Jadikan karyawan');
    }

    public function test_link_whatsapp_dan_ringkasan_hasil(): void
    {
        $this->assertSame('https://wa.me/6281234567890?text=Halo%20Rina', (new Candidate(['phone' => '0812-3456-7890']))->whatsappUrl('Halo Rina'));
        $this->assertSame('https://wa.me/?text=Halo', (new Candidate(['phone' => null]))->whatsappUrl('Halo'));

        $sesi = new PsychotestSession(['results' => []]);
        $this->assertSame('', $sesi->ringkasan());
        $sesi->results = ['kepribadian' => ['tipe' => 'ENFP'], 'disc' => ['utama' => 'I', 'kedua' => 'S'], 'logika' => ['skor' => 75]];
        $this->assertSame('ENFP · DISC I/S · Logika 75', $sesi->ringkasan());
    }
}
