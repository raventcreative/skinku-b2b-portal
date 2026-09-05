<?php

namespace Tests\Feature;

use App\Models\MemberDormancyRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MemberDormancyTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role, string $u, array $attrs = []): User
    {
        $user = User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
        // forceFill: supaya bisa set kolom yang tak fillable (created_at, disabled_at)
        // + last_login_at/sponsor_id/status untuk skenario tes. save() saat update TIDAK
        // menimpa created_at yang sudah kita set.
        if ($attrs !== []) {
            $user->forceFill($attrs)->save();
        }

        return $user;
    }

    public function test_migrasi_seed_6_aturan_default_nonaktif(): void
    {
        $this->assertSame(6, MemberDormancyRule::count());
        $grand = MemberDormancyRule::where('role', 'grand_distributor')->first();
        $this->assertNotNull($grand);
        $this->assertSame('order', $grand->basis);
        $this->assertSame(6, $grand->inactive_months);
        $this->assertFalse($grand->enabled);

        $sponsor = MemberDormancyRule::where('role', 'sponsor')->first();
        $this->assertSame('login', $sponsor->basis);
        $this->assertSame(3, $sponsor->inactive_months);
    }
}
