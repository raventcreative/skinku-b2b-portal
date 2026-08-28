<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolContent;
use App\Models\KolContentSnapshot;
use App\Models\KolDeal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KolContentTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function kol(): Kol
    {
        static $n = 0;
        $n++;

        return Kol::create(['tiktok_username' => "kontenkol{$n}", 'followers' => 50_000]);
    }

    public function test_model_konten_dan_snapshot_replace_per_hari(): void
    {
        $kol = $this->kol();
        $c = KolContent::create(['kol_id' => $kol->id, 'url' => 'https://www.tiktok.com/@x/video/1',
            'label' => 'earned', 'posted_at' => now()->toDateString()]);

        $c->snapshots()->updateOrCreate(['captured_on' => now()->startOfDay()], ['views' => 100, 'source' => 'manual']);
        $c->snapshots()->updateOrCreate(['captured_on' => now()->startOfDay()], ['views' => 250, 'source' => 'manual']);

        $this->assertSame(1, $c->snapshots()->count());          // hari sama = replace
        $this->assertSame(250, (int) $c->latestSnapshot->views);
        $this->assertSame('tiktok', $c->platform);               // default
    }

    public function test_index_render_dan_izin(): void
    {
        $this->actingAs($this->user(User::ROLE_RESELLER, 'res2'))
            ->get(route('kol-konten.index'))->assertForbidden();
        $this->actingAs($this->user('kol_specialist', 'ks1'))
            ->get(route('kol-konten.index'))->assertOk()->assertSee('Konten & Views');
    }

    public function test_store_auto_deteksi_tipe_platform_dan_views_awal(): void
    {
        $spec = $this->user('kol_specialist', 'gk1');
        $kol = $this->kol();
        $this->actingAs($spec)->post(route('kol-konten.store'), [
            'kol_id' => $kol->id, 'url' => 'https://www.instagram.com/reel/abc/', 'label' => 'earned',
            'posted_at' => now()->toDateString(), 'views_awal' => 1000, 'likes_awal' => 100,
        ])->assertRedirect();

        $c = KolContent::where('kol_id', $kol->id)->first();
        $this->assertSame('instagram', $c->platform);        // auto dari host
        $this->assertSame('reels', $c->content_type);        // auto dari /reel
        $this->assertSame(1, $c->snapshots()->count());
        $this->assertSame(1000, (int) $c->latestSnapshot->views);
        $this->assertSame(10.0, $c->engagement_rate);        // 100/1000
    }

    public function test_snapshot_tunggal_tambah_hapus_dengan_saves(): void
    {
        $spec = $this->user('kol_specialist', 'gk2');
        $c = KolContent::create(['kol_id' => $this->kol()->id, 'url' => 'https://www.tiktok.com/@x/video/5', 'label' => 'earned', 'posted_at' => now()->toDateString()]);

        $this->actingAs($spec)->post(route('kol-konten.snapshot.store', $c), [
            'captured_on' => now()->toDateString(), 'views' => 500, 'saves' => 50,
        ])->assertRedirect();
        $snap = $c->snapshots()->first();
        $this->assertSame(50, (int) $snap->saves);

        $this->actingAs($spec)->delete(route('kol-konten.snapshot.destroy', $snap))->assertRedirect();
        $this->assertSame(0, $c->snapshots()->count());
    }

    public function test_filter_konten_creator_dan_type(): void
    {
        $spec = $this->user('kol_specialist', 'gk3');
        $k1 = Kol::create(['tiktok_username' => 'kf1', 'followers' => 1000]);
        $k2 = Kol::create(['tiktok_username' => 'kf2', 'followers' => 1000]);
        KolContent::create(['kol_id' => $k1->id, 'url' => 'https://www.tiktok.com/@x/video/1', 'content_type' => 'video', 'label' => 'earned', 'posted_at' => now()->toDateString()]);
        KolContent::create(['kol_id' => $k2->id, 'url' => 'https://www.tiktok.com/@x/reel/2', 'content_type' => 'reels', 'label' => 'earned', 'posted_at' => now()->toDateString()]);

        $r1 = $this->actingAs($spec)->get(route('kol-konten.index', ['creator' => $k1->id]))->assertOk();
        $this->assertSame(1, $r1->viewData('contents')->count());

        $r2 = $this->actingAs($spec)->get(route('kol-konten.index', ['type' => 'reels']))->assertOk();
        $this->assertSame($k2->id, $r2->viewData('contents')->first()->kol_id);
    }

    public function test_halaman_detail_grafik_dan_riwayat_snapshot(): void
    {
        $kol = $this->kol();
        $c = KolContent::create(['kol_id' => $kol->id, 'url' => 'https://www.tiktok.com/@x/video/7',
            'label' => 'earned', 'posted_at' => now()->subDays(5)->toDateString()]);
        // Dua snapshot beda hari → Δ dan grafik pertumbuhan.
        $c->snapshots()->create(['views' => 1000, 'likes' => 100, 'captured_on' => now()->subDays(3)->startOfDay(), 'source' => 'manual']);
        $c->snapshots()->create(['views' => 1500, 'likes' => 200, 'captured_on' => now()->startOfDay(), 'source' => 'manual']);

        // Partner (reseller) tak boleh lihat.
        $this->actingAs($this->user(User::ROLE_RESELLER, 'resd'))->get(route('kol-konten.show', $c))->assertForbidden();

        $this->actingAs($this->user('kol_specialist', 'ksd'))->get(route('kol-konten.show', $c))->assertOk()
            ->assertSee('Pertumbuhan views')   // grafik
            ->assertSee('1.500')               // views terbaru
            ->assertSee('+500')                // Δ vs snapshot sebelumnya
            ->assertSee('13,33%');             // ER = 200/1500
    }

    public function test_store_deal_memaksa_paid_dan_oembed_autofill(): void
    {
        $spec = $this->user('kol_specialist', 'ks2');
        $kol = $this->kol();
        $deal = KolDeal::create(['kode' => 'KD-T1', 'kol_id' => $kol->id, 'jenis' => 'vt']);

        $this->actingAs($spec)->post(route('kol-konten.store'), [
            'kol_id' => $kol->id, 'kol_deal_id' => $deal->id, 'url' => 'https://www.tiktok.com/@x/video/9',
            'platform' => 'tiktok', 'label' => 'earned', 'posted_at' => now()->toDateString(),
        ])->assertRedirect();
        $this->assertSame('paid', KolContent::first()->label); // deal → paid DIPAKSA

        Http::fake(['www.tiktok.com/oembed*' => Http::response(['title' => 'Judul dari TikTok'])]);
        $this->actingAs($spec)->post(route('kol-konten.oembed'), ['url' => 'https://www.tiktok.com/@x/video/9'])
            ->assertOk()->assertJson(['title' => 'Judul dari TikTok']);
        // URL non-tiktok ditolak tanpa fetch.
        $this->actingAs($spec)->post(route('kol-konten.oembed'), ['url' => 'https://evil.com/x'])
            ->assertStatus(422);
    }

    public function test_grid_massal_snapshot_dan_replace_hari_sama(): void
    {
        $spec = $this->user('kol_specialist', 'ks3');
        $kol = $this->kol();
        $c1 = KolContent::create(['kol_id' => $kol->id, 'url' => 'https://www.tiktok.com/@x/video/11', 'label' => 'earned', 'posted_at' => now()->toDateString()]);
        $c2 = KolContent::create(['kol_id' => $kol->id, 'url' => 'https://www.tiktok.com/@x/video/12', 'label' => 'earned', 'posted_at' => now()->toDateString()]);

        $this->actingAs($spec)->get(route('kol-konten.grid'))->assertOk()->assertSee('Isi Views Massal');

        $this->actingAs($spec)->post(route('kol-konten.grid.save'), ['rows' => [
            ['id' => $c1->id, 'views' => 1000, 'likes' => 50],
            ['id' => $c2->id, 'views' => null],                    // kosong = dilewati
        ]])->assertRedirect();
        $this->assertSame(1, KolContentSnapshot::count());

        // Submit ulang hari sama dgn angka baru → replace, tetap 1 baris.
        $this->actingAs($spec)->post(route('kol-konten.grid.save'), ['rows' => [
            ['id' => $c1->id, 'views' => 4000],
        ]])->assertRedirect();
        $this->assertSame(1, KolContentSnapshot::count());
        $this->assertSame(4000, (int) $c1->refresh()->latestSnapshot->views);
    }
}
