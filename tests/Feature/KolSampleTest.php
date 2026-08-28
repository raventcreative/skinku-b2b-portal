<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolDeal;
use App\Models\KolSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KolSampleTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function deal(int $biaya = 0): KolDeal
    {
        static $n = 0;
        $n++;
        $kol = Kol::create(['tiktok_username' => "smpkol{$n}", 'followers' => 10_000]);

        return KolDeal::create(['kode' => "SMP{$n}", 'kol_id' => $kol->id, 'jenis' => 'vt',
            'total_biaya' => $biaya, 'status' => 'berjalan', 'status_bayar' => 'belum', 'periode_mulai' => now()->toDateString()]);
    }

    public function test_catat_sampel_add_to_deal_hanya_finance(): void
    {
        $deal = $this->deal(1_000_000);

        // Manage tanpa finance → add_to_deal diabaikan (biaya deal tak berubah).
        $spec = $this->user('kol_specialist', 'smpspec');
        $this->actingAs($spec)->post(route('kol-samples.store', $deal), [
            'product' => 'Serum', 'units' => 2, 'unit_cost' => 50_000, 'status' => 'pending', 'add_to_deal' => 1,
        ])->assertRedirect();

        $s = $deal->samples()->first();
        $this->assertSame(100_000, $s->subtotal);          // 2 × 50rb
        $this->assertSame($deal->kol_id, $s->kol_id);
        $this->assertSame(1_000_000, (int) $deal->refresh()->total_biaya); // finance-only → tak nambah

        // Finance (super_admin) → HPP masuk ke biaya deal.
        $root = $this->user(User::ROLE_SUPER_ADMIN, 'smproot');
        $this->actingAs($root)->post(route('kol-samples.store', $deal), [
            'product' => 'Toner', 'units' => 1, 'unit_cost' => 30_000, 'status' => 'shipped', 'add_to_deal' => 1,
        ])->assertRedirect();

        $toner = $deal->samples()->where('product', 'Toner')->first();
        $this->assertSame(now()->toDateString(), $toner->shipped_at->toDateString()); // shipped → shipped_at hari ini
        $this->assertSame(1_030_000, (int) $deal->refresh()->total_biaya);            // +30rb
    }

    public function test_ubah_status_isi_tanggal_dan_hapus(): void
    {
        $deal = $this->deal();
        $spec = $this->user('kol_specialist', 'smk2');
        $s = KolSample::create(['kol_deal_id' => $deal->id, 'kol_id' => $deal->kol_id,
            'product' => 'X', 'units' => 1, 'unit_cost' => 1000, 'status' => 'pending']);

        // pending → received: shipped_at & received_at terisi hari ini.
        $this->actingAs($spec)->patch(route('kol-samples.status', $s), ['status' => 'received'])->assertRedirect();
        $s->refresh();
        $this->assertSame('received', $s->status);
        $this->assertSame(now()->toDateString(), $s->received_at->toDateString());
        $this->assertNotNull($s->shipped_at);

        $this->actingAs($spec)->delete(route('kol-samples.destroy', $s))->assertRedirect();
        $this->assertSame(0, KolSample::count());
    }

    public function test_sampel_butuh_izin_manage(): void
    {
        $deal = $this->deal();
        $this->actingAs($this->user(User::ROLE_RESELLER, 'smres'))
            ->post(route('kol-samples.store', $deal), ['product' => 'X', 'units' => 1, 'status' => 'pending'])
            ->assertForbidden();
    }

    public function test_form_deal_render_dengan_sampel(): void
    {
        $deal = $this->deal();
        KolSample::create(['kol_deal_id' => $deal->id, 'kol_id' => $deal->kol_id,
            'product' => 'Masker Wajah', 'units' => 3, 'unit_cost' => 20_000, 'status' => 'shipped', 'shipped_at' => now()->toDateString()]);

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'smpform'))->get(route('kol-deals.edit', $deal))->assertOk()
            ->assertSee('Sampel Produk')->assertSee('Masker Wajah')->assertSee('Total HPP');
    }
}
