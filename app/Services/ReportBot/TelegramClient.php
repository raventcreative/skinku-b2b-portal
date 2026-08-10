<?php

namespace App\Services\ReportBot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
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
        $res = $this->send(fn () => Http::get($this->apiBase().'/getFile', ['file_id' => $fileId]));
        if (! $res->successful()) {
            throw new RuntimeException('Telegram getFile gagal ('.$res->status().'): '.$res->body());
        }

        return $res->json() ?? [];
    }

    /** Unduh isi file mentah (bytes) dari file_path hasil getFile(). */
    public function downloadFile(string $filePath): string
    {
        $res = $this->send(fn () => Http::get($this->fileBase().'/'.$filePath));
        if (! $res->successful()) {
            throw new RuntimeException('Telegram downloadFile gagal ('.$res->status().'): '.$res->body());
        }

        return $res->body();
    }

    /** Kirim pesan teks biasa ke satu chat. */
    public function sendMessage(int|string $chatId, string $text): void
    {
        $res = $this->send(fn () => Http::asJson()->post($this->apiBase().'/sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
        ]));
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
        $res = $this->send(fn () => Http::attach('document', $bytes, $filename)
            ->post($this->apiBase().'/sendDocument', [
                'chat_id' => (string) $chatId,
                'caption' => $caption,
            ]));
        if (! $res->successful()) {
            throw new RuntimeException('Telegram sendDocument gagal ('.$res->status().'): '.$res->body());
        }
    }

    /**
     * Titik tunggal semua panggilan Http::... lewat client ini. ALASAN: pada
     * kegagalan level KONEKSI (timeout/DNS/TLS/refused — bukan status HTTP
     * biasa spt 404/500), Laravel melempar Illuminate\Http\Client\ConnectionException
     * yang PESANNYA memuat URL REQUEST LENGKAP apa adanya — termasuk token bot
     * di path (".../bot{token}/...", lihat apiBase()/fileBase()). Guzzle cuma
     * me-redact "user:pass@" pada pesan error-nya, TIDAK PERNAH path, jadi
     * token bocor verbatim di exception message itu. Tanpa titik tangkap ini,
     * pesan tsb mengalir ke pemanggil (TelegramWebhookController::handle()
     * nge-log getMessage(); sebelum Finding 2, flow2 juga nyertain getMessage()
     * ke sendMessage) -> token bocor ke log SEKALIGUS ke chat user.
     *
     * Tangkap DI SINI (satu-satunya tempat yang tahu bentuk URL-nya) & lempar
     * ulang RuntimeException GENERIK tanpa url/token sama sekali — pemanggil
     * (flow/controller) cuma pernah lihat pesan aman ini, apa pun isi asli
     * ConnectionException-nya. $previous TETAP disertakan (bukan dibuang) utk
     * debugging via exception chain di level kode, BUKAN via teks log/chat.
     *
     * Kegagalan level HTTP-status (mis. 404/500 dari Telegram) TIDAK lewat
     * sini — itu balik sbg Response biasa (successful() === false), ditangani
     * oleh RuntimeException per-method di atas yang pesannya cuma status+body
     * respons Telegram (tidak pernah memuat URL/token).
     *
     * @param  callable(): Response  $fn
     */
    private function send(callable $fn): Response
    {
        try {
            return $fn();
        } catch (ConnectionException $e) {
            throw new RuntimeException('Gagal menghubungi Telegram (koneksi).', previous: $e);
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
