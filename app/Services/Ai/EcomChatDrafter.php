<?php

namespace App\Services\Ai;

use App\Models\AiKnowledge;
use App\Models\EcomChatConversation;
use Throwable;

/**
 * Menyusun draft balasan CS dari pengetahuan Chat E-commerce + riwayat pesan.
 * Output selalu terstruktur {reply, decision, reason}. Fail-safe: apa pun yang
 * meragukan (error provider, JSON rusak, reply kosong, decision asing) → to_staff,
 * TAK PERNAH mengarang auto-send.
 */
class EcomChatDrafter
{
    /** Jumlah pesan terakhir yang diberikan sebagai konteks. */
    private const HISTORY = 12;

    public function __construct(private AiProvider $provider) {}

    /** @return array{reply:string,decision:string,reason:string} */
    public function draft(EcomChatConversation $conv): array
    {
        $riwayat = $conv->messages()->orderBy('id')->get()->slice(-self::HISTORY)
            ->map(fn ($m) => ['role' => $m->sender === 'buyer' ? 'user' : 'assistant', 'content' => (string) $m->text])
            ->values()->all();

        return $this->jalankan(AiKnowledge::document('chat'), $riwayat);
    }

    /**
     * Uji chat pembeli dari halaman Pengetahuan AI: pengetahuan = isian form (boleh belum disimpan), riwayat = chat
     * uji. Prompt, aturan keputusan & fail-safe SAMA dgn draft() → hasil uji = balasan yang diterima pembeli.
     *
     * @param  array<int,array{dari:string,teks:string}>  $pesan  dari: pembeli | ai
     * @return array{reply:string,decision:string,reason:string}
     */
    public function uji(string $pengetahuan, array $pesan): array
    {
        $riwayat = array_map(fn (array $p) => ['role' => $p['dari'] === 'pembeli' ? 'user' : 'assistant', 'content' => (string) $p['teks']],
            array_slice(array_values($pesan), -self::HISTORY));

        return $this->jalankan($pengetahuan, $riwayat);
    }

    /**
     * @param  array<int,array{role:string,content:string}>  $riwayat
     * @return array{reply:string,decision:string,reason:string}
     */
    private function jalankan(string $pengetahuan, array $riwayat): array
    {
        try {
            $turn = $this->provider->chat([['role' => 'system', 'content' => $this->systemPrompt($pengetahuan)], ...$riwayat], []);
            $parsed = $this->parse((string) ($turn->text ?? ''));
        } catch (Throwable $e) {
            return $this->escalate('AI gagal: '.$e->getMessage());
        }

        if ($parsed === null) {
            return $this->escalate('Balasan AI tak terbaca (JSON rusak).');
        }

        // reply/decision WAJIB string. JSON valid tapi bentuknya salah (mis. objek/array)
        // adalah kasus meragukan juga → to_staff, jangan sampai (string) $array jadi "Array".
        if (! is_string($parsed['reply'] ?? null) || ! is_string($parsed['decision'] ?? null)) {
            return $this->escalate('Bentuk field reply/decision dari AI tak valid (bukan string).');
        }

        $decision = $parsed['decision'];
        $reply = trim($parsed['reply']);

        if ($decision === 'auto_send' && $reply !== '') {
            return ['reply' => $reply, 'decision' => 'auto_send', 'reason' => (string) ($parsed['reason'] ?? '')];
        }

        // to_staff (atau apa pun selain auto_send valid): simpan reply sbg draft usulan.
        return ['reply' => $reply, 'decision' => 'to_staff', 'reason' => (string) ($parsed['reason'] ?? 'diteruskan ke staf')];
    }

    private function systemPrompt(string $knowledge): string
    {
        $kb = $knowledge !== '' ? $knowledge : '(Belum ada pengetahuan chat diisi.)';

        return <<<TXT
        Kamu customer service SKINKU (skincare) yang membalas chat pembeli di marketplace.
        Jawab HANYA berdasarkan PENGETAHUAN di bawah. Ramah, singkat, Bahasa Indonesia, panggil "Kak".

        # PENGETAHUAN
        {$kb}

        # ATURAN KEPUTUSAN (decision)
        - "auto_send" HANYA untuk pertanyaan umum/kebijakan/FAQ yang jawabannya JELAS ada di pengetahuan
          (cara batal umum, kebijakan retur/refund/ongkir, cara lacak, jam kirim, info produk).
        - "to_staff" untuk: pesanan/komplain SPESIFIK milik pembeli (mis. "batalin order SAYA #123",
          "barang saya rusak"), butuh data order/tindakan, nego harga, pembeli minta bicara manusia/CS,
          ATAU kamu ragu / jawabannya tak ada di pengetahuan. Kalau ragu → to_staff.
        - JANGAN mengarang fakta/janji/diskon yang tak ada di pengetahuan.

        # FORMAT OUTPUT (WAJIB JSON valid, tanpa teks lain)
        {"reply": "<balasan buat pembeli>", "decision": "auto_send" | "to_staff", "reason": "<alasan singkat>"}
        Untuk to_staff, "reply" boleh berisi usulan draft (staf akan meninjau) atau string kosong.
        TXT;
    }

    /** @return array<string,mixed>|null */
    private function parse(string $text): ?array
    {
        $text = trim($text);
        // Buang code fence ```json ... ```
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
        // Ambil dari { pertama sampai } terakhir (jaga bila ada teks pembungkus).
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $json = substr($text, $start, $end - $start + 1);
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /** @return array{reply:string,decision:string,reason:string} */
    private function escalate(string $reason): array
    {
        return ['reply' => '', 'decision' => 'to_staff', 'reason' => $reason];
    }
}
