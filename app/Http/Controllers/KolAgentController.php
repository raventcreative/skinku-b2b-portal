<?php

namespace App\Http\Controllers;

use App\Services\KolAffiliateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Endpoint agen scraper (Fase 3c): app lokal (Iyuro/skinku) setor transaksi
 * affiliate hasil scrape ke portal. Auth via header X-Agent-Token (bukan sesi
 * web); CSRF dikecualikan di bootstrap/app.php. source='agent'.
 *
 * Kontrak: POST /api/kol-agent/affiliate
 *   header: X-Agent-Token: <KOL_AGENT_TOKEN>
 *   body  : { "platform": "tiktok", "transactions": [
 *              {order_id, username, gmv, commission?, qty?, product?, status?, order_date?}, ... ] }
 *   resp  : { imported, matched, unmatched }
 */
class KolAgentController extends Controller
{
    public function affiliate(Request $request, KolAffiliateService $svc): JsonResponse
    {
        $token = (string) config('services.kol_agent.token');
        abort_if($token === '' || ! hash_equals($token, (string) $request->header('X-Agent-Token')),
            401, 'Token agen tidak valid.');

        $data = $request->validate([
            'platform' => ['required', Rule::in(['tiktok', 'shopee'])],
            'transactions' => ['required', 'array', 'max:5000'],
            'transactions.*.order_id' => ['required'],
        ]);

        // Baca array MENTAH — validated() memangkas sub-key yang tak divalidasi
        // (username/gmv/dst), jadi ambil langsung dari request.
        $rows = array_map(fn ($t) => [
            'order_id' => $t['order_id'] ?? '',
            'username' => $t['username'] ?? '',
            'gmv' => $t['gmv'] ?? 0,
            'commission' => $t['commission'] ?? null,
            'qty' => $t['qty'] ?? null,
            'product' => $t['product'] ?? null,
            'status' => $t['status'] ?? null,
            'order_date' => $t['order_date'] ?? now()->toDateString(),
        ], $request->input('transactions'));

        $res = $svc->import($rows, $data['platform'], null, 'agent');

        return response()->json($res);
    }
}
