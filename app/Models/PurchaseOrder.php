<?php

namespace App\Models;

use App\Models\Concerns\HasFiles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class PurchaseOrder extends Model
{
    use HasFactory, HasFiles, SoftDeletes;

    /** File collection for the transfer payment proof. */
    public const PAYMENT_PROOF = 'payment_proof';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_DELETED = 'deleted';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_PROCESSING,
        self::STATUS_SHIPPED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Order yang sudah jadi komitmen tapi BELUM selesai — dipakai untuk estimasi
     * penjualan bulan berjalan. Draft belum jadi order; cancelled tidak akan jadi.
     */
    public const PIPELINE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_PROCESSING,
        self::STATUS_SHIPPED,
    ];

    /** Batal — tak akan jadi uang. */
    public const CANCELLED_STATUSES = [self::STATUS_CANCELLED];

    /** Belum jadi order sungguhan (masih draf). */
    public const UNCONFIRMED_STATUSES = [self::STATUS_DRAFT];

    /** Allowed forward transitions for HQ staff. */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_PENDING, self::STATUS_CANCELLED],
        self::STATUS_PENDING => [self::STATUS_APPROVED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_PROCESSING, self::STATUS_CANCELLED],
        self::STATUS_PROCESSING => [self::STATUS_SHIPPED, self::STATUS_CANCELLED],
        self::STATUS_SHIPPED => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Urutan MAJU status. Dipakai aksi massal untuk melangkah bertahap: kalau
     * staf memilih target jauh (mis. completed) untuk PO yang masih pending,
     * sistem menjalankannya lewat tiap status antara — bukan lompat langsung.
     */
    public const STATUS_FLOW = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_PROCESSING,
        self::STATUS_SHIPPED,
        self::STATUS_COMPLETED,
    ];

    /** Kelas warna badge per status (bg + teks) — biar mudah dibedakan sekilas. */
    public const STATUS_COLORS = [
        self::STATUS_DRAFT => 'bg-stone-100 text-stone-500',
        self::STATUS_PENDING => 'bg-amber-100 text-amber-700',
        self::STATUS_APPROVED => 'bg-blue-100 text-blue-700',
        self::STATUS_PROCESSING => 'bg-indigo-100 text-indigo-700',
        self::STATUS_SHIPPED => 'bg-cyan-100 text-cyan-700',
        self::STATUS_COMPLETED => 'bg-emerald-100 text-emerald-700',
        self::STATUS_CANCELLED => 'bg-rose-100 text-rose-700',
        self::STATUS_DELETED => 'bg-stone-200 text-stone-400',
    ];

    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_AWAITING = 'awaiting_verification';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_REJECTED = 'rejected';

    protected $fillable = [
        'po_number', 'created_by', 'user_id', 'seller_id', 'company_name', 'user_role', 'order_date',
        'is_tempo', 'tempo_due_date', 'tempo_notes',
        'status', 'subtotal', 'discount', 'shipping_cost', 'total_amount',
        'payment_status', 'payment_note', 'paid_at', 'payment_verified_by',
        'shipping_address', 'notes', 'revision_notes', 'completed_at', 'stock_skipped', 'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'completed_at' => 'datetime',
            'paid_at' => 'datetime',
            'order_date' => 'date',
            'stock_skipped' => 'boolean',
            // tempo_due_date wajib di-cast: view memanggil ->isPast()/->format(),
            // tanpa cast ia string mentah dan halaman detail 500.
            'tempo_due_date' => 'date',
            'is_tempo' => 'boolean',
        ];
    }

    /**
     * Tanggal transaksi sebenarnya. `created_at` = kapan BARISNYA dibuat — untuk
     * entri back-date itu bukan tanggal ordernya.
     */
    public function orderDate(): Carbon
    {
        return $this->order_date
            ? Carbon::parse($this->order_date)
            : Carbon::parse($this->created_at);
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canTransitionTo(string $next): bool
    {
        return in_array($next, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** Kelas warna Tailwind untuk badge status ini. */
    public function statusColor(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'bg-stone-100 text-stone-700';
    }

    public function isPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_PAID;
    }

    /**
     * Boleh diedit isinya (item & qty)? Hanya selagi masih pending/draft DAN
     * belum ada bukti bayar. Begitu bukti masuk (awaiting_verification) atau
     * status naik, PO terkunci — nominal bayar tak boleh berubah sepihak.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_DRAFT], true)
            && $this->payment_status === self::PAYMENT_UNPAID;
    }

    /** Cicilan-cicilan yang sudah masuk (PO tempo). */
    public function payments()
    {
        return $this->hasMany(PoPayment::class)->orderBy('paid_at')->orderBy('id');
    }

    /** Semua retur PO ini. */
    public function returns()
    {
        return $this->hasMany(PoReturn::class);
    }

    /** Retur yang sudah berlaku (applied) — sumber potongan tagihan. */
    public function appliedReturns()
    {
        return $this->hasMany(PoReturn::class)->where('status', 'applied');
    }

    public function paidTotal(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    /**
     * Potongan tagihan dari barang yang diretur (nilai retur applied). Pakai hasil
     * eager-load withSum('appliedReturns','credit_amount') kalau ada, biar tak N+1.
     */
    public function returnsCredit(): float
    {
        if (isset($this->applied_returns_sum_credit_amount)) {
            return (float) $this->applied_returns_sum_credit_amount;
        }

        return (float) $this->appliedReturns()->sum('credit_amount');
    }

    /** Sisa tagihan — dikurangi cicilan masuk & potongan retur. Tak pernah negatif. */
    public function remaining(): float
    {
        return max(0.0, (float) $this->total_amount - $this->paidTotal() - $this->returnsCredit());
    }

    /** Kelebihan (bayar + retur > tagihan) → refund yang harus dikembalikan ke pembeli. */
    public function refundDue(): float
    {
        return max(0.0, $this->paidTotal() + $this->returnsCredit() - (float) $this->total_amount);
    }

    /** Tagihan beres — lewat pembayaran dan/atau potongan retur. */
    public function isSettled(): bool
    {
        return $this->paidTotal() + $this->returnsCredit() >= (float) $this->total_amount - 0.01;
    }

    /** Recompute total = subtotal - discount + shipping. */
    public function recalcTotal(): void
    {
        $this->total_amount = max(0, (float) $this->subtotal - (float) $this->discount + (float) $this->shipping_cost);
    }

    public function paymentProofUrl(): ?string
    {
        return $this->firstFileUrl(self::PAYMENT_PROOF);
    }
}
