<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * File csv/xlsx yang diupload user lewat Report Bot dan menunggu diproses
 * (task-task berikutnya). Sekali-tulis lalu dihapus setelah diproses — cuma
 * created_at yang relevan, tidak ada updated_at.
 */
class TelegramBotPendingFile extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['chat_id', 'kind', 'path'];
}
