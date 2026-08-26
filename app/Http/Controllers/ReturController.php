<?php

namespace App\Http\Controllers;

use App\Models\JoinTransaction;
use App\Models\PoReturn;
use App\Models\PoReturnItem;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ReturService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RuntimeException;

class ReturController extends Controller
{
    public function __construct(private ReturService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $q = PoReturn::with('purchaseOrder.user', 'items.poItem')->latest();

        // Mitra hanya lihat retur PO miliknya; HQ (process_return) lihat semua.
        if (! $user->canDo('process_return')) {
            abort_unless($user->isPartner(), 403);
            $q->whereHas('purchaseOrder', fn ($x) => $x->where('user_id', $user->id));
        }

        return view('retur.index', [
            'returns' => $q->paginate(20),
            'canProcess' => $user->canDo('process_return'),
        ]);
    }

    public function create(Request $request, PurchaseOrder $purchaseOrder)
    {
        $user = $request->user();
        $this->authorizeReturn($user, $purchaseOrder);
        abort_unless($purchaseOrder->status === PurchaseOrder::STATUS_COMPLETED, 422, 'Hanya PO yang sudah selesai yang bisa diretur.');

        $purchaseOrder->load('items');

        return view('retur.create', [
            'po' => $purchaseOrder,
            'isHq' => $user->canDo('process_return'),
            'returnedQty' => $this->returnedQtyMap($purchaseOrder),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'purchase_order_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            'kondisi' => ['required', 'in:normal,rusak'],
            'reason' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.po_item_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:0'],
        ]);

        $po = PurchaseOrder::with('items')->findOrFail($data['purchase_order_id']);
        $user = $request->user();
        $this->authorizeReturn($user, $po);
        abort_unless($po->status === PurchaseOrder::STATUS_COMPLETED, 422, 'Hanya PO selesai yang bisa diretur.');

        // Item valid: milik PO ini, qty>0, dan (qty + yang sudah diretur) ≤ qty PO item.
        $poQty = $po->items->pluck('qty', 'id');
        $returned = $this->returnedQtyMap($po);
        $lines = [];
        foreach ($data['items'] as $row) {
            $id = (int) $row['po_item_id'];
            $qty = (int) $row['qty'];
            if ($qty <= 0) {
                continue;
            }
            if (! $poQty->has($id) || $qty + (int) ($returned[$id] ?? 0) > (int) $poQty[$id]) {
                return back()->withInput()->with('error', 'Qty retur melebihi jumlah item yang dibeli / tersisa.');
            }
            $lines[] = ['id' => $id, 'qty' => $qty];
        }
        if ($lines === []) {
            return back()->withInput()->with('error', 'Pilih minimal satu item dengan qty di atas 0.');
        }

        $retur = PoReturn::create([
            'purchase_order_id' => $po->id, 'status' => 'pending', 'kondisi' => $data['kondisi'],
            'reason' => $data['reason'] ?? null, 'requested_by' => $user->id,
        ]);
        foreach ($lines as $l) {
            $retur->items()->create(['purchase_order_item_id' => $l['id'], 'qty' => $l['qty']]);
        }

        // HQ input → berlaku langsung. Mitra input → pengajuan (nunggu HQ acc).
        if ($user->canDo('process_return')) {
            try {
                $this->service->apply($retur);
            } catch (RuntimeException $e) {
                return back()->with('error', $e->getMessage());
            }
            $retur->update(['approved_by' => $user->id]);

            return redirect()->route('retur.index')->with('status', 'Retur diproses & berlaku.');
        }

        AuditService::log(action: 'request_po_return', targetType: 'po_return', targetId: $retur->id, after: ['po' => $po->po_number]);

        return redirect()->route('retur.index')->with('status', 'Pengajuan retur dikirim — menunggu persetujuan HQ.');
    }

    public function approve(Request $request, PoReturn $retur): RedirectResponse
    {
        abort_unless($request->user()->canDo('process_return'), 403);
        abort_unless($retur->status === 'pending', 422, 'Retur ini sudah diproses.');

        try {
            $this->service->apply($retur);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $retur->update(['approved_by' => $request->user()->id]);

        return back()->with('status', 'Retur disetujui & berlaku.');
    }

    public function reject(Request $request, PoReturn $retur): RedirectResponse
    {
        abort_unless($request->user()->canDo('process_return'), 403);
        abort_unless($retur->status === 'pending', 422, 'Retur ini sudah diproses.');

        $retur->update(['status' => 'rejected', 'approved_by' => $request->user()->id]);
        AuditService::log(action: 'reject_po_return', targetType: 'po_return', targetId: $retur->id);

        return back()->with('status', 'Pengajuan retur ditolak.');
    }

    /** Batalkan retur applied (super_admin) — undo semua efek. */
    public function void(Request $request, PoReturn $retur): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Hanya super admin yang bisa membatalkan retur.');

        try {
            $this->service->void($retur);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Retur dibatalkan — stok & komisi dikembalikan.');
    }

    /** Batal/Retur JOIN (onboarding) — admin (manage_users): clawback bonus join + balikin stok paket. */
    public function cancelJoin(Request $request, JoinTransaction $joinTransaction): RedirectResponse
    {
        abort_unless($request->user()->canDo('manage_users'), 403);

        try {
            $this->service->cancelJoin($joinTransaction);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Join dibatalkan — bonus join ditarik & stok paket dikembalikan ke HQ.');
    }

    private function authorizeReturn(User $user, PurchaseOrder $po): void
    {
        $isHq = $user->canDo('process_return');
        $isOwner = $user->isPartner() && $po->user_id === $user->id;
        abort_unless($isHq || $isOwner, 403, 'Anda tidak boleh meretur PO ini.');
    }

    /** Map [po_item_id => qty] yang sudah diretur (status applied) untuk PO ini. */
    private function returnedQtyMap(PurchaseOrder $po): Collection
    {
        return PoReturnItem::query()
            ->join('po_returns', 'po_returns.id', '=', 'po_return_items.po_return_id')
            ->where('po_returns.purchase_order_id', $po->id)
            ->where('po_returns.status', 'applied')
            ->selectRaw('purchase_order_item_id, SUM(qty) as q')
            ->groupBy('purchase_order_item_id')->pluck('q', 'purchase_order_item_id');
    }
}
