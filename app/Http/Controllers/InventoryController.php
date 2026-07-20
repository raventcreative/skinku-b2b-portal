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

        $activeProducts = Product::query()
            ->where('status', Product::STATUS_ACTIVE)
            ->orderBy('name')
            ->get();

        // Baris stok yang BENAR-BENAR dimiliki — mitra lihat miliknya sendiri.
        // Tak lagi semua-produk-jadi-baris (10 baris nol berantakan); produk
        // baru diisi lewat form dropdown "Penyesuaian Stok" yang membuat barisnya
        // otomatis saat pertama di-set.
        $partnerStock = Inventory::query()
            ->with('product', 'user')
            ->when($user->isPartner(), fn ($q) => $q->where('user_id', $user->id)->where('quantity', '>', 0))
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString();

        return view('inventory.index', compact('user', 'hqProducts', 'partnerStock', 'activeProducts'));
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

        $this->authorizeStockLine($user, (int) $data['user_id']);

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

        // Mitra menyesuaikan stoknya sendiri = perubahan tanpa dokumen sumber.
        // WAJIB tercatat: inilah jaring pengaman yang membuat self-service aman.
        AuditService::log(
            action: 'adjust_partner_stock',
            targetType: 'inventory',
            targetId: (int) $data['product_id'],
            after: [
                'user_id' => (int) $data['user_id'],
                'type' => $data['type'],
                'quantity' => (int) $data['quantity'],
                'alasan' => $data['notes'],
            ],
            targetUserId: (int) $data['user_id'],
        );

        return back()->with('status', 'Barang keluar dicatat.');
    }

    /**
     * SET stok mitra ke angka absolut (saldo awal / koreksi hitung fisik).
     *
     * Menggantikan dropdown Masuk/Keluar/Penyesuaian: mitra cukup menyatakan
     * "stok sebenarnya sekian", sistem yang menghitung selisihnya. Karena itu
     * form ini tak punya toggle tambah/kurang — memang tak perlu.
     */
    public function setPartner(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'target' => ['required', 'integer', 'min:0'],
            'notes' => ['required', 'string', 'max:500'],
        ]);

        $this->authorizeStockLine($user, (int) $data['user_id']);

        try {
            $line = $this->service->setPartnerStock(
                userId: (int) $data['user_id'],
                productId: (int) $data['product_id'],
                target: (int) $data['target'],
                notes: $data['notes'],
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['target' => $e->getMessage()]);
        }

        AuditService::log(
            action: 'set_partner_stock',
            targetType: 'inventory',
            targetId: (int) $data['product_id'],
            after: [
                'user_id' => (int) $data['user_id'],
                'stok_baru' => (int) $data['target'],
                'alasan' => $data['notes'],
            ],
            targetUserId: (int) $data['user_id'],
        );

        return back()->with('status', "Stok disetel ke {$line->quantity}.");
    }

    /** Halaman Penyesuaian Stok — form multi-baris (mirip nota penjualan). */
    public function adjustForm(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isPartner(), 403, 'Hanya mitra yang menyesuaikan stoknya sendiri di sini.');

        // Produk aktif + stok mitra saat ini, supaya form bisa memandu "sekarang X".
        $products = Product::query()
            ->where('status', Product::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name', 'sku']);

        $current = Inventory::where('user_id', $user->id)->pluck('quantity', 'product_id');

        // Suntikkan stok sekarang ke tiap produk (0 bila belum ada).
        $products->each(fn ($p) => $p->current_qty = (int) ($current[$p->id] ?? 0));

        return view('inventory.adjust', ['user' => $user, 'products' => $products]);
    }

    /** Simpan penyesuaian multi-baris (set stok absolut per produk). */
    public function adjustBulk(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isPartner(), 403, 'Hanya mitra yang menyesuaikan stoknya sendiri di sini.');

        $data = $request->validate([
            'notes' => ['required', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.target' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $applied = $this->service->bulkSetPartnerStock($user->id, $data['items'], $data['notes']);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['items' => $e->getMessage()]);
        }

        AuditService::log(
            action: 'bulk_set_partner_stock',
            targetType: 'inventory',
            after: [
                'user_id' => $user->id,
                'jumlah_produk' => count($applied),
                'alasan' => $data['notes'],
                'rincian' => $applied,
            ],
            targetUserId: $user->id,
        );

        return redirect()->route('inventory.index')
            ->with('status', count($applied).' produk disesuaikan. Stok kini sesuai yang Anda isi.');
    }

    /**
     * Staf boleh menyesuaikan stok mitra mana pun; mitra hanya miliknya
     * sendiri; selain itu ditolak. Guard lama hanya memblokir mitra menyentuh
     * stok orang lain, sehingga user non-staf non-mitra (mis. peran afiliator)
     * lolos dan bisa menyesuaikan stok siapa saja.
     */
    private function authorizeStockLine($user, int $targetUserId): void
    {
        $ownLine = $targetUserId === $user->id;
        if (! $user->isStaff() && ! ($user->isPartner() && $ownLine)) {
            abort(403, 'Anda hanya dapat menyesuaikan stok milik Anda.');
        }
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
