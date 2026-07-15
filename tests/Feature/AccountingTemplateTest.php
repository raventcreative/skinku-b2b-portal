<?php

namespace Tests\Feature;

use App\Models\AccAccount;
use App\Models\AccTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountingTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => "U{$n}", 'fullname' => "U{$n}", 'username' => "{$role}{$n}",
            'email' => "{$role}{$n}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function account(string $code, string $name): AccAccount
    {
        return AccAccount::create(['code' => $code, 'name' => $name, 'type' => 'expense', 'subtype' => 'operating', 'normal_balance' => 'debit']);
    }

    public function test_admin_can_create_template_with_counter_line(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $beban = $this->account('6001', 'Beban Iklan');

        $this->actingAs($admin)->get('/accounting/template')->assertOk();

        $this->actingAs($admin)->post('/accounting/template', [
            'name' => 'Bayar Iklan', 'is_active' => 1,
            'lines' => [
                ['account_id' => $beban->id, 'side' => 'debit'],
                ['account_id' => null, 'side' => 'credit'], // Kas/Bank pilih saat input
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('acc_templates', ['name' => 'Bayar Iklan']);
        $t = AccTemplate::first();
        $this->assertEquals(2, $t->lines()->count());
        $this->assertDatabaseHas('acc_template_lines', ['acc_template_id' => $t->id, 'account_id' => $beban->id, 'side' => 'debit']);
        $this->assertDatabaseHas('acc_template_lines', ['acc_template_id' => $t->id, 'account_id' => null, 'side' => 'credit']);
    }

    public function test_update_replaces_lines(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $a = $this->account('6001', 'Beban Iklan');
        $b = $this->account('6002', 'Beban Sewa');

        $this->actingAs($admin)->post('/accounting/template', [
            'name' => 'X', 'lines' => [['account_id' => $a->id, 'side' => 'debit'], ['account_id' => null, 'side' => 'credit']],
        ]);
        $t = AccTemplate::first();

        $this->actingAs($admin)->put('/accounting/template/'.$t->id, [
            'name' => 'X updated',
            'lines' => [
                ['account_id' => $b->id, 'side' => 'debit'],
                ['account_id' => $a->id, 'side' => 'debit'],
                ['account_id' => null, 'side' => 'credit'],
            ],
        ])->assertRedirect();

        $this->assertEquals('X updated', $t->fresh()->name);
        $this->assertEquals(3, $t->lines()->count()); // replaced
    }

    public function test_template_requires_min_two_lines(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $a = $this->account('6001', 'Beban Iklan');
        $this->actingAs($admin)->post('/accounting/template', [
            'name' => 'X', 'lines' => [['account_id' => $a->id, 'side' => 'debit']],
        ])->assertSessionHasErrors('lines');
    }

    public function test_admin_can_delete_template(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $a = $this->account('6001', 'Beban Iklan');
        $this->actingAs($admin)->post('/accounting/template', [
            'name' => 'X', 'lines' => [['account_id' => $a->id, 'side' => 'debit'], ['account_id' => null, 'side' => 'credit']],
        ]);
        $t = AccTemplate::first();
        $this->actingAs($admin)->delete('/accounting/template/'.$t->id)->assertRedirect();
        $this->assertDatabaseMissing('acc_templates', ['id' => $t->id]);
    }

    public function test_reseller_cannot_access_templates(): void
    {
        $this->actingAs($this->user(User::ROLE_RESELLER))->get('/accounting/template')->assertForbidden();
    }
}
