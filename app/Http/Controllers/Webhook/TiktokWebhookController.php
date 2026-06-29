<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;


class TiktokWebhookController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
       if(!$this->isValidSignature($request)) {
        Log::warning('Tiktok webhook: invalid signature.',[
            'ip' => $request->ip(),
        ]);
        return response('Unauthorized',401);
       }

       $payload = $request->json()->all();
       $type = $payload['type'] ?? null;

       Log::info('Tiktok webhook is received',['type' => $type]);
       
       match ($type) {
        'order_status_change' => $this->handleOrderChange($payload),
        'product_stock_change'=> $this->handleStockUpdate($payload),
        'shop_authorized' => $this->hanleShopAuthorized($payload),
        default => Log::info('Tiktok webhook: undefined type',['type' => $type])
       };
       return response('OK',200);
    }
    private function isValidSignature(Request $request): bool 
    {
        $appSecret = config('tiktok.app.secret');
        $timestamp = $request->header('x-tt-timestamp');
        $nonce = $request->header('x-tt-nonce');
        $rawBody = $request->getContent();

        $tosign = $timestamp."\n".$nonce."\n".$rawBody."\n";
        $expectedSign = base64_encode(hash_hmac('sha256',$tosign,$appSecret,false));
        $receivedSign = $request->header('x-tts-signature','');

        return hash_equals($expectedSign,$receivedSign);
   
    }
    private function handleOrderChange(array $payload)
    {
        Log::info('Tiktok: stock changed',$payload);
    }

    private function handleStockChange(array $payload){
        Log::info('Tiktok: stock changed',$payload);
    }

    private function hanleShopAuthorized(array $payload)
    {
        Log::info('Tiktok: shop authorized.',$payload);
        //TODO : handle 
    }
}
