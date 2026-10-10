<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Penilai AI konten TikTok & Instagram (teks saja) di form konten. */
class ContentAiReviewTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'ar', 'fullname' => 'AR', 'username' => 'ar', 'email' => 'ar@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    /** Provider palsu: simpan pesan yang dikirim, balas teks tetap. */
    private function fakeAi(string $reply): object
    {
        $fake = new class($reply) implements AiProvider
        {
            public array $messages = [];

            public function __construct(private string $reply) {}

            public function chat(array $messages, array $tools): AiTurn
            {
                $this->messages = $messages;

                return new AiTurn(text: $this->reply);
            }
        };
        $this->app->instance(AiProvider::class, $fake);

        return $fake;
    }

    public function test_menilai_caption_untuk_tiktok_dan_instagram(): void
    {
        $ai = $this->fakeAi('```json
{"score": 72, "summary": "Hook kuat, CTA kurang.", "platforms": {"tiktok": {"score": 80, "notes": ["Hook oke"]}, "instagram": {"score": 140, "notes": ["Tambah jeda baris"]}},
 "suggestions": ["Tambah CTA simpan"], "caption": "Kulit cerah 3 detik ✨ Simpan ya!"}
```');

        $this->actingAs($this->admin())->postJson(route('content.ai-review'), [
            'type' => 'video', 'caption' => 'Kulit cerah 3 detik #skinku', 'platforms' => ['tiktok', 'instagram', 'facebook'],
            'captions' => ['instagram' => 'Versi IG panjang'],
        ])->assertOk()->assertJson([
            'score' => 72,
            'platforms' => ['tiktok' => ['score' => 80], 'instagram' => ['score' => 100]], // skor dijepit 0–100
            'suggestions' => ['Tambah CTA simpan'],
            'caption' => 'Kulit cerah 3 detik ✨ Simpan ya!',
        ])->assertJsonMissingPath('platforms.facebook');

        // Caption khusus per platform yang dinilai, bukan selalu caption utama.
        $this->assertStringContainsString('Versi IG panjang', $ai->messages[1]['content']);
        $this->assertStringContainsString('Kulit cerah 3 detik #skinku', $ai->messages[1]['content']);
    }

    public function test_jawaban_ai_rusak_jadi_pesan_jelas_bukan_skor_karangan(): void
    {
        $this->fakeAi('maaf saya tidak bisa');

        $this->actingAs($this->admin())->postJson(route('content.ai-review'), ['type' => 'video', 'caption' => 'Halo'])
            ->assertStatus(502)->assertJsonPath('message', 'Jawaban AI tak terbaca — coba nilai ulang.');
    }

    public function test_caption_kosong_ditolak(): void
    {
        $this->fakeAi('{}');

        $this->actingAs($this->admin())->postJson(route('content.ai-review'), ['type' => 'video', 'caption' => ''])
            ->assertStatus(422)->assertJsonPath('message', 'Tulis caption dulu sebelum dinilai AI.');
    }
}
