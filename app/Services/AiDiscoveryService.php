<?php

namespace App\Services;

use App\Services\Ai\AiProvider;
use App\Services\Discovery\WebSearchProvider;

/**
 * Rekomendasi AI (Discovery): cari di web (WebSearchProvider) lalu minta AI
 * merangkum hasil ASLI jadi kandidat KOL atau laporan tren produk.
 *
 * Prinsip anti-ngarang: AI hanya boleh memakai potongan hasil pencarian yang
 * diberikan, WAJIB menyertakan link sumber, dan kandidat/poin tanpa URL dibuang
 * di sisi kita — supaya tak ada username/angka/tren fiktif yang lolos.
 */
class AiDiscoveryService
{
    public function __construct(
        private WebSearchProvider $search,
        private AiProvider $ai,
    ) {}

    /**
     * Cari kandidat KOL/influencer dari web sesuai brief.
     *
     * @param  array{kategori?:?string, platform?:?string, region?:?string, follower_min?:int|null, follower_max?:int|null, keyword?:?string}  $brief
     * @return array{query:string, candidates:array<int,array{username:string,platform:string,followers_est:?int,kategori:string,url:string,alasan:string}>}
     */
    public function discoverKols(array $brief): array
    {
        $query = $this->kolQuery($brief);
        $results = $this->search->search($query, 8);

        if ($results === []) {
            return ['query' => $query, 'candidates' => []];
        }

        $turn = $this->ai->chat([
            ['role' => 'system', 'content' => $this->kolSystemPrompt()],
            ['role' => 'user', 'content' => $this->kolUserPrompt($brief, $results)],
        ], []);

        $data = $this->decodeJson((string) $turn->text);
        $candidates = [];
        foreach ($data['kandidat'] ?? [] as $c) {
            $source = trim((string) ($c['url'] ?? ''));
            $username = ltrim(trim((string) ($c['username'] ?? '')), '@');
            if ($source === '' || $username === '') {
                continue; // anti-ngarang: tanpa link sumber / tanpa username → buang
            }
            $followers = $c['followers_est'] ?? null;
            $platform = $this->normalizePlatform((string) ($c['platform'] ?? ''));
            $candidates[] = [
                'username' => $username,
                'platform' => $platform,
                'followers_est' => is_numeric($followers) ? (int) $followers : null,
                'kategori' => trim((string) ($c['kategori'] ?? '')),
                // Link @username → profil (dirakit dari handle, pola sama dgn Kol);
                // source_url = tempat ditemukan (artikel/daftar) untuk verifikasi.
                'profile_url' => $this->profileUrl($platform, $username) ?: $source,
                'source_url' => $source,
                'alasan' => trim((string) ($c['alasan'] ?? '')),
            ];
        }

        return ['query' => $query, 'candidates' => array_slice($candidates, 0, 12)];
    }

    /**
     * Laporan tren produk/pasar skincare (read-only) untuk sebuah topik.
     *
     * @return array{query:string, ringkasan:string, poin:array<int,array{judul:string,detail:string,sumber:array<int,string>}>}
     */
    public function productTrends(string $topic): array
    {
        $query = trim($topic).' tren skincare beauty Indonesia terbaru';
        $results = $this->search->search($query, 8);

        if ($results === []) {
            return ['query' => $query, 'ringkasan' => '', 'poin' => []];
        }

        $turn = $this->ai->chat([
            ['role' => 'system', 'content' => $this->trendSystemPrompt()],
            ['role' => 'user', 'content' => $this->trendUserPrompt($topic, $results)],
        ], []);

        $data = $this->decodeJson((string) $turn->text);
        $poin = [];
        foreach ($data['poin'] ?? [] as $p) {
            $judul = trim((string) ($p['judul'] ?? ''));
            if ($judul === '') {
                continue;
            }
            $sumber = collect($p['sumber'] ?? [])
                ->map(fn ($s) => trim((string) $s))
                ->filter(fn ($s) => str_starts_with($s, 'http'))
                ->values()->all();
            $poin[] = [
                'judul' => $judul,
                'detail' => trim((string) ($p['detail'] ?? '')),
                'sumber' => $sumber,
            ];
        }

        return [
            'query' => $query,
            'ringkasan' => trim((string) ($data['ringkasan'] ?? '')),
            'poin' => array_slice($poin, 0, 12),
        ];
    }

    private function kolQuery(array $brief): string
    {
        // "daftar/rekomendasi" sengaja: hasil listicle (artikel yang MENYEBUT banyak
        // influencer) jauh lebih kaya kandidat daripada satu halaman profil.
        $parts = ['daftar rekomendasi influencer skincare beauty'];
        if (filled($brief['kategori'] ?? null)) {
            $parts[] = (string) $brief['kategori'];
        }
        if (filled($brief['keyword'] ?? null)) {
            $parts[] = (string) $brief['keyword'];
        }
        $platform = strtolower((string) ($brief['platform'] ?? ''));
        $parts[] = in_array($platform, ['instagram', 'youtube'], true) ? $platform : 'TikTok';
        $parts[] = filled($brief['region'] ?? null) ? (string) $brief['region'] : 'Indonesia';
        if (! empty($brief['follower_min']) || ! empty($brief['follower_max'])) {
            $parts[] = 'follower '.$this->followerBand((int) ($brief['follower_min'] ?? 0), (int) ($brief['follower_max'] ?? 0));
        }

        return implode(' ', $parts);
    }

    private function followerBand(int $min, int $max): string
    {
        $fmt = fn (int $n) => $n >= 1_000_000 ? round($n / 1_000_000, 1).'jt' : round($n / 1000).'rb';
        if ($min && $max) {
            return $fmt($min).'-'.$fmt($max);
        }

        return $min ? 'di atas '.$fmt($min) : 'di bawah '.$fmt($max);
    }

    private function kolSystemPrompt(): string
    {
        return <<<'TXT'
        Kamu asisten riset influencer untuk brand skincare Indonesia (SKINKU).
        Dari HASIL PENCARIAN WEB (termasuk artikel/daftar yang MENYEBUT banyak
        influencer), EKSTRAK setiap KOL/influencer yang relevan dengan brief.
        ATURAN KERAS:
        - HANYA gunakan informasi yang benar-benar muncul di hasil pencarian.
          DILARANG mengarang username atau angka follower.
        - "url" WAJIB diisi salah satu URL hasil pencarian tempat influencer itu
          disebut — BOLEH URL artikel/daftar, tidak harus halaman profil.
        - Jika jumlah follower tidak disebut di hasil, isi null (jangan menebak).
        - Ekstrak sebanyak mungkin kandidat BERBEDA yang relevan (maksimal 12).
        Balas HANYA JSON valid (tanpa teks lain, tanpa markdown) dengan bentuk:
        {"kandidat":[{"username":"tanpa @","platform":"tiktok|instagram|youtube",
        "followers_est":123000,"kategori":"mis. skincare","url":"https://...(sumber)",
        "alasan":"1 kalimat kenapa relevan"}]}
        TXT;
    }

    private function kolUserPrompt(array $brief, array $results): string
    {
        return 'BRIEF: '.json_encode($this->briefForPrompt($brief), JSON_UNESCAPED_UNICODE)
            ."\n\nHASIL PENCARIAN:\n".$this->renderResults($results);
    }

    private function briefForPrompt(array $brief): array
    {
        return array_filter([
            'kategori' => $brief['kategori'] ?? null,
            'platform' => $brief['platform'] ?? null,
            'region' => $brief['region'] ?? null,
            'follower_min' => $brief['follower_min'] ?? null,
            'follower_max' => $brief['follower_max'] ?? null,
            'keyword' => $brief['keyword'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function trendSystemPrompt(): string
    {
        return <<<'TXT'
        Kamu analis tren pasar skincare/beauty Indonesia untuk brand SKINKU.
        Dari HASIL PENCARIAN WEB yang diberikan, rangkum tren terkini yang relevan
        dengan topik (bahan/ingredient yang naik, tipe produk yang tren, gerakan
        kompetitor). ATURAN KERAS:
        - HANYA gunakan informasi dari hasil pencarian; DILARANG mengarang.
        - Setiap poin WAJIB menyertakan minimal satu "sumber" (URL dari hasil).
        Balas HANYA JSON valid (tanpa teks lain, tanpa markdown) dengan bentuk:
        {"ringkasan":"2-3 kalimat gambaran umum",
        "poin":[{"judul":"judul tren singkat","detail":"penjelasan ringkas",
        "sumber":["https://..."]}]}
        TXT;
    }

    private function trendUserPrompt(string $topic, array $results): string
    {
        return 'TOPIK: '.trim($topic)."\n\nHASIL PENCARIAN:\n".$this->renderResults($results);
    }

    /** Nomori hasil + potong konten panjang biar hemat token. */
    private function renderResults(array $results): string
    {
        $lines = [];
        foreach ($results as $i => $r) {
            $n = $i + 1;
            $content = mb_substr((string) ($r['content'] ?? ''), 0, 500);
            $lines[] = "[{$n}] {$r['title']}\nURL: {$r['url']}\n{$content}";
        }

        return implode("\n\n", $lines);
    }

    /** Rakit URL profil dari platform + handle (pola sama dengan Kol::profileUrl). null bila platform tanpa templat. */
    private function profileUrl(string $platform, string $username): ?string
    {
        $tpl = config("kol.platforms.{$platform}.url");

        return $tpl ? sprintf($tpl, rawurlencode($username)) : null;
    }

    private function normalizePlatform(string $platform): string
    {
        $p = strtolower(trim($platform));

        return in_array($p, ['tiktok', 'instagram', 'youtube', 'shopee'], true) ? $p : 'tiktok';
    }

    /** Ambil JSON walau dibungkus ```json ... ``` atau ada teks di sekitarnya. */
    private function decodeJson(string $raw): array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?|```$/m', '', $raw) ?? $raw;
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }
}
