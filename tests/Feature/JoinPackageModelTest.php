<?php

namespace Tests\Feature;

use App\Models\JoinPackage;
use App\Models\JoinTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class JoinPackageModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_paket_punya_item_produk(): void
    {
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SB-1', 'price_distributor' => 24000,
            'price_reseller' => 29000, 'price_retail' => 39000, 'cogs' => 10000, 'hq_stock' => 100,
            'status' => Product::STATUS_ACTIVE]);
        $paket = JoinPackage::create(['name' => 'Bronze', 'target_role' => User::ROLE_RESELLER_BRONZE,
            'price' => 149000, 'is_active' => true]);
        $paket->items()->create(['product_id' => $p->id, 'qty' => 3]);

        $this->assertCount(1, $paket->items);
        $this->assertSame(3, $paket->items->first()->qty);
        $this->assertSame('Sabun', $paket->items->first()->product->name);
        $this->assertTrue($paket->is_active);
    }

    public function test_transaksi_join_relasi(): void
    {
        $paket = JoinPackage::create(['name' => 'Bronze', 'target_role' => User::ROLE_RESELLER_BRONZE,
            'price' => 149000, 'is_active' => true]);
        $mitra = User::create(['name' => 'm', 'fullname' => 'M', 'username' => 'm1', 'email' => 'm1@t.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER_BRONZE, 'status' => User::STATUS_ACTIVE]);
        $inviter = User::create(['name' => 'd', 'fullname' => 'D', 'username' => 'd1', 'email' => 'd1@t.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_DISTRIBUTOR, 'status' => User::STATUS_ACTIVE]);
        $trx = JoinTransaction::create(['user_id' => $mitra->id, 'join_package_id' => $paket->id,
            'inviter_id' => $inviter->id, 'price' => 149000, 'created_by' => null]);

        $this->assertSame($mitra->id, $trx->member->id);
        $this->assertSame($inviter->id, $trx->inviter->id);
        $this->assertSame($paket->id, $trx->package->id);
        $this->assertSame('Bronze', $trx->package->name);
    }
}
