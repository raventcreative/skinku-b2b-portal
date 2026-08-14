<?php

namespace Tests\Feature;

use App\Models\PartnerSale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OmzetMitraPageTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(): User
    {
        $n = ++$this->seq;

        return User::create([
            'name' => 'A', 'fullname' => 'A', 'username' => "ompadm{$n}", 'email' => "ompadm{$n}@skinku.test",
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function mitra(): User
    {
        $n = ++$this->seq;

        return User::create([
            'name' => "Mitra{$n}", 'fullname' => "Mitra{$n}", 'username' => "ompmit{$n}",
            'email' => "ompmit{$n}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE,
            'company_name' => "Toko Mitra {$n}",
        ]);
    }

    /** Reuse pola helper Task 3 (lihat OmzetMitraServiceTest::partnerSale()). */
    private function partnerSale(User $seller, float $amount, string $soldAt = '2026-08-10'): void
    {
        PartnerSale::create([
            'sale_number' => 'OMP-'.(++$this->seq), 'user_id' => $seller->id,
            'customer_name' => 'Cust '.$this->seq, 'total_amount' => $amount,
            'sold_at' => $soldAt, 'created_by' => $seller->id,
        ]);
    }

    public function test_staff_lihat_halaman_omzet_mitra(): void
    {
        $staff = $this->admin();
        $mitra = $this->mitra();
        $this->partnerSale($mitra, 150000); // masuk bulan berjalan (default filter)

        $resp = $this->actingAs($staff)->get(route('reports.omzet-mitra'));

        $resp->assertOk();
        $resp->assertSee('Omzet Mitra');
        $resp->assertSee($mitra->fullname);
    }

    public function test_mitra_tak_boleh_akses_omzet_mitra(): void
    {
        $mitra = $this->mitra();

        $this->actingAs($mitra)->get(route('reports.omzet-mitra'))->assertForbidden();
    }
}
