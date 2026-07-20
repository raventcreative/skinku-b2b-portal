<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Models\KolScreening;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KolScreeningController extends Controller
{
    public function create(Request $request)
    {
        // Daftar KOL existing untuk autocomplete — supaya username lama tak
        // diketik ulang salah eja (yang bikin duplikat).
        return view('kols.screening_form', [
            'kols' => Kol::orderBy('tiktok_username')
                ->get(['id', 'tiktok_username', 'tiktok_link', 'followers', 'kategori', 'provinsi', 'agency']),
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
            'tiktok_link' => ['nullable', 'url', 'max:255'],
            'followers' => ['required', 'integer', 'min:0'],
            'kategori' => ['nullable', 'string', 'max:100'],
            'provinsi' => ['nullable', 'string', 'max:100'],
            'agency' => ['nullable', 'string', 'max:150'],
            'tanggal_listing' => ['required', 'date', 'before_or_equal:today'],
            'ratecard' => ['nullable', 'integer', 'min:0'],   // opsional: harga sering baru ada setelah nego
        ];
        for ($i = 1; $i <= 7; $i++) {
            $rules["views_{$i}"] = ['required', 'integer', 'min:0'];
        }

        $data = $request->validate($rules);
        $username = ltrim(trim($data['tiktok_username']), '@');   // "@nama" dan "nama" = orang yang sama

        $screening = DB::transaction(function () use ($data, $username, $request) {
            $kol = Kol::where('tiktok_username', $username)->first();

            if (! $kol) {
                $kol = Kol::create([
                    'tiktok_username' => $username,
                    'tiktok_link' => $data['tiktok_link'] ?? null,
                    'followers' => $data['followers'],
                    'kategori' => $data['kategori'] ?? null,
                    'provinsi' => $data['provinsi'] ?? null,
                    'agency' => $data['agency'] ?? null,
                ]);

                AuditService::log(
                    action: 'create_kol',
                    targetType: 'kol',
                    targetId: $kol->id,
                    after: ['username' => $kol->tiktok_username, 'followers' => $kol->followers, 'via' => 'screening'],
                );
            } else {
                // Isi hanya yang dikirim; jangan menimpa kategori/link lama dengan kosong.
                $kol->update(array_filter([
                    'followers' => $data['followers'],
                    'tiktok_link' => $data['tiktok_link'] ?? null,
                    'kategori' => $data['kategori'] ?? null,
                    'provinsi' => $data['provinsi'] ?? null,
                    'agency' => $data['agency'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''));
            }

            return KolScreening::create([
                'kol_id' => $kol->id,
                'tanggal_listing' => $data['tanggal_listing'],
                'ratecard' => $data['ratecard'] ?? null,
                ...collect(range(1, 7))->mapWithKeys(fn ($i) => ["views_{$i}" => $data["views_{$i}"]])->all(),
                'created_by' => $request->user()->id,
            ]);
        });

        AuditService::log(
            action: 'create_kol_screening',
            targetType: 'kol_screening',
            targetId: $screening->id,
            after: [
                'kol' => $screening->kol->tiktok_username,
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
