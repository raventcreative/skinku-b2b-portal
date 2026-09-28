<?php

namespace Tests\Feature;

use App\Models\ContentPost;
use App\Models\ContentPostSnapshot;
use App\Models\ContentPostTarget;
use App\Models\SocialConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Portal Content Creator Fase 3 — insight postingan (FR-80..FR-84). */
class ContentInsightTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    /** Konten terbit ke platform-platform dengan external_id tertentu. */
    private function published(User $creator, array $externalIds, string $type = 'image'): ContentPost
    {
        $post = ContentPost::create(['user_id' => $creator->id, 'title' => 'Serum glow', 'type' => $type, 'caption' => 'x', 'status' => ContentPost::DONE]);
        foreach ($externalIds as $platform => $id) {
            $post->targets()->create(['platform' => $platform, 'status' => ContentPostTarget::PUBLISHED, 'external_id' => $id,
                'permalink' => "https://example.test/{$id}", 'published_at' => now()->subDays(2)]);
        }

        return $post;
    }

    private function connectAll(): void
    {
        foreach (['facebook' => 'page1', 'instagram' => 'ig1', 'threads' => 'th1'] as $p => $id) {
            SocialConnection::create(['platform' => $p, 'account_id' => $id, 'access_token' => 'tok', 'status' => 'active']);
        }
        SocialConnection::create(['platform' => 'tiktok', 'account_id' => 'open1', 'access_token' => 'TT', 'refresh_token' => 'R',
            'access_expires_at' => now()->addDays(3), 'status' => 'active']);
    }

    private static function insights(array $m): array
    {
        return ['data' => array_map(fn ($k, $v) => ['name' => $k, 'period' => 'lifetime', 'values' => [['value' => $v]]], array_keys($m), $m)];
    }

    public function test_sync_insight_simpan_snapshot_per_platform_dan_tidak_dobel_di_hari_sama(): void
    {
        $this->connectAll();
        $post = $this->published($this->user('content_creator', 'ci1'),
            ['facebook' => 'page1_1', 'instagram' => 'igm1', 'threads' => 'thm1', 'tiktok' => '7551234567890']);

        Http::fake(function (HttpRequest $r) {
            $url = $r->url();
            $ok = ['error' => ['code' => 'ok', 'message' => '']];

            return match (true) {
                str_contains($url, '/page1_1/insights') => Http::response(self::insights(['post_media_view' => 1000])),
                str_contains($url, '/page1_1') => Http::response(['reactions' => ['summary' => ['total_count' => 40]],
                    'comments' => ['summary' => ['total_count' => 5]], 'shares' => ['count' => 5]]),
                str_contains($url, '/igm1/insights') => Http::response(self::insights(['views' => 2000, 'reach' => 1500, 'shares' => 10, 'saved' => 30])),
                str_contains($url, '/igm1') => Http::response(['like_count' => 100, 'comments_count' => 20]),
                str_contains($url, '/thm1/insights') => Http::response(self::insights(['views' => 300, 'likes' => 9, 'replies' => 2, 'reposts' => 1, 'quotes' => 1, 'shares' => 1])),
                str_contains($url, '/video/query/') => Http::response($ok + ['data' => ['videos' => [
                    ['id' => '7551234567890', 'view_count' => 5000, 'like_count' => 400, 'comment_count' => 50, 'share_count' => 50]]]]),
                default => Http::response(['error' => ['message' => 'unexpected '.$url]], 400),
            };
        });

        $this->artisan('content:sync-insights')->assertSuccessful();

        $snap = fn ($p) => $post->targets()->where('platform', $p)->first()->snapshots()->sole();
        $this->assertSame([1000, 40, 5, 5], [$snap('facebook')->views, $snap('facebook')->likes, $snap('facebook')->comments, $snap('facebook')->shares]);
        $this->assertSame([2000, 1500, 100, 20, 10, 30], [$snap('instagram')->views, $snap('instagram')->reach, $snap('instagram')->likes,
            $snap('instagram')->comments, $snap('instagram')->shares, $snap('instagram')->saves]);
        $this->assertSame([300, 9, 2, 3], [$snap('threads')->views, $snap('threads')->likes, $snap('threads')->comments, $snap('threads')->shares]);
        $this->assertSame(5000, $snap('tiktok')->views);
        $this->assertEqualsWithDelta(10.0, $snap('tiktok')->er(), 0.001); // (400+50+50)/5000

        // TikTok: filter video_ids + scope video.list lewat token Bearer.
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/video/query/') && $r['filters']['video_ids'] === ['7551234567890']);

        // Jalan ulang hari yang sama → menimpa, bukan menambah; hari berikutnya → baris baru.
        $this->artisan('content:sync-insights');
        $this->assertSame(4, ContentPostSnapshot::count());
        $this->travel(1)->days();
        $this->artisan('content:sync-insights');
        $this->assertSame(8, ContentPostSnapshot::count());
    }

    public function test_scope_insight_belum_ada_simpan_metrik_dasar_tanpa_memutus_koneksi(): void
    {
        SocialConnection::create(['platform' => 'instagram', 'account_id' => 'ig1', 'access_token' => 'tok', 'status' => 'active']);
        $creator = $this->user('content_creator', 'ci2');
        $post = $this->published($creator, ['instagram' => 'igm1']);

        Http::fake([
            '*/igm1/insights*' => Http::response(['error' => ['message' => '(#10) Application does not have permission for this action']], 403),
            '*/igm1*' => Http::response(['like_count' => 7, 'comments_count' => 1]),
        ]);

        $this->artisan('content:sync-insights')->assertSuccessful();

        $s = $post->targets()->first()->snapshots()->sole();
        $this->assertSame([7, 1, null], [$s->likes, $s->comments, $s->views]);
        $conn = SocialConnection::for('instagram');
        $this->assertSame('active', $conn->status);
        $this->assertTrue($conn->isActive());
        $this->assertStringContainsString('permission', $conn->meta['insight_error']);

        $this->actingAs($creator)->get(route('social.index'))->assertSee('Insight belum bisa ditarik');
    }

    public function test_halaman_insight_detail_dan_dashboard_menampilkan_metrik_sesuai_izin(): void
    {
        $creator = $this->user('content_creator', 'ci3');
        $post = $this->published($creator, ['instagram' => 'igm1']);
        $post->targets()->first()->snapshots()->create(['captured_on' => today(), 'views' => 1000, 'likes' => 30, 'comments' => 10]);

        $this->actingAs($creator)->get(route('content-insights.index'))->assertOk()
            ->assertSee('Insight Konten')->assertSee('Serum glow')->assertSee('CI3')->assertSee('4%'); // ER (30+10)/1000
        $this->actingAs($creator)->get(route('content.show', $post))->assertOk()->assertSee('data-insight', false)->assertSee('chartInsight');
        $this->actingAs($creator)->get(route('creator.dashboard'))->assertSee('Views 30 Hari')->assertSee('1.000');

        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'di1'))->get(route('content-insights.index'))->assertForbidden();
    }
}
