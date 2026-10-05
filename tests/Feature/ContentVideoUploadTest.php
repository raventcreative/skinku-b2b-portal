<?php

namespace Tests\Feature;

use App\Models\ContentPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Tests\TestCase;

/** Upload video konten: MP4 hasil ekspor HP (label video/x-m4v) & batas ukuran mengikuti PHP server. */
class ContentVideoUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_mp4_hasil_ekspor_hp_diterima_sebagai_video(): void
    {
        Storage::fake('public');
        $admin = User::create([
            'name' => 'vu', 'fullname' => 'VU', 'username' => 'vu', 'email' => 'vu@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
        // Brand M4V (umum di ekspor iPhone/CapCut): finfo melabelinya video/x-m4v atau application/mp4, tergantung server.
        $head = "\x00\x00\x00\x18ftypM4V \x00\x00\x00\x00M4V isom";
        $video = UploadedFile::fake()->createWithContent('klip.mp4', $head.str_repeat("\x00", 1024000 - strlen($head)));
        $this->assertContains($video->getMimeType(), ['video/x-m4v', 'application/mp4', 'video/mp4']);

        $this->actingAs($admin)->post(route('content.store'), [
            'type' => 'video', 'intent' => 'draft', 'caption' => 'Glow', 'platforms' => ['instagram'],
            'media' => [$video],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, ContentPost::where('title', 'Glow')->first()->filesIn(ContentPost::MEDIA)->count());
    }

    public function test_tanpa_judul_judul_diambil_dari_baris_pertama_caption(): void
    {
        $this->assertSame('Glow tiap hari', \App\Services\ContentPostService::titleFor(['caption' => "Glow tiap hari\n#skinku"]));
        $this->assertSame('Judul manual', \App\Services\ContentPostService::titleFor(['title' => 'Judul manual', 'caption' => 'x']));
        $this->assertStringStartsWith('Konten ', \App\Services\ContentPostService::titleFor(['caption' => '']));
    }

    public function test_batas_video_tidak_melebihi_batas_upload_php(): void
    {
        $this->assertLessThanOrEqual(intdiv(SymfonyUploadedFile::getMaxFilesize(), 1024), config('content.video_max_kb'));
    }
}
