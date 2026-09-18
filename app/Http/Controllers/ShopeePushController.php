<?php

namespace App\Http\Controllers;

use App\Services\EcomChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Penerima push Shopee Open Platform. Endpoint ini PARTNER-LEVEL: satu URL
 * menerima SEMUA push code (order, chat, dll) — jadi controller ini hanya
 * bertindak untuk push chat/message (data.type === 'message') dan mengabaikan
 * (200 OK) sisanya. Keamanan lewat verifikasi tanda tangan HMAC partner_key,
 * bukan auth/CSRF.
 */
class ShopeePushController extends Controller
{
    public function __construct(private EcomChatService $chat) {}

    public function handle(Request $request)
    {
        $raw = $request->getContent();
        if (! $this->validSignature($request, $raw)) {
            return response('invalid signature', 401);
        }

        $payload = json_decode($raw, true) ?: [];
        $data = $payload['data'] ?? [];

        // Hanya push chat/message; sisanya (order dll) diabaikan dgn 200.
        if (($data['type'] ?? null) !== 'message' || empty($data['content'])) {
            return response('ignored', 200);
        }

        try {
            $c = $data['content'];
            $shopId = (int) ($payload['shop_id'] ?? 0);
            $fromShop = (int) ($c['from_shop_id'] ?? 0);
            // Pesan dari TOKO sendiri (echo) → sender != buyer → syncIncoming yang abaikan (anti-loop).
            $sender = ($fromShop !== 0 && $fromShop === $shopId) ? 'seller' : 'buyer';

            $msg = $this->chat->syncIncoming('shopee', [
                'conversation_id' => (string) ($c['conversation_id'] ?? ''),
                'message_id' => (string) ($c['message_id'] ?? ''),
                'sender' => $sender,
                'sent_at' => (int) ($c['created_timestamp'] ?? 0),
                'type' => 'text',
                'text' => (string) ($c['content']['text'] ?? ''),
                'buyer_name' => $c['from_user_name'] ?? null,
                'buyer_id' => isset($c['from_id']) ? (string) $c['from_id'] : null,
            ]);

            if ($msg) {
                $this->chat->processDraft($msg->conversation);
            }
        } catch (\Throwable $e) {
            Log::error('shopee push gagal', ['e' => $e->getMessage()]);
        }

        return response('ok', 200);
    }

    private function validSignature(Request $request, string $raw): bool
    {
        $key = (string) config('services.shopee.partner_key');
        if ($key === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $request->url().'|'.$raw, $key);

        return hash_equals($expected, (string) $request->header('Authorization', ''));
    }
}
