<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Services\AuditService;
use App\Services\KolAffiliateService;
use App\Support\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Affiliate & GMV (Fase 3a): ranking GMV per creator + layar "Belum Cocok"
 * (tautkan username asing ke KOL). Angka uang → gated kol.affiliate.view.
 */
class KolAffiliateController extends Controller
{
    public function index(Request $request, KolAffiliateService $svc)
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('bulan'))
            ? (string) $request->query('bulan') : now()->format('Y-m');
        $m = Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        $ranking = $svc->monthly($m);
        $unmatched = $svc->unmatched();

        return view('kols.affiliate.index', [
            'month' => $month,
            'ranking' => $ranking,
            'summary' => [
                'gmv' => (int) $ranking->sum('gmv'),
                'commission' => (int) $ranking->sum('commission'),
                'orders' => (int) $ranking->sum('orders'),
                'affiliates' => $ranking->count(),
            ],
            'unmatched' => $unmatched,
            'canManage' => $request->user()->canDo('kol.affiliate.manage'),
            'kols' => $request->user()->canDo('kol.affiliate.manage')
                ? Kol::orderBy('tiktok_username')->get(['id', 'tiktok_username']) : collect(),
            'prevMonth' => $m->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $m->copy()->addMonth()->format('Y-m'),
        ]);
    }

    /** Tautkan semua transaksi sebuah username ke KOL (dari layar Belum Cocok). */
    public function match(Request $request, KolAffiliateService $svc): RedirectResponse
    {
        $data = $request->validate([
            'raw_username' => ['required', 'string', 'max:150'],
            'kol_id' => ['required', 'integer', 'exists:kols,id'],
        ]);

        $n = $svc->matchUsername($data['raw_username'], $data['kol_id']);

        AuditService::log(action: 'match_kol_affiliate', targetType: 'kol', targetId: $data['kol_id'],
            after: ['username' => $data['raw_username'], 'orders' => $n]);

        return back()->with('status', "{$n} order ditautkan ke KOL.");
    }

    /** Alias header umum (TikTok Affiliate Center / Shopee / export app Iyuro). */
    private const ALIASES = [
        'username' => ['username', 'creator username', 'creator', 'handle', 'nama creator', 'akun', 'creator name'],
        'order_id' => ['order id', 'order_id', 'id pesanan', 'no pesanan', 'nomor pesanan', 'order', 'order sn'],
        'gmv' => ['gmv', 'total', 'penjualan', 'total penjualan', 'omzet', 'sales', 'total amount', 'pay amount', 'subtotal'],
        'commission' => ['commission', 'komisi', 'estimasi komisi', 'est commission', 'actual commission', 'estimated commission'],
        'qty' => ['qty', 'quantity', 'jumlah', 'item sold', 'units', 'kuantitas'],
        'product' => ['product', 'produk', 'product name', 'nama produk', 'item'],
        'status' => ['status', 'order status', 'status pesanan'],
        'order_date' => ['date', 'order date', 'tanggal', 'tanggal pesanan', 'create time', 'created time', 'waktu pesanan', 'order create time'],
    ];

    public function importForm()
    {
        return view('kols.affiliate.import');
    }

    public function importStore(Request $request, KolAffiliateService $svc): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
            'platform' => ['required', Rule::in(['tiktok', 'shopee'])],
        ]);

        $file = $request->file('file');
        $rows = SpreadsheetReader::rows($file->getRealPath(), $file->getClientOriginalExtension());
        $mapped = $this->mapRows($rows);
        if ($mapped === []) {
            return back()->withErrors(['file' => 'File kosong atau header tak dikenali (butuh kolom username, order id, gmv).']);
        }

        $res = $svc->import($mapped, (string) $request->input('platform'), $request->user()->id);
        AuditService::log(action: 'import_kol_affiliate', targetType: 'kol_affiliate', targetId: 0,
            after: ['platform' => $request->input('platform')] + $res);

        return redirect()->route('kol-affiliate.index')->with('status',
            "{$res['imported']} transaksi diimport — {$res['matched']} cocok, {$res['unmatched']} belum cocok.");
    }

    /** Baris file → shape KolAffiliateService::import (auto-map header + bersihkan angka/tanggal). */
    private function mapRows(array $raw): array
    {
        if ($raw === []) {
            return [];
        }
        $norm = fn ($s) => preg_replace('/\s+/', ' ', trim(mb_strtolower((string) $s)));

        $col = [];
        foreach ($raw[0] as $i => $name) {
            $n = $norm($name);
            foreach (self::ALIASES as $field => $aliases) {
                if (! isset($col[$field]) && in_array($n, $aliases, true)) {
                    $col[$field] = $i;
                }
            }
        }
        if (! isset($col['order_id'], $col['username'])) {
            return []; // header wajib tak ketemu
        }

        $out = [];
        foreach (array_slice($raw, 1) as $cells) {
            if (! array_filter($cells, fn ($c) => trim((string) $c) !== '')) {
                continue;
            }
            $rec = [];
            foreach ($col as $field => $i) {
                $rec[$field] = trim((string) ($cells[$i] ?? ''));
            }
            foreach (['gmv', 'commission', 'qty'] as $num) {
                if (isset($rec[$num])) {
                    $rec[$num] = (int) preg_replace('/[^\d]/', '', $rec[$num]);
                }
            }
            if (! empty($rec['order_date'])) {
                $rec['order_date'] = $this->parseDate($rec['order_date']);
            }
            $out[] = $rec;
        }

        return $out;
    }

    private function parseDate(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^\d{10,13}$/', $v)) {
            $ts = strlen($v) >= 13 ? (int) ($v / 1000) : (int) $v;

            return Carbon::createFromTimestamp($ts)->toDateString();
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
