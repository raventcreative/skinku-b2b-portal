<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolDeal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Penyempurnaan Deal KOL: status massal (Acc/Tolak/Selesai), filter, laporan
 * hasil (verdict ikut tujuan), modal cepat.
 */
class KolDealEnhancementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create([
            'name' => $role, 'fullname' => ucfirst($role), 'username' => $role.'_u', 'email' => $role.'@skinku.test',
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function kol(string $u = 'kolx'): Kol
    {
        return Kol::create(['tiktok_username' => $u, 'platform' => 'tiktok', 'followers' => 100000, 'status' => 'prospek']);
    }

    private function deal(Kol $kol, array $attr = []): KolDeal
    {
        return KolDeal::create(array_merge([
            'kode' => KolDeal::generateKode(), 'kol_id' => $kol->id, 'jenis' => 'vt',
            'ratecard_deal' => 1_000_000, 'jumlah_slot' => 1, 'status' => 'draft', 'total_biaya' => 1_000_000,
        ], $attr));
    }

    public function test_bulk_status_acc_tolak_dan_izin(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN);
        $kol = $this->kol();
        $d1 = $this->deal($kol);
        $d2 = $this->deal($kol);

        // Acc massal → berjalan.
        $this->actingAs($sa)->post(route('kol-deals.bulk-status'), ['ids' => [$d1->id, $d2->id], 'status' => 'berjalan'])
            ->assertRedirect();
        $this->assertSame('berjalan', $d1->fresh()->status);
        $this->assertSame('berjalan', $d2->fresh()->status);

        // Tolak satu → batal.
        $this->actingAs($sa)->post(route('kol-deals.bulk-status'), ['ids' => [$d1->id], 'status' => 'batal'])->assertRedirect();
        $this->assertSame('batal', $d1->fresh()->status);

        // Mitra tanpa izin → 403.
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR))
            ->post(route('kol-deals.bulk-status'), ['ids' => [$d2->id], 'status' => 'batal'])->assertForbidden();
    }

    public function test_filter_status(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN);
        $kol = $this->kol();
        $selesai = $this->deal($kol, ['status' => 'selesai']);
        $draft = $this->deal($kol, ['status' => 'draft']);

        $this->actingAs($sa)->get(route('kol-deals.index', ['status' => 'selesai']))
            ->assertOk()->assertSee($selesai->kode)->assertDontSee($draft->kode);
    }

    public function test_verdict_penjualan_pakai_romi(): void
    {
        $kol = $this->kol();
        $bagus = $this->deal($kol, ['total_biaya' => 1_000_000, 'hasil_tujuan' => 'penjualan', 'hasil_revenue' => 3_000_000]);
        $this->assertSame(KolDeal::VERDICT_BAGUS, $bagus->hasil_verdict);   // ROMI 3

        $jelek = $this->deal($kol, ['total_biaya' => 1_000_000, 'hasil_tujuan' => 'penjualan', 'hasil_revenue' => 500_000]);
        $this->assertSame(KolDeal::VERDICT_JELEK, $jelek->hasil_verdict);   // ROMI 0.5 (rugi)
    }

    public function test_verdict_awareness_pakai_cpm_tanpa_revenue(): void
    {
        $kol = $this->kol();
        // biaya 1jt / 50rb views * 1000 = CPM 20rb (<60rb) → Bagus, walau revenue null.
        $bagus = $this->deal($kol, ['total_biaya' => 1_000_000, 'hasil_tujuan' => 'awareness', 'hasil_views' => 50_000, 'hasil_revenue' => null]);
        $this->assertSame(20_000, $bagus->hasil_cpm);
        $this->assertSame(KolDeal::VERDICT_BAGUS, $bagus->hasil_verdict);

        // biaya 1jt / 5rb views * 1000 = CPM 200rb (≥120rb) → Jelek.
        $jelek = $this->deal($kol, ['total_biaya' => 1_000_000, 'hasil_tujuan' => 'awareness', 'hasil_views' => 5_000]);
        $this->assertSame(KolDeal::VERDICT_JELEK, $jelek->hasil_verdict);
    }

    public function test_modal_deal_cepat_render_untuk_pengelola(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN);
        $this->kol();
        $this->actingAs($sa)->get(route('kols.index'))->assertOk()->assertSee('id="dealModal"', false);
    }

    public function test_ringkasan_hanya_deal_yang_ada_laporan(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN);
        $kol = $this->kol();
        $ada = $this->deal($kol, ['hasil_tujuan' => 'penjualan', 'hasil_views' => 100_000, 'hasil_revenue' => 3_000_000, 'hasil_diisi_at' => now()]);
        $belum = $this->deal($kol); // tanpa laporan

        $this->actingAs($sa)->get(route('kol-deals.laporan'))
            ->assertOk()
            ->assertSee($ada->kode)
            ->assertDontSee($belum->kode)
            ->assertSee('TOTAL');
    }
}
