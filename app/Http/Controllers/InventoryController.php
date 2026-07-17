<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\AuditService;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();

        // HQ (pusat) stock — visible to staff only.
        $hqProducts = $user->isStaff()
            ? Product::query()->where('status', '!=', Product::STATUS_DELETED)->orderBy('name')->get()
            : collect();

        // Partner stock lines — partners see only their own.
        $partnerStock = Inventory::query()
            ->with('product', 'user')
            ->when($user->isPartner(), fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString();

        return view('inventory.index', compact('user', 'hqProducts', 'partnerStock'));
    }

    /** HQ stock adjustment (gudang / management only). */
    public function adjustHq(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canDo('manage_hq_stock'), 403);

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'type' => ['required', Rule::in([StockMovement::TYPE_IN, StockMovement::TYPE_OUT, StockMovement::TYPE_ADJUSTMENT])],
            'quantity' => ['required', 'integer', 'min:1'],
            // Alasan WAJIB. Penyesuaian manual adalah satu-satunya gerakan stok
            // tanpa dokumen sumber; tanpa keterangan ia jadi perubahan yang tak
            // bisa dijelaskan siapa pun selamanya. required di HTML saja tak cukup
            // — POST bisa dikirim langsung tanpa lewat form.
            'notes' => ['required', 'string', 'max:500'],
        ]);

        $product = Product::findOrFail($data['product_id']);
        $delta = $data['type'] === StockMovement::TYPE_OUT ? -$data['quantity'] : $data['quantity'];

        try {
            $this->service->adjustHqStock(
                product: $product,
                delta: $delta,
                movementType: $data['type'],
                notes: $data['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['quantity' => $e->getMessage()]);
        }

        AuditService::log(
            action: 'adjust_hq_stock',
            targetType: 'product',
            targetId: $product->id,
            after: ['type' => $data['type'], 'quantity' => $data['quantity']],
        );

        return back()->with('status', "Stok pusat {$product->name} diperbarui.");
    }

    /** Partner stock adjustment. Partners may only adjust their own line. */
    public function adjustPartner(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'type' => ['required', Rule::in([StockMovement::TYPE_IN, StockMovement::TYPE_OUT, StockMovement::TYPE_ADJUSTMENT])],
            'quantity' => ['required', 'integer', 'min:1'],
            // Alasan WAJIB. Penyesuaian manual adalah satu-satunya gerakan stok
            // tanpa dokumen sumber; tanpa keterangan ia jadi perubahan yang tak
            // bisa dijelaskan siapa pun selamanya. required di HTML saja tak cukup
            // — POST bisa dikirim langsung tanpa lewat form.
            'notes' => ['required', 'string', 'max:500'],
        ]);

        if ($user->isPartner() && (int) $data['user_id'] !== $user->id) {
            abort(403, 'Anda hanya dapat menyesuaikan stok milik Anda.');
        }

        $delta = $data['type'] === StockMovement::TYPE_OUT ? -$data['quantity'] : $data['quantity'];

        try {
            $this->service->adjustPartnerStock(
                userId: (int) $data['user_id'],
                productId: (int) $data['product_id'],
                delta: $delta,
                movementType: $data['type'],
                notes: $data['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['quantity' => $e->getMessage()]);
        }

        return back()->with('status', 'Stok mitra berhasil disesuaikan.');
    }

    public function setMinimum(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'minimum_stock' => ['required', 'integer', 'min:0'],
        ]);

        if ($user->isPartner() && (int) $data['user_id'] !== $user->id) {
            abort(403);
        }

        $this->service->setPartnerMinimum((int) $data['user_id'], (int) $data['product_id'], (int) $data['minimum_stock']);

        return back()->with('status', 'Batas minimum stok diperbarui.');
    }
}
