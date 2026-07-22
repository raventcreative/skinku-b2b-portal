<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Models\KolScreening;
use App\Services\AuditService;
use App\Services\KolService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KolScreeningController extends Controller
{
    public function __construct(private KolService $kol) {}

    public function create(Request $request)
    {
        // Daftar KOL existing untuk autocomplete — supaya username lama tak
        // diketik ulang salah eja (yang bikin duplikat).
        return view('kols.screening_form', [
            'kols' => Kol::orderBy('tiktok_username')
                ->get(['id', 'tiktok_username', 'platform', 'tiktok_link', 'followers', 'kategori', 'provinsi', 'agency', 'phone']),
            'kategoriList' => config('kol.kategori'),
            // ?kol= pra-isi dari halaman detail KOL.
            'selectedKol' => $request->query('kol') ? Kol::find($request->query('kol')) : null,
        ]);
    }

    /**
     * Satu submit = KOL + screening sekaligus, meniru Excel sumber: di sana satu
     * baris berisi username, link, followers, ratecard, dan 7 views. Memaksa
     * "daftarkan KOL dulu, baru screening" berarti input dua kali — persis yang
     * mau dihilangkan dari Excel.
     *
     * Username yang sudah ada dipakai ulang (tak ada duplikat); yang baru
     * dibuatkan. Followers selalu di-update ke angka terbaru: dia dipakai ratio,
     * dan angka basi bikin ratio menyesatkan.
     */
    public function store(Request $request): RedirectResponse
    {
        $rules = [
            'tiktok_username' => ['required', 'string', 'max:100'],
            'platform' => ['nullable', Rule::in(array_keys(config('kol.platforms')))],
            'tiktok_link' => ['nullable', 'url', 'max:255'],
            'followers' => ['required', 'integer', 'min:0'],
            'kategori' => ['nullable', 'string', 'max:100'],
            'provinsi' => ['nullable', 'string', 'max:100'],
            'agency' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'tanggal_listing' => ['required', 'date', 'before_or_equal:today'],
            'ratecard' => ['nullable', 'integer', 'min:0'],   // opsional: harga sering baru ada setelah nego
        ];
        for ($i = 1; $i <= 7; $i++) {
            $rules["views_{$i}"] = ['required', 'integer', 'min:0'];
        }

        $data = $request->validate($rules);

        // Tulis lewat KolService — logika yang sama dipakai impor massal.
        $result = $this->kol->upsertScreening([
            'username' => $data['tiktok_username'],
            'platform' => $data['platform'] ?? null,
            'tiktok_link' => $data['tiktok_link'] ?? null,
            'followers' => $data['followers'],
            'kategori' => $data['kategori'] ?? null,
            'provinsi' => $data['provinsi'] ?? null,
            'agency' => $data['agency'] ?? null,
            'phone' => $data['phone'] ?? null,
            'tanggal_listing' => $data['tanggal_listing'],
            'ratecard' => $data['ratecard'] ?? null,
            'views' => collect(range(1, 7))->map(fn ($i) => $data["views_{$i}"])->all(),
        ], $request->user()->id);

        $kol = $result['kol'];
        $screening = $result['screening'];

        if ($result['created']) {
            AuditService::log(
                action: 'create_kol',
                targetType: 'kol',
                targetId: $kol->id,
                after: ['username' => $kol->tiktok_username, 'followers' => $kol->followers, 'via' => 'screening'],
            );
        }

        AuditService::log(
            action: 'create_kol_screening',
            targetType: 'kol_screening',
            targetId: $screening->id,
            after: [
                'kol' => $kol->tiktok_username,
                'ratecard' => $screening->ratecard,
                'median_views' => $screening->median_views,
                'cpm_median' => $screening->cpm_median,
                'verdict' => $screening->verdict_median,
            ],
        );

        // Kembali ke detail KOL: riwayat screening di sana menampilkan hasil
        // lengkap (median, CPM, ratio, verdict berwarna).
        return redirect()->route('kols.show', $screening->kol_id)
            ->with('status', 'Screening tersimpan — median '.number_format($screening->median_views, 0, ',', '.')
                ." views, verdict {$screening->verdict_median}.");
    }

    /**
     * Isi ratecard BELAKANGAN — pasangan dari ratecard-opsional saat input.
     * Tanpa jalur ini, screening tanpa harga jadi jalan buntu: verdict/CPM/rank
     * tak pernah bisa muncul setelah nego selesai.
     */
    public function updateRatecard(Request $request, KolScreening $screening): RedirectResponse
    {
        $data = $request->validate(['ratecard' => ['required', 'integer', 'min:0']]);

        $before = $screening->ratecard;
        $screening->update(['ratecard' => $data['ratecard']]);

        AuditService::log(
            action: 'update_kol_screening_ratecard',
            targetType: 'kol_screening',
            targetId: $screening->id,
            before: ['ratecard' => $before],
            after: ['ratecard' => $screening->ratecard, 'verdict' => $screening->verdict_median],
        );

        return back()->with('status', "Ratecard diisi — verdict: {$screening->verdict_median}.");
    }
}
