<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Models\KolScreening;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class KolScreeningController extends Controller
{
    public function create(Request $request)
    {
        return view('kols.screening_form', [
            'kols' => Kol::orderBy('tiktok_username')->get(['id', 'tiktok_username', 'followers']),
            // ?kol= pra-pilih dari halaman detail KOL.
            'selectedKolId' => (int) $request->query('kol', 0),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rules = [
            'kol_id' => ['required', 'integer', 'exists:kols,id'],
            'tanggal_listing' => ['required', 'date', 'before_or_equal:today'],
            'ratecard' => ['required', 'integer', 'min:0'],
        ];
        for ($i = 1; $i <= 7; $i++) {
            $rules["views_{$i}"] = ['required', 'integer', 'min:0'];
        }

        $data = $request->validate($rules);
        $data['created_by'] = $request->user()->id;

        $screening = KolScreening::create($data);

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
}
