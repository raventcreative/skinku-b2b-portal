<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\EcomChatConversation;
use App\Models\EcomChatMessage;
use App\Models\TiktokConnection;
use App\Services\Ai\EcomChatDrafter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Facade chat channel-agnostic. UI & webhook lewat sini, bukan langsung ke
 * TikTokClient — supaya menambah Shopee dll nanti tinggal cabang di send()/sync.
 */
class EcomChatService
{
    public function __construct(
        private TikTokClient $tiktok,
        private TikTokSyncService $sync,
    ) {}

    public function autosendEnabled(): bool
    {
        return AppSetting::get(AppSetting::ECOM_CHAT_AUTOSEND, '0') === '1';
    }

    /**
     * Simpan pesan MASUK dari pembeli. Dedupe by (channel, message_id); abaikan
     * pesan non-buyer (anti-loop). Return pesan baru, atau null bila diabaikan/dobel.
     *
     * @param  array{conversation_id:string,message_id:string,text:?string,buyer_name?:?string,buyer_id?:?string,sender?:string,sent_at?:?int}  $msg
     */
    public function syncIncoming(string $channel, array $msg): ?EcomChatMessage
    {
        if (($msg['sender'] ?? 'buyer') !== 'buyer') {
            return null; // anti-loop: hanya proses pesan pembeli
        }

        if (EcomChatMessage::where('channel', $channel)->where('external_message_id', $msg['message_id'])->exists()) {
            return null; // dedupe
        }

        $sentAt = ! empty($msg['sent_at']) ? Carbon::createFromTimestamp($msg['sent_at']) : now();
        $text = (string) ($msg['text'] ?? '');

        $conv = EcomChatConversation::firstOrNew([
            'channel' => $channel,
            'external_conversation_id' => $msg['conversation_id'],
        ]);
        $conv->buyer_name = $msg['buyer_name'] ?? $conv->buyer_name;
        $conv->buyer_id = $msg['buyer_id'] ?? $conv->buyer_id;
        $conv->last_message_at = $sentAt;
        $conv->last_message_preview = mb_substr($text, 0, 255);
        $conv->status = EcomChatConversation::STATUS_OPEN;
        $conv->save();

        try {
            return $conv->messages()->create([
                'channel' => $channel,
                'external_message_id' => $msg['message_id'],
                'sender' => EcomChatMessage::SENDER_BUYER,
                'via' => EcomChatMessage::VIA_BUYER,
                'text' => $text,
                'sent_at' => $sentAt,
            ]);
        } catch (QueryException $e) {
            // Balapan webhook: pesan sama masuk hampir bersamaan → unique index menangkap
            // yang kedua. Perlakukan sebagai duplikat (idempoten), jangan 500.
            return null;
        }
    }

    /**
     * Kirim balasan KELUAR ke pembeli lewat API channel, lalu catat pesan seller
     * & tandai percakapan "replied". $via = 'ai' (auto-send) atau 'staff'.
     */
    public function send(EcomChatConversation $conv, string $text, string $via): EcomChatMessage
    {
        $conn = TiktokConnection::latest('id')->first();
        if (! $conn || ! $conn->shop_cipher) {
            throw new RuntimeException('Belum terhubung ke TikTok Shop.');
        }

        $access = $this->sync->freshToken($conn);
        $res = $this->tiktok->sendMessage($access, $conn->shop_cipher, $conv->external_conversation_id, $text);
        $externalId = (string) ($res['message_id'] ?? ('local-'.uniqid()));

        $msg = $conv->messages()->create([
            'channel' => $conv->channel,
            'external_message_id' => $externalId,
            'sender' => EcomChatMessage::SENDER_SELLER,
            'via' => $via,
            'text' => $text,
            'sent_at' => now(),
        ]);

        $conv->update([
            'status' => EcomChatConversation::STATUS_REPLIED,
            'last_message_at' => now(),
            'last_message_preview' => mb_substr($text, 0, 255),
        ]);

        return $msg;
    }

    /**
     * Jalankan drafter untuk sebuah percakapan, simpan draft/keputusan, dan
     * auto-send bila keputusan auto_send DAN kill switch nyala. Selain itu →
     * needs_staff (draft menunggu ditinjau staf). Aman dipanggil dari webhook
     * (dibungkus try/catch di controller).
     */
    public function processDraft(EcomChatConversation $conv): void
    {
        $draft = app(EcomChatDrafter::class)->draft($conv);
        $conv->update([
            'ai_draft' => $draft['reply'],
            'ai_decision' => $draft['decision'],
            'ai_reason' => $draft['reason'],
        ]);

        if ($draft['decision'] === 'auto_send' && $draft['reply'] !== '' && $this->autosendEnabled()) {
            try {
                $this->send($conv, $draft['reply'], EcomChatMessage::VIA_AI);

                return;
            } catch (\Throwable $e) {
                // Auto-send gagal (belum terhubung / API error) → JANGAN diam: turunkan
                // ke staf agar tetap masuk antrean perhatian, catat alasannya.
                Log::error('ecom-chat auto-send gagal', ['conv' => $conv->id, 'e' => $e->getMessage()]);
                $conv->update(['ai_reason' => trim(((string) $conv->ai_reason).' [auto-send gagal: '.$e->getMessage().']')]);
            }
        }

        $conv->update(['status' => EcomChatConversation::STATUS_NEEDS_STAFF]);
    }
}
