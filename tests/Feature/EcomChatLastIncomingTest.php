<?php

namespace Tests\Feature;

use App\Models\EcomChatConversation;
use App\Models\EcomChatRead;
use App\Models\User;
use App\Services\EcomChatService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $this->expectException(QueryException::class);
        $conv->reads()->create(['user_id' => $user->id, 'last_read_at' => now()]);
    }

    public function test_backfill_menyalakan_backlog_open_dan_needs_staff(): void
    {
        $mk = fn (string $ext, string $status, $lastMsg) => DB::table('ecom_chat_conversations')->insertGetId([
            'channel' => 'tiktok', 'external_conversation_id' => $ext, 'status' => $status,
            'last_message_at' => $lastMsg, 'last_incoming_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $open = $mk('O1', 'open', now()->subHour());
        $needs = $mk('N1', 'needs_staff', now()->subHour());
        $replied = $mk('R1', 'replied', now()->subHour());
        $closed = $mk('C1', 'closed', now()->subHour());
        $noMsg = $mk('X1', 'open', null);

        // SQL identik dengan yang dijalankan migrasi 000129.
        DB::statement("UPDATE ecom_chat_conversations SET last_incoming_at = last_message_at WHERE last_incoming_at IS NULL AND last_message_at IS NOT NULL AND status IN ('open', 'needs_staff')");

        $this->assertNotNull(EcomChatConversation::find($open)->last_incoming_at);
        $this->assertNotNull(EcomChatConversation::find($needs)->last_incoming_at);
        $this->assertNull(EcomChatConversation::find($replied)->last_incoming_at);
        $this->assertNull(EcomChatConversation::find($closed)->last_incoming_at);
        $this->assertNull(EcomChatConversation::find($noMsg)->last_incoming_at);
    }
}
