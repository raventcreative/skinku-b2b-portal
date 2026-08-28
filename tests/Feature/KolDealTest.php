<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Kol;
use App\Models\KolContent;
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

    /**
     * Regresi 500: form finansial dengan "Total biaya" DIKOSONGKAN (super admin
     * pun). Input kosong → null oleh ConvertEmptyStringsToNull → dulu masuk ke
     * kolom NOT NULL total_biaya → SQL error. Harusnya jatuh ke default 0.
     */
    public function test_total_biaya_kosong_tidak_error(): void
    {
        $spec = $this->specialist('finblank', finance: true);
        $kol = $this->kol();

        // Persis form: seluruh field finansial ADA, tapi total_biaya kosong.
        $this->actingAs($spec)->post(route('kol-deals.store'), $this->payload($kol, [
            'total_biaya' => '', 'status_bayar' => 'belum',
            'no_rekening' => '', 'bank' => '', 'atas_nama' => '',
        ]))->assertRedirect();

        $deal = KolDeal::first();
        $this->assertNotNull($deal);
        $this->assertSame(0, (int) $deal->total_biaya);   // kosong → default 0, bukan 500
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

        // 'selesai' (bukan 'berjalan'/'batal') — pengaju/specialist boleh; Acc/Tolak
        // kini wewenang penyetuju (kol.deal.approve).
        $this->actingAs($spec)->put(route('kol-deals.update', $deal), $this->payload($kol, [
            'status' => 'selesai', 'no_rekening' => '555666777',
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

    /**
     * Form deal ter-render dengan pencarian KOL (ketik-untuk-cari). Peta KOL
     * dibangun via json_encode — jaga jangan sampai balik ke @json array-literal
     * yang bikin 500. Create & edit dua-duanya diuji.
     */
    public function test_form_deal_render_dengan_pencarian_kol(): void
    {
        $spec = $this->specialist('rend', finance: true);
        $this->kol();

        $res = $this->actingAs($spec)->get(route('kol-deals.create'))->assertOk();
        $res->assertSee('kolDatalist', false);
        $res->assertSee('ketik untuk cari', false);
        $res->assertSee('name="kol_phone"', false);   // input No. HP KOL ada

        $deal = KolDeal::create(['kode' => KolDeal::generateKode(), 'kol_id' => $this->kol()->id, 'jenis' => 'vt']);
        $this->actingAs($spec)->get(route('kol-deals.edit', $deal))->assertOk();
    }

    /** No. HP tiap KOL ikut ke form deal — JS menampilkannya saat KOL dipilih (kontak). */
    public function test_form_deal_memuat_no_hp_kol_untuk_kontak(): void
    {
        $spec = $this->specialist('rendhp', finance: true);
        Kol::create(['tiktok_username' => 'dealphone', 'followers' => 50_000, 'phone' => '081200001111']);

        $html = $this->actingAs($spec)->get(route('kol-deals.create'))->assertOk()->getContent();
        $this->assertStringContainsString('081200001111', $html);    // No. HP ada di peta JS
        $this->assertStringContainsString('6281200001111', $html);   // nomor WA ternormalkan (08→62)
    }

    /** No. HP diisi/diubah dari form deal ikut tersimpan ke data KOL. */
    public function test_no_hp_dari_form_deal_tersimpan_ke_kol(): void
    {
        $spec = $this->specialist('dphone', finance: true);
        $kol = $this->kol();
        $this->assertNull($kol->phone);

        $this->actingAs($spec)->post(route('kol-deals.store'), $this->payload($kol, [
            'kol_phone' => '0813-9999-8888',
        ]))->assertRedirect();

        $this->assertSame('0813-9999-8888', $kol->fresh()->phone);   // tersimpan ke KOL
    }

    /** No. HP kosong di form deal TIDAK menghapus nomor lama KOL. */
    public function test_kol_phone_kosong_tak_menghapus_nomor_lama(): void
    {
        $spec = $this->specialist('dphone2', finance: true);
        $kol = Kol::create(['tiktok_username' => 'punyahp', 'followers' => 1000, 'phone' => '08110002222']);

        $this->actingAs($spec)->post(route('kol-deals.store'), $this->payload($kol, [
            'kol_phone' => '',
        ]))->assertRedirect();

        $this->assertSame('08110002222', $kol->fresh()->phone);   // tetap, tak terwipe
    }

    /** Field baru deal (tipe, deliverables, jadwal, DP, catatan) tersimpan. */
    public function test_field_deal_diperkaya_tersimpan(): void
    {
        $spec = $this->specialist('enrich', finance: true);
        $kol = $this->kol();

        $this->actingAs($spec)->post(route('kol-deals.store'), $this->payload($kol, [
            'deal_type' => 'barter', 'other_cost' => 200_000,
            'deliverables' => '1 video TikTok + 1 Story IG', 'posting_deadline' => '2026-09-10',
            'usage_rights' => 'repost organik 3 bulan', 'internal_notes' => 'hasil nego alot',
            'total_biaya' => 1_000_000, 'status_bayar' => 'dp', 'dp_percent' => 40,
            'payment_note' => 'TF BCA 12 Agu',
        ]))->assertRedirect();

        $deal = KolDeal::latest('id')->first();
        $this->assertSame('barter', $deal->deal_type);
        $this->assertSame(200_000, $deal->other_cost);
        $this->assertSame('1 video TikTok + 1 Story IG', $deal->deliverables);
        $this->assertSame('2026-09-10', $deal->posting_deadline->format('Y-m-d'));
        $this->assertSame('repost organik 3 bulan', $deal->usage_rights);
        $this->assertSame('hasil nego alot', $deal->internal_notes);
        $this->assertSame(40, $deal->dp_percent);
        $this->assertSame('TF BCA 12 Agu', $deal->payment_note);
        $this->assertSame(400_000, $deal->dpAmount());   // 40% × 1jt
    }

    /** Tanpa finance: field uang baru (other_cost/dp_percent/payment_note) dibuang; non-uang tetap. */
    public function test_field_uang_baru_dibuang_tanpa_finance(): void
    {
        $spec = $this->specialist('enrichnf', finance: false);
        $kol = $this->kol();

        $this->actingAs($spec)->post(route('kol-deals.store'), $this->payload($kol, [
            'deal_type' => 'affiliate_only', 'deliverables' => 'affiliate link di bio',
            'other_cost' => 999_000, 'dp_percent' => 30, 'payment_note' => 'bocor',
            'total_biaya' => 5_000_000,
        ]))->assertRedirect();

        $deal = KolDeal::latest('id')->first();
        $this->assertSame('affiliate_only', $deal->deal_type);      // non-uang → tersimpan
        $this->assertSame('affiliate link di bio', $deal->deliverables);
        $this->assertSame(0, $deal->other_cost);                    // uang → dibuang (default 0)
        $this->assertSame(0, $deal->dp_percent);
        $this->assertNull($deal->payment_note);
        $this->assertSame(0, $deal->total_biaya);
    }

    /** Grand total = fee + biaya lain + subtotal HPP sampel. */
    public function test_grand_total_termasuk_biaya_lain_dan_sampel(): void
    {
        $spec = $this->specialist('gt', finance: true);
        $kol = $this->kol();
        $deal = KolDeal::create($this->payload($kol, [
            'kode' => 'GT1', 'total_biaya' => 1_000_000, 'other_cost' => 250_000,
        ]));
        $deal->samples()->create(['kol_id' => $kol->id, 'product' => 'Serum', 'units' => 2, 'unit_cost' => 75_000]);

        $this->assertSame(1_400_000, $deal->fresh()->grandTotal());   // 1jt + 250rb + (2×75rb)
    }

    /** Halaman detail deal: render + views agregat konten tertaut + CPM aktual + grand total (finance). */
    public function test_halaman_detail_deal_render_dengan_views_agregat(): void
    {
        $spec = $this->specialist('detail', finance: true);
        $kol = $this->kol();
        $deal = KolDeal::create($this->payload($kol, [
            'kode' => 'DT1', 'total_biaya' => 1_000_000, 'other_cost' => 100_000,
            'deliverables' => '1 video TikTok wajib', 'status' => 'berjalan',
        ]));
        $c = KolContent::create(['kol_id' => $kol->id, 'kol_deal_id' => $deal->id,
            'url' => 'https://www.tiktok.com/@x/v/77', 'label' => 'paid', 'posted_at' => now()->toDateString()]);
        $c->snapshots()->create(['views' => 200_000, 'captured_on' => now()->startOfDay(), 'source' => 'manual']);

        $res = $this->actingAs($spec)->get(route('kol-deals.show', $deal))->assertOk()
            ->assertSee('DT1')->assertSee('1 video TikTok wajib')->assertSee('Konten Tertaut')->assertSee('1.100.000');
        $this->assertSame(200_000, $res->viewData('contentViews'));
        $this->assertSame(5000, $res->viewData('contentCpm'));   // 1jt ÷ 200rb × 1000
    }

    /** Detail deal untuk non-finance: tak ada angka uang (grand total). */
    public function test_detail_deal_non_finance_sembunyikan_uang(): void
    {
        $spec = $this->specialist('detailnf', finance: false);
        $kol = $this->kol();
        $deal = KolDeal::create($this->payload($kol, ['kode' => 'DT2', 'total_biaya' => 9_000_000]));

        $this->actingAs($spec)->get(route('kol-deals.show', $deal))->assertOk()
            ->assertSee('DT2')->assertDontSee('Grand total')->assertDontSee('9.000.000');
    }
}
