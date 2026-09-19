<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class MarketplaceStockController extends Controller
{
    public function index(): View
    {
        return view('marketplace-stock.index', ['rows' => [], 'unmapped' => []]);
    }
}
