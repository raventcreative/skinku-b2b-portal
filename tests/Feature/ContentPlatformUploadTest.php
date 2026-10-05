<?php

namespace Tests\Feature;

use App\Models\ContentPostTarget;
use App\Models\SocialConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContentPlatformUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['services.tiktok_content.client_key' => 'client-key', 'services.tiktok_content.client_secret' => 'client-secret']);
    }

    private function creator(): User
    {
        return User::create([
            'name' => 'creator', 'fullname' => 'Creator', 'username' => 'creator-upload',
            'email' => 'creator-upload@example.test', 'password' => Hash::make('secret123'),
            'role' => 'content_creator', 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function connect(string $platform, array $attrs = []): SocialConnection
    {
        return SocialConnection::create($attrs + [
            'platform' => $platform, 'account_id' => $platform === 'instagram' ? 'ig1' : 'tt1',
            'account_name' => 'SKINKU', 'access_token' => 'platform-token',
            'refresh_token' => $platform === 'tiktok' ? 'refresh-token' : null,
            'access_expires_at' => now()->addDay(), 'meta' => ['username' => 'skinku.id'],
        ]);
    }

    private function publish(User $creator, string $platform, string $type, UploadedFile $media, array $options = []): ContentPostTarget
    {
        $payload = [
            'title' => 'Konten SKINKU', 'type' => $type, 'caption' => 'Glow setiap hari',
            'platforms' => [$platform], 'media' => [$media], 'intent' => 'publish',
        ];
        if ($platform === 'tiktok') {
            $payload['tiktok'] = ['privacy_level' => 'SELF_ONLY', 'consent' => '1'];
        }

        $this->actingAs($creator)->post(route('content.store'), $payload + $options)->assertSessionHasNoErrors();

        return ContentPostTarget::latest('id')->firstOrFail();
    }

    public function test_instagram_reel_diupload_lewat_container_sampai_terbit(): void
    {
        $this->connect('instagram');
        Http::fake(function (HttpRequest $request) {
            return match (true) {
                str_contains($request->url(), '/ig1/media_publish') => Http::response(['id' => 'ig-media-1']),
                str_contains($request->url(), '/ig1/media') => Http::response(['id' => 'ig-container-1']),
                str_contains($request->url(), '/ig-container-1') => Http::response(['status_code' => 'FINISHED']),
                str_contains($request->url(), '/ig-media-1') => Http::response(['permalink' => 'https://instagram.com/p/abc/']),
                default => Http::response(['error' => ['message' => $request->url()]], 400),
            };
        });
        $creator = $this->creator();
        $target = $this->publish($creator, 'instagram', 'video', UploadedFile::fake()->create('reel.mp4', 1000, 'video/mp4'));

        $this->artisan('content:publish-due')->assertSuccessful();
        $this->assertSame(ContentPostTarget::PUBLISHED, $target->fresh()->status);
        $this->assertSame('ig-media-1', $target->fresh()->external_id);
        Http::assertSent(fn (HttpRequest $request) => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/ig1/media')
            && $request['media_type'] === 'REELS' && str_contains($request['video_url'], '/storage/content_media/'));
    }

    public function test_tiktok_foto_dikirim_dengan_pull_from_url(): void
    {
        $this->connect('tiktok');
        Http::fake(function (HttpRequest $request) {
            return match (true) {
                str_contains($request->url(), '/content/init/') => Http::response(['error' => ['code' => 'ok'], 'data' => ['publish_id' => 'tt-photo-1']]),
                str_contains($request->url(), '/status/fetch/') => Http::response(['error' => ['code' => 'ok'], 'data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => ['12345']]]),
                default => Http::response(['error' => ['code' => 'unexpected', 'message' => $request->url()]], 400),
            };
        });
        $target = $this->publish($this->creator(), 'tiktok', 'image', UploadedFile::fake()->image('foto.jpg'));

        $this->artisan('content:publish-due')->assertSuccessful();
        $this->assertSame(ContentPostTarget::PUBLISHED, $target->fresh()->status);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/content/init/')
            && $request['source_info']['source'] === 'PULL_FROM_URL'
            && count($request['source_info']['photo_images']) === 1);
    }

    public function test_tiktok_video_diupload_langsung_ke_server_tiktok(): void
    {
        $this->connect('tiktok');
        $bytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 1024);
        Http::fake(function (HttpRequest $request) {
            return match (true) {
                str_contains($request->url(), '/video/init/') => Http::response(['error' => ['code' => 'ok'], 'data' => ['publish_id' => 'tt-video-1', 'upload_url' => 'https://upload.tiktokapis.com/upload/1']]),
                str_contains($request->url(), 'upload.tiktokapis.com') => Http::response('', 201),
                str_contains($request->url(), '/status/fetch/') => Http::response(['error' => ['code' => 'ok'], 'data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => ['12345']]]),
                default => Http::response(['error' => ['code' => 'unexpected', 'message' => $request->url()]], 400),
            };
        });
        $target = $this->publish($this->creator(), 'tiktok', 'video', UploadedFile::fake()->createWithContent('video.mp4', $bytes));

        $this->artisan('content:publish-due')->assertSuccessful();
        $this->assertSame(ContentPostTarget::PUBLISHED, $target->fresh()->status);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/video/init/')
            && $request['source_info']['source'] === 'FILE_UPLOAD');
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT'
            && str_contains($request->url(), 'upload.tiktokapis.com'));
    }
}
