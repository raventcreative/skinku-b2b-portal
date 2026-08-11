<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PartnerHierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PartnerDescendantsTest extends TestCase
{
    use RefreshDatabase;

    private function mk(string $name, string $role, ?int $upline = null): User
    {
        return User::create([
            'name' => $name, 'fullname' => strtoupper($name), 'username' => $name,
            'email' => "{$name}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE, 'upline_id' => $upline,
        ]);
    }

    private function service(): PartnerHierarchyService
    {
        return app(PartnerHierarchyService::class);
    }

    public function test_descendants_kembalikan_seluruh_subtree_multi_level(): void
    {
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->mk('dist', User::ROLE_DISTRIBUTOR, $grand->id);
        $bronze = $this->mk('bronze', User::ROLE_RESELLER_BRONZE, $dist->id);
        $gold = $this->mk('gold', User::ROLE_RESELLER_GOLD, $dist->id);

        $ids = $this->service()->descendants($grand)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$dist->id, $bronze->id, $gold->id], $ids);
        $this->assertNotContains($grand->id, $ids);
    }

    public function test_descendants_kosong_untuk_daun(): void
    {
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->mk('dist', User::ROLE_DISTRIBUTOR, $grand->id);

        $this->assertTrue($this->service()->descendants($dist)->isEmpty());
    }

    public function test_descendants_terisolasi_antar_grand(): void
    {
        $grandA = $this->mk('ga', User::ROLE_GRAND_DISTRIBUTOR);
        $grandB = $this->mk('gb', User::ROLE_GRAND_DISTRIBUTOR);
        $distA = $this->mk('da', User::ROLE_DISTRIBUTOR, $grandA->id);
        $distB = $this->mk('db', User::ROLE_DISTRIBUTOR, $grandB->id);

        $idsA = $this->service()->descendants($grandA)->pluck('id')->all();

        $this->assertContains($distA->id, $idsA);
        $this->assertNotContains($distB->id, $idsA);
    }
}
