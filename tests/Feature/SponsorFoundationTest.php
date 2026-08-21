<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PartnerHierarchy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Sponsor Fase 1 — data foundation: kolom sponsor_id (jalur rekrutmen, TERPISAH
 * dari upline_id) + role sponsor (perekrut murni: isPartner true tapi TANPA stok/PO).
 */
class SponsorFoundationTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role, array $extra = []): User
    {
        $u = 'u'.(++$this->seq);

        return User::create(array_merge([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ], $extra));
    }

    public function test_sponsor_link_terpisah_dari_upline(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $sponsor = $this->user(User::ROLE_SPONSOR);
        // member: upline pasok = grand, perekrut = sponsor (beda orang).
        $member = $this->user(User::ROLE_DISTRIBUTOR, ['upline_id' => $grand->id, 'sponsor_id' => $sponsor->id]);

        $this->assertSame($grand->id, $member->upline->id);       // jalur pasok
        $this->assertSame($sponsor->id, $member->sponsor->id);    // jalur rekrutmen
        $this->assertTrue($sponsor->recruits->contains($member)); // lead sponsor
    }

    public function test_role_sponsor_partner_tapi_tanpa_stok(): void
    {
        $sponsor = $this->user(User::ROLE_SPONSOR);

        $this->assertTrue($sponsor->isPartner());                             // punya saldo/withdraw
        $this->assertFalse(PartnerHierarchy::holdsStock(User::ROLE_SPONSOR)); // tak pegang stok
    }

    public function test_sponsor_tak_bisa_buat_po(): void
    {
        $sponsor = $this->user(User::ROLE_SPONSOR);
        // create_po tak di-default untuk sponsor → route 403.
        $this->actingAs($sponsor)->get(route('purchase-orders.create'))->assertForbidden();
    }
}
