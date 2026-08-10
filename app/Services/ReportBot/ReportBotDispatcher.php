<?php

namespace App\Services\ReportBot;

use App\Services\ReportBot\Flows\AdsReportFlow;
use App\Services\ReportBot\Flows\LeadsReportFlow;
use App\Services\ReportBot\Flows\TikTokIncomeFlow;

/**
 * Titik rangkai webhook Telegram: gate (kode akses) -> router (deteksi flow
 * dari dokumen) -> flow. Dipanggil oleh TelegramWebhookController SETELAH
 * respons 200 dikirim ke Telegram (ack cepat), jadi kelas ini tidak perlu
 * (dan tidak boleh) memedulikan format response HTTP — cuma balas lewat
 * TelegramClient::sendMessage/sendDocument.
 *
 * Task 13: match() di runFlow() menyambungkan tiap flow yang terdeteksi
 * router ke kelas Leads/Ads/TikTokIncomeReportFlow sungguhan (Fase 2-4).
 * Tiap flow bertanggung jawab PENUH atas balasannya sendiri (sendMessage
 * error/sendDocument laporan) lewat TelegramClient miliknya masing-masing —
 * ReportBotDispatcher sendiri TIDAK menyentuh $this->telegram lagi begitu
 * sebuah flow terdeteksi (beda dari cabang null-flow di handleDocument()).
 */
class ReportBotDispatcher
{
    private const MSG_NEED_CODE = 'Kirim kode akses dulu untuk memakai bot ini.';

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

        $this->runFlow($flow, $chatId, $document);
    }

    /**
     * Satu arm per flow (bukan satu string generik dari $flow) supaya tiap
     * arm memanggil flow yang isinya berbeda-beda (unduh+ekstraksi+AI+HTML).
     * $prefix ("Kode benar, aktif ✅") SENGAJA tidak diteruskan ke sini: tiap
     * flow mengirim balasannya sendiri, dan kombinasi kode akses + dokumen
     * dalam SATU update Telegram tidak mungkin terjadi di dunia nyata
     * (dokumen memakai field `caption`, bukan `text`, jadi $text yang dicek
     * gate selalu kosong saat ada dokumen -> status 'authorized_now' tidak
     * pernah bersamaan dengan document — lihat handle()).
     *
     * FINAL REVIEW Finding 5: `default` arm murni DEFENSIF, bukan jalur yang
     * dapat dicapai lewat handleDocument() hari ini — ReportBotRouter::detect()
     * cuma mengembalikan 'leads'|'ads'|'tiktok_income'|null, dan handleDocument()
     * sudah menyaring null (kirim MSG_UNKNOWN_FILE) SEBELUM runFlow() dipanggil.
     * Jaga-jaga andai router berkembang (nilai flow baru) tanpa match() ini
     * ikut diperbarui: tanpa default, match() akan melempar UnhandledMatchError
     * yang lolos ke TelegramWebhookController::handle() (jadi Log::error diam2,
     * user TIDAK dapat balasan apa pun) — dengan default, user tetap dapat
     * pesan yang sama seperti flow benar2 tak dikenal.
     *
     * @param  array<string,mixed>  $document
     */
    private function runFlow(string $flow, int|string $chatId, array $document): void
    {
        match ($flow) {
            'leads' => app(LeadsReportFlow::class)->handle($chatId, $document),
            'ads' => app(AdsReportFlow::class)->handle($chatId, $document),
            'tiktok_income' => app(TikTokIncomeFlow::class)->handle($chatId, $document),
            default => $this->telegram->sendMessage($chatId, self::MSG_UNKNOWN_FILE),
        };
    }
}
