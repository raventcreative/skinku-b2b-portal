<?php

namespace App\Services\ReportBot;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client tipis untuk Telegram Bot API — dipakai TelegramWebhookController +
 * ReportBotDispatcher (task-task berikutnya) untuk balas pesan, ambil file
 * yang diupload user, dan kirim laporan sebagai dokumen. Zero-dependency:
 * cuma Illuminate\Support\Facades\Http, tanpa SDK Telegram pihak ketiga.
 *
 * Base URL resmi Telegram:
 *   - API bot     : https://api.telegram.org/bot{token}/{method}
 *   - File statis : https://api.telegram.org/file/bot{token}/{file_path}
 */
class TelegramClient
{
    private string $token;

    public function __construct()
    {
        $this->token = (string) config('services.telegram.token');
    }

    /** Ambil metadata file (termasuk result.file_path) lewat file_id hasil upload user. */
    public function getFile(string $fileId): array
    {
        $res = Http::get($this->apiBase().'/getFile', ['file_id' => $fileId]);
        if (! $res->successful()) {
            throw new RuntimeException('Telegram getFile gagal ('.$res->status().'): '.$res->body());
        }

        return $res->json() ?? [];
    }

    /** Unduh isi file mentah (bytes) dari file_path hasil getFile(). */
    public function downloadFile(string $filePath): string
    {
        $res = Http::get($this->fileBase().'/'.$filePath);
        if (! $res->successful()) {
            throw new RuntimeException('Telegram downloadFile gagal ('.$res->status().'): '.$res->body());
        }

        return $res->body();
    }

    /** Kirim pesan teks biasa ke satu chat. */
    public function sendMessage(int|string $chatId, string $text): void
    {
        $res = Http::asJson()->post($this->apiBase().'/sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
        ]);
        if (! $res->successful()) {
            throw new RuntimeException('Telegram sendMessage gagal ('.$res->status().'): '.$res->body());
        }
    }

    /**
     * Kirim dokumen (mis. laporan Excel/PDF) sebagai lampiran multipart.
     *
     * chat_id sengaja di-cast ke string di sini (bukan cuma dilempar apa
     * adanya): body multipart Guzzle cuma menerima string/resource/stream utk
     * tiap bagian, dan versi psr7 terbaru sudah men-deprecate nilai skalar
     * non-string (lihat GuzzleHttp\Psr7\Utils::streamFor). Cast ini murni
     * defensif, tidak mengubah payload yang dikirim ke Telegram.
     */
    public function sendDocument(int|string $chatId, string $filename, string $bytes, ?string $caption = null): void
    {
        $res = Http::attach('document', $bytes, $filename)
            ->post($this->apiBase().'/sendDocument', [
                'chat_id' => (string) $chatId,
                'caption' => $caption,
            ]);
        if (! $res->successful()) {
            throw new RuntimeException('Telegram sendDocument gagal ('.$res->status().'): '.$res->body());
        }
    }

    private function apiBase(): string
    {
        return 'https://api.telegram.org/bot'.$this->token;
    }

    private function fileBase(): string
    {
        return 'https://api.telegram.org/file/bot'.$this->token;
    }
}
