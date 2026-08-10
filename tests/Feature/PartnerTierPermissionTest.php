<?php

namespace Tests\Feature;

use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerTierPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::flushCache();
    }

    public function test_grand_seperti_distributor(): void
    {
        $this->assertTrue(Permissions::roleHas('grand_distributor', 'create_po'));
        $this->assertTrue(Permissions::roleHas('grand_distributor', 'view_reports'));
    }

    public function test_reseller_tier_seperti_reseller(): void
    {
        $this->assertTrue(Permissions::roleHas('reseller_bronze', 'create_po'));
        $this->assertTrue(Permissions::roleHas('reseller_gold', 'view_learning'));
        $this->assertFalse(Permissions::roleHas('reseller_bronze', 'view_reports'));
    }
}
