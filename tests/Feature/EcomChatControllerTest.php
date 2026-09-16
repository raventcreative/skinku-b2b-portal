<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\EcomChatConversation;
use App\Models\EcomChatMessage;
use App\Models\TiktokAffiliateConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcomChatControllerTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => "U$n", 'fullname' => "U$n", 'username' => "u$role$n", 'email' => "u$role$n@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function conn(): void
    {
        TiktokAffiliateConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(),
        ]);
        config()->set('services.tiktok_affiliate.app_key', 'k');
        config()->set('services.tiktok_affiliate.app_secret', 's');
        config()->set('services.tiktok_affiliate.api_base', 'https://open-api.tiktokglobalshop.com');
    }

    public function test_non_staf_ditolak(): void
    {
        $conv = EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'C1', 'status' => 'open']);
        $this->actingAs($this->user(User::ROLE_RESELLER))->get('/ecom-chat')->assertForbidden();
        $this->actingAs($this->user(User::ROLE_RESELLER))->post("/ecom-chat/{$conv->id}/send", ['text' => 'x'])->assertForbidden();
    }

    public function test_admin_lihat_inbox_dan_detail(): void
    {
        EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'C1', 'buyer_name' => 'Budi', 'status' => 'needs_staff']);
        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/ecom-chat')->assertOk()->assertSee('Budi');
    }

    public function test_staf_kirim_balasan(): void
    {
        $this->conn();
        Http::fake(['*/customer_service/*' => Http::response(['code' => 0, 'data' => ['message_id' => 'OUT']])]);
        $conv = EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'C1', 'status' => 'needs_staff']);

        $this->actingAs($this->user(User::ROLE_ADMIN))->post("/ecom-chat/{$conv->id}/send", ['text' => 'Terima kasih kak'])->assertRedirect();

        $this->assertSame(1, EcomChatMessage::where('sender', 'seller')->where('via', 'staff')->count());
        $this->assertSame('replied', $conv->fresh()->status);
    }

    public function test_inbox_filter_perlu_dibalas(): void
    {
        EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'B1', 'buyer_name' => 'AdaPembeli', 'status' => 'needs_staff', 'last_incoming_at' => now(), 'last_message_at' => now()]);
        EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'A1', 'buyer_name' => 'CumaOtomatis', 'status' => 'open', 'last_incoming_at' => null, 'last_message_at' => now()]);

        $admin = $this->user(User::ROLE_ADMIN);
        // Tab "perlu" → hanya percakapan dengan pesan pembeli (last_incoming_at terisi).
        $this->actingAs($admin)->get('/ecom-chat?tab=perlu')->assertOk()
            ->assertSee('AdaPembeli')->assertDontSee('CumaOtomatis');
        // Tab "semua" → dua-duanya.
        $this->actingAs($admin)->get('/ecom-chat?tab=semua')->assertOk()
            ->assertSee('AdaPembeli')->assertSee('CumaOtomatis');
    }

    public function test_toggle_autosend(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->post('/ecom-chat/autosend', ['on' => '1'])->assertRedirect();
        $this->assertSame('1', AppSetting::get(AppSetting::ECOM_CHAT_AUTOSEND));
        $this->actingAs($admin)->post('/ecom-chat/autosend', ['on' => '0'])->assertRedirect();
        $this->assertSame('0', AppSetting::get(AppSetting::ECOM_CHAT_AUTOSEND));
    }
}
