<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\TelegramBotChat;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

/**
 * Kontrol admin Report Bot Telegram di Pengaturan Sistem: rotasi kode akses
 * global + cabut akses satu chat (is_blocked). Lihat ReportBotGate untuk
 * bagaimana kode akses membuka chat, dan settings/index.blade.php +
 * report_bot/_admin.blade.php untuk UI-nya.
 */
class ReportBotAdminController extends Controller
{
    /**
     * Ganti kode akses global dengan kode acak baru. Chat yang SUDAH aktif
     * (authorized_at terisi) tidak terpengaruh — kode baru hanya dibutuhkan
     * untuk chat yang belum pernah membuka akses.
     */
    public function rotate(): RedirectResponse
    {
        $code = strtoupper(Str::random(8));
        AppSetting::put('report_bot_access_code', $code);

        AuditService::log(action: 'rotate_report_bot_code', targetType: 'app_setting');

        return back()->with('status', 'Kode akses Report Bot diganti.');
    }

    /** Putus akses satu chat (is_blocked) tanpa mengganti kode akses global. */
    public function revokeChat(TelegramBotChat $chat): RedirectResponse
    {
        $chat->update(['is_blocked' => true]);

        AuditService::log(action: 'revoke_report_bot_chat', targetType: 'telegram_bot_chat', targetId: $chat->id);

        return back()->with('status', 'Akses chat "'.($chat->name ?: $chat->chat_id).'" dicabut.');
    }
}
