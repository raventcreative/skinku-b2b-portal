<?php

namespace Tests\Feature;

use App\Services\Ai\AiTurn;
use App\Services\AiDiscoveryService;
use App\Services\Discovery\DiscoveryException;
use App\Services\Discovery\TavilyProvider;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAiProvider;
use Tests\Support\FakeWebSearchProvider;
use Tests\TestCase;

/**
 * Otak Rekomendasi AI: TavilyProvider (normalisasi hasil) + AiDiscoveryService
 * (rangkum hasil ASLI jadi kandidat/tren, anti-ngarang). Semua tanpa jaringan.
 */
class AiDiscoveryServiceTest extends TestCase
{
    // ---- TavilyProvider (mesin pencari) --------------------------------

    public function test_tavily_menormalkan_hasil_dan_buang_tanpa_url(): void
    {
        Http::fake(['api.tavily.com/*' => Http::response([
            'results' => [
                ['title' => 'Top skincare TikTokers', 'url' => 'https://ex.com/a', 'content' => 'daftar'],
                ['title' => 'Tanpa URL', 'content' => 'harus dibuang'],
            ],
        ])]);

        $out = (new TavilyProvider('tvly-xxx'))->search('skincare influencer', 5);

        $this->assertCount(1, $out);
        $this->assertSame('https://ex.com/a', $out[0]['url']);
        $this->assertSame('Top skincare TikTokers', $out[0]['title']);
    }

    public function test_tavily_key_kosong_lempar_exception(): void
    {
        $this->expectException(DiscoveryException::class);
        (new TavilyProvider(''))->search('apa saja');
    }

    public function test_tavily_http_gagal_lempar_exception(): void
    {
        Http::fake(['api.tavily.com/*' => Http::response(['error' => 'no'], 401)]);
        $this->expectException(DiscoveryException::class);
        (new TavilyProvider('tvly-xxx'))->search('apa saja');
    }

    // ---- AiDiscoveryService: KOL ---------------------------------------

    public function test_discover_kol_rangkum_kandidat_dan_buang_tanpa_link(): void
    {
        $search = new FakeWebSearchProvider([
            ['title' => 'skincarequeen', 'url' => 'https://tiktok.com/@skincarequeen', 'content' => '120rb follower'],
        ]);
        // AI balikin 1 kandidat valid + 1 tanpa url (harus dibuang) + 1 tanpa username.
        $ai = new FakeAiProvider([new AiTurn(text: json_encode(['kandidat' => [
            ['username' => '@skincarequeen', 'platform' => 'tiktok', 'followers_est' => 120000, 'kategori' => 'jerawat', 'url' => 'https://tiktok.com/@skincarequeen', 'alasan' => 'engagement bagus'],
            ['username' => 'nolink', 'platform' => 'tiktok', 'followers_est' => 50000, 'kategori' => 'x', 'url' => '', 'alasan' => 'y'],
            ['username' => '', 'platform' => 'tiktok', 'url' => 'https://ex.com/z'],
        ]]))]);

        $svc = new AiDiscoveryService($search, $ai);
        $out = $svc->discoverKols(['kategori' => 'jerawat', 'follower_min' => 50000, 'follower_max' => 200000]);

        $this->assertCount(1, $out['candidates']);
        $c = $out['candidates'][0];
        $this->assertSame('skincarequeen', $c['username']);   // '@' dibuang
        $this->assertSame('tiktok', $c['platform']);
        $this->assertSame(120000, $c['followers_est']);
        $this->assertStringContainsString('jerawat', $out['query']);   // brief masuk query
    }

    public function test_discover_kol_hasil_kosong_tak_panggil_ai(): void
    {
        $search = new FakeWebSearchProvider([]); // web tak nemu apa-apa
        $ai = new FakeAiProvider([new AiTurn(text: 'TIDAK BOLEH DIPANGGIL')]);

        $out = (new AiDiscoveryService($search, $ai))->discoverKols(['keyword' => 'xyz']);

        $this->assertSame([], $out['candidates']);
        $this->assertSame([], $ai->sent); // AI tak dipanggil saat hasil web kosong
    }

    // ---- AiDiscoveryService: Tren produk -------------------------------

    public function test_product_trends_rangkum_poin_dengan_sumber(): void
    {
        $search = new FakeWebSearchProvider([
            ['title' => 'Tren barrier repair 2026', 'url' => 'https://ex.com/tren', 'content' => 'ceramide naik'],
        ]);
        $ai = new FakeAiProvider([new AiTurn(text: '```json'."\n".json_encode([
            'ringkasan' => 'Barrier repair lagi naik.',
            'poin' => [
                ['judul' => 'Ceramide', 'detail' => 'permintaan naik', 'sumber' => ['https://ex.com/tren', 'bukan-url']],
                ['judul' => '', 'detail' => 'poin tanpa judul dibuang', 'sumber' => ['https://ex.com/x']],
            ],
        ])."\n".'```')]);

        $out = (new AiDiscoveryService($search, $ai))->productTrends('serum');

        $this->assertSame('Barrier repair lagi naik.', $out['ringkasan']);
        $this->assertCount(1, $out['poin']);                       // poin tanpa judul dibuang
        $this->assertSame('Ceramide', $out['poin'][0]['judul']);
        $this->assertSame(['https://ex.com/tren'], $out['poin'][0]['sumber']); // 'bukan-url' disaring
    }

    public function test_discover_kol_teruskan_error_pencarian(): void
    {
        $search = new FakeWebSearchProvider([], throw: 'TAVILY_API_KEY belum diisi di .env server.');
        $this->expectException(DiscoveryException::class);
        (new AiDiscoveryService($search, new FakeAiProvider([])))->discoverKols([]);
    }
}
