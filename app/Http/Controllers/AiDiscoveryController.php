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

    /**
     * Tambah KOL BORONGAN dari daftar username yang di-paste (hasil discovery di
     * TikTok One / FastMoss / Creative Center — sumber mana pun). Tiap baris boleh
     * berupa "name", "@name", atau URL profil; di-dedupe & jadi prospek.
     */
    public function bulkAddKol(Request $request)
    {
        $data = $request->validate([
            'platform' => ['nullable', Rule::in(array_keys(config('kol.platforms')))],
            'kategori' => ['nullable', 'string', 'max:100'],
            'daftar' => ['required', 'string', 'max:20000'],
        ]);

        $handles = array_slice($this->parseHandles($data['daftar']), 0, 500);
        $created = 0;
        $existing = 0;

        foreach ($handles as $handle) {
            try {
                $res = $this->kol->createProspek([
                    'username' => $handle,
                    'platform' => $data['platform'] ?? 'tiktok',
                    'kategori' => $data['kategori'] ?? null,
                ]);
                $res['created'] ? $created++ : $existing++;
            } catch (\Throwable) {
                // Satu baris aneh tak boleh menggagalkan seluruh tempelan.
            }
        }

        // Satu ringkasan audit (bukan per-KOL) supaya log tak dibanjiri.
        AuditService::log(
            action: 'bulk_create_kol',
            targetType: 'kol',
            targetId: 0,
            after: ['created' => $created, 'existing' => $existing, 'via' => 'discovery_paste'],
        );

        return redirect()->route('discovery.index', ['tab' => 'massal'])->with('status',
            "{$created} KOL ditambahkan sebagai prospek".
            ($existing ? ", {$existing} sudah ada (dilewati)" : '').
            '. Buka Database KOL untuk screening.');
    }

    /**
     * Ekstrak handle dari tiap baris — terima "name", "@name", atau URL profil
     * (tiktok.com/@name, instagram.com/name). Dedupe abaikan huruf besar/kecil.
     *
     * @return array<int,string>
     */
    private function parseHandles(string $raw): array
    {
        $handles = [];
        foreach (preg_split('/[\r\n]+/', $raw) ?: [] as $line) {
            $line = trim((string) preg_replace('/[?#].*$/', '', trim($line)));
            if ($line === '') {
                continue;
            }
            if (str_contains($line, '/')) {
                if (preg_match('~@([A-Za-z0-9._]+)~', $line, $m)) {
                    $line = $m[1];
                } else {
                    $line = trim($line, '/');
                    $line = substr($line, ($p = strrpos($line, '/')) === false ? 0 : $p + 1);
                }
            }
            $line = ltrim($line, '@');
            if (preg_match('/^[A-Za-z0-9._]{2,100}$/', $line)) {
                $key = mb_strtolower($line); // dedupe abaikan huruf besar/kecil
                if (! isset($handles[$key])) {
                    $handles[$key] = $line; // simpan ejaan yang PERTAMA muncul
                }
            }
        }

        return array_values($handles);
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
            'tab' => in_array(request('tab'), ['kol', 'produk', 'massal'], true) ? request('tab') : 'kol',
            'kolBrief' => null,
            'kolResult' => null,
            'kolError' => null,
            'produkTopik' => null,
            'produkResult' => null,
            'produkError' => null,
        ];
    }
}
