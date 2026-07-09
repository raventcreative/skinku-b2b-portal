<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Services\AuditService;
use App\Services\ImageService;
use App\Services\PurchaseOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchaseOrderService $service, private ImageService $images) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $filters = $request->only(['status', 'q']);

        $orders = PurchaseOrder::query()
            ->with('user')
            ->when($user->isPartner(), fn ($q) => $q->where('user_id', $user->id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($sub) use ($term) {
                    $sub->where('po_number', 'like', "%{$term}%")
                        ->orWhere('company_name', 'like', "%{$term}%");
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('purchase_orders.index', [
            'orders' => $orders,
            'filters' => $filters,
            'statuses' => PurchaseOrder::STATUSES,
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canDo('create_po'), 403, 'Anda tidak memiliki hak akses untuk membuat PO.');

        $priceField = $user->priceField();
        $products = Product::query()
            ->where('status', Product::STATUS_ACTIVE)
            ->orderBy('name')
            ->get();

        return view('purchase_orders.create', compact('products', 'priceField', 'user'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canDo('create_po'), 403);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:0'],
            'shipping_address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $po = $this->service->createForPartner(
            buyer: $user,
            lines: $data['items'],
            shippingAddress: $data['shipping_address'] ?? null,
            notes: $data['notes'] ?? null,
        );

        return redirect()->route('purchase-orders.show', $po)
            ->with('status', "Purchase Order {$po->po_number} berhasil diajukan.");
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder)
    {
        $user = $request->user();
        if ($user->isPartner() && $purchaseOrder->user_id !== $user->id) {
            abort(403, 'Anda hanya dapat melihat PO milik Anda sendiri.');
        }

        $purchaseOrder->load('items', 'user');
        $nextStatuses = PurchaseOrder::TRANSITIONS[$purchaseOrder->status] ?? [];

        return view('purchase_orders.show', compact('purchaseOrder', 'nextStatuses', 'user'));
    }

    public function updateStatus(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(PurchaseOrder::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->service->updateStatus($purchaseOrder, $data['status'], $data['notes'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', "Status PO {$purchaseOrder->po_number} diperbarui menjadi {$data['status']}.");
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $user = $request->user();
        // Partners may cancel their own PO only while still pending/draft.
        if ($user->isPartner()) {
            if ($purchaseOrder->user_id !== $user->id) {
                abort(403);
            }
            if (! in_array($purchaseOrder->status, [PurchaseOrder::STATUS_PENDING, PurchaseOrder::STATUS_DRAFT], true)) {
                return back()->withErrors(['status' => 'PO hanya dapat dibatalkan saat masih pending.']);
            }
        }

        try {
            $this->service->cancel($purchaseOrder, $request->input('notes'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', "PO {$purchaseOrder->po_number} dibatalkan.");
    }

    public function destroy(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canDo('delete_po'), 403, 'Anda tidak memiliki hak akses untuk menghapus PO.');

        $purchaseOrder->status = PurchaseOrder::STATUS_DELETED;
        $purchaseOrder->deleted_by = $user->id;
        $purchaseOrder->save();
        $purchaseOrder->delete(); // soft delete

        AuditService::log(
            action: 'delete_po',
            targetType: 'purchase_order',
            targetId: $purchaseOrder->id,
            after: ['status' => PurchaseOrder::STATUS_DELETED],
        );

        return redirect()->route('purchase-orders.index')
            ->with('status', "PO {$purchaseOrder->po_number} berhasil dihapus (soft delete).");
    }

    /** Permanently remove a PO (for cleaning up test data). Irreversible. */
    public function forceDestroy(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canDo('delete_po') && $user->isStaff(), 403, 'Anda tidak memiliki hak akses untuk menghapus PO.');

        $number = $purchaseOrder->po_number;
        $purchaseOrder->files()->get()->each->delete(); // remove attached files (payment proof)
        $purchaseOrder->items()->delete();
        $purchaseOrder->forceDelete();

        AuditService::log(
            action: 'force_delete_po',
            targetType: 'purchase_order',
            targetId: $purchaseOrder->id,
            after: ['po_number' => $number],
        );

        return redirect()->route('purchase-orders.index')
            ->with('status', "PO {$number} dihapus permanen.");
    }

    /** Admin sets the manual shipping cost (ongkir) for a PO. */
    public function setShipping(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validate([
            'shipping_cost' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->service->setShipping(
            $purchaseOrder,
            (float) $data['shipping_cost'],
            isset($data['discount']) ? (float) $data['discount'] : null,
        );

        return back()->with('status', 'Ongkir & total PO berhasil diperbarui.');
    }

    /** Buyer uploads a transfer proof image. */
    public function uploadPayment(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $user = $request->user();
        $isOwner = $purchaseOrder->user_id === $user->id;
        if (! $isOwner && ! $user->canDo('update_po_status')) {
            abort(403, 'Anda hanya dapat mengunggah bukti untuk PO Anda sendiri.');
        }

        $data = $request->validate([
            'proof' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // Replace any previous proof, then store the new one in the files table.
        $purchaseOrder->files()->where('collection', PurchaseOrder::PAYMENT_PROOF)->get()->each->delete();
        $this->images->attach($purchaseOrder, $request->file('proof'), PurchaseOrder::PAYMENT_PROOF, 1600);

        $this->service->recordPaymentProof($purchaseOrder, $data['note'] ?? null);

        return back()->with('status', 'Bukti transfer berhasil diunggah. Menunggu verifikasi admin.');
    }

    /** Admin verifies (paid) or rejects a payment proof. */
    public function verifyPayment(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->service->verifyPayment(
            $purchaseOrder,
            $data['decision'] === 'approve',
            $request->user()->id,
            $data['note'] ?? null,
        );

        $msg = $data['decision'] === 'approve'
            ? 'Pembayaran ditandai LUNAS. PO siap diproses.'
            : 'Pembayaran ditolak. Mitra perlu mengunggah ulang bukti.';

        return back()->with('status', $msg);
    }
}
