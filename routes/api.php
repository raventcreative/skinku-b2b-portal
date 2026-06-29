<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Webhook\TiktokWebhookController;
use App\Http\Controllers\Webhook\ShopeeWebhookController;



Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/webhook/tiktok',TiktokWebhookController::class);
Route::post('/webhook/shopee',ShopeeWebhookController::class);
