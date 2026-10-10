<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\PsychotestSession;
use App\Services\PsikotesService;
use App\Support\Psikotes\BankSoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Psikotes kandidat (HR Fase 2): skor 3 tes + halaman publik /tes/{token} tanpa login — urutan tes dipaksa, logika
 * berbatas waktu (dihitung server), sekali pakai, kedaluwarsa 7 hari, hanya nama depan yang tampil.
 */
class PsikotesTest extends TestCase
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

    private function sesi(): PsychotestSession
    {
        $c = Candidate::create(['name' => 'Rina Putri', 'phone' => '0812-3456-7890', 'email' => 'rina@mail.test', 'stage' => 'psikotes']);

        return PsychotestSession::buatUntuk($c, null);
    }

    /** Sangat setuju (5) pada pernyataan yang mendukung huruf di $tipe, sangat tidak setuju (1) pada sisanya. */
    private function jawabanKepribadian(string $tipe): array
    {
        return array_map(fn ($s) => str_contains($tipe, $s[2]) ? 5 : 1, BankSoal::KEPRIBADIAN);
    }

    private function url(PsychotestSession $sesi, ?string $tes = null): string
    {
        return $tes ? route('psikotes.publik.tes', [$sesi->token, $tes]) : route('psikotes.publik', $sesi->token);
    }

    public function test_skor_kepribadian_tipe_dan_persentase_dimensi(): void
    {
        $svc = new PsikotesService;

        $infp = $svc->skorKepribadian($this->jawabanKepribadian('INFP'));
        $this->assertSame('INFP', $infp['tipe']);
        $this->assertSame(BankSoal::TIPE['INFP'], $infp['deskripsi']);
        $this->assertSame(['E' => 0, 'I' => 100], $infp['dimensi']['EI']);
        $this->assertSame('ESTJ', $svc->skorKepribadian($this->jawabanKepribadian('ESTJ'))['tipe']);

        // Semua netral / semua setuju = seri di tiap dimensi → huruf pertama, 50:50.
        $netral = $svc->skorKepribadian(array_fill(0, 40, 3));
        $this->assertSame(['ESTJ', ['S' => 50, 'N' => 50]], [$netral['tipe'], $netral['dimensi']['SN']]);
        $this->assertSame('ESTJ', $svc->skorKepribadian(array_fill(0, 40, 5))['tipe']);

        // Condong sebagian: I menang tipis (pernyataan I dijawab 4, E dijawab 3).
        $jawaban = array_map(fn ($s) => $s[2] === 'I' ? 4 : 3, BankSoal::KEPRIBADIAN);
        $this->assertSame(['E' => 38, 'I' => 62], $svc->skorKepribadian($jawaban)['dimensi']['EI']);
    }

    public function test_skor_disc_dan_urutan_tampil_diputar(): void
    {
        $svc = new PsikotesService;

        $hasil = $svc->skorDisc(array_fill(0, 24, 'I'), array_fill(0, 24, 'C'));
        // Seri D & S (0) → urutan tetap D lebih dulu.
        $this->assertSame([['D' => 0, 'I' => 24, 'S' => 0, 'C' => -24], 'I', 'D'], [$hasil['skor'], $hasil['utama'], $hasil['kedua']]);
        $this->assertSame(BankSoal::GAYA_DISC['I'], $hasil['deskripsi']);

        $this->assertSame(['D', 'I', 'S', 'C'], array_column($svc->urutanDisc(0), 'huruf'));
        $this->assertSame(['I', 'S', 'C', 'D'], array_column($svc->urutanDisc(1), 'huruf'));
        $this->assertSame(['C', 'D', 'I', 'S'], array_column($svc->urutanDisc(7), 'huruf'));
        $this->assertSame(['huruf' => 'D', 'kata' => 'Tegas soal target'], $svc->urutanDisc(17)[3]);
    }

    public function test_skor_logika_dan_kategori(): void
    {
        $svc = new PsikotesService;
        $kunci = array_map(fn ($s) => $s[2], BankSoal::LOGIKA);

        $this->assertSame(['benar' => 20, 'total' => 20, 'skor' => 100, 'kategori' => 'Sangat baik'], $svc->skorLogika($kunci));
        $this->assertSame(['benar' => 12, 'total' => 20, 'skor' => 60, 'kategori' => 'Baik'], $svc->skorLogika(array_slice($kunci, 0, 12, true)));
        $this->assertSame(['benar' => 0, 'total' => 20, 'skor' => 0, 'kategori' => 'Perlu latihan'], $svc->skorLogika([]));
        // Jawaban salah semua (kunci digeser satu pilihan).
        $this->assertSame(0, $svc->skorLogika(array_map(fn ($k) => ($k + 1) % 4, $kunci))['benar']);
    }

    public function test_alur_publik_lengkap_urutan_dipaksa_dan_sekali_pakai(): void
    {
        $sesi = $this->sesi();

        // Tanpa login; hanya nama depan, tanpa kontak.
        $this->get($this->url($sesi))->assertOk()->assertSee('Halo, Rina')->assertDontSee('Putri')->assertDontSee('0812')->assertSee('Mulai tes');
        // Lompat ke tes logika → dikembalikan ke tes pertama.
        $this->get($this->url($sesi, 'logika'))->assertRedirect($this->url($sesi, 'kepribadian'));
        $this->post(route('psikotes.publik.simpan', [$sesi->token, 'disc']), [])->assertRedirect($this->url($sesi, 'kepribadian'));

        $this->get($this->url($sesi, 'kepribadian'))->assertOk()->assertSee('Tes 1 dari 3')->assertSee(BankSoal::KEPRIBADIAN[39][0]);
        $this->assertSame('2026-10-10 10:00:00', $sesi->fresh()->started_at->toDateTimeString());

        // Belum lengkap → ditolak, tidak tersimpan.
        $setengah = array_slice($this->jawabanKepribadian('INFP'), 0, 20);
        $this->from($this->url($sesi, 'kepribadian'))->post(route('psikotes.publik.simpan', [$sesi->token, 'kepribadian']), ['jawaban' => $setengah])
            ->assertRedirect($this->url($sesi, 'kepribadian'))->assertSessionHasErrors('jawaban');
        $this->assertFalse($sesi->fresh()->tesSelesai('kepribadian'));

        $this->post(route('psikotes.publik.simpan', [$sesi->token, 'kepribadian']), ['jawaban' => $this->jawabanKepribadian('INFP')])
            ->assertRedirect($this->url($sesi, 'disc'));
        $this->assertSame('INFP', $sesi->fresh()->results['kepribadian']['tipe']);

        // DISC: "paling" dan "paling tidak" di kelompok yang sama wajib beda.
        $paling = array_fill(0, 24, 'I');
        $kurang = array_fill(0, 24, 'C');
        $this->get($this->url($sesi, 'disc'))->assertOk()->assertSee('Kelompok 24 dari 24');
        $this->from($this->url($sesi, 'disc'))->post(route('psikotes.publik.simpan', [$sesi->token, 'disc']), ['paling' => $paling, 'kurang' => array_replace($kurang, [5 => 'I'])])
            ->assertSessionHasErrors('jawaban');
        $this->post(route('psikotes.publik.simpan', [$sesi->token, 'disc']), ['paling' => $paling, 'kurang' => $kurang])->assertRedirect($this->url($sesi, 'logika'));

        // Logika: hitung mundur 15 menit sejak halaman dibuka; jawaban sebagian tetap diterima.
        $this->assertSame(900, $this->get($this->url($sesi, 'logika'))->assertOk()->viewData('sisaDetik'));
        $this->travel(6)->minutes();
        $this->assertSame(540, $this->get($this->url($sesi, 'logika'))->viewData('sisaDetik'));
        $kunci = array_map(fn ($s) => $s[2], BankSoal::LOGIKA);
        $this->post(route('psikotes.publik.simpan', [$sesi->token, 'logika']), ['jawaban' => array_slice($kunci, 0, 15, true) + [19 => 9]])
            ->assertOk()->assertViewIs('psikotes.selesai')->assertSee('Terima kasih, Rina');

        $sesi->refresh();
        $this->assertTrue($sesi->selesai());
        $this->assertSame(['benar' => 15, 'total' => 20, 'skor' => 75, 'kategori' => 'Baik'], $sesi->results['logika']);
        $this->assertFalse($sesi->progress['logika']['lewat_waktu']);
        $this->assertSame(['I', 'D'], [$sesi->results['disc']['utama'], $sesi->results['disc']['kedua']]);
        $this->assertSame('INFP · DISC I/D · Logika 75', $sesi->ringkasan());

        // Sekali pakai: link selesai tak bisa dibuka / dikirim ulang.
        $this->get($this->url($sesi))->assertOk()->assertSee('Psikotes sudah selesai');
        $this->post(route('psikotes.publik.simpan', [$sesi->token, 'kepribadian']), ['jawaban' => $this->jawabanKepribadian('ESTJ')])
            ->assertOk()->assertViewIs('psikotes.tutup');
        $this->assertSame('INFP', $sesi->fresh()->results['kepribadian']['tipe']);
    }

    public function test_logika_lewat_waktu_ditandai(): void
    {
        $sesi = $this->sesi();
        $sesi->update(['progress' => ['kepribadian' => ['selesai' => '2026-10-10 09:40:00'], 'disc' => ['selesai' => '2026-10-10 09:55:00']]]);

        $this->get($this->url($sesi, 'logika'))->assertOk();
        $this->travel(17)->minutes();   // batas 15 menit + toleransi 60 detik terlewati
        $this->assertSame(0, $this->get($this->url($sesi, 'logika'))->viewData('sisaDetik'));
        $this->post(route('psikotes.publik.simpan', [$sesi->token, 'logika']), ['jawaban' => [0 => 2]])->assertOk();

        $sesi->refresh();
        $this->assertTrue($sesi->progress['logika']['lewat_waktu']);
        $this->assertSame(1, $sesi->results['logika']['benar']);
    }

    public function test_link_kedaluwarsa_dan_token_tidak_dikenal(): void
    {
        $sesi = $this->sesi();
        $this->travel(8)->days();

        $this->get($this->url($sesi))->assertOk()->assertSee('sudah tidak berlaku')->assertDontSee('Mulai tes');
        $this->get($this->url($sesi, 'kepribadian'))->assertOk()->assertViewIs('psikotes.tutup');
        $this->post(route('psikotes.publik.simpan', [$sesi->token, 'kepribadian']), ['jawaban' => $this->jawabanKepribadian('INFP')])->assertViewIs('psikotes.tutup');
        $this->assertNull($sesi->fresh()->results);
        $this->assertSame('Kedaluwarsa', $sesi->fresh()->statusLabel());

        $this->get('/tes/pendek')->assertNotFound();
        $this->get('/tes/'.str_repeat('a', 48))->assertNotFound();
        $this->get('/tes/'.$sesi->token.'/bukan-tes')->assertNotFound();
    }
}
