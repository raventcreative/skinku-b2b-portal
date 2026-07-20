<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Distributor mencatat barang keluar dari stoknya sendiri.
 *
 * Mekanismenya sudah lama ada (endpoint partner-adjust), tapi dropdown-nya
 * menampilkan kode "OUT" mentah — mitra yang mencari "stok keluar" tak
 * menemukannya di balik istilah Inggris itu.
 */
class PartnerStockOutTest extends TestCase
{
    use RefreshDatabase;

    private function distributor(string $u = 'putu'): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'company_name' => 'SKINKU BALI',
            'role' => User::ROLE_DISTRIBUTOR, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'MIZU BODY WASH - 500ml', 'sku' => 'MZ-500ML', 'hq_stock' => 0,
            'status' => 'active', 'cogs' => 10_000, 'price_distributor' => 20_000, 'price_reseller' => 25_000,
        ]);
    }

    public function test_distributor_mencatat_barang_keluar_dari_stoknya(): void
    {
        $d = $this->distributor();
        $p = $this->product();
        Inventory::create(['user_id' => $d->id, 'product_id' => $p->id, 'quantity' => 50]);

        $this->actingAs($d)->post(route('inventory.partner-adjust'), [
            'user_id' => $d->id, 'product_id' => $p->id,
            'type' => StockMovement::TYPE_OUT, 'quantity' => 12, 'notes' => 'Jual ke pelanggan',
        ])->assertRedirect();

        $this->assertSame(38, (int) Inventory::where('user_id', $d->id)->value('quantity'));

        $m = StockMovement::where('user_id', $d->id)->first();
        $this->assertSame('OUT', $m->movement_type);
        $this->assertSame(50, (int) $m->before_qty);
        $this->assertSame(38, (int) $m->after_qty);
        $this->assertSame('Jual ke pelanggan', $m->notes);
    }

    public function test_tidak_bisa_mengeluarkan_lebih_dari_stok(): void
    {
        $d = $this->distributor();
        $p = $this->product();
        Inventory::create(['user_id' => $d->id, 'product_id' => $p->id, 'quantity' => 5]);

        $this->actingAs($d)->from(route('inventory.index'))->post(route('inventory.partner-adjust'), [
            'user_id' => $d->id, 'product_id' => $p->id,
            'type' => StockMovement::TYPE_OUT, 'quantity' => 10, 'notes' => 'Jual',
        ])->assertSessionHasErrors('quantity');

        $this->assertSame(5, (int) Inventory::where('user_id', $d->id)->value('quantity'));
        $this->assertSame(0, StockMovement::count());
    }

    public function test_distributor_tidak_bisa_menyesuaikan_stok_mitra_lain(): void
    {
        $a = $this->distributor('putu');
        $b = $this->distributor('wayan');
        $p = $this->product();
        Inventory::create(['user_id' => $b->id, 'product_id' => $p->id, 'quantity' => 50]);

        $this->actingAs($a)->post(route('inventory.partner-adjust'), [
            'user_id' => $b->id, 'product_id' => $p->id,
            'type' => StockMovement::TYPE_OUT, 'quantity' => 10, 'notes' => 'curang',
        ])->assertForbidden();

        $this->assertSame(50, (int) Inventory::where('user_id', $b->id)->value('quantity'));
    }

    public function test_dua_tombol_penyesuaian_di_atas_penjualan(): void
    {
        $d = $this->distributor();
        $p = $this->product();
        Inventory::create(['user_id' => $d->id, 'product_id' => $p->id, 'quantity' => 50]);

        $html = $this->actingAs($d)->get(route('inventory.index'))->assertOk()->getContent();

        // Dua jalur, masing-masing tombol ke halaman sendiri.
        $this->assertStringContainsString('Penyesuaian Stok / Adjustment', $html);
        $this->assertStringContainsString(route('inventory.adjust'), $html);
        $this->assertStringContainsString('Barang Keluar (Penjualan)', $html);
        $this->assertStringContainsString(route('partner-sales.index'), $html);

        // Penyesuaian harus muncul DI ATAS penjualan (permintaan).
        $this->assertLessThan(
            strpos($html, route('partner-sales.index')),
            strpos($html, route('inventory.adjust')),
            'Tombol Penyesuaian Stok harus berada di atas Catat Penjualan.',
        );
    }

    public function test_tabel_hanya_menampilkan_produk_yang_ada_stoknya(): void
    {
        $d = $this->distributor();
        $ada = $this->product();
        Product::create([
            'name' => 'HAKKA MOUTH SPRAY', 'sku' => 'HK-1', 'hq_stock' => 0,
            'status' => 'active', 'cogs' => 5000, 'price_distributor' => 10000, 'price_reseller' => 12000,
        ]);
        Inventory::create(['user_id' => $d->id, 'product_id' => $ada->id, 'quantity' => 30]);

        $html = $this->actingAs($d)->get(route('inventory.index'))->assertOk()->getContent();

        // Produk ber-stok tampil di tabel; produk tanpa stok TIDAK jadi baris nol.
        $this->assertSame(1, substr_count($html, 'font-semibold text-stone-800">MIZU'));
        $this->assertStringNotContainsString('HAKKA MOUTH SPRAY', $html); // tak ada baris nol
    }

    public function test_stok_kosong_mengarahkan_ke_penyesuaian_stok(): void
    {
        $d = $this->distributor();

        $this->actingAs($d)->get(route('inventory.index'))->assertOk()
            ->assertSee('Belum ada stok tercatat')
            ->assertSee('Penyesuaian Stok');
    }

    public function test_mitra_mengisi_saldo_awal_produk_yang_belum_ada_di_daftarnya(): void
    {
        $d = $this->distributor();
        $p = $this->product();
        // Belum ada baris inventory sama sekali untuk produk ini.
        $this->assertSame(0, Inventory::where('user_id', $d->id)->count());

        $this->actingAs($d)->post(route('inventory.partner-adjust'), [
            'user_id' => $d->id, 'product_id' => $p->id,
            'type' => StockMovement::TYPE_IN, 'quantity' => 40, 'notes' => 'saldo awal',
        ])->assertRedirect();

        // Baris dibuat otomatis dengan saldo 40 — inilah gunanya tombol
        // "Sesuaikan Stok Sendiri": bekerja walau daftar stok kosong.
        $this->assertSame(40, (int) Inventory::where('user_id', $d->id)->where('product_id', $p->id)->value('quantity'));
    }

    public function test_penyesuaian_mitra_tercatat_di_audit_log(): void
    {
        $d = $this->distributor();
        $p = $this->product();
        Inventory::create(['user_id' => $d->id, 'product_id' => $p->id, 'quantity' => 50]);

        $this->actingAs($d)->post(route('inventory.partner-adjust'), [
            'user_id' => $d->id, 'product_id' => $p->id,
            'type' => StockMovement::TYPE_OUT, 'quantity' => 12, 'notes' => 'jual ke customer',
        ]);

        // Self-service tanpa jejak = tak bisa dipertanggungjawabkan. Wajib tercatat.
        $log = AuditLog::where('action', 'adjust_partner_stock')->first();
        $this->assertNotNull($log);
        $this->assertSame($d->id, (int) $log->performed_by);
        $this->assertSame($d->id, (int) $log->after_data['user_id']);
        $this->assertSame('jual ke customer', $log->after_data['alasan']);
    }

    public function test_halaman_penyesuaian_multi_baris_menyetel_banyak_produk_sekaligus(): void
    {
        $d = $this->distributor();
        $a = $this->product();
        $b = Product::create([
            'name' => 'HADA GLOW', 'sku' => 'HD-1', 'hq_stock' => 0,
            'status' => 'active', 'cogs' => 5000, 'price_distributor' => 10000, 'price_reseller' => 12000,
        ]);
        Inventory::create(['user_id' => $d->id, 'product_id' => $a->id, 'quantity' => 10]);

        // Halaman form ada, dengan pemilih produk.
        $this->actingAs($d)->get(route('inventory.adjust'))->assertOk()
            ->assertSee('Penyesuaian Stok')
            ->assertSee('MIZU BODY WASH - 500ml');

        // Satu submit, banyak produk: A diset 40 (dari 10), B saldo awal 25.
        $this->actingAs($d)->post(route('inventory.adjust.store'), [
            'notes' => 'hitung fisik 20 Jul',
            'items' => [
                ['product_id' => $a->id, 'target' => 40],
                ['product_id' => $b->id, 'target' => 25],
            ],
        ])->assertRedirect(route('inventory.index'));

        $this->assertSame(40, (int) Inventory::where('user_id', $d->id)->where('product_id', $a->id)->value('quantity'));
        $this->assertSame(25, (int) Inventory::where('user_id', $d->id)->where('product_id', $b->id)->value('quantity'));
    }

    public function test_penyesuaian_bulk_atomik_satu_baris_negatif_batalkan_semua(): void
    {
        $d = $this->distributor();
        $a = $this->product();
        Inventory::create(['user_id' => $d->id, 'product_id' => $a->id, 'quantity' => 10]);

        // target negatif ditolak validasi → tak ada yang berubah.
        $this->actingAs($d)->from(route('inventory.adjust'))->post(route('inventory.adjust.store'), [
            'notes' => 'x',
            'items' => [['product_id' => $a->id, 'target' => -5]],
        ])->assertSessionHasErrors();

        $this->assertSame(10, (int) Inventory::where('user_id', $d->id)->where('product_id', $a->id)->value('quantity'));
    }

    public function test_penyesuaian_bulk_melewati_baris_tak_berubah_dan_kosong(): void
    {
        $d = $this->distributor();
        $a = $this->product();
        $b = Product::create([
            'name' => 'HADA GLOW', 'sku' => 'HD-1', 'hq_stock' => 0,
            'status' => 'active', 'cogs' => 5000, 'price_distributor' => 10000, 'price_reseller' => 12000,
        ]);
        Inventory::create(['user_id' => $d->id, 'product_id' => $a->id, 'quantity' => 10]);

        // A diisi 10 (SAMA — dilewati), baris kosong (dilewati), B diisi 30 (berubah).
        $this->actingAs($d)->post(route('inventory.adjust.store'), [
            'notes' => 'koreksi',
            'items' => [
                ['product_id' => $a->id, 'target' => 10],
                ['product_id' => null, 'target' => null],
                ['product_id' => $b->id, 'target' => 30],
            ],
        ])->assertRedirect();

        // Hanya B yang menghasilkan gerakan stok.
        $this->assertSame(1, StockMovement::where('user_id', $d->id)->count());
        $this->assertSame(30, (int) Inventory::where('user_id', $d->id)->where('product_id', $b->id)->value('quantity'));
    }

    public function test_penyesuaian_bulk_tercatat_di_audit_log(): void
    {
        $d = $this->distributor();
        $a = $this->product();

        $this->actingAs($d)->post(route('inventory.adjust.store'), [
            'notes' => 'saldo awal',
            'items' => [['product_id' => $a->id, 'target' => 40]],
        ]);

        $log = AuditLog::where('action', 'bulk_set_partner_stock')->first();
        $this->assertNotNull($log);
        $this->assertSame($d->id, (int) $log->performed_by);
        $this->assertSame('saldo awal', $log->after_data['alasan']);
    }

    public function test_non_mitra_tak_bisa_buka_halaman_penyesuaian(): void
    {
        $admin = User::create([
            'name' => 'A', 'fullname' => 'Admin', 'username' => 'adm', 'email' => 'adm@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin)->get(route('inventory.adjust'))->assertForbidden();
    }

    public function test_set_stok_menyetel_ke_angka_absolut_bukan_menambah(): void
    {
        $d = $this->distributor();
        $p = $this->product();
        Inventory::create(['user_id' => $d->id, 'product_id' => $p->id, 'quantity' => 50]);

        // "Stok sebenarnya 30" → disetel ke 30, bukan 50+30.
        $this->actingAs($d)->post(route('inventory.partner-set'), [
            'user_id' => $d->id, 'product_id' => $p->id,
            'target' => 30, 'notes' => 'koreksi hitung fisik',
        ])->assertRedirect();

        $this->assertSame(30, (int) Inventory::where('user_id', $d->id)->value('quantity'));

        $m = StockMovement::where('user_id', $d->id)->latest('id')->first();
        $this->assertSame('ADJUSTMENT', $m->movement_type);
        $this->assertSame(50, (int) $m->before_qty);
        $this->assertSame(30, (int) $m->after_qty);
    }

    public function test_set_stok_mengisi_saldo_awal_dari_nol(): void
    {
        $d = $this->distributor();
        $p = $this->product();

        $this->actingAs($d)->post(route('inventory.partner-set'), [
            'user_id' => $d->id, 'product_id' => $p->id,
            'target' => 40, 'notes' => 'saldo awal',
        ])->assertRedirect();

        $this->assertSame(40, (int) Inventory::where('user_id', $d->id)->where('product_id', $p->id)->value('quantity'));
    }

    public function test_set_stok_ke_angka_sama_ditolak(): void
    {
        $d = $this->distributor();
        $p = $this->product();
        Inventory::create(['user_id' => $d->id, 'product_id' => $p->id, 'quantity' => 50]);

        $this->actingAs($d)->from(route('inventory.index'))->post(route('inventory.partner-set'), [
            'user_id' => $d->id, 'product_id' => $p->id,
            'target' => 50, 'notes' => 'tak berubah',
        ])->assertSessionHasErrors('target');

        // Tak ada gerakan sampah untuk perubahan nol.
        $this->assertSame(0, StockMovement::count());
    }

    public function test_set_stok_tercatat_di_audit_log(): void
    {
        $d = $this->distributor();
        $p = $this->product();

        $this->actingAs($d)->post(route('inventory.partner-set'), [
            'user_id' => $d->id, 'product_id' => $p->id, 'target' => 40, 'notes' => 'saldo awal',
        ]);

        $log = AuditLog::where('action', 'set_partner_stock')->first();
        $this->assertNotNull($log);
        $this->assertSame($d->id, (int) $log->performed_by);
        $this->assertSame(40, (int) $log->after_data['stok_baru']);
    }

    public function test_set_stok_mitra_lain_ditolak(): void
    {
        $a = $this->distributor('putu');
        $b = $this->distributor('wayan');
        $p = $this->product();

        $this->actingAs($a)->post(route('inventory.partner-set'), [
            'user_id' => $b->id, 'product_id' => $p->id, 'target' => 999, 'notes' => 'curang',
        ])->assertForbidden();

        $this->assertSame(0, Inventory::where('user_id', $b->id)->count());
    }

    public function test_afiliator_tidak_bisa_menyesuaikan_stok_mitra(): void
    {
        $aff = User::create([
            'name' => 'Af', 'fullname' => 'Afiliator', 'username' => 'afil', 'email' => 'afil@skinku.test',
            // Peran dinamis: bukan staf, bukan mitra — persis celah guard lama.
            'password' => Hash::make('secret123'), 'role' => 'affiliator', 'status' => User::STATUS_ACTIVE,
        ]);
        $d = $this->distributor();
        $p = $this->product();
        Inventory::create(['user_id' => $d->id, 'product_id' => $p->id, 'quantity' => 50]);

        // Guard lama hanya memblokir MITRA; afiliator (bukan staf, bukan mitra)
        // dulu lolos dan bisa menyesuaikan stok siapa saja.
        $this->actingAs($aff)->post(route('inventory.partner-adjust'), [
            'user_id' => $d->id, 'product_id' => $p->id,
            'type' => StockMovement::TYPE_OUT, 'quantity' => 10, 'notes' => 'x',
        ])->assertForbidden();

        $this->assertSame(50, (int) Inventory::where('user_id', $d->id)->value('quantity'));
    }
}
