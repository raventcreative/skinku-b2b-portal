<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;

class DownlineOrderController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $orders = PurchaseOrder::query()
            ->where('seller_id', $user->id)                 // KUNCI: hanya pesanan di mana dia penjual
            ->with('user')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('pesanan_downline.index', ['orders' => $orders]);
    }
}
