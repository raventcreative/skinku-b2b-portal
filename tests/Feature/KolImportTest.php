<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolScreening;
use App\Models\User;
use App\Services\KolImportService;
use App\Support\SpreadsheetReader;
use App\Support\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KolImportTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $u, string $role = 'kol_specialist'): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    /** Baris selaras urutan kolom kanonik; yang tak diisi = kosong. */
    private function row(array $a): array
    {
        return array_values(array_merge(array_fill_keys(KolImportService::COLUMNS, ''), $a));
    }

    /** Bangun file .xlsx template dari baris data, kembalikan path sementara. */
    private function makeXlsx(array $rows): string
    {
        return XlsxWriter::write(['Data KOL' => ['headers' => KolImportService::COLUMNS, 'rows' => $rows]]);
    }

    private function svc(): KolImportService
    {
        return app(KolImportService::class);
    }

    /* ---------------- SpreadsheetReader ---------------- */

    public function test_reader_xlsx_baca_header_dan_baris(): void
    {
        $path = XlsxWriter::write(['S' => ['headers' => ['a', 'b', 'c'], 'rows' => [['teks', 123, 'z']]]]);
        $rows = SpreadsheetReader::rows($path, 'xlsx');
        @unlink($path);

        $this->assertSame(['a', 'b', 'c'], $rows[0]);
        $this->assertSame(['teks', '123', 'z'], $rows[1]);
    }

    /** Sel kosong di depan (dilewati writer) dinormalkan ke posisinya. */
    public function test_reader_xlsx_sel_kosong_dinormalkan(): void
    {
        $path = XlsxWriter::write(['S' => ['headers' => ['a', 'b', 'c'], 'rows' => [['', 'mid', 'x']]]]);
        $rows = SpreadsheetReader::rows($path, 'xlsx');
        @unlink($path);

        $this->assertSame('', $rows[1][0]);
        $this->assertSame('mid', $rows[1][1]);
        $this->assertSame('x', $rows[1][2]);
    }

    public function test_reader_csv_deteksi_pemisah_titik_koma_dan_bom(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($tmp, "\xEF\xBB\xBFusername;followers\r\nabc;100\r\n");
        $rows = SpreadsheetReader::rows($tmp, 'csv');
        @unlink($tmp);

        $this->assertSame(['username', 'followers'], $rows[0]);
        $this->assertSame(['abc', '100'], $rows[1]);
    }

    /* ---------------- Service: buat / pakai ulang / dedup ---------------- */

    public function test_impor_membuat_kol_baru_dan_screening(): void
    {
        $spec = $this->user('imp1');
        $path = $this->makeXlsx([
            $this->row(['username' => 'imkola', 'followers' => 100000, 'ratecard' => 1000000,
                'views_1' => 100000, 'views_2' => 100000, 'views_3' => 100000, 'views_4' => 100000,
                'views_5' => 100000, 'views_6' => 100000, 'views_7' => 100000]),
            $this->row(['username' => 'imkolb', 'followers' => 50000, 'views_1' => 10000]),
        ]);

        $res = $this->svc()->commit($path, 'xlsx', '2026-07-22', $spec->id);
        @unlink($path);

        $this->assertSame(2, $res['summary']['baru']);
        $this->assertSame(2, Kol::count());
        $this->assertSame(2, KolScreening::count());
        $kol = Kol::where('tiktok_username', 'imkola')->first();
        $this->assertSame(100000, $kol->followers);
        $this->assertSame('tiktok', $kol->platform);
    }

    public function test_impor_username_lama_dipakai_ulang_tanpa_duplikat(): void
    {
        $spec = $this->user('imp2');
        $kol = Kol::create(['tiktok_username' => 'lamakol', 'followers' => 1000]);

        $path = $this->makeXlsx([
            $this->row(['username' => '@lamakol', 'followers' => 250000, 'views_1' => 50000]),
        ]);
        $res = $this->svc()->commit($path, 'xlsx', '2026-07-22', $spec->id);
        @unlink($path);

        $this->assertSame(1, Kol::count());                 // '@lamakol' == 'lamakol'
        $this->assertSame(250000, $kol->fresh()->followers); // followers diperbarui
        $this->assertSame(1, $kol->screenings()->count());
        $this->assertSame(1, $res['summary']['lama']);
    }

    public function test_dedup_dalam_file_username_tanggal_sama_dilewati(): void
    {
        $spec = $this->user('imp3');
        $path = $this->makeXlsx([
            $this->row(['username' => 'dupe', 'followers' => 100000, 'views_1' => 10000, 'tanggal_listing' => '2026-07-10']),
            $this->row(['username' => 'dupe', 'followers' => 100000, 'views_1' => 10000, 'tanggal_listing' => '2026-07-10']),
        ]);
        $res = $this->svc()->commit($path, 'xlsx', '2026-07-22', $spec->id);
        @unlink($path);

        $this->assertSame(1, Kol::count());
        $this->assertSame(1, KolScreening::count());
        $this->assertSame(1, $res['summary']['skip']);
    }

    /** Re-upload file yang sama = nol duplikat (kurasi utama). */
    public function test_reimpor_file_sama_tidak_menggandakan(): void
    {
        $spec = $this->user('imp4');
        $path = $this->makeXlsx([
            $this->row(['username' => 'idem', 'followers' => 100000, 'views_1' => 10000, 'tanggal_listing' => '2026-07-10']),
        ]);

        $this->svc()->commit($path, 'xlsx', '2026-07-22', $spec->id);   // pertama
        $res = $this->svc()->commit($path, 'xlsx', '2026-07-22', $spec->id);   // kedua → dilewati
        @unlink($path);

        $this->assertSame(1, Kol::count());
        $this->assertSame(1, KolScreening::count());        // tetap 1, bukan 2
        $this->assertSame(1, $res['summary']['skip']);
        $this->assertSame(0, $res['summary']['baru'] + $res['summary']['lama']);
    }

    public function test_baris_error_dilewati_yang_valid_tetap_masuk(): void
    {
        $spec = $this->user('imp5');
        $path = $this->makeXlsx([
            $this->row(['username' => 'valid', 'followers' => 100000, 'views_1' => 10000]),
            $this->row(['username' => '', 'followers' => 100000, 'views_1' => 1]),          // username kosong
            $this->row(['username' => 'nofoll', 'followers' => '', 'views_1' => 1]),         // followers kosong
            $this->row(['username' => 'badview', 'followers' => 100000, 'views_1' => 'abc']), // views bukan angka
        ]);
        $res = $this->svc()->commit($path, 'xlsx', '2026-07-22', $spec->id);
        @unlink($path);

        $this->assertSame(1, Kol::count());
        $this->assertSame(1, $res['summary']['baru']);
        $this->assertSame(3, $res['summary']['skip']);
    }

    public function test_followers_ratecard_views_format_indonesia(): void
    {
        $spec = $this->user('imp6');
        $path = $this->makeXlsx([
            $this->row(['username' => 'fmtkol', 'followers' => '100.700', 'ratecard' => '1.500.000',
                'views_1' => '40.000', 'views_2' => 10000, 'views_3' => 10000, 'views_4' => 10000,
                'views_5' => 10000, 'views_6' => 10000, 'views_7' => 10000]),
        ]);
        $this->svc()->commit($path, 'xlsx', '2026-07-22', $spec->id);
        @unlink($path);

        $kol = Kol::where('tiktok_username', 'fmtkol')->first();
        $this->assertSame(100700, $kol->followers);            // titik ribuan, bukan 100
        $s = $kol->screenings()->first();
        $this->assertSame(1500000, $s->ratecard);
        $this->assertEquals(40000, $s->views_1);
    }

    /** Preview tak menyentuh DB. */
    public function test_preview_tidak_menulis_apa_pun(): void
    {
        $spec = $this->user('imp7');
        $path = $this->makeXlsx([
            $this->row(['username' => 'previewkol', 'followers' => 100000, 'ratecard' => 1000000, 'views_1' => 100000]),
        ]);
        $res = $this->svc()->preview($path, 'xlsx', '2026-07-22');
        @unlink($path);

        $this->assertSame(0, Kol::count());                    // belum tersimpan
        $this->assertSame(1, $res['summary']['baru']);
        $this->assertSame('previewkol', $res['items'][0]['username']);
        $this->assertArrayHasKey('verdict', $res['items'][0]);  // verdict terhitung untuk preview
    }

    /* ---------------- HTTP: template, izin, alur penuh ---------------- */

    public function test_template_bisa_diunduh(): void
    {
        $res = $this->actingAs($this->user('imp8'))->get(route('kols.import.template'))->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('content-type'));
    }

    public function test_non_izin_tak_bisa_impor(): void
    {
        $gud = $this->user('impg', User::ROLE_GUDANG);
        $this->actingAs($gud)->get(route('kols.import'))->assertForbidden();
        $this->actingAs($gud)->get(route('kols.import.template'))->assertForbidden();
    }

    public function test_alur_http_upload_preview_lalu_commit(): void
    {
        Storage::fake('local');
        $spec = $this->user('imph');
        $path = $this->makeXlsx([
            $this->row(['username' => 'httpkol', 'followers' => 100000, 'ratecard' => 1000000,
                'views_1' => 100000, 'views_2' => 100000, 'views_3' => 100000, 'views_4' => 100000,
                'views_5' => 100000, 'views_6' => 100000, 'views_7' => 100000]),
        ]);
        $upload = new UploadedFile($path, 'kol.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $html = $this->actingAs($spec)->post(route('kols.import.preview'), [
            'file' => $upload, 'default_date' => '2026-07-22',
        ])->assertOk()->getContent();

        $this->assertStringContainsString('httpkol', $html);
        $this->assertStringContainsString('KOL baru', $html);
        $this->assertSame(0, Kol::count());   // preview belum menyimpan

        preg_match('/name="token" value="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m[1] ?? '');

        $this->actingAs($spec)->post(route('kols.import.commit'), [
            'token' => $m[1], 'ext' => 'xlsx', 'default_date' => '2026-07-22',
        ])->assertRedirect(route('kols.index'));

        $this->assertSame(1, Kol::where('tiktok_username', 'httpkol')->count());
        $this->assertSame(1, KolScreening::count());
        @unlink($path);
    }

    public function test_file_tanpa_kolom_wajib_ditolak(): void
    {
        Storage::fake('local');
        $spec = $this->user('imps');
        $path = XlsxWriter::write(['S' => ['headers' => ['foo', 'bar'], 'rows' => [['x', 'y']]]]);
        $upload = new UploadedFile($path, 'salah.xlsx', null, null, true);

        $this->actingAs($spec)->post(route('kols.import.preview'), [
            'file' => $upload, 'default_date' => '2026-07-22',
        ])->assertSessionHasErrors('file');
        @unlink($path);

        $this->assertSame(0, Kol::count());
    }

    /** Token bukan-uuid ditolak (cegah path traversal). */
    public function test_commit_token_ngawur_ditolak(): void
    {
        $spec = $this->user('impt');
        $this->actingAs($spec)->post(route('kols.import.commit'), [
            'token' => '../../etc/passwd', 'ext' => 'xlsx', 'default_date' => '2026-07-22',
        ])->assertStatus(422);
    }
}
