<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\BoardCard;
use App\Models\BoardColumn;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

/**
 * Fase 4-5: halaman Asisten + alur konfirmasi aksi tulis. Otak di-swap
 * FakeAiProvider (tanpa jaringan); alat & registry pakai yang asli.
 */
class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function super(string $u = 'sa'): User
    {
        return $this->user(User::ROLE_SUPER_ADMIN, $u);
    }

    private function fakeBrain(array $turns): void
    {
        $this->app->bind(AiProvider::class, fn () => new FakeAiProvider($turns));
    }

    public function test_hanya_pemegang_izin_bisa_akses(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN, 'adm'))->get(route('ai.index'))->assertForbidden();
        $this->actingAs($this->user(User::ROLE_ADMIN, 'adm2'))->post(route('ai.send'), ['message' => 'hai'])->assertForbidden();
        $this->actingAs($this->super())->get(route('ai.index'))->assertOk()->assertSee('Asisten AI');
    }

    public function test_kirim_pesan_teks_muncul_di_percakapan(): void
    {
        $this->fakeBrain([new AiTurn(text: 'Halo! Ada yang bisa dibantu?')]);

        $sa = $this->super();
        $this->actingAs($sa)->post(route('ai.send'), ['message' => 'halo asisten'])->assertRedirect(route('ai.index'));
        $this->actingAs($sa)->get(route('ai.index'))->assertOk()
            ->assertSee('halo asisten')->assertSee('Halo! Ada yang bisa dibantu?');
    }

    public function test_alat_baca_dashboard_lewat_loop(): void
    {
        // Model minta ringkas_dashboard (alat asli, DB kosong → nol) lalu menjawab.
        $this->fakeBrain([
            new AiTurn(toolCalls: [['id' => 'c1', 'name' => 'ringkas_dashboard', 'arguments' => []]]),
            new AiTurn(text: 'Bulan ini belum ada penjualan.'),
        ]);

        $sa = $this->super();
        $this->actingAs($sa)->post(route('ai.send'), ['message' => 'ringkas dong'])->assertRedirect();
        $this->actingAs($sa)->get(route('ai.index'))->assertOk()->assertSee('Bulan ini belum ada penjualan.');
    }

    public function test_buat_kartu_minta_konfirmasi_lalu_dieksekusi(): void
    {
        $sa = $this->super();
        $board = Board::create(['name' => 'Papan Tim', 'created_by' => $sa->id]);
        BoardColumn::create(['board_id' => $board->id, 'name' => 'To Do', 'position' => 0]);

        $this->fakeBrain([new AiTurn(toolCalls: [['id' => 'c1', 'name' => 'buat_kartu_kanban', 'arguments' => [
            'papan' => 'Papan Tim', 'kolom' => 'To Do', 'judul' => 'Revisi katalog',
        ]]])]);

        // 1) send → muncul kartu konfirmasi, kartu BELUM dibuat.
        $this->actingAs($sa)->post(route('ai.send'), ['message' => 'buatkan kartu revisi katalog'])->assertRedirect();
        $this->actingAs($sa)->get(route('ai.index'))->assertOk()
            ->assertSee('Konfirmasi aksi')->assertSee('Revisi katalog')->assertSee('Ya, jalankan');
        $this->assertSame(0, BoardCard::count());

        // 2) konfirmasi "ya" → kartu dibuat.
        $this->actingAs($sa)->post(route('ai.confirm'), ['setuju' => 'ya'])->assertRedirect();
        $this->assertSame(1, BoardCard::count());
        $card = BoardCard::first();
        $this->assertSame('Revisi katalog', $card->title);
        $this->assertSame($sa->id, $card->created_by);
        $this->actingAs($sa)->get(route('ai.index'))->assertOk()->assertSee('dibuat');
    }

    public function test_konfirmasi_batal_tidak_bikin_kartu(): void
    {
        $sa = $this->super();
        $board = Board::create(['name' => 'Papan Tim', 'created_by' => $sa->id]);
        BoardColumn::create(['board_id' => $board->id, 'name' => 'To Do', 'position' => 0]);

        $this->fakeBrain([new AiTurn(toolCalls: [['id' => 'c1', 'name' => 'buat_kartu_kanban', 'arguments' => [
            'papan' => 'Papan Tim', 'kolom' => 'To Do', 'judul' => 'Jangan dibuat',
        ]]])]);

        $this->actingAs($sa)->post(route('ai.send'), ['message' => 'buatkan kartu'])->assertRedirect();
        $this->actingAs($sa)->post(route('ai.confirm'), ['setuju' => 'batal'])->assertRedirect();

        $this->assertSame(0, BoardCard::count());
        $this->actingAs($sa)->get(route('ai.index'))->assertOk()->assertSee('dibatalkan');
    }

    public function test_argumen_ambigu_ai_tanya_balik_bukan_konfirmasi(): void
    {
        $sa = $this->super();
        // Papan tak ada → validate gagal → disuap → model nanya balik (giliran ke-2).
        $this->fakeBrain([
            new AiTurn(toolCalls: [['id' => 'c1', 'name' => 'buat_kartu_kanban', 'arguments' => [
                'papan' => 'Papan Hantu', 'kolom' => 'To Do', 'judul' => 'X',
            ]]]),
            new AiTurn(text: 'Papan “Papan Hantu” nggak ada. Mau di papan mana?'),
        ]);

        $this->actingAs($sa)->post(route('ai.send'), ['message' => 'buat kartu'])->assertRedirect();
        // Lewat state JSON (bukan HTML) supaya tak bentrok teks widget: tak ada
        // konfirmasi tertunda, dan AI menjawab minta-klarifikasi.
        $state = $this->actingAs($sa)->getJson(route('ai.state'))->assertOk()
            ->assertJsonPath('pending', null)->json();
        $this->assertStringContainsString('papan mana', $state['thread'][1]['content']);
        $this->assertSame(0, BoardCard::count());
    }

    public function test_error_otak_ditangani_ramah_bukan_500(): void
    {
        $this->app->bind(AiProvider::class, fn () => new class implements AiProvider
        {
            public function chat(array $messages, array $tools): AiTurn
            {
                throw new AiException('OPENAI_API_KEY belum diisi di .env server.');
            }
        });

        $sa = $this->super();
        $this->actingAs($sa)->post(route('ai.send'), ['message' => 'halo'])->assertRedirect();
        $this->actingAs($sa)->get(route('ai.index'))->assertOk()
            ->assertSee('halo')->assertSee('OPENAI_API_KEY');
    }

    public function test_reset_hapus_percakapan(): void
    {
        $this->fakeBrain([new AiTurn(text: 'hai')]);
        $sa = $this->super();
        $this->actingAs($sa)->post(route('ai.send'), ['message' => 'ingatan lama'])->assertRedirect();
        $this->actingAs($sa)->post(route('ai.reset'))->assertRedirect();
        $this->actingAs($sa)->get(route('ai.index'))->assertOk()->assertDontSee('ingatan lama')->assertSee('Aku bisa bantu apa');
    }

    public function test_widget_muncul_di_semua_halaman_untuk_pemegang_izin(): void
    {
        $this->actingAs($this->super())->get(route('dashboard'))->assertOk()
            ->assertSee('id="aiWidget"', false)->assertSee('Buka Asisten AI');
        // Non-pemegang izin tak melihat widget.
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'dst'))->get(route('dashboard'))->assertOk()
            ->assertDontSee('id="aiWidget"', false);
    }

    public function test_endpoint_json_untuk_widget(): void
    {
        $this->fakeBrain([new AiTurn(text: 'Halo dari widget!')]);
        $sa = $this->super();

        $this->actingAs($sa)->getJson(route('ai.state'))->assertOk()->assertExactJson(['thread' => [], 'pending' => null]);

        $this->actingAs($sa)->postJson(route('ai.send'), ['message' => 'hai'])->assertOk()
            ->assertJsonPath('thread.0.content', 'hai')
            ->assertJsonPath('thread.1.content', 'Halo dari widget!');
    }

    public function test_widget_json_konfirmasi_kartu(): void
    {
        $sa = $this->super();
        $board = Board::create(['name' => 'Papan Tim', 'created_by' => $sa->id]);
        BoardColumn::create(['board_id' => $board->id, 'name' => 'To Do', 'position' => 0]);
        $this->fakeBrain([new AiTurn(toolCalls: [['id' => 'c1', 'name' => 'buat_kartu_kanban', 'arguments' => [
            'papan' => 'Papan Tim', 'kolom' => 'To Do', 'judul' => 'Via widget',
        ]]])]);

        $res = $this->actingAs($sa)->postJson(route('ai.send'), ['message' => 'buat kartu'])->assertOk();
        $this->assertStringContainsString('Via widget', $res->json('pending.preview'));

        $this->actingAs($sa)->postJson(route('ai.confirm'), ['setuju' => 'ya'])->assertOk();
        $this->assertSame(1, BoardCard::count());
    }
}
