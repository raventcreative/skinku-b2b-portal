<?php

namespace Tests\Feature;

use App\Models\ContentPost;
use App\Models\ContentPostTarget;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\ContentPostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Portal Content Creator — spec docs/superpowers/specs/2026-09-25-content-creator. */
class ContentCreatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    /** Konten siap review milik $creator (lewat HTTP, sama seperti UI). */
    private function submitted(User $creator, array $platforms, string $type = 'image', array $extra = []): ContentPost
    {
        $media = $type === 'video'
            ? [UploadedFile::fake()->create('v.mp4', 1000, 'video/mp4')]
            : ($type === 'carousel' ? [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')] : [UploadedFile::fake()->image('a.jpg', 800, 800)]);

        $this->actingAs($creator)->post(route('content.store'), $extra + [
            'title' => 'Serum launch', 'type' => $type, 'caption' => 'Kulit glowing #skinku',
            'platforms' => $platforms, 'media' => $media, 'submit' => 1,
        ])->assertRedirect();

        return ContentPost::latest('id')->first();
    }

    public function test_creator_diarahkan_ke_dashboard_creator_dan_tak_bisa_buka_modul_lain(): void
    {
        $creator = $this->user('content_creator', 'cc1');

        $this->actingAs($creator)->get(route('dashboard'))->assertRedirect(route('creator.dashboard'));
        $this->actingAs($creator)->get(route('creator.dashboard'))->assertOk()
            ->assertSee('Dashboard Creator')->assertSee('Konten Saya')->assertDontSee('Produk Master');

        foreach (['products.index', 'purchase-orders.index', 'kols.index', 'users.index'] as $route) {
            $this->actingAs($creator)->get(route($route))->assertForbidden();
        }
        // FR-05 (revisi 2026-09-25): kreator yang menghubungkan akun brand.
        $this->actingAs($creator)->get(route('social.index'))->assertOk()->assertSee('Akun Sosial Media Brand');
        $this->actingAs($creator)->get(route('creator.dashboard'))->assertSee('Akun Sosial Media');

        // Role kustom lain juga tak lagi bisa melihat PO/stok semua mitra (middleware business).
        $kol = $this->user('kol_specialist', 'ks1');
        $this->actingAs($kol)->get(route('purchase-orders.index'))->assertForbidden();
        $this->actingAs($kol)->get(route('inventory.index'))->assertForbidden();

        // Mitra tidak punya menu/akses konten.
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'd1'))->get(route('content.index'))->assertForbidden();
    }

    public function test_validasi_media_dan_caption_per_platform(): void
    {
        $img = ['mime' => 'image/jpeg', 'size' => 1000];
        $vid = ['mime' => 'video/mp4', 'size' => 1000];

        $this->assertSame([], ContentPostService::submitErrors('image', [$img], 'ok', ['facebook', 'instagram', 'threads', 'tiktok']));
        $this->assertNotEmpty(ContentPostService::submitErrors('image', [$img, $img], 'ok', ['facebook']));
        $this->assertNotEmpty(ContentPostService::submitErrors('video', [$img], 'ok', ['facebook']));
        $this->assertNotEmpty(ContentPostService::submitErrors('carousel', [$img, $vid], 'ok', ['instagram']));
        $this->assertNotEmpty(ContentPostService::submitErrors('carousel', [$img], 'ok', ['instagram']));
        $this->assertNotEmpty(ContentPostService::submitErrors('image', [$img], 'ok', []));
        $this->assertNotEmpty(ContentPostService::submitErrors('image', [['mime' => 'image/jpeg', 'size' => 9 * 1024 * 1024]], 'ok', ['facebook']));

        // Threads 500 karakter; override per platform yang dihitung.
        $long = str_repeat('a', 600);
        $this->assertNotEmpty(ContentPostService::submitErrors('image', [$img], $long, ['threads']));
        $this->assertSame([], ContentPostService::submitErrors('image', [$img], $long, ['threads', 'instagram'], ['threads' => 'pendek']));
        // Instagram maks 30 hashtag.
        $tags = implode(' ', array_map(fn ($i) => "#t{$i}", range(1, 31)));
        $this->assertNotEmpty(ContentPostService::submitErrors('image', [$img], $tags, ['instagram']));
    }

    public function test_alur_ajukan_review_setujui_tolak_dan_kepemilikan(): void
    {
        $creator = $this->user('content_creator', 'cc2');
        $other = $this->user('content_creator', 'cc3');
        $admin = $this->user(User::ROLE_ADMIN, 'adm');

        // Ajukan dengan media tak cocok → ditolak di form, belum tersimpan.
        $this->actingAs($creator)->post(route('content.store'), [
            'title' => 'x', 'type' => 'video', 'platforms' => ['facebook'], 'media' => [UploadedFile::fake()->image('a.jpg')], 'submit' => 1,
        ])->assertSessionHasErrors('content');
        $this->assertSame(0, ContentPost::count());

        $post = $this->submitted($creator, ['facebook', 'tiktok']);
        $this->assertSame(ContentPost::IN_REVIEW, $post->status);
        $this->assertSame(1, $post->filesIn(ContentPost::MEDIA)->count());

        // Kreator lain = reviewer (keputusan HQ 2026-09-26) → boleh melihat detail.
        $this->actingAs($other)->get(route('content.show', $post))->assertOk()->assertSee('Setujui');
        $this->actingAs($creator)->get(route('content.edit', $post))->assertForbidden(); // sedang direview
        // Pembuat ≠ penyetuju: konten sendiri tidak bisa disetujui/ditolak.
        $this->actingAs($creator)->get(route('content.show', $post))->assertSee('Ini konten kamu sendiri');
        $this->actingAs($creator)->post(route('content.approve', $post))->assertSessionHasErrors('status');
        $this->actingAs($creator)->post(route('content.reject', $post), ['review_note' => 'Tolak sendiri'])->assertSessionHasErrors('status');
        $this->assertSame(ContentPost::IN_REVIEW, $post->fresh()->status);

        // Tolak wajib alasan.
        $this->actingAs($admin)->post(route('content.reject', $post), ['review_note' => ''])->assertSessionHasErrors('review_note');
        $this->actingAs($admin)->post(route('content.reject', $post), ['review_note' => 'Logo kurang jelas'])->assertRedirect();
        $this->assertSame(ContentPost::REJECTED, $post->fresh()->status);
        $this->actingAs($creator)->get(route('creator.dashboard'))->assertSee('Logo kurang jelas');

        // Revisi → ajukan ulang → setujui dengan edit caption.
        $this->actingAs($creator)->post(route('content.submit', $post))->assertRedirect();
        $this->actingAs($admin)->post(route('content.approve', $post), ['caption' => 'Caption final', 'captions' => ['tiktok' => 'Versi TikTok']])->assertRedirect();

        $post->refresh();
        $this->assertSame(ContentPost::SCHEDULED, $post->status);
        $this->assertSame('Caption final', $post->caption);
        $this->assertSame(ContentPostTarget::QUEUED, $post->targets->firstWhere('platform', 'facebook')->status);
        $tiktok = $post->targets->firstWhere('platform', 'tiktok');
        $this->assertSame(ContentPostTarget::MANUAL_PENDING, $tiktok->status);
        $this->assertSame('Versi TikTok', $tiktok->caption());

        // Transisi ilegal ditolak (FR-30).
        $this->actingAs($admin)->post(route('content.approve', $post))->assertSessionHasErrors('status');
        $this->actingAs($creator)->post(route('content.withdraw', $post))->assertSessionHasErrors('status');
    }

    public function test_kreator_lain_bisa_setujui_dan_menu_review_sosmed_tersembunyi_dari_super_admin(): void
    {
        $creator = $this->user('content_creator', 'ccr1');
        $reviewer = $this->user('content_creator', 'ccr2');
        $post = $this->submitted($creator, ['facebook']);

        $this->actingAs($reviewer)->get(route('content-review.index'))->assertOk();
        $this->actingAs($reviewer)->post(route('content.approve', $post))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(ContentPost::SCHEDULED, $post->fresh()->status);
        $this->assertSame($reviewer->id, $post->fresh()->reviewed_by);

        // Menu: kreator melihat Review Konten & Akun Sosial Media; super admin tidak (URL tetap bisa diakses).
        $this->actingAs($reviewer)->get(route('creator.dashboard'))->assertSee('Review Konten')->assertSee('Akun Sosial Media');
        $root = $this->user(User::ROLE_SUPER_ADMIN, 'rootmenu');
        $this->actingAs($root)->get(route('dashboard'))->assertDontSee('Review Konten')->assertDontSee('Akun Sosial Media');
        $this->actingAs($root)->get(route('social.index'))->assertOk();
    }

    public function test_publish_facebook_instagram_threads_dan_manual_tiktok(): void
    {
        $creator = $this->user('content_creator', 'cc4');
        $admin = $this->user(User::ROLE_ADMIN, 'adm2');
        SocialConnection::create(['platform' => 'facebook', 'account_id' => 'page1', 'access_token' => 'PAGE-SECRET-TOKEN']);
        SocialConnection::create(['platform' => 'instagram', 'account_id' => 'ig1', 'access_token' => 'PAGE-SECRET-TOKEN']);
        SocialConnection::create(['platform' => 'threads', 'account_id' => 'th1', 'access_token' => 'THREADS-TOKEN',
            'access_expires_at' => now()->addDays(50)]);

        Http::fake(function (HttpRequest $r) {
            $url = $r->url();

            return match (true) {
                str_contains($url, '/page1/photos') => Http::response(['id' => 'ph1', 'post_id' => 'page1_111']),
                str_contains($url, '/page1_111') => Http::response(['permalink_url' => 'https://www.facebook.com/page1/posts/111']),
                str_contains($url, '/ig1/media_publish') => Http::response(['id' => 'igm1']),
                str_contains($url, '/ig1/media') => Http::response(['id' => 'igc1']),
                str_contains($url, '/igc1') => Http::response(['status_code' => 'FINISHED']),
                str_contains($url, '/igm1') => Http::response(['permalink' => 'https://www.instagram.com/p/abc/']),
                str_contains($url, '/th1/threads_publish') => Http::response(['id' => 'thm1']),
                str_contains($url, '/th1/threads') => Http::response(['id' => 'thc1']),
                str_contains($url, '/thc1') => Http::response(['status' => 'FINISHED']),
                str_contains($url, '/thm1') => Http::response(['permalink' => 'https://www.threads.net/@skinku/post/xyz']),
                default => Http::response(['error' => ['message' => 'unexpected '.$url]], 400),
            };
        });

        $post = $this->submitted($creator, ['facebook', 'instagram', 'threads', 'tiktok']);
        $this->actingAs($admin)->post(route('content.approve', $post))->assertRedirect();

        $this->artisan('content:publish-due')->assertSuccessful();

        $post->refresh()->load('targets');
        foreach (['facebook' => 'page1_111', 'instagram' => 'igm1', 'threads' => 'thm1'] as $platform => $id) {
            $t = $post->targets->firstWhere('platform', $platform);
            $this->assertSame(ContentPostTarget::PUBLISHED, $t->status, "{$platform}: {$t->last_error}");
            $this->assertSame($id, $t->external_id);
            $this->assertNotNull($t->permalink);
        }
        // Token dikirim via header, bukan URL.
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer PAGE-SECRET-TOKEN') && ! str_contains($r->url(), 'SECRET'));

        // TikTok masih menunggu posting manual → status konten belum "done".
        $this->assertSame(ContentPost::PUBLISHING, $post->status);
        $tiktok = $post->targets->firstWhere('platform', 'tiktok');
        $this->actingAs($admin)->post(route('content-targets.mark-published', $tiktok), ['permalink' => 'https://www.tiktok.com/@skinku/video/1'])->assertRedirect();
        $this->assertSame(ContentPost::DONE, $post->fresh()->status);

        // Jalan ulang tidak mempublikasikan dobel (FR-58).
        $before = Http::recorded()->count();
        $this->artisan('content:publish-due')->assertSuccessful();
        $this->assertSame($before, Http::recorded()->count());
    }

    public function test_gagal_publish_retry_bertahap_lalu_failed_dan_status_partial(): void
    {
        $creator = $this->user('content_creator', 'cc5');
        $admin = $this->user(User::ROLE_ADMIN, 'adm3');
        SocialConnection::create(['platform' => 'facebook', 'account_id' => 'page1', 'access_token' => 'tok']);
        // Threads tidak terhubung → selalu gagal.

        Http::fake([
            '*/page1/photos' => Http::response(['id' => 'ph1', 'post_id' => 'page1_1']),
            '*/page1_1*' => Http::response(['permalink_url' => '/page1/posts/1']),
        ]);

        $post = $this->submitted($creator, ['facebook', 'threads']);
        $this->actingAs($admin)->post(route('content.approve', $post))->assertSessionHasNoErrors();

        $this->artisan('content:publish-due');
        $threads = $post->targets()->where('platform', 'threads')->first();
        $this->assertSame(ContentPostTarget::QUEUED, $threads->status);
        $this->assertSame(1, $threads->attempts);
        $this->assertTrue($threads->next_attempt_at->isFuture());
        $this->assertSame('https://www.facebook.com/page1/posts/1', $post->targets()->where('platform', 'facebook')->value('permalink'));

        // Percobaan 2..4 (backoff dilewati dengan memajukan jam).
        foreach ([2, 3, 4] as $attempt) {
            $this->travel(2)->hours();
            $this->artisan('content:publish-due');
            $this->assertSame($attempt, $threads->fresh()->attempts);
        }
        $this->assertSame(ContentPostTarget::FAILED, $threads->fresh()->status);
        $this->assertSame(ContentPost::PARTIAL, $post->fresh()->status);

        // Retry manual oleh admin → antre lagi.
        $this->actingAs($admin)->post(route('content-targets.retry', $threads))->assertRedirect();
        $this->assertSame(ContentPostTarget::QUEUED, $threads->fresh()->status);
        $this->assertSame(0, $threads->fresh()->attempts);
    }

    public function test_jadwal_masa_depan_belum_diterbitkan(): void
    {
        $creator = $this->user('content_creator', 'cc6');
        $admin = $this->user(User::ROLE_ADMIN, 'adm4');
        SocialConnection::create(['platform' => 'facebook', 'account_id' => 'page1', 'access_token' => 'tok']);
        Http::fake();

        $post = $this->submitted($creator, ['facebook'], 'image', ['scheduled_at' => now()->addDay()->format('Y-m-d H:i')]);
        $this->actingAs($admin)->post(route('content.approve', $post))->assertSessionHasNoErrors();
        $this->artisan('content:publish-due');

        Http::assertNothingSent();
        $this->assertSame(ContentPost::SCHEDULED, $post->fresh()->status);
    }

    public function test_koneksi_sosial_token_terenkripsi_dan_state_oauth_dicek(): void
    {
        $root = $this->user(User::ROLE_SUPER_ADMIN, 'root');
        SocialConnection::create(['platform' => 'threads', 'account_id' => 'th1', 'account_name' => '@skinku', 'access_token' => 'PLAIN-TOKEN-XYZ']);

        $this->assertStringNotContainsString('PLAIN-TOKEN-XYZ', (string) DB::table('social_connections')->value('access_token'));
        $this->actingAs($root)->get(route('social.index'))->assertOk()->assertSee('@skinku')->assertDontSee('PLAIN-TOKEN-XYZ');

        $this->actingAs($root)->withSession(['oauth_state_threads' => 'benar'])
            ->get(route('social.callback', ['provider' => 'threads', 'state' => 'salah', 'code' => 'x']))
            ->assertRedirect(route('social.index'))->assertSessionHas('error');

        // Admin biasa tidak boleh mengelola token akun brand.
        $this->actingAs($this->user(User::ROLE_ADMIN, 'adm5'))->get(route('social.index'))->assertForbidden();
    }
}
