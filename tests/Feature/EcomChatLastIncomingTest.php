<?php

namespace Tests\Feature;

use App\Models\EcomChatConversation;
use App\Models\EcomChatRead;
use App\Models\User;
use App\Services\EcomChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EcomChatLastIncomingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_incoming_mengisi_last_incoming_at(): void
    {
        $epoch = 1_757_000_000;
        app(EcomChatService::class)->syncIncoming('tiktok', [
            'conversation_id' => 'C1', 'message_id' => 'M1', 'text' => 'halo',
            'sender' => 'buyer', 'sent_at' => $epoch,
        ]);

        $conv = EcomChatConversation::first();
        $this->assertNotNull($conv->last_incoming_at);
        $this->assertSame($epoch, $conv->last_incoming_at->timestamp);
    }

    public function test_read_terhubung_ke_percakapan_dan_unik(): void
    {
        $user = User::create([
            'name' => 'A', 'fullname' => 'A', 'username' => 'a', 'email' => 'a@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
        $conv = EcomChatConversation::create([
            'channel' => 'tiktok', 'external_conversation_id' => 'C1', 'status' => 'open',
        ]);

        $conv->reads()->create(['user_id' => $user->id, 'last_read_at' => now()]);

        $this->assertSame(1, $conv->reads()->count());
        $this->assertSame(1, EcomChatRead::where('user_id', $user->id)->where('conversation_id', $conv->id)->count());
    }
}
