<?php

namespace App\Services\Ai;

use App\Models\AiKnowledge;
use Throwable;

/**
 * Menilai draft konten (teks saja: caption, hashtag, tipe, platform) sebelum terbit — untuk TikTok & Instagram.
 * Hanya memberi skor & saran; tak pernah mengubah/menerbitkan konten. Output terstruktur, fail-safe:
 * error provider / JSON rusak → AiException dengan pesan jelas, bukan skor karangan.
 */
class ContentReviewer
{
    public const PLATFORMS = ['tiktok' => 'TikTok', 'instagram' => 'Instagram'];

    public function __construct(private AiProvider $provider) {}

    /**
     * @param  array<string,string>  $captions  caption khusus per platform (kosong = pakai caption utama)
     * @return array{score:int, summary:string, platforms:array<string,array{score:int,notes:list<string>}>, suggestions:list<string>, caption:string}
     *
     * @throws AiException
     */
    public function review(string $type, string $caption, array $platforms, array $captions = []): array
    {
        $targets = array_values(array_intersect(array_keys(self::PLATFORMS), $platforms)) ?: array_keys(self::PLATFORMS);
        $draft = collect($targets)->map(fn ($p) => '## '.self::PLATFORMS[$p]."\n".(trim($captions[$p] ?? '') ?: $caption))->implode("\n\n");

        try {
            $turn = $this->provider->chat([
                ['role' => 'system', 'content' => $this->systemPrompt($targets)],
                ['role' => 'user', 'content' => "Tipe konten: {$type}\n\n{$draft}"],
            ], []);
        } catch (Throwable $e) {
            throw new AiException('AI gagal menilai: '.$e->getMessage(), previous: $e);
        }

        $data = self::parse((string) ($turn->text ?? ''));
        if (! is_array($data) || ! is_numeric($data['score'] ?? null)) {
            throw new AiException('Jawaban AI tak terbaca — coba nilai ulang.');
        }

        $platformsOut = [];
        foreach ($targets as $p) {
            $row = (array) ($data['platforms'][$p] ?? []);
            $platformsOut[$p] = ['score' => self::clamp($row['score'] ?? $data['score']), 'notes' => self::strings($row['notes'] ?? [])];
        }

        return [
            'score' => self::clamp($data['score']),
            'summary' => is_string($data['summary'] ?? null) ? trim($data['summary']) : '',
            'platforms' => $platformsOut,
            'suggestions' => self::strings($data['suggestions'] ?? []),
            'caption' => is_string($data['caption'] ?? null) ? trim($data['caption']) : '',
        ];
    }

    private function systemPrompt(array $targets): string
    {
        $brand = AiKnowledge::document('sistem') ?: '(Belum ada pengetahuan bisnis diisi.)';
        $names = collect($targets)->map(fn ($p) => self::PLATFORMS[$p])->implode(' & ');
        $keys = collect($targets)->map(fn ($p) => "\"{$p}\": {\"score\": 0-100, \"notes\": [\"…\"]}")->implode(', ');

        return <<<TXT
        Kamu content strategist SKINKU (skincare & bodycare Indonesia) yang menilai DRAFT konten {$names} sebelum terbit.
        Nilai dari teksnya saja (kamu tidak melihat video/gambar). Bahasa Indonesia, ringkas, praktis.

        # KONTEKS BISNIS
        {$brand}

        # KRITERIA
        - Hook: kalimat pertama memancing berhenti scroll?
        - Kejelasan pesan & manfaat produk; CTA jelas (komentar, simpan, klik link/keranjang).
        - Hashtag: relevan, tidak berlebihan (TikTok 3–5, Instagram 5–15), campuran niche & umum.
        - Kesesuaian platform: TikTok santai & to the point; Instagram boleh lebih panjang, rapi, ada jeda baris.
        - Aman: tanpa klaim medis berlebihan/janji hasil pasti (mis. "putih permanen", "sembuh"), tanpa info yang tak ada di konteks.

        # FORMAT OUTPUT (WAJIB JSON valid, tanpa teks lain)
        {"score": 0-100, "summary": "<1–2 kalimat>", "platforms": {{$keys}}, "suggestions": ["<saran konkret>", "…"], "caption": "<usulan caption perbaikan, siap pakai>"}
        TXT;
    }

    private static function clamp(mixed $v): int
    {
        return max(0, min(100, (int) round((float) $v)));
    }

    /** @return list<string> */
    private static function strings(mixed $v): array
    {
        return array_values(array_slice(array_filter(array_map(fn ($s) => is_string($s) ? trim($s) : '', (array) $v)), 0, 8));
    }

    /** @return array<string,mixed>|null */
    private static function parse(string $text): ?array
    {
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)) ?? $text;
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($data) ? $data : null;
    }
}
