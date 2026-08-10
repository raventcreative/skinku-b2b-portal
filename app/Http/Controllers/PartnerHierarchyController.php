<?php

namespace App\Http\Controllers;

use App\Models\User;

class PartnerHierarchyController extends Controller
{
    public function index()
    {
        $roots = User::where('role', User::ROLE_GRAND_DISTRIBUTOR)
            ->whereNull('upline_id')
            ->with('downlines.downlines') // distributor -> reseller
            ->orderBy('fullname')
            ->get();

        $unplaced = User::whereIn('role', [
            User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER,
            User::ROLE_RESELLER_BRONZE, User::ROLE_RESELLER_GOLD,
        ])->whereNull('upline_id')->orderBy('fullname')->get();

        return view('struktur_jaringan.index', ['roots' => $roots, 'unplaced' => $unplaced]);
    }
}
