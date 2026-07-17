<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Penyesuaian stok manual WAJIB berketerangan.
 *
 * Berangkat dari kejadian nyata: satu gerakan "OUT 5" di stok mitra tanpa
 * referensi dan tanpa catatan butuh tiga putaran penelusuran untuk dijelaskan,
 * dan pada akhirnya memblokir pembersihan data uji coba. Penyebabnya bukan
 * kelalaian operator — form-nya memang tak punya kolom catatan sama sekali,
 * sementara validasinya menerima nullable.
 */
class StockAdjustNotesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'A', 'fullname' => 'Admin', 'username' => 'sanadm', 'email' => 'san@skinku.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function mitra(): User
    {
        return User::create([
            'name' => 'M', 'fullname' => 'Mitra', 'username' => 'sanmitra', 'email' => 'sanm@skinku.test',
            'password' => Hash::make('secret123'), 'company_name' => 'ARMUZNAH MART',
            'role' => User::ROLE_DISTRIBUTOR, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'MIZU BODY WASH - 500ml', 'sku' => 'MZ-500ML', 'hq_stock' => 346,
            'status' => 'active', 'cogs' => 10_000, 'price_distributor' => 20_000, 'price_reseller' => 25_000,
        ]);
    }

    public function test_penyesuaian_stok_hq_tanpa_alasan_ditolak(): void
    {
        $p = $this->product();

        $this->actingAs($this->admin())->from(route('inventory.index'))
            ->post(route('inventory.hq-adjust'), [
                'product_id' => $p->id, 'type' => StockMovement::TYPE_OUT, 'quantity' => 5,
            ])
            ->assertSessionHasErrors('notes');

        $this->assertSame(346, (int) $p->fresh()->hq_stock);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_penyesuaian_stok_mitra_tanpa_alasan_ditolak(): void
    {
        $p = $this->product();
        $m = $this->mitra();
        Inventory::create(['user_id' => $m->id, 'product_id' => $p->id, 'quantity' => 300]);

        // Persis bentuk POST yang dulu menghasilkan "OUT 5" tanpa keterangan.
        $this->actingAs($this->admin())->from(route('inventory.index'))
            ->post(route('inventory.partner-adjust'), [
                'user_id' => $m->id, 'product_id' => $p->id,
                'type' => StockMovement::TYPE_OUT, 'quantity' => 5,
            ])
            ->assertSessionHasErrors('notes');

        $this->assertSame(300, (int) Inventory::where('user_id', $m->id)->value('quantity'));
        $this->assertSame(0, StockMovement::count());
    }

    public function test_dengan_alasan_diterima_dan_alasannya_ikut_tersimpan(): void
    {
        $p = $this->product();
        $m = $this->mitra();
        Inventory::create(['user_id' => $m->id, 'product_id' => $p->id, 'quantity' => 300]);

        $this->actingAs($this->admin())
            ->post(route('inventory.partner-adjust'), [
                'user_id' => $m->id, 'product_id' => $p->id,
                'type' => StockMovement::TYPE_OUT, 'quantity' => 5, 'notes' => 'Retur botol rusak',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(295, (int) Inventory::where('user_id', $m->id)->value('quantity'));

        $gerakan = StockMovement::where('user_id', $m->id)->first();
        $this->assertSame('Retur botol rusak', $gerakan->notes);
        // Arahnya tetap harus terbaca dari after<before — quantity disimpan abs.
        $this->assertSame(5, (int) $gerakan->quantity);
        $this->assertSame(300, (int) $gerakan->before_qty);
        $this->assertSame(295, (int) $gerakan->after_qty);
    }

    public function test_alasan_kosong_atau_spasi_saja_tetap_ditolak(): void
    {
        $p = $this->product();
        $admin = $this->admin();   // sekali saja: username-nya tetap, unik

        foreach (['', '   '] as $alasan) {
            $this->actingAs($admin)->from(route('inventory.index'))
                ->post(route('inventory.hq-adjust'), [
                    'product_id' => $p->id, 'type' => StockMovement::TYPE_OUT,
                    'quantity' => 5, 'notes' => $alasan,
                ])
                ->assertSessionHasErrors('notes');
        }

        $this->assertSame(346, (int) $p->fresh()->hq_stock);
    }
}
