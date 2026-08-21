<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Halaman "Rekrutan Saya" — perekrut lihat lead + earning (join + RO cashback).
 */
class RecruitPageTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role, array $extra = []): User
    {
        $u = 'u'.(++$this->seq);

        return User::create(array_merge([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@t.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ], $extra));
    }

    public function test_sponsor_lihat_rekrutan_dan_earning(): void
    {
        $sponsor = $this->user(User::ROLE_SPONSOR);
        $recruit = $this->user(User::ROLE_GRAND_DISTRIBUTOR, ['sponsor_id' => $sponsor->id]);

        Commission::create([
            'user_id' => $sponsor->id, 'source_po_id' => null, 'source_user_id' => $recruit->id,
            'type' => 'join', 'level' => 1, 'rate' => 10, 'base_amount' => 49000000,
            'amount' => 4900000, 'status' => 'saldo',
        ]);

        $this->actingAs($sponsor)->get(route('rekrutan-saya.index'))
            ->assertOk()
            ->assertSee('Rekrutan Saya')
            ->assertSee($recruit->fullname)
            ->assertSee('4.900.000'); // earning tampil
    }

    public function test_non_partner_403(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->get(route('rekrutan-saya.index'))->assertForbidden();
    }
}
