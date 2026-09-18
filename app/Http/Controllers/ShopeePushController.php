<?php

namespace App\Http\Controllers;

use App\Services\EcomChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Penerima push Shopee Open Platform. Endpoint ini PARTNER-LEVEL: satu URL
 * menerima SEMUA push code (order, chat, dll) — jadi controller ini hanya
 * bertindak untuk push chat/message (data.type === 'message') dan mengabaikan
 * (200 OK) sisanya. Keamanan lewat verifikasi tanda tangan HMAC memakai Push
 * Partner Key (di-generate di halaman Set Push Shopee), bukan auth/CSRF.
 */
class ShopeePushController extends Controller
{
    public function __construct(private EcomChatService $chat) {}

    public function handle(Request $request)
    {
        $raw = $request->getContent();

        // Mode tangkap Fase 0: rekam request asli (URL, header tanda tangan, body)
        // & SELALU balas 200 — supaya tombol Verify + Get Test Push di Shopee lolos
        // dan kita bisa mengunci formula tanda tangan + bentuk payload sebelum
        // penegakan ketat dinyalakan. Matikan SHOPEE_PUSH_DEBUG setelah verifikasi.
        if (config('services.shopee.push_debug')) {
            $entry = [
                'at' => now()->toDateTimeString(),
                'method' => $request->method(),
                'url' => $request->url(),
                'authorization' => $request->header('Authorization'),
                'body' => mb_substr($raw, 0, 4000),
                'sig_api_key' => hash_hmac('sha256', $request->url().'|'.$raw, (string) config('services.shopee.partner_key')),
                'sig_push_key' => hash_hmac('sha256', $request->url().'|'.$raw, (string) config('services.shopee.push_partner_key')),
            ];
            // File KHUSUS: tak terfilter LOG_LEVEL prod (info sering dibuang → error-only).
            @file_put_contents(storage_path('logs/shopee-push-capture.log'), json_encode($entry, JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);
            Log::warning('shopee-push-debug', $entry);
            try {
                $this->processMessage($raw);
            } catch (\Throwable $e) {
                Log::error('shopee push (debug) gagal proses', ['e' => $e->getMessage()]);
            }

            return response('ok', 200);
        }

        if (! $this->validSignature($request, $raw)) {
            return response('invalid signature', 401);
        }

        try {
            $this->processMessage($raw);
        } catch (\Throwable $e) {
            Log::error('shopee push gagal', ['e' => $e->getMessage()]);
        }

        return response('ok', 200);
    }

    /**
     * Push chat Shopee = NOTIFIKASI (data.type='notification', conversation_id ada
     * di data.content). Isi pesan TIDAK di payload → kita TARIK dari API get_message
     * (pola andal, sama seperti TikTok). Push non-chat (order dll) tak punya
     * conversation_id → diabaikan.
     */
    private function processMessage(string $raw): void
    {
        $payload = json_decode($raw, true) ?: [];
        $convId = (string) ($payload['data']['content']['conversation_id'] ?? '');
        if ($convId === '') {
            return;
        }

        $this->chat->syncShopeeConversation($convId, true);
    }

    /** Kunci tanda tangan push = Push Partner Key (fallback API partner_key). */
    private function validSignature(Request $request, string $raw): bool
    {
        $key = (string) (config('services.shopee.push_partner_key') ?: config('services.shopee.partner_key'));
        if ($key === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $request->url().'|'.$raw, $key);

        return hash_equals($expected, (string) $request->header('Authorization', ''));
    }
}
