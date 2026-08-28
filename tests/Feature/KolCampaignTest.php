<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolCampaign;
use App\Models\KolContent;
use App\Models\KolDeal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KolCampaignTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_campaign_crud_dan_rollup(): void
    {
        $root = $this->user(User::ROLE_SUPER_ADMIN, 'cmproot');

        $this->actingAs($root)->post(route('kol-campaigns.store'), [
            'name' => 'Ramadan Glow', 'platform' => 'tiktok', 'status' => 'active',
            'target_views' => 100_000, 'target_gmv' => 5_000_000,
        ])->assertRedirect();
        $camp = KolCampaign::first();
        $this->assertSame('Ramadan Glow', $camp->name);

        // Deal tertaut (1 berjalan + 1 batal) + konten views → rollup.
        $kol = Kol::create(['tiktok_username' => 'cmpkol', 'followers' => 20_000]);
        $deal = KolDeal::create(['kode' => 'C1', 'kol_id' => $kol->id, 'kol_campaign_id' => $camp->id, 'jenis' => 'vt',
            'total_biaya' => 2_000_000, 'status' => 'berjalan', 'status_bayar' => 'belum', 'periode_mulai' => now()->toDateString()]);
        KolDeal::create(['kode' => 'C2', 'kol_id' => $kol->id, 'kol_campaign_id' => $camp->id, 'jenis' => 'vt',
            'total_biaya' => 9_000_000, 'status' => 'batal', 'periode_mulai' => now()->toDateString()]);
        $content = KolContent::create(['kol_id' => $kol->id, 'kol_deal_id' => $deal->id,
            'url' => 'https://www.tiktok.com/@x/v/1', 'label' => 'paid', 'posted_at' => now()->toDateString()]);
        $content->snapshots()->create(['views' => 60_000, 'captured_on' => now()->startOfDay(), 'source' => 'manual']);

        $res = $this->actingAs($root)->get(route('kol-campaigns.index'))->assertOk()->assertSee('Ramadan Glow');
        $agg = $res->viewData('agg')[$camp->id];
        $this->assertSame(1, $agg['deals']);          // batal dikecualikan
        $this->assertSame(2_000_000, $agg['cost']);
        $this->assertSame(60_000, $agg['views']);

        // Update.
        $this->actingAs($root)->patch(route('kol-campaigns.update', $camp), [
            'name' => 'Ramadan Glow 2026', 'platform' => 'multi', 'status' => 'done',
        ])->assertRedirect();
        $this->assertSame('done', $camp->refresh()->status);

        // Hapus → deal dilepas (null), deal tak ikut terhapus.
        $this->actingAs($root)->delete(route('kol-campaigns.destroy', $camp))->assertRedirect();
        $this->assertSame(0, KolCampaign::count());
        $this->assertNull($deal->refresh()->kol_campaign_id);
        $this->assertNotNull(KolDeal::find($deal->id));
    }

    public function test_deal_ditautkan_ke_campaign_dan_tampil_di_daftar(): void
    {
        $root = $this->user(User::ROLE_SUPER_ADMIN, 'cmpd');
        $camp = KolCampaign::create(['name' => 'Kampanye A', 'platform' => 'tiktok', 'status' => 'active']);
        $kol = Kol::create(['tiktok_username' => 'dkol', 'followers' => 10_000]);

        $this->actingAs($root)->post(route('kol-deals.store'), [
            'kol_id' => $kol->id, 'kol_campaign_id' => $camp->id, 'jenis' => 'vt',
            'ratecard_deal' => 500_000, 'jumlah_slot' => 1, 'status' => 'draft', 'total_biaya' => 0, 'status_bayar' => 'belum',
        ])->assertRedirect();

        $deal = KolDeal::first();
        $this->assertSame($camp->id, $deal->kol_campaign_id);

        $this->actingAs($root)->get(route('kol-deals.index'))->assertOk()->assertSee('Kampanye A');
    }

    public function test_campaign_butuh_izin_manage(): void
    {
        $this->actingAs($this->user(User::ROLE_RESELLER, 'cmpres'))->get(route('kol-campaigns.index'))->assertForbidden();
    }
}
