<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PartnerHierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PartnerHierarchyServiceTest extends TestCase
{
    use RefreshDatabase;

    private PartnerHierarchyService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PartnerHierarchyService;
    }

    private function mk(string $u, string $role, ?int $upline = null, ?string $region = null): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
            'upline_id' => $upline, 'region' => $region,
        ]);
    }

    public function test_assign_valid_dan_null_boleh(): void
    {
        $grand = $this->mk('g', 'grand_distributor');
        $dist = $this->mk('d', 'distributor');

        $this->svc->assignUpline($dist, $grand->id);
        $this->assertSame($grand->id, $dist->upline_id);

        $this->svc->assignUpline($dist, null); // belum ditempatkan
        $this->assertNull($dist->upline_id);
    }

    public function test_tolak_level_salah(): void
    {
        $grand = $this->mk('g', 'grand_distributor');
        $reseller = $this->mk('r', 'reseller_bronze');
        $this->expectException(ValidationException::class);
        $this->svc->assignUpline($reseller, $grand->id); // reseller induk harus distributor
    }

    public function test_tolak_diri_sendiri(): void
    {
        $dist = $this->mk('d', 'distributor');
        $this->expectException(ValidationException::class);
        $this->svc->assignUpline($dist, $dist->id);
    }

    public function test_grand_tak_boleh_punya_upline(): void
    {
        $grand = $this->mk('g', 'grand_distributor');
        $other = $this->mk('g2', 'grand_distributor');
        $this->expectException(ValidationException::class);
        $this->svc->assignUpline($grand, $other->id); // allowedParents grand = [] → tolak
    }

    public function test_tolak_distributor_jadi_upline_distributor(): void
    {
        $distA = $this->mk('da', 'distributor');
        $distB = $this->mk('db', 'distributor');

        $this->expectException(ValidationException::class);
        $this->svc->assignUpline($distB, $distA->id); // allowedParents distributor = [grand_distributor] → tolak
    }

    public function test_member_id_urut_unik_dan_stabil(): void
    {
        $a = $this->mk('a', 'distributor');
        $this->svc->ensureMemberId($a);
        $a->save();
        $this->assertSame('SKN-000001', $a->member_id);

        $b = $this->mk('b', 'reseller_gold');
        $this->svc->ensureMemberId($b);
        $b->save();
        $this->assertSame('SKN-000002', $b->member_id);

        // stabil walau tier berubah + tak menimpa yang sudah ada
        $b->role = 'reseller_bronze';
        $this->svc->ensureMemberId($b);
        $this->assertSame('SKN-000002', $b->member_id);
    }

    public function test_ensure_member_id_lewati_non_mitra(): void
    {
        $admin = $this->mk('adm', 'admin');
        $this->svc->ensureMemberId($admin);
        $this->assertNull($admin->member_id);
    }

    public function test_eligible_uplines_utamakan_region_sama(): void
    {
        $farA = $this->mk('gA', 'grand_distributor', null, 'Jatim');
        $nearB = $this->mk('gB', 'grand_distributor', null, 'Jabar');
        $dist = $this->mk('d', 'distributor', null, 'Jabar');

        $list = $this->svc->eligibleUplines($dist->role, $dist->region);
        $this->assertSame($nearB->id, $list->first()->id); // region sama di urutan pertama
        $this->assertTrue($list->contains('id', $farA->id));
    }

    public function test_has_active_downline(): void
    {
        $grand = $this->mk('g', 'grand_distributor');
        $this->assertFalse($this->svc->hasActiveDownline($grand));
        $this->mk('d', 'distributor', $grand->id);
        $this->assertTrue($this->svc->hasActiveDownline($grand->fresh()));
    }
}
