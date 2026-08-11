<?php

namespace App\Http\Controllers;

use App\Services\NetworkSummaryService;
use Illuminate\Http\Request;

/**
 * "Jaringan Saya" — mitra upline memantau ringkasan performa subtree-nya.
 * Read-only, agregat; TANPA nama/kontak customer downline (privasi antar-mitra).
 * HQ tak memakai halaman ini (punya god-view di Struktur Jaringan + laporan).
 */
class JaringanSayaController extends Controller
{
    public function __construct(private NetworkSummaryService $summary) {}

    public function index(Request $request)
    {
        $me = $request->user();
        abort_unless($me->isPartner(), 403, 'Hanya mitra yang memiliki halaman jaringan.');

        $payload = $this->summary->summarize($me);

        return view('jaringan_saya.index', $payload);
    }
}
