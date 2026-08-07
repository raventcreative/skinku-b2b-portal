<?php

namespace App\Services\ReportBot;

use App\Models\AppSetting;
use App\Models\TelegramBotChat;

/**
 * Gerbang akses per-chat Report Bot. Satu kode akses global (disetel admin
 * lewat AppSetting::get('report_bot_access_code')) membuka SEMUA chat yang
 * mengetiknya — begitu terbuka, chat itu tetap 'active' selamanya sampai
 * admin memblokirnya (is_blocked), tidak perlu mengetik ulang kode.
 *
 * Urutan pengecekan (lihat task-3-brief.md Step 3):
 *   1. chat diblokir admin           -> blocked
 *   2. chat sudah pernah diotorisasi -> catat last_used_at, active
 *   3. teks == kode akses saat ini   -> buka (authorized_at = now), authorized_now
 *   4. chat baru pertama kali kontak -> catat baris, need_code
 *   5. chat sudah pernah kontak tapi belum terbuka & teks salah -> wrong_code
 */
class ReportBotGate
{
    public function check(string|int $chatId, ?string $name, string $text): string
    {
        $chatId = (string) $chatId;
        $chat = TelegramBotChat::where('chat_id', $chatId)->first();

        if ($chat && $chat->is_blocked) {
            return 'blocked';
        }

        if ($chat && $chat->authorized_at !== null) {
            $chat->update(['last_used_at' => now()]);

            return 'active';
        }

        $code = AppSetting::get('report_bot_access_code');

        if ($code !== null && trim($text) === $code) {
            if ($chat) {
                $chat->update(['authorized_at' => now()]);
            } else {
                TelegramBotChat::create(['chat_id' => $chatId, 'name' => $name, 'authorized_at' => now()]);
            }

            return 'authorized_now';
        }

        if (! $chat) {
            TelegramBotChat::create(['chat_id' => $chatId, 'name' => $name]);

            return 'need_code';
        }

        return 'wrong_code';
    }
}
