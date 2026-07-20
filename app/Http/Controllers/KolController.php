<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KolController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->only(['level', 'kategori', 'status']);

        $kols = Kol::query()
            ->with('latestScreening')
            ->when($filters['kategori'] ?? null, fn ($q, $v) => $q->where('kategori', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('tiktok_username')
            ->get();

        // Level = accessor (turunan followers), bukan kolom — jadi filter level
        // dijalankan di koleksi, bukan SQL. Skala data KOL (ratusan) aman.
        if ($filters['level'] ?? null) {
            $kols = $kols->filter(fn (Kol $k) => $k->level === $filters['level'])->values();
        }

        return view('kols.index', [
            'kols' => $kols,
            'filters' => $filters,
            'levels' => ['Nano', 'Mikro', 'Middle', 'Makro', 'Mega', 'Super Mega'],
            'kategoriList' => config('kol.kategori'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tiktok_username' => ['required', 'string', 'max:100', 'unique:kols,tiktok_username'],
            'tiktok_link' => ['nullable', 'url', 'max:255'],
            'followers' => ['required', 'integer', 'min:0'],
            'kategori' => ['nullable', 'string', 'max:100'],
            'provinsi' => ['nullable', 'string', 'max:100'],
            'catatan' => ['nullable', 'string', 'max:2000'],
        ]);

        $kol = Kol::create($data);

        AuditService::log(
            action: 'create_kol',
            targetType: 'kol',
            targetId: $kol->id,
            after: ['username' => $kol->tiktok_username, 'followers' => $kol->followers],
        );

        return redirect()->route('kols.show', $kol)
            ->with('status', "KOL @{$kol->tiktok_username} ditambahkan (level {$kol->level}).");
    }

    public function show(Kol $kol)
    {
        $kol->load(['screenings', 'deals.pic']);

        return view('kols.show', [
            'kol' => $kol,
            'kategoriList' => config('kol.kategori'),
        ]);
    }

    public function update(Request $request, Kol $kol): RedirectResponse
    {
        $data = $request->validate([
            'tiktok_link' => ['nullable', 'url', 'max:255'],
            'followers' => ['required', 'integer', 'min:0'],
            'kategori' => ['nullable', 'string', 'max:100'],
            'provinsi' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::in(Kol::STATUSES)],
            'catatan' => ['nullable', 'string', 'max:2000'],
        ]);

        $before = $kol->only(array_keys($data));
        $kol->update($data);

        AuditService::log(
            action: 'update_kol',
            targetType: 'kol',
            targetId: $kol->id,
            before: $before,
            after: $data,
        );

        return back()->with('status', 'Data KOL diperbarui.');
    }
}
