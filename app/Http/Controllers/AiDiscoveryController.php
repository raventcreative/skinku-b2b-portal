<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiException;
use App\Services\AiDiscoveryService;
use App\Services\AuditService;
use App\Services\Discovery\DiscoveryException;
use App\Services\Discovery\WebSearchFactory;
use App\Services\KolService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rekomendasi AI (Discovery): cari KOL & tren produk dari web (Tavily) lalu
 * dirangkum AI. Dua tab dalam satu halaman; hasil dirender balik ke halaman yang
 * sama (bukan redirect) supaya form tetap terisi. Kandidat KOL bisa 1-klik masuk
 * Database KOL sebagai prospek (dijaga izin kol.screening.manage di rute).
 */
class AiDiscoveryController extends Controller
{
    public function __construct(private KolService $kol) {}

    public function index()
    {
        return view('discovery.index', $this->base());
    }

    public function searchKol(Request $request, AiDiscoveryService $svc)
    {
        $brief = $request->validate([
            'kategori' => ['nullable', 'string', 'max:100'],
            'platform' => ['nullable', Rule::in(array_keys(config('kol.platforms')))],
            'region' => ['nullable', 'string', 'max:100'],
            'follower_min' => ['nullable', 'integer', 'min:0'],
            'follower_max' => ['nullable', 'integer', 'min:0'],
            'keyword' => ['nullable', 'string', 'max:150'],
        ]);

        $data = $this->base();
        $data['tab'] = 'kol';
        $data['kolBrief'] = $brief;

        try {
            $data['kolResult'] = $svc->discoverKols($brief);
        } catch (DiscoveryException|AiException $e) {
            $data['kolError'] = $e->getMessage();
        }

        return view('discovery.index', $data);
    }

    public function searchProduct(Request $request, AiDiscoveryService $svc)
    {
        $validated = $request->validate(['topik' => ['required', 'string', 'max:200']]);

        $data = $this->base();
        $data['tab'] = 'produk';
        $data['produkTopik'] = $validated['topik'];

        try {
            $data['produkResult'] = $svc->productTrends($validated['topik']);
        } catch (DiscoveryException|AiException $e) {
            $data['produkError'] = $e->getMessage();
        }

        return view('discovery.index', $data);
    }

    public function addKol(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'platform' => ['nullable', Rule::in(array_keys(config('kol.platforms')))],
            'url' => ['nullable', 'url', 'max:255'],
            'followers' => ['nullable', 'integer', 'min:0'],
            'kategori' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->kol->createProspek([
            'username' => $data['username'],
            'platform' => $data['platform'] ?? 'tiktok',
            'tiktok_link' => $data['url'] ?? null,
            'followers' => $data['followers'] ?? 0,
            'kategori' => $data['kategori'] ?? null,
        ]);

        $kol = $result['kol'];

        if ($result['created']) {
            AuditService::log(
                action: 'create_kol',
                targetType: 'kol',
                targetId: $kol->id,
                after: ['username' => $kol->tiktok_username, 'status' => $kol->status, 'via' => 'discovery'],
            );
        }

        return redirect()->route('kols.show', $kol->id)->with('status', $result['created']
            ? 'KOL @'.$kol->tiktok_username.' ditambahkan sebagai prospek — lanjut screening di bawah.'
            : 'KOL @'.$kol->tiktok_username.' sudah ada di database.');
    }

    /** Var dasar yang selalu dibutuhkan view (tab + hasil null di GET). */
    private function base(): array
    {
        return [
            'configured' => WebSearchFactory::configured() && filled(config('services.ai.openai.key')),
            'kategoriList' => config('kol.kategori'),
            'platforms' => config('kol.platforms'),
            'tab' => 'kol',
            'kolBrief' => null,
            'kolResult' => null,
            'kolError' => null,
            'produkTopik' => null,
            'produkResult' => null,
            'produkError' => null,
        ];
    }
}
