<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Kol;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AuditLogController extends Controller
{
    /** Tipe target yang ditampilkan dgn nama (bukan cuma "#id"): tipe => [model, kolom nama, awalan]. */
    private const NAMA_TARGET = [
        'kol' => [Kol::class, 'tiktok_username', '@'],
        'user' => [User::class, 'fullname', ''],
        'product' => [Product::class, 'name', ''],
        'purchase_order' => [PurchaseOrder::class, 'po_number', ''],
    ];

    public function index(Request $request)
    {
        $filters = $request->only(['action', 'q']);

        $logs = AuditLog::query()
            ->with('performer')
            ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($sub) use ($term) {
                    $sub->where('target_email', 'like', "%{$term}%")
                        ->orWhere('performed_by_email', 'like', "%{$term}%")
                        ->orWhere('action', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $actions = AuditLog::query()->distinct()->orderBy('action')->pluck('action');

        $namaTarget = $this->namaTarget($logs->getCollection());

        return view('audit_logs.index', compact('logs', 'filters', 'actions', 'namaTarget'));
    }

    /** "tipe:id" => nama tampilan, satu query per tipe utk log di halaman ini; target yang sudah dihapus ikut. */
    private function namaTarget(Collection $logs): array
    {
        $out = [];
        foreach ($logs->whereNotNull('target_id')->groupBy('target_type') as $type => $group) {
            if (! isset(self::NAMA_TARGET[$type])) {
                continue;
            }
            [$model, $kolom, $awalan] = self::NAMA_TARGET[$type];
            foreach ($model::withoutGlobalScopes()->whereIn('id', $group->pluck('target_id')->unique())->pluck($kolom, 'id') as $id => $nama) {
                if (filled($nama)) {
                    $out["{$type}:{$id}"] = $awalan.$nama;
                }
            }
        }

        return $out;
    }
}
