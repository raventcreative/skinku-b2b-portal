<?php

namespace App\Services\ReportBot;

use App\Services\Ai\AiProviderFactory;

/**
 * Wrapper tipis di atas AiProviderFactory untuk kebutuhan Report Bot:
 *   (a) readFile()  — kirim file (base64 data URL) ke model MULTIMODAL,
 *       balikin JSON ter-decode. Dipakai saat PdfTextExtractor gagal baca
 *       teks (looksUnreadable) — model langsung "baca" berkas aslinya.
 *   (b) analyze()    — chat system+user (data JSON) → teks naratif. Dipakai
 *       untuk analisis ala "AI Daily Report/ADS Analyzer" (n8n lama).
 *
 * TIDAK menyimpan state/riwayat — tiap panggilan satu giliran (single-turn),
 * provider aktif diambil ulang tiap kali lewat AiProviderFactory::make().
 */
class ReportAi
{
    /**
     * Kirim $bytes (mis. isi PDF) sebagai lampiran multimodal + $instruction
     * sebagai teks pengarah, balikin balasan model yang di-decode sebagai
     * JSON. Balikin array kosong bila balasan bukan JSON valid.
     *
     * @return array<mixed>
     */
    public function readFile(string $bytes, string $mime, string $instruction): array
    {
        $userMessage = [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $instruction],
                ['type' => 'file', 'file' => [
                    'filename' => 'doc',
                    'file_data' => 'data:'.$mime.';base64,'.base64_encode($bytes),
                ]],
            ],
        ];

        $turn = AiProviderFactory::make()->chat([$userMessage], []);

        return $this->decodeJson((string) $turn->text);
    }

    /**
     * Chat system+user biasa: $systemPrompt sebagai peran system, $json
     * (di-encode) sebagai pesan user. Balikin teks balasan model apa adanya.
     *
     * @param  array<mixed>  $json
     */
    public function analyze(string $systemPrompt, array $json): string
    {
        $turn = AiProviderFactory::make()->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => json_encode($json, JSON_UNESCAPED_UNICODE)],
        ], []);

        return (string) $turn->text;
    }

    /**
     * json_decode toleran: model kadang membungkus balasan dengan pagar kode
     * ```json ... ``` — lepas pagar itu (kalau ada) sebelum decode. Balikin
     * array kosong bila hasil decode bukan array (gagal parse / bukan objek).
     *
     * @return array<mixed>
     */
    private function decodeJson(string $text): array
    {
        $text = trim($text);

        if (preg_match('/^```[a-zA-Z]*\s*(.*?)\s*```$/s', $text, $m) === 1) {
            $text = $m[1];
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : [];
    }
}
