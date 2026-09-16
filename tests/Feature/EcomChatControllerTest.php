<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\EcomChatConversation;
use App\Models\EcomChatMessage;
use App\Models\TiktokAffiliateConnection;
use App\Models\User;
use App\Services\EcomChatService;
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
        EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'C1', 'buyer_name' => 'Budi', 'status' => 'needs_staff', 'last_incoming_at' => now(), 'last_message_at' => now()]);
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

    public function test_thread_endpoint_render_pesan_dan_tandai_read(): void
    {
        $conv = EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'C9', 'buyer_name' => 'Rina', 'status' => 'needs_staff', 'last_incoming_at' => now(), 'last_message_at' => now()]);
        $conv->messages()->create(['channel' => 'tiktok', 'external_message_id' => 'MM', 'sender' => 'buyer', 'via' => 'buyer', 'type' => 'text', 'text' => 'Halo ada stok?', 'sent_at' => now()]);

        $svc = app(EcomChatService::class);
        $admin = $this->user(User::ROLE_ADMIN);
        $this->assertSame(1, $svc->unreadCountFor($admin));

        $this->actingAs($admin)->get("/ecom-chat/{$conv->id}/thread")->assertOk()
            ->assertSee('Halo ada stok?')->assertSee('Rina');

        // Buka thread → ditandai terbaca untuk user itu.
        $this->assertSame(0, $svc->unreadCountFor($admin->fresh()));

        // Non-staf → 403.
        $this->actingAs($this->user(User::ROLE_RESELLER))->get("/ecom-chat/{$conv->id}/thread")->assertForbidden();
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

    public function test_inbox_filter_terbalas_dan_ditutup(): void
    {
        // Sudah dibalas: balasan (last_message_at) DATANG SETELAH pesan pembeli.
        EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'R1', 'buyer_name' => 'SudahDibalas', 'status' => 'replied', 'last_incoming_at' => now()->subMinutes(5), 'last_message_at' => now()]);
        EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'X1', 'buyer_name' => 'SudahDitutup', 'status' => 'closed', 'last_incoming_at' => now(), 'last_message_at' => now()]);
        // Perlu dijawab: pesan pembeli adalah yang terbaru (last_incoming_at >= last_message_at).
        EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'N1', 'buyer_name' => 'PerluDijawab', 'status' => 'needs_staff', 'last_incoming_at' => now(), 'last_message_at' => now()]);

        $admin = $this->user(User::ROLE_ADMIN);

        // Tab "terbalas" → hanya status replied.
        $this->actingAs($admin)->get('/ecom-chat?tab=terbalas')->assertOk()
            ->assertSee('SudahDibalas')->assertDontSee('SudahDitutup')->assertDontSee('PerluDijawab');

        // Tab "ditutup" → hanya status closed.
        $this->actingAs($admin)->get('/ecom-chat?tab=ditutup')->assertOk()
            ->assertSee('SudahDitutup')->assertDontSee('SudahDibalas')->assertDontSee('PerluDijawab');

        // Tab "perlu" → HANYA yang pesan terbarunya dari pembeli. Yang sudah dibalas
        // & yang ditutup TIDAK boleh muncul lagi.
        $this->actingAs($admin)->get('/ecom-chat?tab=perlu')->assertOk()
            ->assertSee('PerluDijawab')->assertDontSee('SudahDibalas')->assertDontSee('SudahDitutup');
    }

    public function test_balas_mengeluarkan_percakapan_dari_perlu_dibalas(): void
    {
        $this->conn();
        Http::fake(['*/customer_service/*' => Http::response(['code' => 0, 'data' => ['message_id' => 'OUT']])]);
        $conv = EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'P1', 'buyer_name' => 'PembeliNanya', 'status' => 'needs_staff', 'last_incoming_at' => now(), 'last_message_at' => now()]);

        $admin = $this->user(User::ROLE_ADMIN);
        // Sebelum dibalas → ada di "Perlu dibalas".
        $this->actingAs($admin)->get('/ecom-chat?tab=perlu')->assertOk()->assertSee('PembeliNanya');

        // Staf membalas.
        $this->actingAs($admin)->post("/ecom-chat/{$conv->id}/send", ['text' => 'Halo kak, ready ya'])->assertRedirect();

        // Setelah dibalas → HILANG dari "Perlu dibalas", pindah ke "Terbalas".
        $this->actingAs($admin)->get('/ecom-chat?tab=perlu')->assertOk()->assertDontSee('PembeliNanya');
        $this->actingAs($admin)->get('/ecom-chat?tab=terbalas')->assertOk()->assertSee('PembeliNanya');
    }

    public function test_inbox_tab_default_dan_tab_ngawur_jadi_perlu(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        // Tab tak dikenal → jatuh ke "perlu" (bukan 500).
        $this->actingAs($admin)->get('/ecom-chat?tab=ngawur')->assertOk();
        $this->actingAs($admin)->get('/ecom-chat')->assertOk();
    }

    public function test_tutup_dan_buka_lagi_percakapan(): void
    {
        $conv = EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'CL1', 'buyer_name' => 'MauDitutup', 'status' => 'needs_staff', 'last_incoming_at' => now(), 'last_message_at' => now()]);
        $admin = $this->user(User::ROLE_ADMIN);

        // Tutup → status closed, masuk "ditutup", keluar dari "perlu".
        $this->actingAs($admin)->post("/ecom-chat/{$conv->id}/close")->assertRedirect();
        $this->assertSame('closed', $conv->fresh()->status);
        $this->actingAs($admin)->get('/ecom-chat?tab=ditutup')->assertOk()->assertSee('MauDitutup');
        $this->actingAs($admin)->get('/ecom-chat?tab=perlu')->assertOk()->assertDontSee('MauDitutup');

        // Buka lagi → status open.
        $this->actingAs($admin)->post("/ecom-chat/{$conv->id}/reopen")->assertRedirect();
        $this->assertSame('open', $conv->fresh()->status);
    }

    public function test_tutup_via_ajax_kembalikan_thread_dengan_tombol_buka_lagi(): void
    {
        $conv = EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'CL2', 'buyer_name' => 'Ajax', 'status' => 'needs_staff', 'last_incoming_at' => now(), 'last_message_at' => now()]);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->post("/ecom-chat/{$conv->id}/close")->assertOk()
            ->assertSee('data-thread-status="closed"', false)
            ->assertSee('Buka lagi');
    }

    public function test_badge_bedakan_dibalas_ai_dan_staf(): void
    {
        $this->conn();
        Http::fake(['*/customer_service/*' => Http::sequence()
            ->push(['code' => 0, 'data' => ['message_id' => 'OUT-STAF']])
            ->push(['code' => 0, 'data' => ['message_id' => 'OUT-AI']])]);
        $svc = app(EcomChatService::class);

        $convStaff = EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'S1', 'buyer_name' => 'BalasStaf', 'status' => 'needs_staff', 'last_incoming_at' => now(), 'last_message_at' => now()]);
        $convAi = EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'A1', 'buyer_name' => 'BalasAI', 'status' => 'needs_staff', 'last_incoming_at' => now(), 'last_message_at' => now()]);

        $svc->send($convStaff, 'balasan staf', EcomChatMessage::VIA_STAFF);
        $svc->send($convAi, 'balasan ai', EcomChatMessage::VIA_AI);

        $this->assertSame(EcomChatMessage::VIA_STAFF, $convStaff->fresh()->last_reply_via);
        $this->assertSame(EcomChatMessage::VIA_AI, $convAi->fresh()->last_reply_via);

        // Inbox tab "terbalas" → badge beda sumber.
        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/ecom-chat?tab=terbalas')->assertOk()
            ->assertSee('Dibalas staf')->assertSee('Dibalas AI');
    }

    public function test_tandai_chat_masuk_tab_ditandai(): void
    {
        $conv = EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'F1', 'buyer_name' => 'DitandaiUser', 'status' => 'open', 'last_incoming_at' => now(), 'last_message_at' => now()]);
        $admin = $this->user(User::ROLE_ADMIN);

        // Belum ditandai → tak ada di tab "ditandai".
        $this->actingAs($admin)->get('/ecom-chat?tab=ditandai')->assertOk()->assertDontSee('DitandaiUser');

        // Tandai → flagged true, muncul di tab "ditandai".
        $this->actingAs($admin)->post("/ecom-chat/{$conv->id}/flag")->assertRedirect();
        $this->assertTrue($conv->fresh()->flagged);
        $this->actingAs($admin)->get('/ecom-chat?tab=ditandai')->assertOk()->assertSee('DitandaiUser');

        // Toggle lagi → lepas tanda.
        $this->actingAs($admin)->post("/ecom-chat/{$conv->id}/flag")->assertRedirect();
        $this->assertFalse($conv->fresh()->flagged);
        $this->actingAs($admin)->get('/ecom-chat?tab=ditandai')->assertOk()->assertDontSee('DitandaiUser');
    }

    public function test_ganti_tab_via_ajax_balikan_partial_daftar(): void
    {
        EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'AJ1', 'buyer_name' => 'AjaxUser', 'status' => 'needs_staff', 'last_incoming_at' => now(), 'last_message_at' => now()]);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get('/ecom-chat?tab=perlu')->assertOk()
            ->assertSee('AjaxUser')
            ->assertSee('data-tab="perlu"', false)
            ->assertDontSee('<!DOCTYPE', false); // partial daftar, bukan halaman penuh
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
