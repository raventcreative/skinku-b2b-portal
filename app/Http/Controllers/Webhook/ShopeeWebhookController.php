<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;


class ShopeeWebhookController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request)
    {
        if(!$this->isValidSignature($request)){
            Log::warning('Shoppe webhook" invalid sighnature',[
                'ip'=> $request->ip(),
            ]);
            return response('Unathourized');
        }
        $payload = $request->json()->all();
        $code  = $payload['code'] ?? null;

        Log::info('Shoppe webhook is received',['code'=> $code]);
        
        match ((int) $code){
            3 => $this->handleOrderStatusChange($payload),
            4 => $this->handleOrderTrackingUpdate($payload),
            10 => $this->handleStockUpdate($payload),
            default => Log::info('Shoppe webhook: undahnled code',['code'=> $code]),
        };

        return response('OK',200);

    }

    private function isValidSignature(Request $request)
    {
        $partnerKey = config('shopee.partner_key');
        $rawBody = $request->getContent();
        $partnerId = config('shoppe.partner_id');
        
        
        $path = '/api/webhooks/shoppe';
        $timestamp = $request->header('timestamp');

        $baseString = $partnerId . $path . $timestamp . $rawBody;
        $expectedSign = hash_hmac('sha256', $baseString, $partnerKey);
        $receivedSign = $request->header('Authorization');
        
        return hash_equals($expectedSign, $receivedSign);

        
    }

    private function handleOrderStatusChange(array $payload): void
    {
        // 
        Log::info('Shoppe: order status changed',$payload);

        
    }

    private function handleOrderTrackingUpdate(array $payload): void
    {
        Log::info('Shopee: order tracking updated.',$payload);
    }
    
    private function handleStockUpdate(array $payload): void
    {
        Log::info('Shopee: stock updated.',$payload);
    }

}
