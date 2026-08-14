<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
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

    public function show(Request $request, PurchaseOrder $purchaseOrder)
    {
        $user = $request->user();
        // INTI KEAMANAN: hanya upline yang JADI PENJUAL di PO ini boleh lihat.
        // seller_id null (PO ke HQ) atau seller_id mitra lain → 403 otomatis.
        abort_unless($purchaseOrder->seller_id === $user->id, 403, 'Ini bukan pesanan downline Anda.');

        $purchaseOrder->load(['items', 'user']);

        // Pre-cek stok upline per item (biar pesan rapi, bukan exception generik).
        $stok = Inventory::where('user_id', $user->id)->pluck('quantity', 'product_id');
        $kurang = [];
        foreach ($purchaseOrder->items as $item) {
            $tersedia = (int) ($stok[$item->product_id] ?? 0);
            if ($tersedia < (int) $item->qty) {
                $kurang[] = ['nama' => $item->product_name, 'tersedia' => $tersedia, 'butuh' => (int) $item->qty];
            }
        }

        return view('pesanan_downline.show', [
            'po' => $purchaseOrder,
            'stokKurang' => $kurang,      // [] = cukup
        ]);
    }
}
