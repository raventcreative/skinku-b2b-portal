<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolContent;
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
}
