<?php

namespace Tests\Feature;

use App\Jobs\BacaCvJob;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\File;
use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Baca CV dengan AI" (HR → Rekrutmen): CV foto/PDF disimpan apa adanya → BacaCvJob (antrean; di test sinkron) → AI
 * (dipalsukan Http::fake) → form tambah kandidat terisi (nama, kontak, lowongan, Ringkasan CV berpoin tanpa kesimpulan)
 * → CV sementara ikut terlampir saat disimpan. PDF berteks dikirim sebagai teks, PDF scan & foto sebagai berkas.
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

    /** Hasil baca CV terakhir di sesi ini (status/hasil/pesan dari cache). */
    private function hasilBaca(): array
    {
        return Cache::get(BacaCvJob::kunci(session('hr_cv_ai.token'))) ?? [];
    }

    /** PDF mini berteks (stream FlateDecode) yang bisa dibaca PdfTextExtractor. */
    private function pdfBerteks(string $teks): UploadedFile
    {
        $stream = gzcompress('BT /F1 12 Tf ('.$teks.') Tj ET');

        return UploadedFile::fake()->createWithContent('cv-budi.pdf', "%PDF-1.4\n1 0 obj << /Length ".strlen($stream)." /Filter /FlateDecode >>\nstream\n{$stream}\nendstream\nendobj\n%%EOF");
    }

    public function test_foto_cv_dibaca_di_antrean_mengisi_form_lalu_cv_ikut_terlampir(): void
    {
        $gudang = JobOpening::create(['title' => 'Admin Gudang', 'department' => 'Gudang', 'status' => 'buka']);
        JobOpening::create(['title' => 'Host Live', 'status' => 'tutup']);
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $this->aiBalas("```json\n".json_encode([
            'nama' => 'Rina Putri', 'telepon' => '0812-3456-7890', 'email' => 'rina@mail.test', 'posisi_dilamar' => 'Staf Gudang',
            'lowongan_id' => $gudang->id, 'domisili' => 'Surabaya', 'tanggal_lahir' => '1998-04-12', 'gaji_diharapkan' => 'Rp4.500.000',
            // Bagian mengikuti CV aslinya (judul & urutan), maks 3 poin per bagian / entri.
            'bagian' => [
                ['judul' => 'Tentang Saya', 'poin' => ['Staf gudang berpengalaman 5 tahun', 'Teliti dan cekatan', 'Terbiasa target harian', 'Poin keempat dibuang']],
                ['judul' => 'Pengalaman Kerja', 'item' => [
                    ['judul' => 'Admin Gudang — PT Maju Jaya (Jan 2019 – Des 2024)',
                        'poin' => ['Mencatat barang masuk & keluar harian', 'Stock opname bulanan 1.200 SKU', 'Membuat laporan stok mingguan', 'Poin keempat dibuang']],
                    ['judul' => 'Staf Packing — CV Sinar (2017 – 2018)', 'poin' => []],
                ]],
                ['judul' => 'Pengalaman Organisasi & Volunteer', 'item' => [['judul' => 'Panitia Hari Tani — Divisi Logistik (2023)', 'poin' => ['Pengadaan & inventarisasi logistik acara']]]],
                ['judul' => 'Ketrampilan Utama', 'poin' => ['Excel, stock opname, forklift']],
                ['judul' => '', 'poin' => ['bagian tanpa judul dibuang']],
            ],
            'ringkasan' => 'Kandidat sangat cocok.',
        ])."\n```");

        $this->actingAs($sa)->post(route('hr.rekrutmen.baca-cv'), ['cv' => UploadedFile::fake()->image('cv-rina.jpg', 1200, 1700)])
            ->assertRedirect(route('hr.rekrutmen.kandidat.create'));

        $baca = $this->hasilBaca();
        $this->assertSame('selesai', $baca['status']);
        $this->assertSame(['Rina Putri', '0812-3456-7890', 'rina@mail.test', $gudang->id],
            [$baca['hasil']['name'], $baca['hasil']['phone'], $baca['hasil']['email'], $baca['hasil']['job_opening_id']]);
        $this->assertSame(implode("\n", [
            'Melamar: Staf Gudang · Domisili: Surabaya · Lahir: 12-04-1998 · Gaji diharapkan: Rp4.500.000',
            '',
            'TENTANG SAYA',
            '- Staf gudang berpengalaman 5 tahun',
            '- Teliti dan cekatan',
            '- Terbiasa target harian',
            '',
            'PENGALAMAN KERJA',
            '- Admin Gudang — PT Maju Jaya (Jan 2019 – Des 2024)',
            '   - Mencatat barang masuk & keluar harian',
            '   - Stock opname bulanan 1.200 SKU',
            '   - Membuat laporan stok mingguan',
            '- Staf Packing — CV Sinar (2017 – 2018)',
            '',
            'PENGALAMAN ORGANISASI & VOLUNTEER',
            '- Panitia Hari Tani — Divisi Logistik (2023)',
            '   - Pengadaan & inventarisasi logistik acara',
            '',
            'KETRAMPILAN UTAMA',
            '- Excel, stock opname, forklift',
        ]), $baca['hasil']['cv_summary']);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->data()['messages'][0]['content'][0]['text'], 'JANGAN memaksakan template'));
        $this->assertStringNotContainsString('sangat cocok', $baca['hasil']['cv_summary']);   // tanpa kesimpulan AI

        // Foto dikirim apa adanya sebagai image_url; daftar lowongan yang BUKA saja ikut di instruksi.
        Http::assertSent(function (HttpRequest $r) use ($gudang) {
            $isi = $r->data()['messages'][0]['content'];

            return $isi[1]['type'] === 'image_url' && str_starts_with($isi[1]['image_url']['url'], 'data:image/jpeg;base64,')
                && str_contains($isi[0]['text'], '"id":'.$gudang->id.',"posisi":"Admin Gudang"') && ! str_contains($isi[0]['text'], 'Host Live');
        });
        $this->assertCount(1, Storage::disk('local')->files('cv_sementara'));

        // Form terisi dari hasil (muat ulang halaman), polling mengembalikan hasil yang sama.
        $this->actingAs($sa)->get(route('hr.rekrutmen.kandidat.create'))->assertOk()
            ->assertSee('cv-rina.jpg')->assertSee('value="Rina Putri"', false)->assertSee('TENTANG SAYA')->assertSee('Form sudah diisi AI');
        $this->actingAs($sa)->getJson(route('hr.rekrutmen.baca-cv.status', session('hr_cv_ai.token')))
            ->assertOk()->assertJsonPath('status', 'selesai')->assertJsonPath('hasil.name', 'Rina Putri');

        $this->actingAs($sa)->post(route('hr.rekrutmen.kandidat.store'), [
            'name' => 'Rina Putri', 'job_opening_id' => $gudang->id, 'phone' => '0812-3456-7890', 'email' => 'rina@mail.test',
            'cv_summary' => $baca['hasil']['cv_summary'], 'lampirkan_cv' => '1',
        ])->assertRedirect();

        $c = Candidate::sole();
        $this->assertSame(['Rina Putri', $gudang->id, null], [$c->name, $c->job_opening_id, $c->notes]);
        $this->assertStringStartsWith('Melamar: Staf Gudang', $c->cv_summary);
        $f = File::sole();
        $this->assertSame(['hr_cv', 'local', 'cv-rina.jpg', 'image/jpeg'], [$f->collection, $f->disk, $f->original_name, $f->mime_type]);
        Storage::disk('local')->assertExists($f->path);
        $this->assertSame([], Storage::disk('local')->files('cv_sementara'));
        $this->assertNull(session('hr_cv_ai'));
        $this->actingAs($sa)->get(route('hr.rekrutmen.kandidat.show', $c))->assertOk()->assertSee('Ringkasan CV')->assertSee('Stock opname bulanan 1.200 SKU');
        $this->assertSame(['read_candidate_cv_ai', 'create_candidate', 'upload_candidate_cv'], AuditLog::orderBy('id')->pluck('action')->all());
        // Isi CV (kontak, pengalaman) tak masuk audit.
        $audit = json_encode(AuditLog::all()->map->only(['before_data', 'after_data']));
        $this->assertStringNotContainsString('3456', $audit);
        $this->assertStringNotContainsString('PT Maju Jaya', $audit);
    }

    public function test_request_web_tidak_menunggu_ai(): void
    {
        Queue::fake();
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');

        $this->actingAs($sa)->post(route('hr.rekrutmen.baca-cv'), ['cv' => UploadedFile::fake()->image('cv.jpg')])
            ->assertRedirect(route('hr.rekrutmen.kandidat.create'));
        // Satu job ke antrean saja — bukan dispatchAfterResponse (di hosting LiteSpeed browser tetap ditahan sampai AI selesai).
        Queue::assertPushed(BacaCvJob::class, 1);
        Queue::assertPushed(BacaCvJob::class, fn ($job) => $job->token === session('hr_cv_ai.token') && $job->mime === 'image/jpeg');
        Http::assertNothingSent();
        $this->assertSame('antri', $this->hasilBaca()['status']);

        // Halaman menunggu (polling) sambil HR boleh mengisi; token milik sesi lain → 404.
        $this->actingAs($sa)->get(route('hr.rekrutmen.kandidat.create'))->assertOk()->assertSee('AI sedang membaca CV');
        $this->actingAs($sa)->getJson(route('hr.rekrutmen.baca-cv.status', session('hr_cv_ai.token')))->assertJsonPath('status', 'antri');
        $this->actingAs($sa)->getJson(route('hr.rekrutmen.baca-cv.status', str_repeat('a', 32)))->assertNotFound();
    }

    public function test_pdf_berteks_dikirim_sebagai_teks_pdf_scan_sebagai_berkas(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $this->aiBalas(json_encode(['nama' => 'Budi Santoso', 'email' => 'budi@mail.test']), json_encode(['nama' => 'Citra Lestari']));

        $this->actingAs($sa)->post(route('hr.rekrutmen.baca-cv'), ['cv' => $this->pdfBerteks('Budi Santoso budi@mail.test Kepala Gudang')]);
        $this->assertSame('Budi Santoso', $this->hasilBaca()['hasil']['name']);
        Http::assertSent(fn (HttpRequest $r) => $r->data()['messages'][0]['role'] === 'system'
            && str_contains($r->data()['messages'][1]['content'], 'Budi Santoso budi@mail.test'));

        // PDF tanpa teks terbaca (hasil scan) → dikirim utuh sebagai berkas multimodal.
        $scan = UploadedFile::fake()->createWithContent('cv-scan.pdf', "%PDF-1.4\n%".str_repeat("\xE2\xE3", 50)."\n%%EOF");
        $this->actingAs($sa)->post(route('hr.rekrutmen.baca-cv'), ['cv' => $scan]);
        $this->assertSame('Citra Lestari', $this->hasilBaca()['hasil']['name']);
        Http::assertSent(function (HttpRequest $r) {
            $isi = $r->data()['messages'][0]['content'] ?? null;

            return is_array($isi) && $isi[1]['type'] === 'file' && $isi[1]['file']['filename'] === 'cv.pdf'
                && str_starts_with($isi[1]['file']['file_data'], 'data:application/pdf;base64,');
        });
        // Membaca CV baru membuang CV sementara sebelumnya di sesi yang sama.
        $this->assertCount(1, Storage::disk('local')->files('cv_sementara'));
    }

    public function test_pdf_canva_font_cid_dibaca_portal_lalu_dikirim_sebagai_teks(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $this->aiBalas("Berikut data CV-nya:\n".json_encode(['nama' => 'Rina ABC'])."\nSemoga membantu!");   // kalimat pembuka tetap terbaca
        // Font Identity-H + CMap ToUnicode (gaya Canva): kode 2-byte 0001=R 0002=i 0003=n 0004=a, 0005=spasi, 0010..0012=A..C.
        $cmap = gzcompress("begincmap\n1 begincodespacerange <0000> <FFFF> endcodespacerange\n5 beginbfchar <0001> <0052> <0002> <0069> <0003> <006E> <0004> <0061> <0005> <0020> endbfchar\n1 beginbfrange <0010> <0012> <0041> endbfrange\nendcmap");
        $baris2 = str_repeat('<0010000200030004>', 10);   // "Aina" ×10 → teks cukup panjang untuk dianggap terbaca
        $konten = gzcompress("BT /F1 12 Tf 1 0 0 -1 10 20 Tm <00010002000300040005001000110012> Tj ET BT /F1 9 Tf 1 0 0 -1 10 40 Tm [{$baris2}] TJ ET");
        $pdf = "%PDF-1.4\n3 0 obj\n<< /Type /Page /Resources << /Font << /F1 4 0 R >> >> /Contents 6 0 R >>\nendobj\n"
            ."4 0 obj\n<< /Type /Font /Subtype /Type0 /Encoding /Identity-H /ToUnicode 5 0 R >>\nendobj\n"
            ."5 0 obj\n<< /Length ".strlen($cmap)." /Filter /FlateDecode >>\nstream\n{$cmap}\nendstream\nendobj\n"
            ."6 0 obj\n<< /Length ".strlen($konten)." /Filter /FlateDecode >>\nstream\n{$konten}\nendstream\nendobj\n%%EOF";

        $this->actingAs($sa)->post(route('hr.rekrutmen.baca-cv'), ['cv' => UploadedFile::fake()->createWithContent('cv-canva.pdf', $pdf)]);

        $this->assertSame('Rina ABC', $this->hasilBaca()['hasil']['name']);
        Http::assertSent(fn (HttpRequest $r) => $r->data()['messages'][0]['role'] === 'system'
            && $r->data()['messages'][1]['content'] === "Rina ABC\n".str_repeat('Aina', 10)
            && $r->data()['max_tokens'] === 3000);   // batas jawaban dinaikkan untuk ringkasan berpoin …
        $this->assertSame(1500, (int) config('services.ai.max_output_tokens'));   // … lalu dikembalikan
    }

    public function test_hasil_ai_disaring_lowongan_tutup_dan_email_rusak_tidak_dipakai(): void
    {
        $tutup = JobOpening::create(['title' => 'Host Live', 'status' => 'tutup']);
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        $this->aiBalas(json_encode(['nama' => 'Dewi Ayu', 'email' => 'bukan email', 'telepon' => 'WA: 0857 1111 2222',
            'lowongan_id' => $tutup->id, 'bagian' => [['judul' => 'Pengalaman', 'item' => [['poin' => ['entri tanpa judul']]]], 'bukan bagian']]));

        $this->actingAs($sa)->post(route('hr.rekrutmen.baca-cv'), ['cv' => UploadedFile::fake()->image('cv.png')]);
        $this->assertSame(['name' => 'Dewi Ayu', 'phone' => '0857 1111 2222', 'email' => null, 'job_opening_id' => null, 'cv_summary' => null],
            $this->hasilBaca()['hasil']);
    }

    public function test_ai_gagal_cv_tetap_bisa_dilampirkan_dan_isi_manual(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');
        Http::fake(['*/chat/completions' => Http::sequence()
            ->push(['error' => ['message' => 'Incorrect API key provided']], 401)
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Maaf, saya tidak bisa membaca dokumen ini.']]]])]);

        $this->actingAs($sa)->post(route('hr.rekrutmen.baca-cv'), ['cv' => UploadedFile::fake()->image('cv.jpg')]);
        $this->assertSame('gagal', $this->hasilBaca()['status']);
        $this->assertStringStartsWith('AI belum bisa membaca CV', $this->hasilBaca()['pesan']);
        $this->actingAs($sa)->get(route('hr.rekrutmen.kandidat.create'))->assertOk()->assertSee('AI belum bisa membaca CV')->assertSee('Lampirkan file CV ini');

        $this->actingAs($sa)->post(route('hr.rekrutmen.baca-cv'), ['cv' => UploadedFile::fake()->image('cv-2.jpg')]);
        $this->assertSame(['gagal', 'AI tidak menemukan data kandidat di file ini.'], [$this->hasilBaca()['status'], $this->hasilBaca()['pesan']]);

        // HR isi manual; CV tetap terlampir.
        $this->actingAs($sa)->post(route('hr.rekrutmen.kandidat.store'), ['name' => 'Fajar', 'lampirkan_cv' => '1'])->assertRedirect();
        $this->assertSame('cv-2.jpg', File::sole()->original_name);

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
        $token = session('hr_cv_ai.token');
        $this->actingAs($sa)->post(route('hr.rekrutmen.kandidat.store'), ['name' => 'Eko Prasetyo'])->assertRedirect();   // tanpa lampirkan_cv

        $this->assertSame([0, []], [File::count(), $disk->files('cv_sementara')]);
        $this->assertNull(Cache::get(BacaCvJob::kunci($token)));
        $this->assertSame('Eko Prasetyo', Candidate::sole()->name);
    }

    public function test_worker_antrean_jalan_tiap_15_detik(): void
    {
        // Baca CV menunggu worker antrean → jangan tiap menit (bisa 60 dtk) dan jangan diproses di request web.
        $worker = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'queue:work'));
        $this->assertNotNull($worker);
        $this->assertSame([true, 15], [$worker->isRepeatable(), $worker->repeatSeconds]);
        $this->assertStringContainsString('--timeout=290', (string) $worker->command);
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
