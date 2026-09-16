<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\EcomChatConversation;
use App\Models\EcomChatMessage;
use App\Models\EcomChatRead;
use App\Models\TiktokAffiliateConnection;
use App\Models\User;
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
        private TikTokAffiliateService $affiliate,
    ) {}

    public function autosendEnabled(): bool
    {
        return AppSetting::get(AppSetting::ECOM_CHAT_AUTOSEND, '0') === '1';
    }

    /**
     * Koneksi app affiliate ("Seller Analitik") — app yang PUNYA scope Customer
     * Service + shop_cipher. Chat (baca/kirim/sync) lewat app ini, BUKAN app Shop
     * utama yang kategorinya tak menyediakan scope CS.
     */
    private function chatConn(): TiktokAffiliateConnection
    {
        $conn = TiktokAffiliateConnection::latest('id')->first();
        if (! $conn || ! $conn->shop_cipher) {
            throw new RuntimeException('Belum terhubung ke TikTok untuk chat (app affiliate / Seller Analitik).');
        }

        return $conn;
    }

    private function chatClient(): TikTokClient
    {
        return new TikTokClient('tiktok_affiliate');
    }

    /**
     * Jumlah percakapan yang punya pesan pembeli BELUM dilihat $user (unread per-staf).
     * Unread = last_incoming_at ada, status != closed, dan lebih baru dari last_read_at
     * user itu (atau user belum pernah buka). Difilter per user → tak bocor antar staf.
     */
    public function unreadCountFor(User $user): int
    {
        return EcomChatConversation::query()
            ->leftJoin('ecom_chat_reads', function ($join) use ($user) {
                $join->on('ecom_chat_reads.conversation_id', '=', 'ecom_chat_conversations.id')
                    ->where('ecom_chat_reads.user_id', '=', $user->id);
            })
            ->whereNotNull('ecom_chat_conversations.last_incoming_at')
            ->where('ecom_chat_conversations.status', '!=', EcomChatConversation::STATUS_CLOSED)
            ->whereRaw("ecom_chat_conversations.last_incoming_at > COALESCE(ecom_chat_reads.last_read_at, '1970-01-01 00:00:00')")
            ->count('ecom_chat_conversations.id');
    }

    /** Tandai percakapan sudah dibaca oleh $user (idempoten). */
    public function markRead(User $user, EcomChatConversation $conv): void
    {
        EcomChatRead::updateOrCreate(
            ['user_id' => $user->id, 'conversation_id' => $conv->id],
            ['last_read_at' => now()],
        );
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

        $sentAt = ! empty($msg['sent_at']) ? Carbon::createFromTimestamp($msg['sent_at'], config('app.timezone')) : now();
        $text = (string) ($msg['text'] ?? '');
        // Isi webhook TikTok kadang JSON terbungkus {"content":"..."} — ambil teksnya.
        $decoded = json_decode($text, true);
        if (is_array($decoded) && array_key_exists('content', $decoded)) {
            $text = (string) $decoded['content'];
        }

        $conv = EcomChatConversation::firstOrNew([
            'channel' => $channel,
            'external_conversation_id' => $msg['conversation_id'],
        ]);
        $conv->buyer_name = $msg['buyer_name'] ?? $conv->buyer_name;
        $conv->buyer_id = $msg['buyer_id'] ?? $conv->buyer_id;
        $conv->last_message_at = $sentAt;
        $conv->last_incoming_at = $sentAt;
        $conv->last_message_preview = mb_substr($text, 0, 255);
        $conv->status = EcomChatConversation::STATUS_OPEN;
        $conv->save();

        try {
            return $conv->messages()->create([
                'channel' => $channel,
                'external_message_id' => $msg['message_id'],
                'sender' => EcomChatMessage::SENDER_BUYER,
                'via' => EcomChatMessage::VIA_BUYER,
                'type' => (string) ($msg['type'] ?? 'text'),
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
        $conn = $this->chatConn();
        $access = $this->affiliate->freshToken($conn);
        $res = $this->chatClient()->sendMessage($access, $conn->shop_cipher, $conv->external_conversation_id, $text);
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

    /**
     * Tarik percakapan + pesan yang SUDAH ada di TikTok ke inbox SKINKU (backlog).
     * Dipetakan defensif ke bentuk respons Customer Service API. Return jumlah
     * percakapan disentuh & pesan baru tersimpan.
     *
     * @return array{conversations:int,messages:int}
     */
    public function importFromTikTok(int $maxConversations = 100, int $msgLimit = 10): array
    {
        $conn = $this->chatConn();
        $access = $this->affiliate->freshToken($conn);
        $client = $this->chatClient();

        $convCount = 0;
        $msgCount = 0;
        $pageToken = '';
        $pages = 0;

        // CS API batasi page_size <= 10, jadi halaman-per-halaman lewat next_page_token
        // sampai habis (atau batas aman) supaya seluruh backlog kebawa, bukan cuma 10.
        do {
            $data = $client->getConversations($access, $conn->shop_cipher, 10, $pageToken);

            foreach ($data['conversations'] ?? [] as $c) {
                $extId = (string) ($c['id'] ?? $c['conversation_id'] ?? '');
                if ($extId === '') {
                    continue;
                }

                $conv = EcomChatConversation::firstOrNew([
                    'channel' => 'tiktok',
                    'external_conversation_id' => $extId,
                ]);
                $buyer = $this->buyerOf($c);
                if ($buyer !== null) {
                    $conv->buyer_name = $buyer['nickname'] ?? $conv->buyer_name;
                    $conv->buyer_id = $buyer['im_user_id'] ?? $conv->buyer_id;
                }
                if (! $conv->exists) {
                    $conv->status = EcomChatConversation::STATUS_OPEN;
                }
                $conv->save();
                $convCount++;

                $msgData = $client->getConversationMessages($access, $conn->shop_cipher, $extId, $msgLimit);
                // TERTUA dulu agar recency & last_incoming_at berakhir di pesan terbaru.
                foreach (array_reverse($msgData['messages'] ?? []) as $m) {
                    if ($this->storeSyncedMessage($conv, $m)) {
                        $msgCount++;
                    }
                }
            }

            $pageToken = (string) ($data['next_page_token'] ?? '');
            $pages++;
        } while ($pageToken !== '' && $convCount < $maxConversations && $pages < 30);

        return ['conversations' => $convCount, 'messages' => $msgCount];
    }

    /** Peserta ber-peran pembeli dari payload percakapan (null bila tak ada). */
    private function buyerOf(array $conv): ?array
    {
        foreach ($conv['participants'] ?? [] as $p) {
            if (strtolower((string) ($p['role'] ?? '')) === 'buyer') {
                return $p;
            }
        }

        return null;
    }

    /** Simpan satu pesan hasil sync (buyer/seller), dedupe. Return true bila baru. */
    private function storeSyncedMessage(EcomChatConversation $conv, array $m): bool
    {
        $extId = (string) ($m['id'] ?? $m['message_id'] ?? '');
        if ($extId === '') {
            return false;
        }

        $isBuyer = strtolower((string) ($m['sender']['role'] ?? '')) === 'buyer';
        [$type, $text, $meta] = $this->parseChannelMessage($m);
        $sentAt = ! empty($m['create_time'])
            ? Carbon::createFromTimestamp((int) $m['create_time'], config('app.timezone'))
            : now();

        // updateOrCreate: sinkron ulang MEMPERBAIKI pesan lama (tipe/teks/meta) tanpa dobel.
        $msg = EcomChatMessage::updateOrCreate(
            ['channel' => $conv->channel, 'external_message_id' => $extId],
            [
                'conversation_id' => $conv->id,
                'sender' => $isBuyer ? EcomChatMessage::SENDER_BUYER : EcomChatMessage::SENDER_SELLER,
                'via' => $isBuyer ? EcomChatMessage::VIA_BUYER : EcomChatMessage::VIA_STAFF,
                'type' => $type,
                'text' => $text,
                'meta' => $meta,
                'sent_at' => $sentAt,
            ],
        );

        if ($conv->last_message_at === null || $sentAt->gte($conv->last_message_at)) {
            $conv->last_message_at = $sentAt;
            $conv->last_message_preview = mb_substr($text, 0, 255);
        }
        if ($isBuyer && ($conv->last_incoming_at === null || $sentAt->gte($conv->last_incoming_at))) {
            $conv->last_incoming_at = $sentAt;
        }
        $conv->save();

        return $msg->wasRecentlyCreated;
    }

    /**
     * Terjemahkan pesan TikTok CS jadi [type, teks-terbaca]. `content` selalu
     * JSON: TEXT/OTHER = {"content":"..."}, ORDER_CARD = {"order_id":"..."},
     * LOGISTICS_CARD = {"order_id","package_id"}. Kartu & tipe tak-didukung
     * dijadikan label ramah, bukan JSON mentah.
     *
     * @return array{0:string,1:string,2:?array<string,string>}
     */
    private function parseChannelMessage(array $m): array
    {
        $type = strtoupper((string) ($m['type'] ?? 'TEXT'));
        $raw = (string) ($m['content'] ?? '');
        $in = json_decode($raw, true);
        $in = is_array($in) ? $in : [];
        $orderId = (string) ($in['order_id'] ?? '');

        return match ($type) {
            'ORDER_CARD' => ['order_card', '🧾 Kartu Pesanan'.($orderId !== '' ? ' #'.$orderId : ''), ['order_id' => $orderId]],
            'LOGISTICS_CARD' => ['logistics_card', '🚚 Info Pengiriman'.($orderId !== '' ? ' — Pesanan #'.$orderId : ''), ['order_id' => $orderId, 'package_id' => (string) ($in['package_id'] ?? '')]],
            'OTHER' => ['other', '📎 Pesan tipe lain — buka di TikTok Seller Center', null],
            default => ['text', (string) ($in['content'] ?? $raw), null],
        };
    }
}
