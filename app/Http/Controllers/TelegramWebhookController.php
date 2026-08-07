<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TelegramWebhookController extends Controller
{
    /**
     * Terima update dari Telegram Bot API. Publik (tanpa auth) — keamanan
     * dijaga dengan membandingkan header X-Telegram-Bot-Api-Secret-Token
     * terhadap secret yang dikonfigurasi saat setWebhook di BotFather.
     */
    public function handle(Request $request): Response
    {
        $secret = (string) config('services.telegram.webhook_secret');
        $header = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');

        if ($secret === '' || ! hash_equals($secret, $header)) {
            abort(403);
        }

        // Dispatch ke ReportBotDispatcher datang di task berikutnya.
        return response('', 200);
    }
}
