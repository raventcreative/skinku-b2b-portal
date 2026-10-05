<?php

namespace Tests\Feature;

use App\Models\ContentPost;
use App\Models\ContentPostTarget;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\ContentPostService;
use App\Services\Social\ContentPublisher;
use App\Services\Social\TikTokContentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Selama app TikTok belum lolos audit, semua postingan TikTok wajib private (SELF_ONLY). */
class TikTokPrivacyAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['services.tiktok_content.client_key' => 'ck', 'services.tiktok_content.client_secret' => 'cs']);
        SocialConnection::create([
            'platform' => 'tiktok', 'account_id' => 'open1', 'account_name' => 'SKINKU', 'access_token' => 'TT-ACCESS',
            'refresh_token' => 'TT-REFRESH', 'access_expires_at' => now()->addHours(20),
        ]);
    }

    private function draft(): ContentPost
    {
        $user = User::create([
            'name' => 'tp', 'fullname' => 'TP', 'username' => 'tp', 'email' => 'tp@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
        $head = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";
        $video = UploadedFile::fake()->createWithContent('v.mp4', $head.str_repeat("\x00", 1024000 - strlen($head)));

        return app(ContentPostService::class)->save(null, $user, [
            'title' => 'Serum', 'type' => 'video', 'caption' => 'Glow #skinku', 'platforms' => ['tiktok'], 'intent' => 'draft',
        ], [$video]);
    }

    public function test_pilihan_privasi_hanya_private_sebelum_audit(): void
    {
        config(['services.tiktok_content.audited' => false]);
        $this->assertSame(['SELF_ONLY'], TikTokContentClient::privacyOptions(['SELF_ONLY', 'PUBLIC_TO_EVERYONE']));

        config(['services.tiktok_content.audited' => true]);
        $this->assertSame(['SELF_ONLY', 'PUBLIC_TO_EVERYONE'], TikTokContentClient::privacyOptions(['SELF_ONLY', 'PUBLIC_TO_EVERYONE']));
    }

    public function test_terbit_publik_ditolak_sebelum_audit(): void
    {
        config(['services.tiktok_content.audited' => false]);
        $post = $this->draft();

        $this->expectException(ValidationException::class);
        app(ContentPostService::class)->publish($post, ['privacy_level' => 'PUBLIC_TO_EVERYONE', 'consent' => '1']);
    }

    public function test_target_lama_berprivasi_publik_tetap_dikirim_private(): void
    {
        config(['services.tiktok_content.audited' => false]);
        $ok = ['error' => ['code' => 'ok', 'message' => '']];
        Http::fake(fn (HttpRequest $r) => match (true) {
            str_contains($r->url(), '/video/init/') => Http::response($ok + ['data' => ['publish_id' => 'pub1', 'upload_url' => 'https://open-upload.tiktokapis.com/u']]),
            str_contains($r->url(), 'open-upload.tiktokapis.com') => Http::response('', 201),
            default => Http::response($ok + ['data' => ['status' => 'PROCESSING_UPLOAD']]),
        });
        $target = $this->draft()->targets()->where('platform', 'tiktok')->first();
        $target->update(['status' => ContentPostTarget::QUEUED, 'options' => ['privacy_level' => 'PUBLIC_TO_EVERYONE']]);

        app(ContentPublisher::class)->publish($target->fresh());

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/video/init/')
            && $r['post_info']['privacy_level'] === 'SELF_ONLY');
    }
}
