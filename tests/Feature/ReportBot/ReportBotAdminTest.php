<?php

namespace Tests\Feature\ReportBot;

use App\Models\AppSetting;
use App\Models\TelegramBotChat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Task 4: kontrol admin Report Bot di halaman Pengaturan Sistem — rotasi kode
 * akses global (AppSetting::report_bot_access_code) + cabut akses satu chat
 * Telegram (TelegramBotChat::is_blocked). Dijaga permission system_settings.
 */
class ReportBotAdminTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_super_admin_bisa_rotasi_kode_akses(): void
    {
        AppSetting::put('report_bot_access_code', 'LAMA1234');

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'sa'))
            ->post(route('report-bot.rotate'))
            ->assertRedirect();

        $new = AppSetting::get('report_bot_access_code');
        $this->assertNotEmpty($new);
        $this->assertNotSame('LAMA1234', $new);
    }

    public function test_revoke_memblokir_chat(): void
    {
        $chat = TelegramBotChat::create(['chat_id' => '555', 'name' => 'Budi', 'authorized_at' => now()]);

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'sa'))
            ->post(route('report-bot.chat.revoke', $chat))
            ->assertRedirect();

        $this->assertTrue($chat->fresh()->is_blocked);
    }

    public function test_halaman_pengaturan_menampilkan_kode_dan_daftar_chat(): void
    {
        AppSetting::put('report_bot_access_code', 'TAMPIL12');
        TelegramBotChat::create(['chat_id' => '111', 'name' => 'Aktif Satu', 'authorized_at' => now()]);
        TelegramBotChat::create(['chat_id' => '222', 'name' => 'Sudah Blokir', 'authorized_at' => now(), 'is_blocked' => true]);
        TelegramBotChat::create(['chat_id' => '333', 'name' => 'Belum Buka']); // authorized_at null -> belum relevan, tak tampil

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'sa'))
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('TAMPIL12')
            ->assertSee('Aktif Satu')
            ->assertSee('Sudah Blokir')
            ->assertSee('Diblokir')
            ->assertDontSee('Belum Buka');
    }

    public function test_tanpa_system_settings_ditolak(): void
    {
        $chat = TelegramBotChat::create(['chat_id' => '555', 'name' => 'Budi', 'authorized_at' => now()]);
        $user = $this->user(User::ROLE_DISTRIBUTOR, 'dist');

        $this->actingAs($user)->post(route('report-bot.rotate'))->assertForbidden();
        $this->actingAs($user)->post(route('report-bot.chat.revoke', $chat))->assertForbidden();
    }
}
