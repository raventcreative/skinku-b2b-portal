<?php

namespace Tests\Feature;

use App\Models\AiKnowledge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EcomChatKnowledgeTabTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'A', 'fullname' => 'A', 'username' => 'admin', 'email' => 'a@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_halaman_menampilkan_dua_tab(): void
    {
        $admin = $this->admin();

        // Tab default (sistem) menampilkan label tab "Chat E-commerce" di nav.
        $this->actingAs($admin)->get('/asisten/pengetahuan')->assertOk()
            ->assertSee('Chat E-commerce');

        // Pindah ke tab chat (?tab=chat) menampilkan section khusus grup chat.
        $this->actingAs($admin)->get('/asisten/pengetahuan?tab=chat')->assertOk()
            ->assertSee('Gaya bahasa ke pembeli');
    }

    public function test_simpan_grup_chat_tak_menghapus_sistem(): void
    {
        AiKnowledge::create(['section' => 'rules', 'content' => 'Aturan lama', 'group' => 'sistem']);

        $this->actingAs($this->admin())->post('/asisten/pengetahuan', [
            'group' => 'chat',
            'content' => ['chat_faq' => 'BPOM semua terdaftar'],
        ])->assertRedirect();

        // Chat tersimpan dgn grup benar
        $faq = AiKnowledge::where('section', 'chat_faq')->first();
        $this->assertNotNull($faq);
        $this->assertSame('chat', $faq->group);
        $this->assertSame('BPOM semua terdaftar', $faq->content);

        // Grup sistem tak tersentuh saat menyimpan tab chat
        $this->assertSame('Aturan lama', AiKnowledge::where('section', 'rules')->first()->content);
    }

    public function test_param_tab_array_tak_bikin_500(): void
    {
        // request('tab') bisa jadi array (?tab[]=x) → array_key_exists($array, $groups)
        // lempar TypeError. Harus fallback ke 'sistem', bukan 500.
        $this->actingAs($this->admin())->get('/asisten/pengetahuan?tab[]=x')->assertOk()
            ->assertSee('Aturan & gaya bicara');
    }
}
