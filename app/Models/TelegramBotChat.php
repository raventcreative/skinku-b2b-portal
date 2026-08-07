<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris per chat Telegram yang pernah menghubungi Report Bot. Status
 * otorisasi (authorized_at) dibuka lewat ReportBotGate memakai kode akses
 * global (AppSetting::get('report_bot_access_code')). is_blocked = tombol
 * darurat admin untuk memutus akses satu chat tanpa mengubah kode akses.
 */
class TelegramBotChat extends Model
{
    protected $fillable = ['chat_id', 'name', 'authorized_at', 'last_used_at', 'is_blocked'];

    protected function casts(): array
    {
        return [
            'authorized_at' => 'datetime',
            'last_used_at' => 'datetime',
            'is_blocked' => 'boolean',
        ];
    }
}
