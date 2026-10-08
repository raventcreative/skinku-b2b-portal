<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\ContentPost;
use App\Models\ContentPostSnapshot;
use App\Models\ContentPostTarget;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Alat Asisten AI menu Konten — izin & cakupan = halamannya: Pipeline & Kalender = content.create (creator hanya
 * kontennya, pengelola semua creator), Insight Konten = content.manage, Akun Sosial Media = social.connect.
 * Caption, catatan, token & nilai kredensial tak pernah dikirim ke AI.
 */
class AiKontenToolsTest extends TestCase
{
    use RefreshDatabase;

    private const ALAT = ['pipeline_konten', 'insight_konten', 'akun_sosmed'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?string $u = null): User
    {
        $u ??= $role;

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function alat(User $user): array
    {
        $names = array_map(fn ($t) => $t->name(), app(ToolRegistry::class)->forUser($user));

        return array_values(array_intersect(self::ALAT, $names));
    }

    private function pakai(string $alat, User $user, array $args = []): array
    {
        $tool = app(ToolRegistry::class)->find($alat, $user);
        $this->assertNotNull($tool, "alat {$alat} harus tersedia untuk {$user->role}");

        return $tool->run($args, $user);
    }

    /** @param array<string,array> $targets platform => kolom target */
    private function konten(User $creator, string $judul, string $status, ?string $jadwal, array $targets = []): ContentPost
    {
        $post = ContentPost::create(['user_id' => $creator->id, 'title' => $judul, 'type' => 'image', 'caption' => 'CAPTION-RAHASIA',
            'creator_note' => 'CATATAN-RAHASIA', 'status' => $status, 'scheduled_at' => $jadwal]);
        foreach ($targets as $platform => $kolom) {
            $post->targets()->create(['platform' => $platform] + $kolom);
        }

        return $post;
    }

    public function test_alat_tersedia_sesuai_izin_role(): void
    {
        $this->assertSame(self::ALAT, $this->alat($this->user(User::ROLE_SUPER_ADMIN)));
        // Creator: konten sendiri + hubungkan akun; tanpa insight (content.manage).
        $this->assertSame(['pipeline_konten', 'akun_sosmed'], $this->alat($this->user('content_creator')));
        // Admin: pengelola konten lintas creator + insight; akun sosmed bukan izinnya.
        $this->assertSame(['pipeline_konten', 'insight_konten'], $this->alat($this->user(User::ROLE_ADMIN)));
        $this->assertSame([], $this->alat($this->user('kol_specialist')));
        $this->assertSame([], $this->alat($this->user(User::ROLE_DISTRIBUTOR)));
    }

    public function test_pipeline_konten_creator_hanya_kontennya_dan_angka_sama_dengan_halaman(): void
    {
        $ani = $this->user('content_creator', 'cc.ani');
        $budi = $this->user('content_creator', 'cc.budi');
        $this->konten($ani, 'Draft serum', ContentPost::DRAFT, null);
        $this->konten($ani, 'Promo payday', ContentPost::SCHEDULED, '2026-10-08 10:00:00',
            ['instagram' => ['status' => ContentPostTarget::PENDING], 'tiktok' => ['status' => ContentPostTarget::PENDING]]);
        $this->konten($ani, 'Tutorial glow', ContentPost::DONE, '2026-10-01 09:00:00', ['instagram' => ['status' => ContentPostTarget::PUBLISHED,
            'permalink' => 'https://instagram.com/p/glow', 'published_at' => '2026-10-01 09:01:00']]);
        $this->konten($budi, 'Review sabun', ContentPost::FAILED, '2026-10-05 08:00:00', ['instagram' => ['status' => ContentPostTarget::FAILED,
            'last_error' => 'Token kedaluwarsa', 'attempts' => 3]]);
        $this->konten($budi, 'Live jualan', ContentPost::SCHEDULED, '2026-10-07 09:00:00', ['tiktok' => ['status' => ContentPostTarget::MANUAL_PENDING]]);

        // Creator: hanya kontennya — sama dengan kartu tahap halaman.
        $out = $this->pakai('pipeline_konten', $ani);
        $this->assertSame('Hanya konten milik user ini', $out['cakupan']);
        $this->assertSame(['semua' => 3, 'draft' => 1, 'terjadwal' => 1, 'sedang_terbit' => 0, 'perlu_tindakan' => 0, 'selesai' => 1], $out['jumlah_per_tahap']);
        $page = $this->actingAs($ani)->get(route('content.index'))->assertOk();
        $this->assertSame([3, 0], [(int) $page->viewData('counts')->sum(), $page->viewData('attentionCount')]);
        // Urutan halaman: jadwal terdekat dulu, tanpa jadwal di belakang.
        $this->assertSame(['Tutorial glow', 'Promo payday', 'Draft serum'], array_column($out['konten'], 'judul'));
        $this->assertSame(['judul' => 'Tutorial glow', 'tipe' => 'Foto', 'jadwal' => '2026-10-01 09:00', 'status' => 'Terbit',
            'platform' => [['platform' => 'Instagram', 'status' => 'Terbit', 'terbit' => '2026-10-01 09:01', 'link' => 'https://instagram.com/p/glow']]],
            $out['konten'][0]);
        // Filter kreator diabaikan untuk creator biasa (tetap kontennya sendiri).
        $this->assertSame(3, $this->pakai('pipeline_konten', $ani, ['kreator' => 'cc.budi'])['jumlah_konten']);

        // Pengelola (admin): semua creator + nama kreator; perlu tindakan = gagal + menunggu posting manual.
        $admin = $this->user(User::ROLE_ADMIN);
        $semua = $this->pakai('pipeline_konten', $admin);
        $this->assertSame('Seluruh content creator', $semua['cakupan']);
        $this->assertSame(['semua' => 5, 'draft' => 1, 'terjadwal' => 2, 'sedang_terbit' => 0, 'perlu_tindakan' => 2, 'selesai' => 1], $semua['jumlah_per_tahap']);
        $page = $this->actingAs($admin)->get(route('content.index'))->assertOk();
        $this->assertSame([5, 2], [(int) $page->viewData('counts')->sum(), $page->viewData('attentionCount')]);

        $perlu = $this->pakai('pipeline_konten', $admin, ['tahap' => 'perlu_tindakan']);
        $this->assertSame(['Review sabun', 'Live jualan'], array_column($perlu['konten'], 'judul'));
        $this->assertSame(['judul' => 'Review sabun', 'tipe' => 'Foto', 'jadwal' => '2026-10-05 08:00', 'status' => 'Gagal', 'kreator' => 'CC.BUDI',
            'platform' => [['platform' => 'Instagram', 'status' => 'Gagal', 'alasan_gagal' => 'Token kedaluwarsa']]], $perlu['konten'][0]);

        // Kalender: jadwal 7–8 Okt; saring kreator (pengelola) lewat nama/username.
        $kalender = $this->pakai('pipeline_konten', $admin, ['dari' => '2026-10-07', 'sampai' => '2026-10-08']);
        $this->assertSame(['Live jualan', 'Promo payday'], array_column($kalender['konten'], 'judul'));
        $budiSaja = $this->pakai('pipeline_konten', $admin, ['kreator' => 'budi']);
        $this->assertSame([2, 'CC.BUDI'], [$budiSaja['jumlah_konten'], $budiSaja['filter']['kreator']]);
        $this->assertArrayHasKey('error', $this->pakai('pipeline_konten', $admin, ['kreator' => 'cc'])); // ambigu → tanya balik

        $this->assertStringNotContainsString('RAHASIA', json_encode([$out, $semua, $perlu]));
    }

    public function test_insight_konten_angka_sama_dengan_halaman(): void
    {
        $ani = $this->user('content_creator', 'cc.ani');
        $post = $this->konten($ani, 'Tutorial glow', ContentPost::DONE, '2026-10-01 09:00:00', [
            'instagram' => ['status' => ContentPostTarget::PUBLISHED, 'permalink' => 'https://instagram.com/p/glow', 'published_at' => '2026-10-01 09:01:00'],
            'tiktok' => ['status' => ContentPostTarget::PUBLISHED, 'permalink' => 'https://tiktok.com/@skinku/video/1', 'published_at' => '2026-10-01 09:02:00'],
        ]);
        $ig = $post->targets->firstWhere('platform', 'instagram');
        $tt = $post->targets->firstWhere('platform', 'tiktok');
        ContentPostSnapshot::create(['content_post_target_id' => $ig->id, 'captured_on' => '2026-10-04', 'views' => 500, 'likes' => 10]);
        ContentPostSnapshot::create(['content_post_target_id' => $ig->id, 'captured_on' => '2026-10-05', 'views' => 1_000, 'likes' => 40, 'comments' => 10]);
        ContentPostSnapshot::create(['content_post_target_id' => $tt->id, 'captured_on' => '2026-10-05', 'views' => 3_000, 'likes' => 100, 'shares' => 50]);
        SocialConnection::create(['platform' => 'tiktok', 'account_id' => 'open1', 'access_token' => 'TOKEN-RAHASIA', 'status' => 'active',
            'meta' => ['insight_error' => 'Scope video.list belum diizinkan']]);
        $admin = $this->user(User::ROLE_ADMIN);

        $out = $this->pakai('insight_konten', $admin);

        $this->assertSame(['30 hari terakhir', 'Instagram & TikTok', 4_000, 200, 5.0, 1, 2, 2], [$out['periode'], $out['platform'], $out['total_views'],
            $out['interaksi'], $out['engagement_rate_persen'], $out['postingan'], $out['postingan_platform_terbit'], $out['postingan_platform_ada_data']]);
        $page = $this->actingAs($admin)->get(route('content-insights.index'))->assertOk()->viewData('stats');
        $this->assertSame([$page['views'], $page['posts'], $page['targets']], [$out['total_views'], $out['postingan'], $out['postingan_platform_terbit']]);
        $this->assertSame(['TIKTOK: Scope video.list belum diizinkan'], $out['error_sinkron']);
        $this->assertSame(['judul' => 'Tutorial glow', 'platform' => 'TikTok', 'terbit' => '2026-10-01', 'views' => 3_000, 'like' => 100,
            'share' => 50, 'engagement_rate_persen' => 5.0, 'link' => 'https://tiktok.com/@skinku/video/1'], $out['postingan_teratas'][0]);
        $this->assertSame([['kreator' => 'CC.ANI', 'postingan' => 1, 'postingan_platform' => 2, 'views' => 4_000, 'engagement_rate_persen' => 5.0]], $out['per_kreator']);

        $ig = $this->pakai('insight_konten', $admin, ['platform' => 'instagram', 'hari' => 7]);
        $this->assertSame(['7 hari terakhir', 'Instagram', 1_000], [$ig['periode'], $ig['platform'], $ig['total_views']]);
        $this->assertArrayNotHasKey('error_sinkron', $ig); // error TikTok tak relevan saat saring Instagram

        $this->assertStringNotContainsString('RAHASIA', json_encode($out));
    }

    public function test_akun_sosmed_tanpa_token_dan_nilai_kredensial(): void
    {
        $creator = $this->user('content_creator', 'cc.ani');
        SocialConnection::create(['platform' => 'facebook', 'account_id' => 'page1', 'account_name' => 'SKINKU Official',
            'access_token' => 'TOKEN-RAHASIA', 'status' => 'active', 'connected_by' => $creator->id]);
        SocialConnection::create(['platform' => 'tiktok', 'account_id' => 'open1', 'account_name' => '@skinku', 'access_token' => 'TT-RAHASIA',
            'refresh_token' => 'REFRESH-RAHASIA', 'access_expires_at' => now()->subDay(), 'status' => 'error', 'last_error' => 'refresh_token kedaluwarsa']);
        AppSetting::put('social_cred.meta_app_id', '123456789');
        AppSetting::put('social_cred.meta_app_secret', 'SECRET-RAHASIA');
        config(['services.threads.app_secret' => null]); // .env lokal bisa berisi kredensial asli

        $out = $this->pakai('akun_sosmed', $creator);

        $this->assertSame(['platform' => 'Facebook Page', 'terhubung' => true, 'akun' => 'SKINKU Official', 'status' => 'aktif',
            'dihubungkan_oleh' => 'CC.ANI', 'diperbarui' => '2026-10-06'], $out['akun'][0]);
        $this->assertSame(['platform' => 'Instagram Business', 'terhubung' => false], $out['akun'][1]);
        $this->assertSame(['platform' => 'TikTok', 'terhubung' => true, 'akun' => '@skinku', 'status' => 'bermasalah — perlu dihubungkan ulang',
            'token_berlaku_sampai' => '2026-10-05', 'error_terakhir' => 'refresh_token kedaluwarsa', 'diperbarui' => '2026-10-06'], $out['akun'][3]);
        $kred = collect($out['kredensial_app'])->pluck('status', 'kredensial');
        $this->assertSame(['terisi (portal)', 'terisi (portal)'], [$kred['Meta App ID'], $kred['Meta App Secret']]);
        $this->assertSame('belum diisi', $kred['Threads App Secret']);
        $json = json_encode($out);
        foreach (['RAHASIA', '123456789', 'page1', 'open1'] as $bocor) {
            $this->assertStringNotContainsString($bocor, $json);
        }
    }
}
