<?php

namespace App\Http\Controllers;

use App\Models\ShopeeConnection;
use App\Services\ShopeeClient;
use App\Services\ShopeeOrderService;

class ShopeeController extends Controller
{
    public function __construct(
        private ShopeeClient $shopee,
        private ShopeeOrderService $orders,
    ) {}

    public function index()
    {
        return view('shopee.index', [
            'configured' => $this->shopee->configured(),
            'connection' => ShopeeConnection::latest('id')->first(),
            'needMap' => $this->orders->skusNeedingMap(),
        ]);
    }
}
