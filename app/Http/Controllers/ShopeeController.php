<?php

namespace App\Http\Controllers;

use App\Models\ShopeeConnection;
use App\Services\AuditService;
use App\Services\ShopeeClient;
use App\Services\ShopeeOrderService;
use App\Services\ShopeeSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShopeeController extends Controller
{
    public function __construct(
        private ShopeeClient $shopee,
        private ShopeeOrderService $orders,
        private ShopeeSyncService $sync,
    ) {}

    public function index()
    {
        return view('shopee.index', [
            'configured' => $this->shopee->configured(),
            'connection' => ShopeeConnection::latest('id')->first(),
            'needMap' => $this->orders->skusNeedingMap(),
        ]);
    }

    public function connect(): RedirectResponse
    {
        abort_unless($this->shopee->configured(), 400, 'Kredensial Shopee belum diisi di .env server.');

        return redirect()->away($this->shopee->authorizeUrl(route('shopee.callback')));
    }

    public function callback(Request $request): RedirectResponse
    {
        $code = (string) $request->query('code');
        $shopId = (string) $request->query('shop_id');
        if ($code === '' || $shopId === '') {
            return redirect()->route('shopee.index')->with('error', 'Otorisasi dibatalkan / kode Shopee tidak lengkap.');
        }

        try {
            $t = $this->shopee->getToken($code, $shopId);

            ShopeeConnection::updateOrCreate(
                ['shop_id' => $shopId],
                [
                    'access_token' => $t['access_token'],
                    'refresh_token' => $t['refresh_token'],
                    'access_expires_at' => $this->sync->toTime($t['expire_in'] ?? null),
                    'refresh_expires_at' => now()->addDays(30),
                    'connected_by' => $request->user()->id,
                ],
            );

            AuditService::log(action: 'connect_shopee', targetType: 'shopee', after: ['shop_id' => $shopId]);

            return redirect()->route('shopee.index')->with('status', 'Toko Shopee berhasil terhubung.');
        } catch (\Throwable $e) {
            return redirect()->route('shopee.index')->with('error', 'Gagal menghubungkan: '.$e->getMessage());
        }
    }
}
