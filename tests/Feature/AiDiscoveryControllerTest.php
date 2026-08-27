<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiTurn;
use App\Services\Discovery\WebSearchProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeAiProvider;
use Tests\Support\FakeWebSearchProvider;
use Tests\TestCase;

/**
 * Halaman Rekomendasi AI: gating izin/internal, render, cari KOL & tren produk
 * (provider di-fake), dan "+ Tambah ke DB KOL" (prospek + anti-duplikat + izin).
 */
class AiDiscoveryControllerTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    /** Fake mesin pencari + AI supaya endpoint tak menyentuh jaringan. */
    private function fakeProviders(array $searchResults, AiTurn $aiTurn): void
    {
        $this->app->instance(WebSearchProvider::class, new FakeWebSearchProvider($searchResults));
        $this->app->instance(AiProvider::class, new FakeAiProvider([$aiTurn]));
    }

    // ---- Gating --------------------------------------------------------

    public function test_mitra_diblokir_keras_walau_izin_dicentang(): void
    {
        // Distributor = partner → InternalOnlyMiddleware 403 apa pun matriks.
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'mitra1'))
            ->get(route('discovery.index'))->assertForbidden();
    }

    public function test_staf_tanpa_izin_discovery_403(): void
    {
        // Gudang bukan partner tapi tak punya use_ai_discovery → permission 403.
        $this->actingAs($this->user(User::ROLE_GUDANG, 'gud1'))
            ->get(route('discovery.index'))->assertForbidden();
    }

    public function test_kol_specialist_bisa_buka_halaman(): void
    {
        $this->actingAs($this->user('kol_specialist', 'spec1'))
            ->get(route('discovery.index'))->assertOk()->assertSee('Rekomendasi AI');
    }

    // ---- Cari KOL ------------------------------------------------------

    public function test_cari_kol_render_kandidat_dengan_tombol_tambah(): void
    {
        $this->fakeProviders(
            [['title' => 'q', 'url' => 'https://tiktok.com/@ratu', 'content' => 'x']],
            new AiTurn(text: json_encode(['kandidat' => [[
                'username' => 'ratuskincare', 'platform' => 'tiktok', 'followers_est' => 88000,
                'kategori' => 'jerawat', 'url' => 'https://tiktok.com/@ratu', 'alasan' => 'engagement tinggi',
            ]]])),
        );

        $this->actingAs($this->user('kol_specialist', 'spec2'))
            ->post(route('discovery.kol'), ['kategori' => 'jerawat'])
            ->assertOk()
            ->assertSee('ratuskincare')
            ->assertSee('Tambah ke Database KOL');
    }

    // ---- Tambah ke DB KOL ---------------------------------------------

    public function test_tambah_kol_bikin_prospek_lalu_redirect_ke_detail(): void
    {
        $spec = $this->user('kol_specialist', 'spec3');

        $res = $this->actingAs($spec)->post(route('discovery.kol.add'), [
            'username' => '@newkol', 'platform' => 'tiktok', 'url' => 'https://tiktok.com/@newkol',
            'followers' => 120000, 'kategori' => 'brightening',
        ]);

        $kol = Kol::where('tiktok_username', 'newkol')->first();
        $this->assertNotNull($kol);
        $this->assertSame(Kol::STATUS_PROSPEK, $kol->status);
        $this->assertSame(120000, $kol->followers);
        $res->assertRedirect(route('kols.show', $kol->id));
    }

    public function test_tambah_kol_tak_bikin_duplikat(): void
    {
        $spec = $this->user('kol_specialist', 'spec4');
        Kol::create(['tiktok_username' => 'sudahada', 'followers' => 5000, 'status' => Kol::STATUS_AKTIF]);

        $this->actingAs($spec)->post(route('discovery.kol.add'), [
            'username' => 'SudahAda', 'followers' => 999999, // beda kapital → tetap sama
        ])->assertRedirect();

        $this->assertSame(1, Kol::where('tiktok_username', 'sudahada')->count());
        // followers lama TIDAK ditimpa angka discovery (tetap 5000).
        $this->assertSame(5000, Kol::where('tiktok_username', 'sudahada')->first()->followers);
    }

    public function test_admin_bisa_cari_tapi_tak_bisa_tambah_kol(): void
    {
        // Admin punya use_ai_discovery TAPI bukan kol.screening.manage (default
        // hanya kol_specialist) → tombol/route tambah 403.
        $this->actingAs($this->user(User::ROLE_ADMIN, 'admin1'))
            ->post(route('discovery.kol.add'), ['username' => 'x'])
            ->assertForbidden();
    }

    public function test_tambah_massal_paste_jadi_prospek_dan_dedupe(): void
    {
        $spec = $this->user('kol_specialist', 'spec6');
        Kol::create(['tiktok_username' => 'sudahada2', 'followers' => 1000, 'status' => Kol::STATUS_AKTIF]);

        $this->actingAs($spec)->post(route('discovery.kol.bulk'), [
            'platform' => 'tiktok', 'kategori' => 'skincare',
            'daftar' => "@ratu1\nratu2\nhttps://www.tiktok.com/@ratu3\nRATU1\nsudahada2\nnama dengan spasi",
        ])->assertRedirect(route('discovery.index', ['tab' => 'massal']));

        // ratu1/2/3 baru; RATU1 = duplikat ratu1 (dilewati); sudahada2 sudah ada;
        // "nama dengan spasi" tak valid (dilewati).
        $this->assertSame(3, Kol::whereIn('tiktok_username', ['ratu1', 'ratu2', 'ratu3'])
            ->where('status', Kol::STATUS_PROSPEK)->count());
        $this->assertSame('skincare', Kol::where('tiktok_username', 'ratu1')->first()->kategori);
        $this->assertSame(1, Kol::where('tiktok_username', 'sudahada2')->count()); // tak tergandakan
    }

    public function test_tambah_massal_butuh_screening_manage(): void
    {
        // Admin punya use_ai_discovery tapi bukan kol.screening.manage → 403.
        $this->actingAs($this->user(User::ROLE_ADMIN, 'admin2'))
            ->post(route('discovery.kol.bulk'), ['daftar' => 'ratux'])
            ->assertForbidden();
    }

    // ---- Tren Produk ---------------------------------------------------

    public function test_cari_tren_produk_render_poin(): void
    {
        $this->fakeProviders(
            [['title' => 'tren', 'url' => 'https://ex.com/t', 'content' => 'ceramide']],
            new AiTurn(text: json_encode([
                'ringkasan' => 'Barrier repair naik.',
                'poin' => [['judul' => 'Ceramide naik', 'detail' => 'permintaan tinggi', 'sumber' => ['https://ex.com/t']]],
            ])),
        );

        $this->actingAs($this->user('kol_specialist', 'spec5'))
            ->post(route('discovery.produk'), ['topik' => 'serum'])
            ->assertOk()
            ->assertSee('Ceramide naik')
            ->assertSee('Barrier repair naik.');
    }
}
