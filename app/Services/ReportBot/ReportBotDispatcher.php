<?php

namespace App\Services\ReportBot;

/**
 * Titik rangkai webhook Telegram: gate (kode akses) -> router (deteksi flow
 * dari dokumen) -> flow. Dipanggil oleh TelegramWebhookController SETELAH
 * respons 200 dikirim ke Telegram (ack cepat), jadi kelas ini tidak perlu
 * (dan tidak boleh) memedulikan format response HTTP — cuma balas lewat
 * TelegramClient::sendMessage/sendDocument.
 *
 * Flow Leads/Ads/TikTok Income (Fase 2-4) masih stub di sini — Task 13
 * mengganti isi tiap cabang match() di runFlow() dengan pemanggilan
 * LeadsReportFlow/AdsReportFlow/TikTokIncomeFlow sungguhan tanpa mengubah
 * struktur gate->router->flow di atasnya.
 */
class ReportBotDispatcher
{
    private const MSG_NEED_CODE = 'Kirim *kode akses* dulu untuk memakai bot ini.';

    private const MSG_WRONG_CODE = 'Kode akses salah. Coba lagi.';

    private const MSG_JUST_AUTHORIZED_PREFIX = "Kode benar, aktif \u{2705}\n\n";

    private const MSG_SEND_FILE = 'Kirim file laporan (Leads/Ads) atau CSV+XLSX (TikTok Income).';

    private const MSG_UNKNOWN_FILE = 'Jenis file belum dikenali. Kirim laporan (Leads/Ads) atau CSV+XLSX (TikTok Income).';

    public function __construct(
        private ReportBotGate $gate,
        private TelegramClient $telegram,
    ) {}

    /**
     * @param  array<string,mixed>  $update  Payload update Telegram mentah (dari $request->all()).
     */
    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;
        $chatId = is_array($message) ? ($message['chat']['id'] ?? null) : null;

        if ($chatId === null) {
            // Bukan pesan chat biasa (mis. edited_message/channel_post/dll) atau
            // payload tak lengkap — tidak ada tujuan balasan, abaikan diam-diam.
            return;
        }

        $name = is_string($message['from']['first_name'] ?? null) ? $message['from']['first_name'] : null;
        $text = (string) ($message['text'] ?? '');
        $document = $message['document'] ?? null;

        $status = $this->gate->check($chatId, $name, $text);

        if ($status === 'blocked') {
            return;
        }

        if ($status === 'need_code') {
            $this->telegram->sendMessage($chatId, self::MSG_NEED_CODE);

            return;
        }

        if ($status === 'wrong_code') {
            $this->telegram->sendMessage($chatId, self::MSG_WRONG_CODE);

            return;
        }

        // $status di sini pasti 'active' atau 'authorized_now'.
        $prefix = $status === 'authorized_now' ? self::MSG_JUST_AUTHORIZED_PREFIX : '';

        if (is_array($document)) {
            $this->handleDocument($chatId, $document, $prefix);

            return;
        }

        $this->telegram->sendMessage($chatId, $prefix.self::MSG_SEND_FILE);
    }

    /**
     * @param  array<string,mixed>  $document  message.document dari update Telegram.
     */
    private function handleDocument(int|string $chatId, array $document, string $prefix): void
    {
        $fileName = (string) ($document['file_name'] ?? '');
        $mime = (string) ($document['mime_type'] ?? '');

        $flow = ReportBotRouter::detect($fileName, $mime);

        if ($flow === null) {
            $this->telegram->sendMessage($chatId, $prefix.self::MSG_UNKNOWN_FILE);

            return;
        }

        $this->runFlow($flow, $chatId, $document, $prefix);
    }

    /**
     * Stub tiap flow (Fase 2-4 mengimplementasikannya sungguhan, Task 13
     * menyambungkannya ke sini). Sengaja satu arm per flow (bukan satu
     * string generik dari $flow) supaya tiap arm bisa diganti sendiri-sendiri
     * dengan pemanggilan flow yang isinya berbeda-beda (unduh+ekstraksi+AI+HTML).
     *
     * @param  array<string,mixed>  $document
     */
    private function runFlow(string $flow, int|string $chatId, array $document, string $prefix): void
    {
        match ($flow) {
            'leads' => $this->telegram->sendMessage($chatId, $prefix.'Flow leads belum aktif — segera.'),
            'ads' => $this->telegram->sendMessage($chatId, $prefix.'Flow ads belum aktif — segera.'),
            'tiktok_income' => $this->telegram->sendMessage($chatId, $prefix.'Flow tiktok_income belum aktif — segera.'),
        };
    }
}
