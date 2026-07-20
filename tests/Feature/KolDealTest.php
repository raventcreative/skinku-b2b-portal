<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Kol;
use App\Models\KolDeal;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KolDealTest extends TestCase
{
    use RefreshDatabase;

    private function specialist(string $u = 'spec', bool $finance = false): User
    {
        $user = User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => 'kol_specialist', 'status' => User::STATUS_ACTIVE,
        ]);

        if ($finance) {
            // Lewat jalur override matriks hak akses — persis cara produksi
            // memberikannya, bukan jalan pintas test.
            RolePermission::create(['role' => 'kol_specialist', 'permission_key' => 'kol.deal.finance', 'allowed' => true]);
            Permissions::flushCache();
        }

        return $user;
    }

    private function kol(): Kol
    {
        static $n = 0;
        $n++;

        return Kol::create(['tiktok_username' => "dealkol{$n}", 'followers' => 50_000]);
    }

    private function payload(Kol $kol, array $extra = []): array
    {
        return array_merge([
            'kol_id' => $kol->id, 'jenis' => 'vt', 'ratecard_deal' => 1_500_000,
            'jumlah_slot' => 4, 'status' => 'draft',
        ], $extra);
    }

    public function test_kode_deal_unik_dan_formatnya_benar_termasuk_dua_deal_sehari(): void
    {
        $spec = $this->specialist();
        $kol = $this->kol();

        $this->actingAs($spec)->post(route('kol-deals.store'), $this->payload($kol))->assertRedirect();
        $this->actingAs($spec)->post(route('kol-deals.store'), $this->payload($kol))->assertRedirect();

        $kodes = KolDeal::pluck('kode');
        $this->assertCount(2, $kodes);
        $this->assertSame(2, $kodes->unique()->count());
        foreach ($kodes as $kode) {
            $this->assertMatchesRegularExpression('/^SKN-KOL-'.now()->format('Ymd').'-\d{4}$/', $kode);
        }
    }

    /**
     * Tanpa kol.deal.finance: field finansial tak tampil DAN input finansial
     * dibuang di server. Menyembunyikan di form bukan pengamanan — POST bisa
     * dikirim langsung.
     */
    public function test_tanpa_finance_field_finansial_dibuang_dan_tak_tampil(): void
    {
        $spec = $this->specialist('nofin');
        $kol = $this->kol();

        // Kirim field finansial langsung (bypass form) → harus DIABAIKAN.
        $this->actingAs($spec)->post(route('kol-deals.store'), $this->payload($kol, [
            'total_biaya' => 9_999_999, 'status_bayar' => 'lunas',
            'no_rekening' => '1234567890', 'bank' => 'BCA', 'atas_nama' => 'Hacker',
        ]))->assertRedirect();

        $deal = KolDeal::first();
        $this->assertSame(0, (int) $deal->total_biaya);
        $this->assertSame('belum', $deal->status_bayar);
        $this->assertNull($deal->no_rekening);

        // Isi finansial dari pihak lain (langsung DB) tak boleh bocor di tampilan.
        $deal->update(['total_biaya' => 5_000_000, 'no_rekening' => '9876543210', 'bank' => 'BRI']);

        foreach ([route('kol-deals.index'), route('kol-deals.edit', $deal), route('kols.show', $kol)] as $url) {
            $res = $this->actingAs($spec)->get($url)->assertOk();
            $res->assertDontSee('9876543210');
            $res->assertDontSee('5.000.000');
            $res->assertDontSee('Total Biaya');
        }
    }

    public function test_dengan_finance_field_finansial_tampil_dan_tersimpan(): void
    {
        $spec = $this->specialist('fin', finance: true);
        $kol = $this->kol();

        $this->actingAs($spec)->post(route('kol-deals.store'), $this->payload($kol, [
            'total_biaya' => 6_000_000, 'status_bayar' => 'dp', 'no_rekening' => '111222333',
            'bank' => 'BCA', 'atas_nama' => 'Kol A',
        ]))->assertRedirect();

        $deal = KolDeal::first();
        $this->assertSame(6_000_000, (int) $deal->total_biaya);
        $this->assertSame('dp', $deal->status_bayar);
        $this->assertSame('111222333', $deal->no_rekening);

        $this->actingAs($spec)->get(route('kol-deals.index'))->assertOk()->assertSee('6.000.000');
    }

    public function test_pemilik_deal_manage_bisa_hapus_tanpa_permission_tidak(): void
    {
        $spec = $this->specialist('del1');
        $kol = $this->kol();
        $deal = KolDeal::create(['kode' => KolDeal::generateKode(), 'kol_id' => $kol->id, 'jenis' => 'vt']);

        // kol.view saja (tanpa deal.manage): route deal tertutup — buat via override.
        $viewer = User::create([
            'name' => 'V', 'fullname' => 'Viewer', 'username' => 'viewer1', 'email' => 'v1@skinku.test',
            'password' => Hash::make('secret123'), 'role' => 'kol_viewer', 'status' => User::STATUS_ACTIVE,
        ]);
        RolePermission::create(['role' => 'kol_viewer', 'permission_key' => 'kol.view', 'allowed' => true]);
        Permissions::flushCache();

        $this->actingAs($viewer)->delete(route('kol-deals.destroy', $deal))->assertForbidden();
        $this->assertNotNull(KolDeal::find($deal->id));

        // Pemegang kol.deal.manage BISA hapus (keputusan: tak dibatasi super admin).
        $this->actingAs($spec)->delete(route('kol-deals.destroy', $deal))->assertRedirect();
        $this->assertNull(KolDeal::find($deal->id));
        $this->assertNotNull(KolDeal::withTrashed()->find($deal->id));   // soft delete
    }

    public function test_audit_log_tercatat_untuk_create_update_delete_deal(): void
    {
        $spec = $this->specialist('aud', finance: true);
        $kol = $this->kol();

        $this->actingAs($spec)->post(route('kol-deals.store'), $this->payload($kol));
        $deal = KolDeal::first();
        $this->assertNotNull(AuditLog::where('action', 'create_kol_deal')->where('target_id', $deal->id)->first());

        $this->actingAs($spec)->put(route('kol-deals.update', $deal), $this->payload($kol, [
            'status' => 'berjalan', 'no_rekening' => '555666777',
        ]));
        $update = AuditLog::where('action', 'update_kol_deal')->first();
        $this->assertNotNull($update);

        // Nomor rekening TIDAK boleh mengendap di audit trail — cukup penanda.
        $log = json_encode($update->after_data);
        $this->assertStringNotContainsString('555666777', $log);
        $this->assertStringContainsString('rekening diubah', $log);

        $this->actingAs($spec)->delete(route('kol-deals.destroy', $deal));
        $this->assertNotNull(AuditLog::where('action', 'delete_kol_deal')->where('target_id', $deal->id)->first());
    }
}
