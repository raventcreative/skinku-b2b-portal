<?php

namespace Tests\Feature;

use App\Models\EcomChatConversation;
use App\Models\EcomChatRead;
use App\Models\User;
use App\Services\EcomChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EcomChatUnreadTest extends TestCase
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

    private function convWithIncoming(string $ext, Carbon $when, string $status = 'needs_staff'): EcomChatConversation
    {
        return EcomChatConversation::create([
            'channel' => 'tiktok', 'external_conversation_id' => $ext, 'status' => $status,
            'last_message_at' => $when, 'last_incoming_at' => $when,
        ]);
    }

    public function test_belum_dibaca_terhitung_lalu_mark_read_nol(): void
    {
        $svc = app(EcomChatService::class);
        $admin = $this->user(User::ROLE_ADMIN);
        $conv = $this->convWithIncoming('C1', now()->subMinute());

        $this->assertSame(1, $svc->unreadCountFor($admin));

        $svc->markRead($admin, $conv);
        $this->assertSame(0, $svc->unreadCountFor($admin));
    }

    public function test_pesan_baru_sesudah_dibaca_jadi_unread_lagi(): void
    {
        $svc = app(EcomChatService::class);
        $admin = $this->user(User::ROLE_ADMIN);
        $conv = $this->convWithIncoming('C1', now()->subMinutes(5));
        $svc->markRead($admin, $conv);
        $this->assertSame(0, $svc->unreadCountFor($admin));

        // Pesan pembeli baru → last_incoming_at maju melewati last_read_at.
        $conv->update(['last_incoming_at' => now()->addMinute()]);
        $this->assertSame(1, $svc->unreadCountFor($admin));
    }

    public function test_dua_staf_independen(): void
    {
        $svc = app(EcomChatService::class);
        $u1 = $this->user(User::ROLE_ADMIN);
        $u2 = $this->user(User::ROLE_SUPER_ADMIN);
        $conv = $this->convWithIncoming('C1', now()->subMinute());

        $svc->markRead($u1, $conv);
        $this->assertSame(0, $svc->unreadCountFor($u1));
        $this->assertSame(1, $svc->unreadCountFor($u2));
    }

    public function test_percakapan_closed_tak_dihitung(): void
    {
        $svc = app(EcomChatService::class);
        $admin = $this->user(User::ROLE_ADMIN);
        $this->convWithIncoming('C1', now()->subMinute(), 'closed');

        $this->assertSame(0, $svc->unreadCountFor($admin));
    }

    public function test_mark_read_idempoten(): void
    {
        $svc = app(EcomChatService::class);
        $admin = $this->user(User::ROLE_ADMIN);
        $conv = $this->convWithIncoming('C1', now()->subMinute());

        $svc->markRead($admin, $conv);
        $svc->markRead($admin, $conv);

        $this->assertSame(1, EcomChatRead::where('user_id', $admin->id)->where('conversation_id', $conv->id)->count());
    }

    public function test_buka_detail_menandai_read(): void
    {
        $svc = app(EcomChatService::class);
        $admin = $this->user(User::ROLE_ADMIN);
        $conv = $this->convWithIncoming('C1', now()->subMinute());
        $this->assertSame(1, $svc->unreadCountFor($admin));

        $this->actingAs($admin)->get("/ecom-chat/{$conv->id}")->assertOk();

        $this->assertSame(0, $svc->unreadCountFor($admin->fresh()));
    }

    public function test_endpoint_unread_count_per_user_dan_gate(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->convWithIncoming('C1', now()->subMinute());

        $this->actingAs($admin)->getJson('/ecom-chat/unread-count')
            ->assertOk()->assertExactJson(['count' => 1]);

        // Non-staf → 403.
        $this->actingAs($this->user(User::ROLE_RESELLER))->getJson('/ecom-chat/unread-count')->assertForbidden();
    }
}
