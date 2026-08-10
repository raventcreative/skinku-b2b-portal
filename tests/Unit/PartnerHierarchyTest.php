<?php

namespace Tests\Unit;

use App\Support\PartnerHierarchy;
use PHPUnit\Framework\TestCase;

class PartnerHierarchyTest extends TestCase
{
    public function test_level_dan_tier(): void
    {
        $this->assertSame(1, PartnerHierarchy::levelOf('grand_distributor'));
        $this->assertSame(2, PartnerHierarchy::levelOf('distributor'));
        $this->assertSame(3, PartnerHierarchy::levelOf('reseller_bronze'));
        $this->assertSame(3, PartnerHierarchy::levelOf('reseller_gold'));
        $this->assertNull(PartnerHierarchy::levelOf('admin'));
        $this->assertTrue(PartnerHierarchy::isTierRole('reseller_gold'));
        $this->assertFalse(PartnerHierarchy::isTierRole('reseller')); // reseller generik bukan tier
    }

    public function test_induk_yang_sah(): void
    {
        $this->assertSame([], PartnerHierarchy::allowedParentRoles('grand_distributor'));
        $this->assertSame(['grand_distributor'], PartnerHierarchy::allowedParentRoles('distributor'));
        $this->assertSame(['distributor'], PartnerHierarchy::allowedParentRoles('reseller_bronze'));
        $this->assertSame(['distributor'], PartnerHierarchy::allowedParentRoles('reseller_gold'));
    }

    public function test_holds_stock(): void
    {
        $this->assertTrue(PartnerHierarchy::holdsStock('grand_distributor'));
        $this->assertTrue(PartnerHierarchy::holdsStock('distributor'));
        $this->assertFalse(PartnerHierarchy::holdsStock('reseller_bronze'));
        $this->assertFalse(PartnerHierarchy::holdsStock('reseller_gold'));
    }
}
