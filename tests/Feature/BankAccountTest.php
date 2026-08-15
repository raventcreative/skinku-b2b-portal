<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BankAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_mitra_simpan_rekening(): void
    {
        $u = $this->partner();
        $this->actingAs($u)->post(route('account.rekening'), [
            'bank' => 'BCA', 'no_rekening' => '1234567890', 'atas_nama' => 'Budi',
        ])->assertRedirect();
        $u->refresh();
        $this->assertSame('BCA', $u->bank);
        $this->assertSame('1234567890', $u->no_rekening);
    }

    private function partner(): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => "Erin{$n}", 'fullname' => "Erin{$n}", 'username' => "erin{$n}",
            'email' => "erin{$n}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE,
            'company_name' => "Toko Erin {$n}",
        ]);
    }
}
