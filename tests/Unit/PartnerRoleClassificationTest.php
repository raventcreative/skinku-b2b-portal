<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\User;
use PHPUnit\Framework\TestCase;

class PartnerRoleClassificationTest extends TestCase
{
    private function user(string $role): User
    {
        $u = new User;
        $u->role = $role;

        return $u;
    }

    public function test_ispartner_mencakup_role_tier_baru(): void
    {
        foreach (['distributor', 'reseller', 'grand_distributor', 'reseller_bronze', 'reseller_gold'] as $role) {
            $this->assertTrue($this->user($role)->isPartner(), "{$role} harus mitra");
        }
        $this->assertFalse($this->user('admin')->isPartner());
    }

    public function test_pricefield_grand_ikut_distributor(): void
    {
        $this->assertSame('price_distributor', $this->user('grand_distributor')->priceField());
        $this->assertSame('price_distributor', $this->user('distributor')->priceField());
        $this->assertSame('price_reseller', $this->user('reseller_bronze')->priceField());
        $this->assertSame('price_reseller', $this->user('reseller_gold')->priceField());
    }

    public function test_priceforrole_role_baru_tidak_jatuh_ke_retail(): void
    {
        $p = new Product;
        $p->price_distributor = 100;
        $p->price_reseller = 150;
        $p->price_retail = 999;

        $this->assertSame(100.0, $p->priceForRole('grand_distributor')); // BUKAN 999
        $this->assertSame(150.0, $p->priceForRole('reseller_bronze'));
        $this->assertSame(150.0, $p->priceForRole('reseller_gold'));
        $this->assertSame(100.0, $p->priceForRole('distributor'));      // regresi lama
        $this->assertSame(150.0, $p->priceForRole('reseller'));         // regresi lama
        $this->assertSame(999.0, $p->priceForRole('admin'));            // default tetap retail
    }
}
