<?php

namespace App\Http\Controllers;

use App\Services\ReportBot\ReportBotDispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    /**
     * Terima update dari Telegram Bot API. Publik (tanpa auth) — keamanan
     * dijaga dengan membandingkan header X-Telegram-Bot-Api-Secret-Token
     * terhadap secret yang dikonfigurasi saat setWebhook di BotFather.
     *
     * Ack cepat: begitu secret valid, langsung kirim 200 lalu tutup koneksi
     * FastCGI (fastcgi_finish_request) SEBELUM memproses dispatcher — Telegram
     * cuma menunggu 200 untuk anggap update "diterima", pemrosesan (unduh
     * file, panggil AI, dst) tak perlu bikin Telegram menunggu/redeliver.
     * Di bawah PHPUnit (SAPI cli, bukan FPM) fastcgi_finish_request tidak ada,
     * jadi dispatcher jalan inline sebelum return — inilah yang membuat alur
     * ini bisa dites end-to-end tanpa proses background sungguhan.
     *
     * Dispatcher dibungkus try/catch supaya error apa pun di dalamnya (mis.
     * Telegram API gagal, file tak bisa diunduh) tidak pernah bocor jadi
     * response 5xx ke Telegram — sudah kadung 200, tinggal dicatat ke log.
     */
    public function handle(Request $request): Response
    {
        $secret = (string) config('services.telegram.webhook_secret');
        $header = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');

        if ($secret === '' || ! hash_equals($secret, $header)) {
            abort(403);
        }

        if (function_exists('fastcgi_finish_request')) {
            response('', 200)->send();
            fastcgi_finish_request();
        }

        try {
            app(ReportBotDispatcher::class)->handle($request->all());
        } catch (\Throwable $e) {
            Log::error('report-bot dispatch', ['e' => $e->getMessage()]);
        }

        return response('', 200);
    }
}
