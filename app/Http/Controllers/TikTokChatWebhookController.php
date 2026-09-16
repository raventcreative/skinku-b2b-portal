<?php

namespace App\Http\Controllers;

use App\Models\EcomChatConversation;
use App\Services\EcomChatService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Penerima webhook Customer Service TikTok (NEW_MESSAGE = event type 14).
 * Publik (tanpa auth); keamanan lewat verifikasi tanda tangan HMAC app_secret.
 *
 * Ack cepat: begitu tanda tangan & pesan tersimpan, kirim 200 lalu tutup koneksi
 * FastCGI SEBELUM memanggil AI drafter (pola sama TelegramWebhookController) —
 * TikTok butuh 200 < 3 detik; pemrosesan AI/kirim jalan setelahnya via
 * fastcgi_finish_request, tak butuh queue worker. Di PHPUnit (CLI) drafter jalan
 * inline sebelum return → alur bisa dites end-to-end.
 */
class TikTokChatWebhookController extends Controller
{
    public function handle(Request $request, EcomChatService $chat): Response
    {
        if (! $this->verifySignature($request)) {
            abort(401);
        }

        $data = (array) $request->input('data', []);
        // Payload NEW_MESSAGE bisa bersarang di data.message atau rata di data — dukung dua-duanya.
        $msg = isset($data['message']) && is_array($data['message']) ? $data['message'] : $data;
        $sender = (array) ($msg['sender'] ?? $data['sender'] ?? []);
        $role = strtolower((string) ($sender['role'] ?? 'buyer'));

        $message = $chat->syncIncoming('tiktok', [
            'conversation_id' => (string) ($msg['conversation_id'] ?? $data['conversation_id'] ?? ''),
            'message_id' => (string) ($msg['id'] ?? $msg['message_id'] ?? $data['message_id'] ?? ''),
            'text' => (string) ($msg['content'] ?? $data['content'] ?? ''),
            'type' => strtolower((string) ($msg['type'] ?? 'text')),
            'buyer_name' => $sender['nickname'] ?? null,
            'buyer_id' => $sender['im_user_id'] ?? null,
            'sender' => $role === 'buyer' ? 'buyer' : 'seller',
            'sent_at' => isset($msg['create_time']) ? (int) $msg['create_time'] : (isset($data['create_time']) ? (int) $data['create_time'] : null),
        ]);

        // Pesan diabaikan/dobel/non-buyer → tak ada yang perlu diproses.
        if ($message === null) {
            return response('', 200);
        }

        $conversationId = $message->conversation_id;

        if (function_exists('fastcgi_finish_request')) {
            response('', 200)->send();
            fastcgi_finish_request();
        }

        try {
            $conv = EcomChatConversation::find($conversationId);
            if ($conv) {
                $chat->processDraft($conv);
            }
        } catch (\Throwable $e) {
            Log::error('ecom-chat webhook process', ['e' => $e->getMessage()]);
        }

        return response('', 200);
    }

    /**
     * Verifikasi tanda tangan webhook TikTok Shop.
     * NB (verifikasi ke docs TikTok saat deploy): default = HMAC-SHA256 dari
     * (app_key + raw body) memakai app_secret, hex, di header Authorization.
     * Bila docs berbeda, ubah HANYA baris perhitungan $expected di sini.
     */
    private function verifySignature(Request $request): bool
    {
        // Webhook chat didaftarkan di app affiliate ("Seller Analitik") — app yang
        // punya scope Customer Service — jadi tanda tangan pakai kredensial app itu.
        $secret = (string) config('services.tiktok_affiliate.app_secret');
        $appKey = (string) config('services.tiktok_affiliate.app_key');
        if ($secret === '') {
            return false;
        }

        $provided = (string) $request->header('Authorization', '');
        $expected = hash_hmac('sha256', $appKey.$request->getContent(), $secret);

        return $provided !== '' && hash_equals($expected, $provided);
    }
}
