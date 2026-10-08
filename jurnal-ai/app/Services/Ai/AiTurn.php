<?php

namespace App\Services\Ai;

/** Satu balasan model — teks mentah + jejak model mana yang menjawab. */
final class AiTurn
{
    public function __construct(
        public readonly string $text,
        public readonly string $model = '',
    ) {}

    /**
     * Decode balasan sebagai JSON, toleran terhadap pagar kode ```json dan
     * teks pengantar/penutup di luar objek. Balikin array kosong kalau gagal.
     *
     * @return array<mixed>
     */
    public function json(): array
    {
        $text = trim($this->text);

        if (preg_match('/```[a-zA-Z]*\s*(.*?)\s*```/s', $text, $m) === 1) {
            $text = trim($m[1]);
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Model kadang menambah kalimat pembuka sebelum objeknya. Ambil
        // kurung terluar: dari '{' pertama sampai '}' terakhir.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
