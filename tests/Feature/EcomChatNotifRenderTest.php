<?php

namespace Tests\Feature;

use App\Models\EcomChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EcomChatNotifRenderTest extends TestCase
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

    public function test_staf_lihat_tombol_badge_dan_poller(): void
    {
        EcomChatConversation::create([
            'channel' => 'tiktok', 'external_conversation_id' => 'C1', 'status' => 'needs_staff',
            'last_message_at' => now()->subMinute(), 'last_incoming_at' => now()->subMinute(),
        ]);

        $res = $this->actingAs($this->user(User::ROLE_ADMIN))->get('/dashboard')->assertOk();
        $res->assertSee('ecomChatBadge');                 // badge ada
        $res->assertSee('ecomChatMute');                  // toggle mute ada
        $res->assertSee('ecom-chat/unread-count');        // URL poller tertanam
    }

    public function test_non_staf_tak_lihat_tombol(): void
    {
        $this->actingAs($this->user(User::ROLE_RESELLER))->get('/dashboard')->assertOk()
            ->assertDontSee('ecomChatBadge');
    }
}
