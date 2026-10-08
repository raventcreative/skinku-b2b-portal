<?php

namespace App\Http\Controllers;

use App\Services\CommissionService;
use Illuminate\Http\Request;

class RecruitController extends Controller
{
    public function __construct(private CommissionService $commissions) {}

    /**
     * "Rekrutan Saya" — perekrut (sponsor / GD / distributor yang merekrut) lihat
     * daftar lead-nya + earning (join + RO cashback). Tarik dana lewat Saldo Komisi.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isPartner(), 403);

        // Rekrutan + earning per rekrutan (join + ro_cashback) — sama dgn alat Asisten AI rekrutan_saya.
        return view('rekrutan_saya.index', $this->commissions->ringkasanRekrutan($user));
    }
}
