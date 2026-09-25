<?php

namespace Tests\Feature;

use App\Models\ContentPost;
use App\Models\ContentPostTarget;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\Social\TikTokContentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Portal Content Creator Fase 2 — TikTok Content Posting API (FR-43, FR-54). */
class ContentTikTokTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['services.tiktok_content.client_key' => 'ck', 'services.tiktok_content.client_secret' => 'cs']);
    }

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function connect(array $attrs = []): SocialConnection
    {
        return SocialConnection::create($attrs + [
            'platform' => 'tiktok', 'account_id' => 'open1', 'account_name' => 'SKINKU', 'access_token' => 'TT-ACCESS',
            'refresh_token' => 'TT-REFRESH', 'access_expires_at' => now()->addHours(20), 'meta' => ['username' => 'skinku.id'],
        ]);
    }

    private function fakeTikTok(): void
    {
        Http::fake(function (HttpRequest $r) {
            $ok = ['error' => ['code' => 'ok', 'message' => '']];

            return match (true) {
                str_contains($r->url(), '/oauth/token/') => Http::response(['access_token' => 'TT-NEW', 'refresh_token' => 'TT-REFRESH-2', 'expires_in' => 86400, 'open_id' => 'open1']),
                str_contains($r->url(), '/creator_info/query/') => Http::response($ok + ['data' => ['creator_nickname' => 'SKINKU', 'creator_username' => 'skinku.id',
                    'privacy_level_options' => ['SELF_ONLY', 'PUBLIC_TO_EVERYONE'], 'comment_disabled' => false]]),
                str_contains($r->url(), '/video/init/') => Http::response($ok + ['data' => ['publish_id' => 'pub1', 'upload_url' => 'https://open-upload.tiktokapis.com/upload/?id=1']]),
                str_contains($r->url(), '/content/init/') => Http::response($ok + ['data' => ['publish_id' => 'pub2']]),
                str_contains($r->url(), 'open-upload.tiktokapis.com') => Http::response('', 201),
                str_contains($r->url(), '/status/fetch/') => Http::response($ok + ['data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => [7551234567890]]]),
                default => Http::response(['error' => ['code' => 'unexpected', 'message' => $r->url()]], 400),
            };
        });
    }

    /** MP4 minimal (header ftyp asli) 1.024.000 byte — fake()->create() isinya kosong. */
    private static function mp4Bytes(): string
    {
        $head = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

        return $head.str_repeat("\x00", 1024000 - strlen($head));
    }

    private function submitted(User $creator, string $type): ContentPost
    {
        $media = $type === 'video'
            ? [UploadedFile::fake()->createWithContent('v.mp4', self::mp4Bytes())]
            : [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')];

        $this->actingAs($creator)->post(route('content.store'), [
            'title' => 'Serum', 'type' => $type, 'caption' => "Glow tiap hari\n#skinku", 'platforms' => ['tiktok'], 'media' => $media, 'submit' => 1,
        ])->assertSessionHasNoErrors();

        return ContentPost::latest('id')->first();
    }

    public function test_pembagian_potongan_upload_sesuai_aturan_tiktok(): void
    {
        $mb = 1024 * 1024;
        $this->assertSame([3 * $mb, 1], TikTokContentClient::chunkPlan(3 * $mb));       // < 5 MB utuh
        $this->assertSame([10 * $mb, 1], TikTokContentClient::chunkPlan(10 * $mb));
        $this->assertSame([10 * $mb, 2], TikTokContentClient::chunkPlan(25 * $mb));     // sisa 5 MB ikut potongan terakhir
        $this->assertSame([10 * $mb, 1], TikTokContentClient::chunkPlan(19 * $mb));     // potongan terakhir 19 MB (≤128 MB)
    }

    public function test_approve_wajib_pilih_privacy_bila_tiktok_terhubung_dan_form_tampilkan_akun(): void
    {
        $this->connect();
        $this->fakeTikTok();
        $creator = $this->user('content_creator', 'tc1');
        $admin = $this->user(User::ROLE_ADMIN, 'ta1');
        $post = $this->submitted($creator, 'video');

        $this->actingAs($admin)->get(route('content.show', $post))->assertOk()
            ->assertSee('posting ke SKINKU')->assertSee('Music Usage Confirmation')->assertSee('Hanya saya (private)');

        $this->actingAs($admin)->post(route('content.approve', $post))->assertSessionHasErrors('tiktok');
        $this->assertSame(ContentPost::IN_REVIEW, $post->fresh()->status);
    }

    public function test_video_diupload_per_potongan_lalu_terbit_dengan_opsi_reviewer(): void
    {
        $this->connect(['access_expires_at' => now()->subHour()]); // token akses basi → harus di-refresh dulu
        $this->fakeTikTok();
        $creator = $this->user('content_creator', 'tc2');
        $admin = $this->user(User::ROLE_ADMIN, 'ta2');
        $post = $this->submitted($creator, 'video');

        $this->actingAs($admin)->post(route('content.approve', $post), [
            'tiktok' => ['privacy_level' => 'SELF_ONLY', 'consent' => '1', 'allow_duet' => '1'],
        ])->assertSessionHasNoErrors();
        $target = $post->targets()->first();
        $this->assertSame(ContentPostTarget::QUEUED, $target->status);

        $this->artisan('content:publish-due')->assertSuccessful();

        $target->refresh();
        $this->assertSame(ContentPostTarget::PUBLISHED, $target->status, (string) $target->last_error);
        $this->assertSame('7551234567890', $target->external_id);
        $this->assertSame('https://www.tiktok.com/@skinku.id/video/7551234567890', $target->permalink);
        $this->assertSame(ContentPost::DONE, $post->fresh()->status);
        $this->assertSame('TT-NEW', SocialConnection::for('tiktok')->access_token);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/video/init/')
            && $r->hasHeader('Authorization', 'Bearer TT-NEW')
            && $r['post_info']['privacy_level'] === 'SELF_ONLY'
            && $r['post_info']['disable_comment'] === true
            && $r['post_info']['disable_duet'] === false
            && $r['source_info']['source'] === 'FILE_UPLOAD'
            && $r['source_info']['video_size'] === 1024000
            && $r['source_info']['total_chunk_count'] === 1);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT'
            && $r->hasHeader('Content-Range', 'bytes 0-1023999/1024000')
            && strlen($r->body()) === 1024000);
    }

    public function test_carousel_dikirim_sebagai_photo_post_pull_from_url(): void
    {
        $this->connect();
        $this->fakeTikTok();
        $creator = $this->user('content_creator', 'tc3');
        $admin = $this->user(User::ROLE_ADMIN, 'ta3');
        $post = $this->submitted($creator, 'carousel');

        $this->actingAs($admin)->post(route('content.approve', $post), ['tiktok' => ['privacy_level' => 'SELF_ONLY', 'consent' => '1']]);
        $this->artisan('content:publish-due');

        $this->assertSame(ContentPostTarget::PUBLISHED, $post->targets()->first()->status);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/content/init/')
            && $r['media_type'] === 'PHOTO' && $r['post_mode'] === 'DIRECT_POST'
            && $r['source_info']['source'] === 'PULL_FROM_URL'
            && count($r['source_info']['photo_images']) === 2
            && $r['post_info']['title'] === 'Glow tiap hari');
    }

    public function test_tanpa_koneksi_tiktok_tetap_mode_manual(): void
    {
        $creator = $this->user('content_creator', 'tc4');
        $admin = $this->user(User::ROLE_ADMIN, 'ta4');
        $post = $this->submitted($creator, 'video');

        $this->actingAs($admin)->post(route('content.approve', $post))->assertSessionHasNoErrors();
        $this->assertSame(ContentPostTarget::MANUAL_PENDING, $post->targets()->first()->status);
    }

    public function test_halaman_privacy_dan_terms_publik(): void
    {
        $this->get(route('legal.privacy'))->assertOk()->assertSee('Privacy Policy');
        $this->get(route('legal.terms'))->assertOk()->assertSee('Terms of Service');
    }
}
