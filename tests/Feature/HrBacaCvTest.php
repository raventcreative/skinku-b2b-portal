<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\File;
use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Baca CV dengan AI" (HR → Rekrutmen): CV foto/PDF → AI (dipalsukan Http::fake) → form tambah kandidat terisi, CV
 * sementara ikut terlampir saat disimpan. PDF berteks dikirim sebagai teks, PDF scan & foto sebagai berkas multimodal.
 */
class HrBacaCvTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 10:00:00');
        Storage::fake('local');
        config(['services.ai.openai.key' => 'sk-test', 'services.ai.backup.key' => null, 'services.ai.backup.model' => null]);
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

    /** Balasan AI berurutan (satu per panggilan). */
    private function aiBalas(string ...$isi): void
    {
        $urutan = Http::sequence();
        foreach ($isi as $teks) {
            $urutan->push(['choices' => [['message' => ['role' => 'assistant', 'content' => $teks]]]]);
        }
        Http::fake(['*/chat/completions' => $urutan]);
    }

    /** PDF mini berteks (stream FlateDecode) yang bisa dibaca PdfTextExtractor. */
    private function pdfBerteks(string $teks): UploadedFile
    {
        $stream = gzcompress('BT /F1 12 Tf ('.$teks.') Tj ET');

        return UploadedFile::fake()->createWithContent('cv-budi.pdf', "%PDF-1.4\n1 0 obj << /Length ".strlen($stream)." /Filter /FlateDecode >>\nstream\n{$stream}\nendstream\nendobj\n%%EOF");
    }

    public function test_foto_cv_dibaca_ai_mengisi_form_lalu_cv_ikut_terlampir(): void
    {
        $gudang = JobOpening::create(['title' => 'Admin Gudang', 'department' => 'Gudang', 'status' => 'buka']);
        JobOpening::create(['title' => 'Host Live', 'status' => 'tutup']);
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $this->aiBalas("```json\n".json_encode([
            'nama' => 'Rina Putri', 'telepon' => '0812-3456-7890', 'email' => 'rina@mail.test', 'posisi_dilamar' => 'Staf Gudang',
            'lowongan_id' => $gudang->id, 'domisili' => 'Surabaya', 'tanggal_lahir' => '1998-04-12',
            'pendidikan' => 'SMK Akuntansi, SMKN 1 Surabaya (2016)', 'pengalaman' => ['Admin Gudang — PT Maju Jaya (2019–2024)'],
            'keahlian' => ['Excel', 'Stock opname'], 'ringkasan' => 'Berpengalaman 5 tahun di gudang kosmetik.',
        ])."\n```");

        $this->actingAs($sa)->post(route('hr.rekrutmen.baca-cv'), ['cv' => UploadedFile::fake()->image('cv-rina.jpg', 1200, 1700)])
            ->assertRedirect(route('hr.rekrutmen.kandidat.create'))
            ->assertSessionHasInput('name', 'Rina Putri')->assertSessionHasInput('phone', '0812-3456-7890')
            ->assertSessionHasInput('email', 'rina@mail.test')->assertSessionHasInput('job_opening_id', $gudang->id);
        $notes = session()->getOldInput('notes');
        $this->assertStringContainsString("Pengalaman:\n- Admin Gudang — PT Maju Jaya (2019–2024)", $notes);
        $this->assertStringContainsString('Keahlian: Excel, Stock opname', $notes);
        $this->assertStringContainsString('Posisi dilamar: Staf Gudang', $notes);

        // Foto dikirim sebagai image_url (sudah diperkecil jadi JPEG), daftar lowongan yang buka ikut di instruksi.
        Http::assertSent(function (HttpRequest $r) use ($gudang) {
            $isi = $r->data()['messages'][0]['content'];

            return $isi[1]['type'] === 'image_url' && str_starts_with($isi[1]['image_url']['url'], 'data:image/jpeg;base64,')
                && str_contains($isi[0]['text'], '"id":'.$gudang->id.',"posisi":"Admin Gudang"') && ! str_contains($isi[0]['text'], 'Host Live');
        });
        $sementara = Storage::disk('local')->files('cv_sementara');
        $this->assertCount(1, $sementara);

        $this->actingAs($sa)->get(route('hr.rekrutmen.kandidat.create'))->assertOk()->assertSee('Diisi AI dari CV')->assertSee('cv-rina.jpg');
        $this->actingAs($sa)->post(route('hr.rekrutmen.kandidat.store'), [
            'name' => 'Rina Putri', 'job_opening_id' => $gudang->id, 'phone' => '0812-3456-7890', 'email' => 'rina@mail.test',
            'notes' => $notes, 'lampirkan_cv' => '1',
        ])->assertRedirect();

        $c = Candidate::sole();
        $this->assertSame(['Rina Putri', $gudang->id], [$c->name, $c->job_opening_id]);
        $this->assertStringContainsString('Ringkasan CV oleh AI', $c->notes);
        $f = File::sole();
        $this->assertSame(['hr_cv', 'local', 'cv-rina.jpg', 'image/jpeg'], [$f->collection, $f->disk, $f->original_name, $f->mime_type]);
        Storage::disk('local')->assertExists($f->path);
        $this->assertSame([], Storage::disk('local')->files('cv_sementara'));
        $this->assertNull(session('hr_cv_ai'));
        $this->assertSame(['read_candidate_cv_ai', 'create_candidate', 'upload_candidate_cv'], AuditLog::orderBy('id')->pluck('action')->all());
        // Isi CV (kontak, pengalaman) tak masuk audit.
        $audit = json_encode(AuditLog::all()->map->only(['before_data', 'after_data']));
        $this->assertStringNotContainsString('3456', $audit);
        $this->assertStringNotContainsString('PT Maju Jaya', $audit);
    }

    public function test_pdf_berteks_dikirim_sebagai_teks_pdf_scan_sebagai_berkas(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $this->aiBalas(json_encode(['nama' => 'Budi Santoso', 'email' => 'budi@mail.test']), json_encode(['nama' => 'Citra Lestari']));

        $this->actingAs($sa)->post(route('hr.rekrutmen.baca-cv'), ['cv' => $this->pdfBerteks('Budi Santoso budi@mail.test Kepala Gudang')])
            ->assertRedirect(route('hr.rekrutmen.kandidat.create'))->assertSessionHasInput('name', 'Budi Santoso');
        Http::assertSent(fn (HttpRequest $r) => $r->data()['messages'][0]['role'] === 'system'
            && str_contains($r->data()['messages'][1]['content'], 'Budi Santoso budi@mail.test'));

        // PDF tanpa teks terbaca (hasil scan) → dikirim utuh sebagai berkas multimodal.
        $scan = UploadedFile::fake()->createWithContent('cv-scan.pdf', "%PDF-1.4\n%".str_repeat("\xE2\xE3", 50)."\n%%EOF");
        $this->actingAs($sa)->post(route('hr.rekrutmen.baca-cv'), ['cv' => $scan])->assertSessionHasInput('name', 'Citra Lestari');
        Http::assertSent(function (HttpRequest $r) {
            $isi = $r->data()['messages'][0]['content'] ?? null;

            return is_array($isi) && $isi[1]['type'] === 'file' && str_starts_with($isi[1]['file']['file_data'], 'data:application/pdf;base64,');
        });
        // Membaca CV baru membuang CV sementara sebelumnya di sesi yang sama.
        $this->assertCount(1, Storage::disk('local')->files('cv_sementara'));
    }

    public function test_hasil_ai_disaring_lowongan_tutup_dan_email_rusak_tidak_dipakai(): void
    {
        $tutup = JobOpening::create(['title' => 'Host Live', 'status' => 'tutup']);
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $this->aiBalas(json_encode(['nama' => 'Dewi Ayu', 'email' => 'bukan email', 'telepon' => 'WA: 0857 1111 2222', 'lowongan_id' => $tutup->id, 'pengalaman' => 'bukan daftar']));

        $this->actingAs($sa)->post(route('hr.rekrutmen.baca-cv'), ['cv' => UploadedFile::fake()->image('cv.png')])
            ->assertSessionHasInput('name', 'Dewi Ayu')->assertSessionHasInput('phone', '0857 1111 2222');
        $this->assertNull(session()->getOldInput('email'));
        $this->assertNull(session()->getOldInput('job_opening_id'));
        $this->assertNull(session()->getOldInput('notes'));
    }

    public function test_ai_gagal_atau_kosong_berkas_sementara_dibuang(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');

        Http::fake(['*/chat/completions' => Http::sequence()
            ->push(['error' => ['message' => 'Incorrect API key provided']], 401)
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Maaf, saya tidak bisa membaca dokumen ini.']]]])]);
        $this->actingAs($sa)->from(route('hr.rekrutmen.index'))->post(route('hr.rekrutmen.baca-cv'), ['cv' => UploadedFile::fake()->image('cv.jpg')])
            ->assertRedirect(route('hr.rekrutmen.index'))->assertSessionHas('error');
        $this->assertSame([], Storage::disk('local')->files('cv_sementara'));

        $this->actingAs($sa)->from(route('hr.rekrutmen.index'))->post(route('hr.rekrutmen.baca-cv'), ['cv' => UploadedFile::fake()->image('cv.jpg')])
            ->assertSessionHas('error', 'AI tidak menemukan data kandidat di file ini — silakan isi form secara manual.');
        $this->assertSame([], Storage::disk('local')->files('cv_sementara'));
        $this->assertNull(session('hr_cv_ai'));

        // Jenis file selain PDF/foto ditolak sebelum dikirim ke AI.
        $this->actingAs($sa)->post(route('hr.rekrutmen.baca-cv'), ['cv' => UploadedFile::fake()->create('cv.docx', 20)])->assertSessionHasErrors('cv');
        Http::assertSentCount(2);
    }

    public function test_batal_lampirkan_dan_sisa_cv_terlantar_dibersihkan(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $disk = Storage::disk('local');
        $disk->put('cv_sementara/lama.pdf', '%PDF-lama');
        touch($disk->path('cv_sementara/lama.pdf'), now()->subDays(2)->getTimestamp());
        $this->aiBalas(json_encode(['nama' => 'Eko Prasetyo']));

        $this->actingAs($sa)->post(route('hr.rekrutmen.baca-cv'), ['cv' => UploadedFile::fake()->image('cv.jpg')]);
        $disk->assertMissing('cv_sementara/lama.pdf');
        $this->actingAs($sa)->post(route('hr.rekrutmen.kandidat.store'), ['name' => 'Eko Prasetyo'])->assertRedirect();   // tanpa lampirkan_cv

        $this->assertSame([0, []], [File::count(), $disk->files('cv_sementara')]);
        $this->assertSame('Eko Prasetyo', Candidate::sole()->name);
    }

    public function test_hanya_izin_hr_recruit(): void
    {
        $this->aiBalas(json_encode(['nama' => 'X']));
        $this->actingAs($this->user(User::ROLE_ADMIN, 'adm'))->post(route('hr.rekrutmen.baca-cv'), ['cv' => UploadedFile::fake()->image('cv.jpg')])
            ->assertForbidden();
        Http::assertNothingSent();
        // Tombol tampil dengan teksnya sendiri (variabel halaman tak bocor ke partial).
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $this->actingAs($sa)->get(route('hr.rekrutmen.index'))->assertOk()->assertSee('✨ Baca CV dengan AI')->assertDontSee('✨ block');
        $this->actingAs($sa)->get(route('hr.rekrutmen.kandidat.create'))->assertOk()->assertSee('✨ Baca CV dengan AI')->assertDontSee('✨ block');
    }
}
