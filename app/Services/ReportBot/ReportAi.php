<?php

namespace App\Services\ReportBot;

use App\Services\Ai\AiProviderFactory;

/**
 * Wrapper tipis di atas AiProviderFactory untuk kebutuhan Report Bot (juga "Baca CV dengan AI" di HR):
 *   (a) readFile()  — kirim file (base64 data URL) ke model MULTIMODAL,
 *       balikin JSON ter-decode. Dipakai saat PdfTextExtractor gagal baca
 *       teks (looksUnreadable) — model langsung "baca" berkas aslinya. Foto
 *       (image/*) dikirim sebagai image_url, selain itu sebagai file (PDF).
 *   (b) readText()   — instruksi + teks dokumen → JSON ter-decode (PDF berteks).
 *   (c) analyze()    — chat system+user (data JSON) → teks naratif. Dipakai
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
    public function readFile(string $bytes, string $mime, string $instruction, string $filename = 'doc'): array
    {
        $dataUrl = 'data:'.$mime.';base64,'.base64_encode($bytes);
        $userMessage = [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $instruction],
                str_starts_with($mime, 'image/')
                    ? ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]]
                    : ['type' => 'file', 'file' => ['filename' => $filename, 'file_data' => $dataUrl]],
            ],
        ];

        $turn = AiProviderFactory::make()->chat([$userMessage], []);

        return $this->decodeJson((string) $turn->text);
    }

    /**
     * Instruksi + teks dokumen (mis. hasil PdfTextExtractor) → balasan model di-decode sebagai JSON.
     *
     * @return array<mixed>
     */
    public function readText(string $instruction, string $text): array
    {
        $turn = AiProviderFactory::make()->chat([
            ['role' => 'system', 'content' => $instruction],
            ['role' => 'user', 'content' => $text],
        ], []);

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
     * ```json ... ``` — lepas pagar itu (kalau ada) sebelum decode — atau
     * menyelipkan JSON di antara kalimat ("Berikut datanya: {...}") — ambil
     * dari "{" pertama sampai "}" terakhir. Balikin array kosong bila hasil
     * decode bukan array (gagal parse / bukan objek).
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
        if (! is_array($decoded) && ($awal = strpos($text, '{')) !== false && ($akhir = strrpos($text, '}')) > $awal) {
            $decoded = json_decode(substr($text, $awal, $akhir - $awal + 1), true);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
