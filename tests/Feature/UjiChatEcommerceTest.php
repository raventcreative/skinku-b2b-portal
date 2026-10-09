<?php

namespace Tests\Feature;

use App\Models\AiKnowledge;
use App\Models\EcomChatConversation;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

/**
 * Uji chat pembeli di Pengetahuan AI → tab Chat E-commerce: balasan memakai isian form saat itu (belum disimpan pun)
 * lewat EcomChatDrafter yang sama dgn chat sungguhan; tidak menyimpan & tidak mengirim apa pun. Akses = halaman
 * Pengetahuan AI (use_ai_assistant + bukan mitra).
 */
class UjiChatEcommerceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function fakeAi(string ...$jawaban): FakeAiProvider
    {
        $fake = new FakeAiProvider(array_map(fn ($t) => new AiTurn(text: $t), $jawaban));
        $this->app->instance(AiProvider::class, $fake);

        return $fake;
    }

    public function test_uji_memakai_isian_belum_disimpan_dan_tidak_menyimpan_apa_pun(): void
    {
        AiKnowledge::create(['section' => 'chat_faq', 'content' => 'COD: belum ada COD', 'group' => 'chat']);
        $fake = $this->fakeAi('{"reply":"Bisa COD Kak","decision":"auto_send","reason":"ada di FAQ"}');

        $this->actingAs($this->user(User::ROLE_ADMIN, 'adm'))->postJson(route('ai.knowledge.test-chat'), [
            'content' => ['chat_faq' => 'COD: sekarang BISA COD', 'chat_tone' => 'Panggil pembeli Kak'],
            'pesan' => [['dari' => 'pembeli', 'teks' => 'Bisa COD?']],
        ])->assertOk()->assertExactJson(['reply' => 'Bisa COD Kak', 'decision' => 'auto_send', 'reason' => 'ada di FAQ']);

        // Prompt memakai isian form (bukan yang tersimpan) + aturan keputusan yang sama dgn chat sungguhan.
        $system = $fake->sent[0]['messages'][0]['content'];
        $this->assertStringContainsString('COD: sekarang BISA COD', $system);
        $this->assertStringContainsString('Panggil pembeli Kak', $system);
        $this->assertStringNotContainsString('belum ada COD', $system);
        $this->assertStringContainsString('ATURAN KEPUTUSAN', $system);
        $this->assertSame(['role' => 'user', 'content' => 'Bisa COD?'], $fake->sent[0]['messages'][1]);

        // Tidak ada yang tersimpan / terkirim.
        $this->assertSame('COD: belum ada COD', AiKnowledge::where('section', 'chat_faq')->value('content'));
        $this->assertSame(1, AiKnowledge::count());
        $this->assertSame(0, EcomChatConversation::count());
    }

    public function test_chat_beruntun_dikirim_berurutan_dan_kasus_spesifik_ke_staf(): void
    {
        $fake = $this->fakeAi('{"reply":"Mohon maaf Kak, kami cek dulu ya","decision":"to_staff","reason":"pesanan spesifik pembeli"}');

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'sa'))->postJson(route('ai.knowledge.test-chat'), [
            'content' => ['chat_policy' => 'Batal hanya sebelum dikirim.'],
            'pesan' => [
                ['dari' => 'pembeli', 'teks' => 'Halo kak'],
                ['dari' => 'ai', 'teks' => 'Halo Kak, ada yang bisa dibantu?'],
                ['dari' => 'pembeli', 'teks' => 'Batalin pesanan saya #123'],
            ],
        ])->assertOk()->assertJson(['decision' => 'to_staff', 'reason' => 'pesanan spesifik pembeli']);

        $this->assertSame(['system', 'user', 'assistant', 'user'], array_column($fake->sent[0]['messages'], 'role'));
        $this->assertSame('Batalin pesanan saya #123', $fake->sent[0]['messages'][3]['content']);
    }

    public function test_balasan_ai_rusak_tetap_aman_ke_staf(): void
    {
        $this->fakeAi('maaf ini bukan json');

        $this->actingAs($this->user(User::ROLE_ADMIN, 'adm'))->postJson(route('ai.knowledge.test-chat'), [
            'pesan' => [['dari' => 'pembeli', 'teks' => 'halo']],
        ])->assertOk()->assertJson(['reply' => '', 'decision' => 'to_staff']);
    }

    public function test_validasi_dan_hak_akses(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'adm');
        $this->fakeAi();

        $this->actingAs($admin)->postJson(route('ai.knowledge.test-chat'), ['pesan' => []])
            ->assertStatus(422)->assertJson(['message' => 'Ketik pesan pembeli dulu.']);
        $this->actingAs($admin)->postJson(route('ai.knowledge.test-chat'), ['pesan' => [['dari' => 'ai', 'teks' => 'halo']]])
            ->assertStatus(422)->assertJson(['message' => 'Pesan terakhir harus dari pembeli.']);

        // Mitra diblok (fitur internal) walau punya izin Asisten AI; role tanpa izin Asisten AI juga.
        $kirim = ['pesan' => [['dari' => 'pembeli', 'teks' => 'halo']]];
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'dist'))->postJson(route('ai.knowledge.test-chat'), $kirim)->assertForbidden();
        $this->actingAs($this->user('kol_specialist', 'kol'))->postJson(route('ai.knowledge.test-chat'), $kirim)->assertForbidden();
    }

    public function test_panel_uji_hanya_di_tab_chat_ecommerce(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');

        $this->actingAs($sa)->get(route('ai.knowledge', ['tab' => 'chat']))->assertOk()
            ->assertSee('Uji chat pembeli')->assertSee(route('ai.knowledge.test-chat'), false)->assertSee('data-pengetahuan', false);
        $this->actingAs($sa)->get(route('ai.knowledge', ['tab' => 'sistem']))->assertOk()->assertDontSee('Uji chat pembeli');
    }
}
