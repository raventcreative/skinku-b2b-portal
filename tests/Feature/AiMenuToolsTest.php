<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\MarketplaceMaster;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\TiktokOrder;
use App\Models\User;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Alat baca per menu untuk Asisten AI: tiap alat hanya muncul untuk role yang
 * punya akses menunya, dan mitra hanya melihat datanya sendiri.
 */
class AiMenuToolsTest extends TestCase
{
    use RefreshDatabase;

    private const ALAT = ['laporan_stok_hq', 'daftar_po', 'stok_marketplace', 'pesanan_marketplace', 'komisi'];

    private function user(string $role, ?string $u = null): User
    {
        $u ??= $role;

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    /** Nama alat menu yang tersedia untuk user ini. */
    private function alat(User $user): array
    {
        $names = array_map(fn ($t) => $t->name(), app(ToolRegistry::class)->forUser($user));

        return array_values(array_intersect(self::ALAT, $names));
    }

    private function pakai(string $alat, User $user, array $args = []): array
    {
        $tool = app(ToolRegistry::class)->find($alat, $user);
        $this->assertNotNull($tool, "alat {$alat} harus tersedia untuk {$user->role}");

        return $tool->run($args, $user);
    }

    private function po(User $mitra, string $no, float $total): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_number' => $no, 'created_by' => $mitra->id, 'user_id' => $mitra->id,
            'company_name' => $mitra->fullname, 'user_role' => $mitra->role,
            'status' => PurchaseOrder::STATUS_PENDING, 'total_amount' => $total,
            'order_date' => now()->toDateString(),
        ]);
    }

    public function test_alat_menu_disaring_sesuai_akses_role(): void
    {
        $this->assertSame(self::ALAT, $this->alat($this->user(User::ROLE_ADMIN)));
        $this->assertSame(['laporan_stok_hq', 'daftar_po'], $this->alat($this->user(User::ROLE_GUDANG)));
        $this->assertSame(['daftar_po', 'komisi'], $this->alat($this->user(User::ROLE_DISTRIBUTOR)));
        // Role non-bisnis tak dapat alat PO/komisi walau izin lain diberikan.
        $this->assertSame([], $this->alat($this->user('kol_specialist')));
    }

    public function test_alat_terlarang_tidak_bisa_dipanggil_lewat_nama(): void
    {
        $dist = $this->user(User::ROLE_DISTRIBUTOR);
        $this->assertNull(app(ToolRegistry::class)->find('laporan_stok_hq', $dist));
        $this->assertNull(app(ToolRegistry::class)->find('stok_marketplace', $dist));
    }

    public function test_mitra_hanya_lihat_po_miliknya(): void
    {
        $a = $this->user(User::ROLE_DISTRIBUTOR, 'dista');
        $b = $this->user(User::ROLE_DISTRIBUTOR, 'distb');
        $this->po($a, 'PO-A', 100_000);
        $this->po($b, 'PO-B', 900_000);

        $out = $this->pakai('daftar_po', $a);
        $this->assertSame(1, $out['jumlah_po']);
        $this->assertSame('PO-A', $out['po_terbaru'][0]['no_po']);
        $this->assertArrayNotHasKey('mitra', $out['po_terbaru'][0]);

        $admin = $this->pakai('daftar_po', $this->user(User::ROLE_ADMIN));
        $this->assertSame(2, $admin['jumlah_po']);
        $this->assertEquals(1_000_000, $admin['total_sisa_tagihan']);
    }

    public function test_mitra_hanya_lihat_komisinya_sendiri(): void
    {
        $a = $this->user(User::ROLE_DISTRIBUTOR, 'dista');
        $b = $this->user(User::ROLE_DISTRIBUTOR, 'distb');
        foreach ([[$a, 50_000], [$b, 700_000]] as [$m, $amt]) {
            Commission::create(['user_id' => $m->id, 'source_po_id' => null, 'source_user_id' => $m->id,
                'type' => 'override', 'level' => 1, 'rate' => 6, 'base_amount' => $amt, 'amount' => $amt, 'status' => 'saldo']);
        }

        $out = $this->pakai('komisi', $a);
        $this->assertEquals(50_000, $out['saldo']);
        $this->assertCount(1, $out['komisi_terbaru']);
        $this->assertArrayNotHasKey('per_mitra', $out);
    }

    public function test_laporan_stok_hq_pakai_kolom_reseller_distributor(): void
    {
        $p = Product::create(['name' => 'Yuki Soap', 'sku' => 'YK-1', 'hq_stock' => 0, 'status' => 'active',
            'price_distributor' => 1, 'price_reseller' => 1]);
        $inv = app(InventoryService::class);
        $inv->adjustHqStock($p, 100, StockMovement::TYPE_IN, null, 'production', occurredAt: Carbon::parse('2026-07-14 08:00'));
        $inv->adjustHqStock($p, -7, StockMovement::TYPE_OUT, null, 'purchase_order', occurredAt: Carbon::parse('2026-07-14 09:00'));

        $out = $this->pakai('laporan_stok_hq', $this->user(User::ROLE_GUDANG), ['tanggal' => '2026-07-14', 'produk' => 'yuki']);
        $this->assertSame(1, $out['jumlah_produk']);
        $this->assertSame(7, $out['produk'][0]['reseller_distributor']);
        $this->assertSame(93, $out['produk'][0]['akhir']);
    }

    public function test_stok_marketplace_dan_pesanan_untuk_admin(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        MarketplaceMaster::create(['master_sku' => 'SK-1', 'name' => 'Body Lotion', 'base_stock' => 40, 'base_price' => 59000]);
        TiktokOrder::create(['tiktok_order_id' => 'T-1', 'status' => 'COMPLETED', 'total_amount' => 600_000,
            'order_created_at' => now()->toDateString(), 'line_items' => []]);

        $stok = $this->pakai('stok_marketplace', $admin, ['cari' => 'lotion']);
        $this->assertSame(1, $stok['jumlah']);
        $this->assertSame('SK-1', $stok['produk'][0]['sku']);

        $order = $this->pakai('pesanan_marketplace', $admin, ['channel' => 'tiktok']);
        $this->assertSame(1, $order['tiktok']['jumlah_pesanan']);
        $this->assertEquals(600_000, $order['tiktok']['omzet']);
        $this->assertArrayNotHasKey('shopee', $order);
    }
}
