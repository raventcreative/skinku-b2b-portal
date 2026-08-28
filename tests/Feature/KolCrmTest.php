<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolAccount;
use App\Models\KolContactLog;
use App\Models\KolRateCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KolCrmTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_store_update_field_crm_dan_blacklist(): void
    {
        $root = $this->user(User::ROLE_SUPER_ADMIN, 'crmroot');

        $this->actingAs($root)->post(route('kols.store'), [
            'tiktok_username' => 'crmkol', 'name' => 'Sari Beauty', 'role' => 'both', 'followers' => 50_000,
            'manager_name' => 'Budi', 'voucher_code' => 'SARI10', 'barter_ok' => 1, 'tiktok_shop_active' => 1,
        ])->assertRedirect();

        $kol = Kol::where('tiktok_username', 'crmkol')->first();
        $this->assertSame('Sari Beauty', $kol->name);
        $this->assertSame('both', $kol->role);
        $this->assertTrue($kol->barter_ok);
        $this->assertTrue($kol->tiktok_shop_active);
        $this->assertFalse($kol->shopee_affiliate_active);   // tak dicentang
        $this->assertSame('SARI10', $kol->voucher_code);

        // Update → blacklist + alasan; uncheck barter harus tersimpan (jadi false).
        $this->actingAs($root)->put(route('kols.update', $kol), [
            'followers' => 50_000, 'status' => 'blacklist', 'blacklist_reason' => 'ghosting', 'role' => 'kol',
        ])->assertRedirect();
        $kol->refresh();
        $this->assertTrue($kol->isBlacklisted());
        $this->assertSame('ghosting', $kol->blacklist_reason);
        $this->assertFalse($kol->barter_ok);

        $this->actingAs($root)->get(route('kols.show', $kol))->assertOk()->assertSee('di-BLACKLIST')->assertSee('ghosting');
    }

    public function test_filter_platform_role_dan_cari_lintas_field(): void
    {
        $spec = $this->user('kol_specialist', 'crmspec');
        Kol::create(['tiktok_username' => 'ttkol', 'platform' => 'tiktok', 'role' => 'kol', 'followers' => 1000]);
        Kol::create(['tiktok_username' => 'igkol', 'platform' => 'instagram', 'role' => 'affiliate', 'followers' => 1000, 'name' => 'Ratu IG']);

        $usernames = fn ($res) => $res->viewData('kols')->pluck('tiktok_username');

        $r1 = $this->actingAs($spec)->get(route('kols.index', ['platform' => 'instagram']))->assertOk();
        $this->assertTrue($usernames($r1)->contains('igkol'));
        $this->assertFalse($usernames($r1)->contains('ttkol'));

        $r2 = $this->actingAs($spec)->get(route('kols.index', ['role' => 'affiliate']))->assertOk();
        $this->assertTrue($usernames($r2)->contains('igkol'));
        $this->assertFalse($usernames($r2)->contains('ttkol'));

        // Cari lintas-field: nama tampilan.
        $r3 = $this->actingAs($spec)->get(route('kols.index', ['q' => 'Ratu']))->assertOk();
        $this->assertTrue($usernames($r3)->contains('igkol'));
        $this->assertFalse($usernames($r3)->contains('ttkol'));
    }

    public function test_log_kontak_dan_hapus_kol(): void
    {
        $root = $this->user(User::ROLE_SUPER_ADMIN, 'logroot');
        $kol = Kol::create(['tiktok_username' => 'logkol', 'followers' => 1000]);

        $this->actingAs($root)->post(route('kols.contact-log.store', $kol), [
            'channel' => 'wa', 'note' => 'DM perkenalan', 'contacted_at' => now()->toDateString(),
        ])->assertRedirect();
        $this->assertSame(1, $kol->contactLogs()->count());
        $this->actingAs($root)->get(route('kols.show', $kol))->assertOk()->assertSee('DM perkenalan');

        $log = $kol->contactLogs()->first();
        $this->actingAs($root)->delete(route('kols.contact-log.destroy', $log))->assertRedirect();
        $this->assertSame(0, KolContactLog::count());

        // Hapus KOL: super_admin OK (soft delete), non-super_admin 403.
        $this->actingAs($this->user('kol_specialist', 'nokill'))->delete(route('kols.destroy', $kol))->assertForbidden();
        $this->actingAs($root)->delete(route('kols.destroy', $kol))->assertRedirect();
        $this->assertSoftDeleted('kols', ['id' => $kol->id]);
    }

    public function test_akun_platform_dan_rate_card(): void
    {
        $root = $this->user(User::ROLE_SUPER_ADMIN, 'multiroot');
        $kol = Kol::create(['tiktok_username' => 'multikol', 'platform' => 'tiktok', 'followers' => 50_000]);

        // Akun IG tambahan (akun utama tetap di kols).
        $this->actingAs($root)->post(route('kols.accounts.store', $kol), [
            'platform' => 'instagram', 'username' => 'multi.ig', 'followers' => 30_000,
        ])->assertRedirect();
        $this->assertSame(1, $kol->accounts()->count());
        $acc = $kol->accounts()->first();

        // Rate card per tipe.
        $this->actingAs($root)->post(route('kols.rate-cards.store', $kol), [
            'content_type' => 'tiktok_video', 'rate' => 750_000, 'note' => 'per video',
        ])->assertRedirect();
        $this->assertSame(750_000, (int) $kol->rateCards()->first()->rate);

        $this->actingAs($root)->get(route('kols.show', $kol))->assertOk()
            ->assertSee('Akun Platform')->assertSee('multi.ig')
            ->assertSee('Rate Card per Tipe Konten')->assertSee('Video TikTok');

        // Hapus.
        $this->actingAs($root)->delete(route('kols.accounts.destroy', $acc))->assertRedirect();
        $this->assertSame(0, KolAccount::count());
        $this->actingAs($root)->delete(route('kols.rate-cards.destroy', $kol->rateCards()->first()))->assertRedirect();
        $this->assertSame(0, KolRateCard::count());
    }
}
