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

        // DIAGNOSTIK (sementara): bentuk payload NEW_MESSAGE TikTok berbeda-beda —
        // catat kunci + id yang terekstrak biar ketahuan kalau conversation_id kosong.
        Log::info('ecom-chat webhook payload', [
            'data_keys' => array_keys($data),
            'msg_keys' => is_array($msg) ? array_keys($msg) : [],
            'conversation_id' => (string) ($msg['conversation_id'] ?? $data['conversation_id'] ?? ''),
            'message_id' => (string) ($msg['id'] ?? $msg['message_id'] ?? $data['message_id'] ?? ''),
            'has_content' => array_key_exists('content', $msg) || array_key_exists('content', $data),
            'role' => $role,
        ]);

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
                // Payload NEW_MESSAGE tak membawa teks → tarik isi asli dari API dulu
                // supaya pesan tak tersimpan kosong & AI punya konteks untuk membalas.
                try {
                    $chat->pullConversationMessages($conv);
                    $conv->refresh();
                } catch (\Throwable $e) {
                    Log::warning('ecom-chat webhook tarik isi pesan gagal', ['e' => $e->getMessage()]);
                }
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
            Log::warning('ecom-chat webhook ditolak: app_secret affiliate kosong (.env services.tiktok_affiliate.app_secret).');

            return false;
        }

        // Algoritma resmi TikTok Shop: HMAC-SHA256(app_secret, app_key + RAW body),
        // hex huruf-kecil, di header Authorization apa adanya (tanpa "Bearer").
        $provided = (string) $request->header('Authorization', '');
        $expected = hash_hmac('sha256', $appKey.$request->getContent(), $secret);

        if ($provided === '') {
            // Di Apache/shared-hosting header Authorization sering dibuang bila
            // public/.htaccess tak punya aturan "Handle Authorization Header".
            Log::warning('ecom-chat webhook ditolak: header Authorization kosong (cek aturan Authorization di public/.htaccess).');

            return false;
        }

        if (! hash_equals($expected, $provided)) {
            // Panjang saja (bukan nilainya) untuk bantu diagnosa tanpa membocorkan tanda tangan.
            Log::warning('ecom-chat webhook ditolak: tanda tangan tak cocok.', [
                'panjang_diberikan' => strlen($provided),
                'panjang_diharapkan' => strlen($expected),
            ]);

            return false;
        }

        return true;
    }
}
