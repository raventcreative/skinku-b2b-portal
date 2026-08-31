<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\ReportSkuMap;
use App\Models\TelegramBotChat;
use App\Services\AuditService;
use App\Services\ReportBot\TikTokIncomeN8nService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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

    /** Halaman kelola peta SKU parser Report Bot (SKU ID → kategori × qty). */
    public function skuMap()
    {
        return view('report_bot.sku_map', [
            'maps' => ReportSkuMap::orderBy('sku_id')->orderBy('category')->get()->groupBy('sku_id'),
            'categories' => TikTokIncomeN8nService::CATEGORIES,
        ]);
    }

    /** Tambah/ubah satu komponen SKU (isi ulang sku_id+kategori sama = perbarui qty). */
    public function skuStore(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'sku_id' => ['required', 'string', 'max:40', 'regex:/^\d+$/'],
            'category' => ['required', Rule::in(TikTokIncomeN8nService::CATEGORIES)],
            'qty' => ['required', 'integer', 'min:1', 'max:99'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        ReportSkuMap::updateOrCreate(
            ['sku_id' => $d['sku_id'], 'category' => $d['category']],
            ['qty' => $d['qty'], 'note' => $d['note'] ?? null],
        );

        AuditService::log(action: 'upsert_report_sku_map', targetType: 'report_sku_map', after: $d);

        return back()->with('status', "SKU {$d['sku_id']} → {$d['category']} ×{$d['qty']} disimpan.");
    }

    public function skuDestroy(ReportSkuMap $map): RedirectResponse
    {
        $ref = "{$map->sku_id} / {$map->category}";
        $map->delete();

        AuditService::log(action: 'delete_report_sku_map', targetType: 'report_sku_map', targetId: $map->id);

        return back()->with('status', "Komponen SKU {$ref} dihapus.");
    }
}
