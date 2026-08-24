# TikTok Fase 2-4 Reference Map — Returns + Settlement + Accounting/Journal

Purpose: exact structural map of the existing TikTok returns/settlement/accounting implementation in `skinku-b2b-php`, to be mirrored 1:1 for the Shopee equivalent (Shopee Fase 2-4). Read-only research — no source files were modified.

Repo root: `C:\Users\DELL\Downloads\skinku-b2b-php`

---

## 0. TL;DR — the one thing to get right when mirroring

TikTok's own **returns never touch accounting**. `TikTokReturnService` only calls `InventoryService::adjustHqStock()` (pure stock movement) — there is no `AccJournal` created for a restock/reject. The financial effect of a refund washes through the **settlement** journal only (a TikTok statement with `revenue_amount <= 0` gets labeled "Refund / retur" via `TikTokSettlementService::translateType()` and posted through the *same* generic `previewSettlement()`/`postSettlement()` code path as an ad-fee or shipping deduction — there is no dedicated debit-Retur-Penjualan/credit-Piutang recipe). Decide explicitly whether the Shopee mirror should copy this "stock-only, no dedicated return journal" pattern or add a real return journal — don't assume it already exists on the TikTok side.

Also important for code allocation: only the **kas** and **piutang** accounts are channel-specific (`1003 Kas TikTok`, `1103 Piutang TikTok`). Every other account used by `TikTokAccountingService` (`persediaan`, `transit`, `penjualan`, `pendapatan_lain`, `hpp`, `fee`, `iklan`, `ongkir`) is a **shared/generic control account** with a channel-neutral name, and `1001 Kas Shopee` is **already reserved** in `database/seeders/ChartOfAccountSeeder.php` — see §3.4 for the full chart-of-accounts cross-reference. The Shopee mirror should reuse the shared codes as-is and only mint new codes for Kas Shopee (`1001`, already seeded) and Piutang Shopee (not seeded yet — pick the next free `11xx`, e.g. `1104`, following the same off-seeder lazy-`firstOrCreate` pattern TikTok uses for `1103`).

---

## 1. MODELS

### 1.1 `App\Models\TiktokReturn`
File: `app/Models/TiktokReturn.php`
Table: `tiktok_returns`
Migration: `database/migrations/2026_01_01_000035_create_tiktok_returns_table.php`

```php
public const REVIEW_PENDING = 'pending';
public const REVIEW_RESTOCKED = 'restocked';   // layak jual → stok ditambah
public const REVIEW_REJECTED = 'rejected';     // cacat → tidak masuk stok

protected $fillable = [
    'tiktok_return_id', 'tiktok_order_id', 'status', 'return_type', 'line_items',
    'review_status', 'review_note', 'return_created_at', 'reviewed_at', 'reviewed_by',
];

protected $casts = [
    'line_items' => 'array',
    'return_created_at' => 'datetime',
    'reviewed_at' => 'datetime',
];
```
No helper methods on the model itself (all logic lives in `TikTokReturnService`).

**Migration columns** (`create_tiktok_returns_table`):
| column | type | notes |
|---|---|---|
| `tiktok_return_id` | string, unique | |
| `tiktok_order_id` | string, nullable, indexed | |
| `status` | string, nullable | raw TikTok return status |
| `return_type` | string, nullable | `REFUND` \| `RETURN_AND_REFUND` |
| `line_items` | json, nullable | `[{sku, name, qty}]` |
| `review_status` | string(20), default `'pending'`, indexed | internal review verdict |
| `review_note` | text, nullable | |
| `return_created_at` | timestamp, nullable | |
| `reviewed_at` | timestamp, nullable | |
| `reviewed_by` | FK → `users`, nullable, `nullOnDelete` | |
| timestamps | | |

No later migration touches `tiktok_returns` — this is its complete schema (no journal-tracking columns at all, consistent with §0).

### 1.2 `App\Models\TiktokSettlement`
File: `app/Models/TiktokSettlement.php`
Table: `tiktok_settlements`
Migrations: `2026_01_01_000036_create_tiktok_settlements_table.php` + `2026_01_01_000037_add_kind_to_tiktok_settlements.php`

```php
public const POST_PENDING = 'pending';
public const POST_POSTED = 'posted';

protected $fillable = [
    'tiktok_statement_id', 'payment_status', 'currency',
    'revenue_amount', 'fee_amount', 'adjustment_amount', 'settlement_amount',
    'order_ids', 'raw', 'statement_time', 'paid_time',
    'posting_status', 'journal_id', 'posted_at', 'posted_by',
    'kind', 'kind_raw',
];

protected $casts = [
    'revenue_amount' => 'decimal:2',
    'fee_amount' => 'decimal:2',
    'adjustment_amount' => 'decimal:2',
    'settlement_amount' => 'decimal:2',
    'order_ids' => 'array',
    'raw' => 'array',
    'statement_time' => 'datetime',
    'paid_time' => 'datetime',
    'posted_at' => 'datetime',
];

public function isPosted(): bool
{
    return $this->posting_status === self::POST_POSTED;
}
```

**Migration columns** (`create_tiktok_settlements_table`, then `add_kind`):
| column | type | notes |
|---|---|---|
| `tiktok_statement_id` | string, unique | |
| `payment_status` | string, nullable, indexed | `PAID` \| `PROCESSING` \| ... |
| `currency` | string(8), nullable | |
| `revenue_amount` | decimal(16,2), default 0 | omzet bruto |
| `fee_amount` | decimal(16,2), default 0 | total fee, **stored positive** |
| `adjustment_amount` | decimal(16,2), default 0 | penyesuaian lain |
| `settlement_amount` | decimal(16,2), default 0 | net yang cair ke bank |
| `order_ids` | json, nullable | list of order ids in payout, when present |
| `raw` | json, nullable | full raw API response, for later remapping |
| `statement_time` | dateTime, nullable | **`dateTime` not `timestamp`** — avoids 2038 overflow |
| `paid_time` | dateTime, nullable | |
| `posting_status` | string(20), default `'pending'`, indexed | `pending` \| `posted` |
| `journal_id` | unsignedBigInteger, nullable | → `acc_journals.id` (no FK constraint) |
| `posted_at` | dateTime, nullable | |
| `posted_by` | FK → `users`, nullable, `nullOnDelete` | |
| `kind` (added in 000037) | string(80), nullable, indexed | human label e.g. "Penjualan" / "Iklan TikTok" |
| `kind_raw` (added in 000037) | string(190), nullable | original TikTok transaction type string |

### 1.3 `App\Models\TiktokOrder` — return/settlement-relevant columns
File: `app/Models/TiktokOrder.php`
Table: `tiktok_orders`
Migrations: `2026_01_01_000032_create_tiktok_orders_table.php` + `2026_01_01_000039_add_journal_tracking_to_tiktok_orders.php`

```php
public const STATUS_PENDING = 'pending';
public const STATUS_DEDUCTED = 'deducted';
public const STATUS_SKIPPED = 'skipped';

public const SHIPPED_STATUSES = ['AWAITING_COLLECTION', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED'];
public const DELIVERED_STATUSES = ['DELIVERED', 'COMPLETED'];
public const PIPELINE_STATUSES = ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION', 'IN_TRANSIT'];
public const CANCELLED_STATUSES = ['CANCELLED'];
public const UNCONFIRMED_STATUSES = ['UNPAID'];

protected $fillable = [
    'tiktok_order_id', 'status', 'total_amount', 'hpp_amount', 'currency', 'line_items',
    'stock_status', 'order_created_at', 'deducted_at', 'deducted_by',
    'transit_journal_id', 'sale_journal_id',
];

protected $casts = [
    'line_items' => 'array',
    'total_amount' => 'decimal:2',
    'hpp_amount' => 'decimal:2',
    'order_created_at' => 'datetime',
    'deducted_at' => 'datetime',
];

public function isDelivered(): bool { return in_array($this->status, self::DELIVERED_STATUSES, true); }
public function isShipped(): bool   { return in_array($this->status, self::SHIPPED_STATUSES, true); }
```

Accounting-relevant columns added by `add_journal_tracking_to_tiktok_orders` (000039), all `after` the columns named:
| column | type | notes |
|---|---|---|
| `hpp_amount` | decimal(16,2), default 0, after `total_amount` | HPP **locked at stock-out time**, reused at delivery so the transit account nets to zero |
| `transit_journal_id` | unsignedBigInteger, nullable, after `deducted_by` | idempotency guard for step 1 (barang keluar) |
| `sale_journal_id` | unsignedBigInteger, nullable, after `transit_journal_id` | idempotency guard for step 2 (order sampai) |

### 1.4 `App\Models\TiktokConnection` — journal/cutoff-relevant columns
File: `app/Models/TiktokConnection.php`
Table: `tiktok_connections`
Migrations: `2026_01_01_000030_create_tiktok_connections_table.php`, `..._000031_fix_tiktok_expiry_columns_to_datetime.php`, `..._000034_add_auto_deduct_to_tiktok_connections.php`, `..._000038_add_deduct_from_to_tiktok_connections.php`, `..._000040_add_journal_enabled_to_tiktok_connections.php`

```php
protected $fillable = [
    'shop_id', 'shop_cipher', 'shop_name', 'region', 'seller_name',
    'access_token', 'refresh_token', 'access_expires_at', 'refresh_expires_at',
    'connected_by', 'last_synced_at', 'auto_deduct', 'deduct_from', 'journal_enabled',
];

protected $casts = [
    'access_expires_at' => 'datetime',
    'refresh_expires_at' => 'datetime',
    'last_synced_at' => 'datetime',
    'auto_deduct' => 'boolean',
    'deduct_from' => 'date',
    'journal_enabled' => 'boolean',
];

protected $hidden = ['access_token', 'refresh_token'];

public function syncStale(int $hours = 2): bool
{
    return $this->last_synced_at === null || $this->last_synced_at->lt(now()->subHours($hours));
}

public function accessExpiringSoon(): bool
{
    return $this->access_expires_at === null || $this->access_expires_at->subMinutes(5)->isPast();
}
```

Accounting/cutoff-relevant columns (added across three later migrations):
| column | migration | type | meaning |
|---|---|---|---|
| `auto_deduct` | 000034 | boolean, default false, after `last_synced_at` | auto-potong-stok on every sync |
| `deduct_from` | 000038 | date, nullable, after `auto_deduct` | orders before this date are assumed pre-opname → never deducted/posted |
| `journal_enabled` | 000040 | boolean, **default false**, after `deduct_from` | master accounting switch, deliberately OFF by default |

Only one row of `tiktok_connections` is ever really "current" — every service reads `TiktokConnection::latest('id')->first()`.

### 1.5 `App\Models\TiktokSkuMap` (supporting model, referenced by return/order preview & HPP calc)
File: `app/Models/TiktokSkuMap.php`
Table: `tiktok_sku_maps`
Migrations: created inside `2026_01_01_000032_create_tiktok_orders_table.php`, then `2026_01_01_000033_add_qty_to_tiktok_sku_maps.php`

```php
protected $fillable = ['tiktok_sku', 'product_id', 'qty'];
protected $casts = ['qty' => 'integer'];
public function product(): BelongsTo { return $this->belongsTo(Product::class); }
```
Columns: `tiktok_sku` (string), `product_id` (FK → `products`, cascade delete), `qty` (unsignedInteger, default 1, added in 000033). Unique constraint is `['tiktok_sku', 'product_id']` (changed from a plain unique on `tiktok_sku` in 000033) — one TikTok SKU can map to *multiple* product components (bundles), but not the same product twice.

---

## 2. API CLIENT

File: `app/Services/TikTokClient.php` — TikTok Shop Open API v2 HTTP client. HMAC-SHA256 request signing per TikTok's spec (documented in the class docblock: sort query params minus `sign`/`access_token`, concat `path+k1v1k2v2...`, append raw body JSON, wrap with `app_secret`, HMAC with `app_secret`).

Config keys (from `config/services.php` → `services.tiktok.*`, env `TIKTOK_APP_KEY` / `TIKTOK_APP_SECRET` / `TIKTOK_SERVICE_ID` / `TIKTOK_AUTH_BASE` / `TIKTOK_API_BASE` / `TIKTOK_AUTHORIZE_BASE`).

Methods relevant to returns/settlements:

```php
/** Cari retur/refund — TERBARU dulu. Satu halaman. */
public function searchReturns(string $accessToken, string $shopCipher, int $pageSize = 50, string $pageToken = ''): array
```
- Endpoint: `POST /return_refund/202309/returns/search`
- Query: `page_size`, `sort_field=create_time`, `sort_order=DESC`, `page_token` (if paging)
- Body: none (`[]` passed as filter, currently unused)
- Response shape consumed by caller: `data['return_orders']` (fallback `data['returns']`), `data['next_page_token']`

```php
/** Daftar pencairan (settlement statements) — TERBARU dulu. Satu halaman. */
public function getStatements(string $accessToken, string $shopCipher, int $pageSize = 50, string $pageToken = ''): array
```
- Endpoint: `GET /finance/202309/statements`
- Query: `page_size`, `sort_field=statement_time`, `sort_order=DESC`, `page_token`
- Response shape consumed: `data['statements']` (fallback `data['statement_list']`, then `data['list']`), `data['next_page_token']`

```php
/** Rincian transaksi dalam 1 pencairan. Satu halaman. */
public function getStatementTransactions(string $accessToken, string $shopCipher, string $statementId, int $pageSize = 50, string $pageToken = ''): array
```
- Endpoint: `GET /finance/202309/statements/{statementId}/statement_transactions` (statementId is `rawurlencode`d into the path)
- Query: `page_size`, `sort_field=order_create_time` (**required** — TikTok returns error `36009004` without it), `sort_order=DESC`, `page_token`
- Response shape consumed: `data['statement_transactions']` (fallback `data['transactions']`, then `data['list']`)

Other methods on the same client (order/auth side, listed for completeness since `TikTokSyncService` composes them together): `configured(): bool`, `authorizeUrl(): string`, `getToken(string $authCode): array` (`POST /api/v2/token/get` on the *auth* base, unsigned), `refreshToken(string $refreshToken): array` (`POST /api/v2/token/refresh`, unsigned), `getShops(string $accessToken): array` (`GET /authorization/202309/shops`), `searchOrders(string $accessToken, string $shopCipher, int $pageSize = 50, string $pageToken = '', array $filters = []): array` (`POST /order/202309/orders/search`).

Internal/shared plumbing: `request(string $method, string $path, string $accessToken, ?string $shopCipher = null, array $extraQuery = [], ?array $body = null): array` (all signed calls funnel through this — adds `app_key`, `timestamp`, `shop_cipher`, computes `sign`, sends `x-tts-access-token` header, throws `RuntimeException` on non-zero `code`), and `sign(string $path, array $query, string $bodyString = ''): string` (public, used directly by the signature test).

**For the Shopee client**: Shopee's equivalent endpoints to add would be a returns/refund search call and a payout/wallet-transaction (settlement) call, following the same "one page per call, caller loops with `next_page_token`" convention already used by `searchReturns`/`getStatements`. Shopee's own signing scheme differs (already implemented separately per `config/services.tiktok.*` vs `services.shopee.*` — see `app/Services/ShopeeClient.php`, not detailed here as it's out of this doc's scope).

---

## 3. SERVICES

### 3.1 `App\Services\TikTokReturnService`
File: `app/Services/TikTokReturnService.php`
Constructor deps: `TikTokOrderService $orders`, `InventoryService $inventory`

| Method | Signature | What it does |
|---|---|---|
| `store` | `public function store(array $apiReturns): int` | `updateOrCreate` by `tiktok_return_id` for each API return; maps `return_id`/`return_order_id` → `tiktok_return_id`, `order_id` → `tiktok_order_id`, `return_status`/`status` → `status`, `return_type`, normalizes line items, converts `create_time` epoch → `return_created_at`. **Never resets `review_status`** if the row already exists (preserves a human decision on resync). Returns count processed. |
| `normalizeItems` | `public function normalizeItems(array $ret): array` | Reads `return_line_items` (fallback `line_items`), aggregates by `seller_sku`/`sku_id`/`product_name` into `[{sku, name, qty}]`, summing `quantity`/`return_quantity` per SKU. |
| `preview` | `public function preview(TiktokReturn $return): array` | For each line item, resolves SKU → product components via `TikTokOrderService::resolve()` (same "recipe" map used for orders), multiplies qty. Returns `['lines' => [...], 'all_matched' => bool]` — `all_matched` is false if any SKU is unmapped or there are zero lines. |
| `restock` | `public function restock(TiktokReturn $return, int $userId, ?string $note = null): void` | **Idempotent**: no-op if already `REVIEW_RESTOCKED`. Throws `RuntimeException` if `preview()['all_matched']` is false. Inside `DB::transaction`: for every resolved component, `InventoryService::adjustHqStock($product, +qty, StockMovement::TYPE_IN, "Retur TikTok {id} (layak jual)", 'tiktok_return', $return->id)`, then sets `review_status = REVIEW_RESTOCKED`, `review_note`, `reviewed_at = now()`, `reviewed_by = $userId`. **No `AccJournal` is created here** (see §0). |
| `reject` | `public function reject(TiktokReturn $return, int $userId, ?string $note = null): void` | If currently `REVIEW_RESTOCKED`, first calls private `pullBack()` to reverse the stock-in, then sets `review_status = REVIEW_REJECTED` + note/reviewer. |
| `resetReview` | `public function resetReview(TiktokReturn $return): void` | Back to `REVIEW_PENDING`; if currently `REVIEW_RESTOCKED`, calls `pullBack()` first. Clears `review_note`/`reviewed_at`/`reviewed_by`. |
| `pullBack` (private) | `private function pullBack(TiktokReturn $return): void` | Recomputes `preview()`, and for every resolved component calls `InventoryService::adjustHqStock($product, -qty, StockMovement::TYPE_OUT, "Koreksi retur TikTok {id}", 'tiktok_return', $return->id)` inside a `DB::transaction`. This **is** the "clawback" logic — but it is a pure inventory reversal, not a financial/commission clawback (there's no commission or accounting entry from a restock to claw back in the first place). |

### 3.2 `App\Services\TikTokSettlementService`
File: `app/Services/TikTokSettlementService.php` — no constructor deps (stateless).

| Method | Signature | What it does |
|---|---|---|
| `store` | `public function store(array $apiStatements): int` | `updateOrCreate` by `tiktok_statement_id`. Maps `id`/`statement_id`, `payment_status`/`status`, `currency`, `revenue_amount` (fallback `net_sales_amount`), `fee_amount` = `abs(...)` (**always stored positive**), `adjustment_amount`, `settlement_amount`, `statement_time`, `paid_time` (fallback `payment_time`), stores the whole row as `raw`, computes `kind` via `kindFromStatement()`. **Never resets `posting_status`** on an existing row (idempotent w.r.t. accounting). |
| `kindFromStatement` | `public function kindFromStatement(array $s): string` | Cheap heuristic without hitting the per-statement transactions endpoint: `revenue_amount > 0` → `'Penjualan'`; else `shipping_cost_amount != 0` → `'Ongkir / logistik'`; else → `'Iklan TikTok'` (default fallback — confirmed by the business owner that unexplained ~daily deductions are ad spend). |
| `deriveKind` | `public function deriveKind(array $transactions, ?TiktokSettlement $s = null): array` | Given the *statement_transactions* API payload, tallies `type`/`transaction_type`/`adjustment_type`/`sub_type` across all transactions, picks the most frequent (`arsort` + `array_key_first`), returns `['raw' => $rawType, 'label' => translateType($rawType)]`. Falls back to `kindFromStatement()` if transactions is empty. |
| `translateType` | `public static function translateType(?string $type): string` | Best-effort ID translation of TikTok's raw transaction-type string via `str_contains` matching, e.g. `AFFILIATE`+`AD` → "Iklan afiliasi", `REFUND`/`RETURN` → **"Refund / retur"**, `SHIP`/`LOGISTIC`/`FREIGHT` → "Ongkir / logistik", `ADS`/`ADVERTIS`/`GMV_MAX` → "Biaya iklan", `PENALTY`/`FINE` → "Denda / penalti", etc. (This is the *only* place "return/refund" is named anywhere near the accounting layer — purely a display label, it does not change how `TikTokAccountingService::previewSettlement()` books the line.) |
| `num` (private) | `private function num($v): float` | Strips `,`/space from string amounts, casts to float. |
| `toTime` (private) | `private function toTime($v): ?Carbon` | Numeric → `Carbon::createFromTimestamp`; else best-effort `Carbon::parse`; null/failure → null. |

### 3.3 `App\Services\TikTokAccountingService` — journal recipes (CRITICAL SECTION)

File: `app/Services/TikTokAccountingService.php`
Constructor deps: `AccountingService $accounting`, `TikTokOrderService $orders`
Design doctrine, verbatim from the class docblock ("Opsi C" = accrual with correct matching):
1. **Stock-out** (barang keluar) → pure asset transfer, zero P&L impact.
2. **Order DELIVERED** → revenue and COGS recognized *together* (accurate gross margin).
3. **Settlement (dana cair)** → not new revenue, just collection; the receivable is cleared using TikTok's own `revenue_amount` (a control-account approach — no per-order reconciliation needed).
All three steps are idempotent (guarded by columns/status) and respect a per-connection cutoff date.

#### 3.3.1 Chart of accounts used (`accounts(): array`)

```php
/** @return array<string, AccAccount> */
public function accounts(): array
{
    return [
        'kas'             => $this->acc('1003', 'Kas TikTok',                    'asset',   'cash',        'debit'),
        'piutang'         => $this->acc('1103', 'Piutang TikTok',                'asset',   'receivable',  'debit'),
        'transit'         => $this->acc('1203', 'Persediaan Dalam Perjalanan',   'asset',   'inventory',   'debit'),
        'persediaan'      => $this->acc('1202', 'Persediaan Barang Jadi',        'asset',   'inventory',   'debit'),
        'penjualan'       => $this->acc('4001', 'Penjualan',                     'revenue', 'sales',       'credit'),
        'pendapatan_lain' => $this->acc('4002', 'Pendapatan Lain-lain',          'revenue', 'other',       'credit'),
        'hpp'             => $this->acc('5003', 'Beban HPP',                     'expense', 'cogs',        'debit'),
        'fee'             => $this->acc('6005', 'Beban Biaya E-commerce',        'expense', 'operating',   'debit'),
        'iklan'           => $this->acc('6001', 'Beban Iklan / Promosi',         'expense', 'operating',   'debit'),
        'ongkir'          => $this->acc('6007', 'Beban Ongkos Kirim',            'expense', 'operating',   'debit'),
    ];
}
```
`acc()` (private) = `AccAccount::firstOrCreate(['code' => $code], ['name'=>..., 'type'=>..., 'subtype'=>..., 'normal_balance'=>..., 'is_active'=>true])` — **lazily creates the account row on first use** if it doesn't already exist; if it exists (e.g. seeded), the seeded row wins (firstOrCreate's second array is ignored on a hit).

#### 3.3.2 Recipe 1 — Stock-out → transit (`previewTransit` / `postTransit`)

```php
public function previewTransit(TiktokOrder $order): array
public function postTransit(TiktokOrder $order): ?AccJournal
```
- Guard (no-op, returns `null`): `$order->transit_journal_id` already set, OR `hpp_amount <= 0`.
- Lines (only when `hpp > 0`):

| Account | Code | Debit | Credit | Memo |
|---|---|---|---|---|
| Persediaan Dalam Perjalanan | `1203` | `hpp_amount` | | "Barang keluar TikTok {order_id}" |
| Persediaan Barang Jadi | `1202` | | `hpp_amount` | "Keluar dari gudang" |

- `record()` called with: `date = ($order->deducted_at ?? now())->toDateString()`, `reference = "TT-KELUAR {tiktok_order_id}"`, `description = 'Barang keluar gudang (belum diakui penjualan)'`, `source_type = 'tiktok_order_transit'`, `source_id = $order->id`, `type = 'inventory'`.
- On success, sets `$order->transit_journal_id = $journal->id`.

#### 3.3.3 Recipe 2 — Order DELIVERED → sale + COGS (`previewSale` / `postSale`)

```php
public function previewSale(TiktokOrder $order): array
public function postSale(TiktokOrder $order): ?AccJournal
```
- Guard: no-op if `sale_journal_id` already set, OR `! $order->isDelivered()`; also no-op if the computed line list is empty.
- Lines:

| Condition | Account | Code | Debit | Credit | Memo |
|---|---|---|---|---|---|
| `bruto > 0` | Piutang TikTok | `1103` | `total_amount` | | "Order sampai {order_id}" |
| `bruto > 0` | Penjualan | `4001` | | `total_amount` | "Penjualan TikTok (bruto)" |
| `hpp > 0` **AND** `transit_journal_id` already set | Beban HPP | `5003` | `hpp_amount` | | "HPP terjual" |
| `hpp > 0` **AND** `transit_journal_id` already set | Persediaan Dalam Perjalanan | `1203` | | `hpp_amount` | "Lepas dari perjalanan" |

Important nuance: revenue is recognized **even if HPP/transit is unknown** — the HPP/transit release lines are only added when a transit journal actually exists, to avoid driving the transit account negative. Revenue is never gated on HPP.
- `record()` called with: `date = now()->toDateString()` (**not** the order date), `reference = "TT-JUAL {tiktok_order_id}"`, `description = 'Order sampai — akui penjualan & HPP'`, `source_type = 'tiktok_order_sale'`, `source_id = $order->id`, `type = 'sales'`.
- On success, sets `$order->sale_journal_id = $journal->id`.

#### 3.3.4 Recipe 3 — Settlement (dana cair) → cash + fee, clear receivable (`previewSettlement` / `postSettlement`)

```php
public function previewSettlement(TiktokSettlement $s): array
public function postSettlement(TiktokSettlement $s): ?AccJournal
```
- Guard: no-op if `$s->isPosted()` (i.e. `posting_status === 'posted'`); also no-op if computed lines are empty.
- **Branch A — `revenue_amount > 0`** (a sales-type payout):

| Account | Code | Debit | Credit | Memo |
|---|---|---|---|---|
| Kas TikTok | `1003` | `settlement_amount` (net) | | "Dana cair bersih" |
| Beban Biaya E-commerce | `6005` | `fee_amount` | | "Fee marketplace" |
| *(if `adjustment_amount < 0`)* Beban Biaya E-commerce | `6005` | `-adjustment_amount` | | "Penyesuaian TikTok" |
| *(if `adjustment_amount > 0`)* Pendapatan Lain-lain | `4002` | | `adjustment_amount` | "Penyesuaian TikTok" |
| Piutang TikTok | `1103` | | `revenue_amount` (bruto) | "Piutang TikTok tertagih" |

  Note: the receivable is credited by the statement's **bruto `revenue_amount`**, not by summing order amounts — this is the "control account" trick that avoids per-order matching.

- **Branch B — `revenue_amount <= 0`** (a pure deduction: ads, shipping, refund, penalty, etc. — whatever `kind` labeled it):
  - `$beban = $s->kind === 'Ongkir / logistik' ? ongkir(6007) : iklan(6001)` — i.e. **everything that isn't explicitly "Ongkir / logistik" is booked to the Iklan/Promosi (6001) expense account**, including settlements TikTok labeled "Refund / retur" via `translateType()`. There is no `4101 Retur Penjualan` line ever produced by this service.
  - `$out = -$net` (net = `settlement_amount`)
    - if `$out >= 0` (money left the account): Debit `$beban` `$out` ("kind label" as memo) / Credit Kas `1003` `$out` ("Kas keluar")
    - if `$out < 0` (money came *back* in — a reversal of a prior deduction): Debit Kas `1003` `-$out` ("Kas masuk") / Credit `$beban` `-$out` ("{kind} (pengembalian)")
- Zero-value lines are filtered out (`round(...,2) != 0.0`) before returning.
- `record()` called with: `date = ($s->statement_time ?? now())->toDateString()`, `reference = "TT-CAIR {tiktok_statement_id}"`, `description = 'Pencairan TikTok — {kind}'`, `source_type = 'tiktok_settlement'`, `source_id = $s->id`, `type = $s->settlement_amount >= 0 ? 'cash_in' : 'cash_out'`.
- On success: `posting_status = POST_POSTED`, `journal_id = $journal->id`, `posted_at = now()`.

#### 3.3.5 `preview(TiktokSettlement $s): array` (public, UI-facing)
```php
public function preview(TiktokSettlement $s): array   // ['lines' => ..., 'balanced' => bool]
```
Wraps `previewSettlement()` and additionally sums debit/credit to report `balanced` (`abs($d - $c) < 0.005`). Used by `TikTokController::settlementDetail()` to render the "Rencana Jurnal" preview table before posting.

#### 3.3.6 Batch posting pass — `postPending(): array`
```php
/** @return array{transit:int, sale:int, settlement:int, failed:int} */
public function postPending(): array
```
1. **Throws `RuntimeException`** immediately if `! $this->enabled()`.
2. **HPP backfill**: for `TiktokOrder` rows with `stock_status = DEDUCTED` and `hpp_amount = 0` (orders deducted before the `hpp_amount` column existed), recompute via `TikTokOrderService::computeHpp()` and save if `> 0`. Respects cutoff on `order_created_at`.
3. **Transit pass**: all `stock_status = DEDUCTED` and `transit_journal_id IS NULL`, respecting cutoff on `order_created_at` → `postTransit()` each, catching `\Throwable` per-row (logs `[tiktok-jurnal] transit order {id} gagal: ...`, increments `failed`, continues).
4. **Sale pass**: all `status IN DELIVERED_STATUSES` and `sale_journal_id IS NULL`, respecting cutoff on `order_created_at` (**deliberately not gated on transit already posted** — see §3.3.3) → `postSale()` each, same per-row try/catch.
5. **Settlement pass**: all `TiktokSettlement` with `posting_status = PENDING`, respecting cutoff on `statement_time` → `postSettlement()` each, same per-row try/catch.
6. Returns counts of each + `failed`.

Every step is idempotent by construction — calling `postPending()` repeatedly only posts what's still pending (verified by `test_posting_is_idempotent`, §7).

#### 3.3.7 Switches, cutoff, and rollback

```php
public function enabled(): bool
```
`(bool) TiktokConnection::latest('id')->first()?->journal_enabled` — the master accounting kill-switch, **default false** (migration 000040).

```php
public function cutoff(): ?Carbon
```
`TiktokConnection::latest('id')->first()?->deduct_from` parsed to `startOfDay()`, or `null`. Same semantics/column as `TikTokOrderService::cutoff()` (stock-cutoff and accounting-cutoff share the single `deduct_from` field).

```php
private function withCutoff($query, ?Carbon $cut, string $column)
```
Applies `->where($column, '>=', $cut)` only if `$cut` is set.

```php
/** @return array{journals:int, orders:int, settlements:int} */
public function unpostAll(): array
```
Inside `DB::transaction`: deletes every `AccJournal` whose `source_type` is in `['tiktok_order_transit', 'tiktok_order_sale', 'tiktok_settlement']` (lines cascade-delete with the journal); resets `transit_journal_id`/`sale_journal_id` to `null` on every `TiktokOrder` that had either set; resets every `posted` `TiktokSettlement` back to `posting_status = PENDING`, `journal_id = null`, `posted_at = null`. **Scoped strictly by `source_type`** — journals from Excel import, manual entry, or POs are never touched (covered by `test_unpost_removes_only_tiktok_journals`, §7).

#### 3.3.8 Internal helpers
```php
private function record(array $lines, string $date, string $reference, string $description, string $sourceType, int $sourceId, string $type): AccJournal
```
Resolves `$branch = AccBranch::active()->orderBy('id')->first()` (throws `RuntimeException('Belum ada cabang (acc_branches) — jurnal tidak bisa dibuat.')` if none), then calls `AccountingService::record()` (see §3.4) with the header and the `{account, debit, credit, memo}` lines remapped to `{account_id, debit, credit, memo}`.
```php
private function acc(string $code, string $name, string $type, string $subtype, string $normal): AccAccount
```
`AccAccount::firstOrCreate(['code' => $code], [...])` — see §3.3.1.

### 3.4 Generic posting engine — how `TikTokAccountingService` actually writes to the books

#### `App\Services\AccountingService`
File: `app/Services/AccountingService.php` — **the single doorway for creating journals** app-wide (used by every channel/module, not just TikTok).

```php
/**
 * @param  array{branch_id:int, date:string, reference?:?string, description?:?string, type?:string, source_type?:?string, source_id?:?int}  $header
 * @param  array<int, array{account_id:int, debit?:float, credit?:float, memo?:?string, branch_id?:int}>  $lines
 * @throws AccountingException
 */
public function record(array $header, array $lines, string $status = AccJournal::STATUS_POSTED): AccJournal
```
Validation performed (app-level, in addition to DB): `branch_id` required; each line's `debit`/`credit` rounded to 2dp, both must be `>= 0`, cannot both be `> 0` on the same line; a line with both `0` is silently skipped; a non-empty line requires `account_id`; **at least 2 non-empty lines** required; **total debit must equal total credit** within `0.005` tolerance, else throws `AccountingException` with the exact mismatch amounts formatted in Rupiah. `period` is auto-derived as `date('Y-m')`. Runs inside `DB::transaction`: creates the `AccJournal` row, then `$journal->lines()->create($n)` per normalized line, returns `$journal->load('lines')`.

```php
public function post(AccJournal $journal): AccJournal   // draft → posted, revalidates isBalanced()
public function void(AccJournal $journal): AccJournal   // status → 'void' (posted-only balances now exclude it)
public function balanceOf(int $accountId, ?string $period = null): float
```
`balanceOf` = `SUM(debit) - SUM(credit)` joined through `acc_journals`, filtered to `status = 'posted'`, optional `period = 'YYYY-MM'` filter. This is exactly the method the TikTok accounting tests use to assert account balances after posting (e.g. `$svc->balanceOf($a['transit']->id)`).

`TikTokAccountingService` only ever calls `record()` (via its private `record()` wrapper) — it never calls `post()` or `void()` directly; everything it creates goes straight in as `STATUS_POSTED` (the default `$status` param).

#### `App\Models\AccJournal`
File: `app/Models/AccJournal.php`, table `acc_journals`.
```php
public const STATUS_DRAFT = 'draft';
public const STATUS_POSTED = 'posted';
public const STATUS_VOID = 'void';

protected $fillable = ['branch_id', 'date', 'period', 'reference', 'description', 'type', 'status', 'source_type', 'source_id'];
protected function casts(): array { return ['date' => 'date']; }

public function branch() // belongsTo AccBranch
public function lines()  // hasMany AccJournalLine, FK journal_id
public function totalDebit(): float
public function totalCredit(): float
public function isBalanced(): bool   // abs(totalDebit - totalCredit) < 0.005
```
`type` is a free-ish string enum by convention (values seen from TikTok: `inventory`, `sales`, `cash_in`, `cash_out`; default `general`). `source_type`/`source_id` is the polymorphic-by-convention link back to the originating row (`tiktok_order_transit` / `tiktok_order_sale` / `tiktok_settlement` for this integration) — used by `unpostAll()` to scope deletions and by tests to assert provenance.

#### `App\Models\AccJournalLine`
File: `app/Models/AccJournalLine.php`, table `acc_journal_lines`.
```php
protected $fillable = ['journal_id', 'account_id', 'branch_id', 'debit', 'credit', 'memo'];
protected function casts(): array { return ['debit' => 'decimal:2', 'credit' => 'decimal:2']; }
public function journal() // belongsTo AccJournal
public function account() // belongsTo AccAccount
public function branch()  // belongsTo AccBranch
```

#### `App\Models\AccAccount` (used by `accounts()`/`acc()`, not explicitly requested but load-bearing)
File: `app/Models/AccAccount.php`, table `acc_accounts`.
```php
public const TYPE_ASSET='asset'; TYPE_LIABILITY='liability'; TYPE_EQUITY='equity'; TYPE_REVENUE='revenue'; TYPE_EXPENSE='expense';
public const DEBIT_TYPES = [self::TYPE_ASSET, self::TYPE_EXPENSE];
protected $fillable = ['code', 'name', 'type', 'subtype', 'normal_balance', 'legacy_code', 'is_active'];
protected function casts(): array { return ['is_active' => 'boolean']; }
public function scopeActive($query)
public function scopeCashLike($query)   // whereIn subtype ['cash','bank']
public function isDebitNormal(): bool
public function lines() // hasMany AccJournalLine
```

#### `App\Models\AccBranch` (used to pick the posting branch)
File: `app/Models/AccBranch.php`, table `acc_branches`.
```php
protected $fillable = ['code', 'name', 'is_active'];
protected function casts(): array { return ['is_active' => 'boolean']; }
public function scopeActive($query)
public function journals() // hasMany AccJournal
```

#### Chart-of-accounts cross-reference — `database/seeders/ChartOfAccountSeeder.php`
This seeder is the canonical, pre-populated chart of accounts (idempotent upsert by `code`, preserves `is_active`). Cross-referencing it against §3.3.1 matters a lot for a 1:1 Shopee mirror:

| Code used by TikTokAccountingService | In `ChartOfAccountSeeder`? | Seeder's name for it |
|---|---|---|
| `1003` Kas TikTok | **Yes** | `Kas TikTok` (legacy_code `1007`) — matches exactly |
| `1103` Piutang TikTok | **No** | not seeded — only exists once `TikTokAccountingService::accounts()` runs and lazily creates it |
| `1203` Persediaan Dalam Perjalanan | **No** | not seeded — same lazy-create situation, but note this is a **shared/generic** name, not TikTok-specific |
| `1202` Persediaan Barang Jadi | **Yes** | `Persediaan Barang Jadi` — matches exactly, shared account |
| `4001` Penjualan | **Yes** | `Penjualan` — matches exactly, shared account |
| `4002` Pendapatan Lain-lain | **Yes** | `Pendapatan Lain-lain` — matches exactly, shared account |
| `5003` Beban HPP | **Yes** | `Beban HPP` — matches exactly, shared account |
| `6005` Beban Biaya E-commerce | **Yes** | `Beban Biaya E-commerce` — matches exactly, shared account (generic "e-commerce fee", not per-channel) |
| `6001` Beban Iklan / Promosi | **Yes** | `Beban Iklan / Promosi` — matches exactly, shared account |
| `6007` Beban Ongkos Kirim | **Yes** | `Beban Ongkos Kirim` — matches exactly, shared account |

And, critically: **`1001` is already seeded as `'Kas Shopee'`** in the same seeder (asset/cash/debit, legacy_code `1001`) — reserved and waiting. There is no `acc_accounts` seed row yet for a "Piutang Shopee" equivalent of `1103`. Also note `4101 Retur Penjualan` (revenue/contra_revenue/debit) and `4102 Potongan Penjualan` already exist in the seeded COA but are **never referenced by `TikTokAccountingService`** — confirming again that TikTok returns don't get their own journal line today; if the Shopee (or a revised TikTok) design wants a real return/refund journal entry, `4101`/`5002` (`Retur Pembelian`) are the pre-existing, currently-unused accounts designed for exactly that.

---

## 4. SYNC WIRING

### 4.1 `App\Services\TikTokSyncService`
File: `app/Services/TikTokSyncService.php`
Constructor deps: `TikTokClient $tiktok`, `TikTokOrderService $orders`, `TikTokReturnService $returns`, `TikTokSettlementService $settlements`
Shared by both the manual controller buttons and the `tiktok:sync`/`tiktok:describe` cron commands — single source of truth so the two never drift.

```php
public function connection(): ?TiktokConnection
```
`TiktokConnection::latest('id')->first()`.

```php
public function syncReturns(TiktokConnection $conn): int
```
Refreshes token via `freshToken()`. Pages through `TikTokClient::searchReturns($access, $conn->shop_cipher, 50, $token)` up to **40 pages** (`return_orders` fallback `returns` from each page), logs a warning if the page cap is hit (`data belum lengkap`). Calls `TikTokReturnService::store($all)` and returns its count.

```php
/** @return array{count:int, keys:array} */
public function syncSettlements(TiktokConnection $conn): array
```
Refreshes token. Pages through `TikTokClient::getStatements($access, $conn->shop_cipher, 50, $token)` up to **40 pages** (`statements` → fallback `statement_list` → fallback `list`), captures `$firstKeys = array_keys($data)` from the *first* page (diagnostic aid shown in the controller when 0 rows come back — helps spot a TikTok response-shape change). Calls `TikTokSettlementService::store($all)`. Returns `['count' => ..., 'keys' => $firstKeys]`.

```php
/** @return array{done:int, failed:int, remaining:int} */
public function describeSettlements(TiktokConnection $conn, int $limit = 60): array
```
Targets settlements where `kind IS NULL` OR `kind IN self::KETERANGAN_KABUR` (`= ['Potongan lain', 'Penyesuaian TikTok']` — labels considered "still vague, worth re-asking"), ordered by `statement_time DESC`, limited to `$limit`. For each, calls `TikTokClient::getStatementTransactions()`, derives kind via `TikTokSettlementService::deriveKind()`, updates `kind`/`kind_raw`. Per-row try/catch (logs `[tiktok:describe] Statement {id} gagal: ...`, does not abort the batch). Returns done/failed/`remaining = sisaTanpaKeterangan()`.

```php
public function sisaTanpaKeterangan(): int
```
Count of settlements still `kind IS NULL` or in `KETERANGAN_KABUR`.

```php
public function freshToken(TiktokConnection $conn): string
public function toTime(mixed $v): ?Carbon
```
Token refresh (auto-refreshes if `accessExpiringSoon()`) and epoch/seconds-from-now → `Carbon` helper, both reused by the controller directly (`private freshToken()`/`toTime()` in `TikTokController` just delegate here).

Order-side methods on the same service (context only, not core to this doc): `syncOrders()`, `pullOrders()` (private), `backfillOrders()`.

### 4.2 `App\Console\Commands\TikTokSyncCommand`
File: `app/Console/Commands/TikTokSyncCommand.php`
```php
protected $signature = 'tiktok:sync {--returns : Sekalian tarik retur} {--settlements : Sekalian tarik pencairan} {--full : Abaikan filter waktu, sapu 500 order terbaru}';
public function handle(TikTokSyncService $sync): int
```
Always syncs orders first (`$sync->syncOrders($conn, null, (bool) $this->option('full'))`). If `--returns` passed, calls `$sync->syncReturns($conn)`. If `--settlements` passed, calls `$sync->syncSettlements($conn)`. Each block is independently try/caught (`Log::error('[tiktok:sync] ... gagal: ...')`); overall command returns `FAILURE` if *any* block failed, else `SUCCESS`. No connection → warns and returns `SUCCESS` (cron shouldn't be noisy).

### 4.3 `App\Console\Commands\TikTokDescribeCommand`
File: `app/Console/Commands/TikTokDescribeCommand.php`
```php
protected $signature = 'tiktok:describe {--limit=60 : Jumlah statement per jalan}';
public function handle(TikTokSyncService $sync): int
```
Short-circuits (no API call at all) if `sisaTanpaKeterangan() === 0`. Otherwise calls `$sync->describeSettlements($conn, (int) $this->option('limit'))`. Returns `FAILURE` if `done === 0 && failed > 0` (signals a systemic problem — token/scope/rate-limit — vs. just "nothing to do").

*(`TikTokBackfillCommand` and `TikTokAuditCommand` also exist but operate purely on orders, not returns/settlements/accounting — out of this doc's scope.)*

### 4.4 Cron schedule — `routes/console.php`
```php
// Order tiap 30 menit — sekaligus auto-potong stok kalau saklarnya aktif.
Schedule::command('tiktok:sync')->everyThirtyMinutes()->withoutOverlapping(15);

// Retur & pencairan cukup sekali sehari (jarang berubah, hemat kuota API).
Schedule::command('tiktok:sync --returns --settlements')->dailyAt('01:00')->withoutOverlapping(30);

// Keterangan pencairan: satu panggilan API per statement, dijalankan tiap jam.
Schedule::command('tiktok:describe')->hourly()->withoutOverlapping(20);

// Sapu penuh sekali sehari: jaring pengaman kalau ada perubahan status yang lolos
// dari jendela update_time (mis. cron sempat mati lama).
Schedule::command('tiktok:sync --full')->dailyAt('03:30')->withoutOverlapping(30);
```
(Plus the unrelated `shopee:sync` entry already sitting right next to `tiktok:sync` at `everyThirtyMinutes()`, and the app-wide `scheduler-heartbeat`/`db:backup`/`okr-queue-worker` entries — not TikTok-specific.)

**No cron entry posts journals.** `TikTokAccountingService::postPending()` is only ever invoked from `TikTokController::postJournals()` — accounting posting is a deliberate manual action gated behind the `journal_enabled` switch and a confirm dialog, not an automatic background job. (Confirmed by `grep -r postPending` across the repo — only the controller and the test file call it.)

---

## 5. CONTROLLER + ROUTES + PERMISSIONS

### 5.1 `App\Http\Controllers\TikTokController`
File: `app/Http/Controllers/TikTokController.php`
Constructor deps: `TikTokClient $tiktok`, `TikTokOrderService $orders`, `TikTokReturnService $returns`, `TikTokSettlementService $settlements`, `TikTokAccountingService $journals`, `TikTokSyncService $sync`.

**Returns actions:**
| Method | Signature | Behavior |
|---|---|---|
| `syncReturns` | `public function syncReturns(Request $request): RedirectResponse` | Requires an existing connection with `shop_cipher`. Calls `$this->sync->syncReturns($conn)`. Redirects to `tiktok.returns` with count, or an error hint suggesting the "Return" scope may be missing in Partner Center. |
| `returnList` | `public function returnList()` | `TiktokReturn::latest('return_created_at')->latest('id')->paginate(25)`, builds a `preview()` per row via `TikTokReturnService`, renders `tiktok.returns`. |
| `restockReturn` | `public function restockReturn(Request $request, TiktokReturn $ret): RedirectResponse` | Calls `TikTokReturnService::restock($ret, $request->user()->id, $request->input('note'))`, logs `AuditService::log(action: 'tiktok_return_restock', ...)`. |
| `rejectReturn` | `public function rejectReturn(Request $request, TiktokReturn $ret): RedirectResponse` | Calls `TikTokReturnService::reject(...)`, audit-logs `tiktok_return_reject`. |
| `resetReturn` | `public function resetReturn(TiktokReturn $ret): RedirectResponse` | Calls `TikTokReturnService::resetReview($ret)`. |

**Settlements actions:**
| Method | Signature | Behavior |
|---|---|---|
| `syncSettlements` | `public function syncSettlements(Request $request): RedirectResponse` | Calls `$this->sync->syncSettlements($conn)`. If `count === 0`, shows the raw response's top-level keys as a diagnostic hint (`$r['keys']`). |
| `settlementList` | `public function settlementList()` | `TiktokSettlement::latest('statement_time')->latest('id')->paginate(25)`, renders `tiktok.settlements` with the current `connection`. |
| `settlementDetail` | `public function settlementDetail(TiktokSettlement $settlement)` | Calls `TikTokClient::getStatementTransactions()` **live** (not through `TikTokSyncService` — deliberately, per `TikTokDescribeTest::test_service_dipakai_bersama_controller_dan_cron`, since this shows one statement's raw transactions rather than bulk-filling `kind`). Opportunistically fills `kind`/`kind_raw` via `TikTokSettlementService::deriveKind()` if still empty. Also computes `$journalPreview = $this->journals->preview($settlement)`. Renders `tiktok.settlement_detail`. |
| `describeSettlements` | `public function describeSettlements(Request $request): RedirectResponse` | Delegates to `$this->sync->describeSettlements($conn, 60)` — "jump the schedule" button, same logic as the hourly cron. |

**Journal actions:**
| Method | Signature | Behavior |
|---|---|---|
| `postJournals` | `public function postJournals(): RedirectResponse` | Calls `$this->journals->postPending()`, audit-logs `tiktok_post_journals` with the result counts, flashes a summary message (transit/sale/settlement/failed counts). Catches `RuntimeException` (e.g. journal disabled) and flashes it as an error. |
| `unpostJournals` | `public function unpostJournals(): RedirectResponse` | Calls `$this->journals->unpostAll()`, audit-logs `tiktok_unpost_journals`. |
| `toggleJournal` | `public function toggleJournal(Request $request): RedirectResponse` | `$conn->update(['journal_enabled' => $request->boolean('journal_enabled')])` — the master on/off switch. |

**Related (order-side, cutoff) actions, listed for context since they gate what `postPending()` will pick up:** `setDeductFrom()` (sets `deduct_from`, shared cutoff for both stock-deduction and accounting), `toggleAuto()`, `deductStock()`, `deductAll()`, `reverseStock()`.

### 5.2 Routes — `routes/web.php` (all under one group)
```php
Route::middleware('permission:manage_tiktok')->group(function () {
    Route::get('/tiktok', [TikTokController::class, 'index'])->name('tiktok.index');
    Route::get('/tiktok/connect', [TikTokController::class, 'connect'])->name('tiktok.connect');
    Route::get('/tiktok/callback', [TikTokController::class, 'callback'])->name('tiktok.callback');
    Route::post('/tiktok/sync-orders', [TikTokController::class, 'syncOrders'])->name('tiktok.sync-orders');
    Route::get('/tiktok/orders', [TikTokController::class, 'orderList'])->name('tiktok.orders');
    Route::get('/tiktok/stok', [TikTokController::class, 'stockFunnel'])->name('tiktok.stock');
    // ... income routes omitted (unrelated Fase 1 feature) ...
    Route::post('/tiktok/sku-map', [TikTokController::class, 'saveSkuMap'])->name('tiktok.sku-map');
    Route::delete('/tiktok/sku-map/{map}', [TikTokController::class, 'removeSkuMap'])->name('tiktok.sku-map.remove');
    Route::post('/tiktok/orders/{order}/deduct', [TikTokController::class, 'deductStock'])->name('tiktok.deduct');
    Route::post('/tiktok/deduct-all', [TikTokController::class, 'deductAll'])->name('tiktok.deduct-all');
    Route::post('/tiktok/toggle-auto', [TikTokController::class, 'toggleAuto'])->name('tiktok.toggle-auto');
    Route::post('/tiktok/deduct-from', [TikTokController::class, 'setDeductFrom'])->name('tiktok.deduct-from');

    Route::get('/tiktok/returns', [TikTokController::class, 'returnList'])->name('tiktok.returns');
    Route::post('/tiktok/returns/sync', [TikTokController::class, 'syncReturns'])->name('tiktok.returns.sync');
    Route::post('/tiktok/returns/{ret}/restock', [TikTokController::class, 'restockReturn'])->name('tiktok.returns.restock');
    Route::post('/tiktok/returns/{ret}/reject', [TikTokController::class, 'rejectReturn'])->name('tiktok.returns.reject');
    Route::post('/tiktok/returns/{ret}/reset', [TikTokController::class, 'resetReturn'])->name('tiktok.returns.reset');
    Route::post('/tiktok/orders/{order}/reverse', [TikTokController::class, 'reverseStock'])->name('tiktok.reverse');

    Route::get('/tiktok/settlements', [TikTokController::class, 'settlementList'])->name('tiktok.settlements');
    Route::post('/tiktok/settlements/sync', [TikTokController::class, 'syncSettlements'])->name('tiktok.settlements.sync');
    Route::post('/tiktok/settlements/describe', [TikTokController::class, 'describeSettlements'])->name('tiktok.settlements.describe');
    Route::post('/tiktok/post-journals', [TikTokController::class, 'postJournals'])->name('tiktok.post-journals');
    Route::post('/tiktok/unpost-journals', [TikTokController::class, 'unpostJournals'])->name('tiktok.unpost-journals');
    Route::post('/tiktok/toggle-journal', [TikTokController::class, 'toggleJournal'])->name('tiktok.toggle-journal');
    Route::get('/tiktok/settlements/{settlement}/detail', [TikTokController::class, 'settlementDetail'])->name('tiktok.settlements.detail');
    Route::delete('/tiktok/disconnect', [TikTokController::class, 'disconnect'])->name('tiktok.disconnect');
});
```
Every single TikTok route — orders, returns, settlements, journal, connection management — sits behind **one** middleware gate: `permission:manage_tiktok`. There is no finer-grained split (e.g. no separate "can view settlements" vs "can post journals" permission).

### 5.3 Permission definition — `app/Support/Permissions.php`
```php
public const DEFINITIONS = [
    // ...
    'manage_tiktok' => 'Integrasi TikTok Shop',
    'manage_shopee' => 'Integrasi Shopee',   // already defined, parallel key ready for the mirror
    // ...
];

public const DEFAULTS = [
    // ...
    'manage_tiktok' => [User::ROLE_ADMIN],
    'manage_shopee' => [User::ROLE_ADMIN],
    // ...
];
```
`Permissions::roleHas($role, $key)`: `super_admin` always passes (locked, implicit); otherwise checks a per-role `role_permissions` DB override first, falling back to `DEFAULTS`. So in practice: `super_admin` and `admin` can reach every TikTok route out of the box; other roles need an explicit `role_permissions` grant via the admin permissions-matrix UI. **`manage_shopee` already exists as a sibling key** with the identical `[User::ROLE_ADMIN]` default — the Shopee mirror doesn't need a new permission key, just to gate its routes with `permission:manage_shopee` the same way.

---

## 6. VIEWS

Directory: `resources/views/tiktok/`

| File | What it shows |
|---|---|
| `returns.blade.php` | Retur list page. "↻ Tarik Retur dari TikTok" sync button; per-return card showing return id, linked order id, status badge, each line item's SKU→product recipe preview (or a red "SKU belum ada resep" flag if unmapped); action buttons are state-dependent: pending → "✓ Terima & Tambah Stok" (disabled/hint if not all SKUs matched) + "✗ Tolak (cacat)"; restocked → green badge + "batalkan"; rejected → red badge + "ubah". No accounting/journal UI at all on this page (consistent with §0). |
| `settlements.blade.php` | Dana Cair (payout) list page. Header explains everything is cron-automated (buttons are just "jump the schedule"): "🏷️ Ambil Keterangan sekarang" and "↻ Tarik Pencairan sekarang". **Contains the entire journal control panel** — this is the only place the accounting on/off switch and posting buttons live (there is no separate `journal.blade.php`): a `journal_enabled` toggle checkbox (auto-submits on change), a conditional "📒 Posting Jurnal" button (only shown when the switch is on, confirm-dialog warns what it will do), and an always-visible "↩ Cabut semua jurnal TikTok" link (confirm-dialog explains scope: only TikTok-sourced journals). Below that, a paginated table of settlements: statement id, date, `kind` badge (green if sale, amber if a deduction, gray "Potongan (?)" if not yet described), omzet/fee/adjustment/net columns, and a "Lihat rincian →" link per row. |
| `settlement_detail.blade.php` | Single-settlement detail page. Summary card (date/omzet/fee/net). **"Rencana Jurnal" preview table** — renders `$journalPreview['lines']` (account code+name, memo, debit, credit) with a balanced/unbalanced badge, and either "✓ Sudah diposting ke jurnal (#id)..." or an explanatory note that settlement isn't new revenue. A collapsible raw-JSON dump of all transactions pulled live from TikTok (for reverse-engineering field names), a per-transaction table (translated label, raw type, related order, amount, expandable raw JSON), and a final collapsible raw-JSON dump of the stored `settlement.raw` column. |
| `index.blade.php` | Integration landing/status page: connection status, stale-sync warning banner (cross-references the scheduler heartbeat cache key to distinguish "cron dead" vs "task failing"), connect/disconnect actions, links out to Orders/Retur/Dana Cair/Stok sub-pages. No return/settlement/journal business logic itself. |
| `orders.blade.php` | Order list + stock-deduction preview/approve UI (SKU mapping, cutoff date control, auto-deduct toggle). Not return/settlement/journal-specific — included here only because it's in the same directory. |
| `stock.blade.php` | "Konversi Stok per Item" funnel report (Total / Dalam Perjalanan / Terkirim / Sisa bars per product), sourced from `TikTokOrderService::stockFunnel()`. Not return/settlement/journal-specific. |
| `income.blade.php` | Unrelated Fase-1 feature (CSV/xlsx income report upload+merge via `TikTokIncomeController`/`TikTokIncomeReportService`) — not part of the returns/settlement/accounting flow, listed only for completeness of the directory. |

---

## 7. TESTS

### 7.1 `tests/Feature/TikTokTest.php` (892 lines, `RefreshDatabase`, PHPUnit-style `test_*` methods)

**Accounting/journal-specific:**
| Test | Asserts |
|---|---|
| `test_journal_off_by_default_never_touches_the_books` | `TikTokAccountingService::enabled()` is false with a connection that has no `journal_enabled` set; `postPending()` throws `RuntimeException`. |
| `test_unpost_removes_only_tiktok_journals` | After `postPending()` + a manually-recorded non-TikTok journal (`source_type='excel_import'`), `AccJournal::count() === 2`; after `unpostAll()`, only the TikTok one is gone (`journals === 1`), the Excel journal survives, the `iklan` account balance is back to `0`, and the settlement's `posting_status` reverts to `'pending'`. |
| `test_option_c_full_cycle_transit_clears_and_piutang_settles` | The full 3-step happy path end to end: (1) `deduct()` locks `hpp_amount=50000`; `postPending()` posts only the transit journal, `transit` balance = 50000, `penjualan`/`hpp` balances = 0. (2) order flipped to `DELIVERED`, `postPending()` again → `sale_journal_id` set, `transit` back to 0, `penjualan` = −100000 (credit), `hpp` = 50000, `piutang` = 100000. (3) a matching `TiktokSettlement` (revenue 100000, fee 8000, net 92000) created, `postPending()` again → `piutang` = 0 (lunas), `kas` = 92000, `fee` = 8000. |
| `test_backfills_hpp_for_orders_deducted_before_column_existed` | An order with `stock_status=DEDUCTED` but `hpp_amount=0` gets its HPP recomputed by the backfill pass inside `postPending()`, and both transit+sale journals post correctly afterward (transit ends at 0, `penjualan`=−100000). |
| `test_sale_posts_even_when_hpp_unknown` | A delivered order with `cogs=0` still posts revenue (`penjualan`=−75000) with `transit` staying at 0 — revenue is never gated on HPP. |
| `test_posting_is_idempotent` | Calling `postPending()` twice only posts the settlement once (`r1['settlement']===1`, `r2['settlement']===0`); `iklan` balance is not doubled. |
| `test_journal_preview_balances_for_sales_and_ads` | `TikTokAccountingService::preview()` for a sales settlement: `balanced===true`, credits `1103` by the full bruto (10,000,000), **never touches `4001`** (confirms settlement ≠ new revenue), debits `1003` by net (9,200,000) and `6005` by fee (800,000). For an ads-only settlement: debits `6001` (Beban Iklan) and credits `1003` by the same 100,178. |

**Returns-specific:**
| Test | Asserts |
|---|---|
| `test_return_restock_adds_stock_reject_does_not_reverse_pulls_back` | Restock → `hq_stock` +2 (100→102), a `stock_movements` row with `movement_type=IN`, `reference_type=tiktok_return`; reject on a different pending return → stock unchanged; resetting the *restocked* one back to pending → stock pulled back to 100. |
| `test_return_only_sku_appears_in_recipe_panel` | A SKU that only ever appears on a return (not any order) still surfaces in `TikTokOrderService::skusNeedingMap()` for manual mapping. |
| `test_return_sync_stores_and_reseller_forbidden` | `POST /tiktok/returns/sync` (mocked `Http::fake` on `*/return_refund/202309/returns/search*`) stores a `tiktok_returns` row correctly mapped from the API shape; a `RESELLER`-role user gets `403` on `GET /tiktok/returns`. |

**Settlements-specific:**
| Test | Asserts |
|---|---|
| `test_settlement_sync_stores_and_maps_amounts` | `POST /tiktok/settlements/sync` (mocked on `*/finance/202309/statements*`) stores `revenue_amount`/`settlement_amount` as decimal strings, and **`fee_amount` is stored positive** even though the API sent `-800000`; `posting_status` starts `'pending'`. |
| `test_settlement_detail_pulls_transactions` | `GET /tiktok/settlements/{id}/detail` (mocked on `*/statement_transactions*`) renders the translated label ("Iklan afiliasi") for a raw `AFFILIATE_AD` transaction type. |
| `test_describe_settlements_fills_kind_from_transactions` | `POST /tiktok/settlements/describe` updates `kind` from the (mocked) transaction breakdown. |
| `test_sales_settlement_labeled_penjualan_on_sync` | `TikTokSettlementService::store()` directly labels a `revenue_amount > 0` statement `kind='Penjualan'`. |
| `test_settlement_page_renders_and_reseller_forbidden` | `GET /tiktok/settlements` renders for an authorized role; forbidden for `RESELLER`. |

**Order/sync/client tests in the same file** (context only, not return/settlement/accounting — listed for completeness of file coverage): `test_peta_sku_ajax_json_simpan_dan_hapus`, `test_signature_is_stable_order_independent_and_excludes_token`, `test_callback_exchanges_code_and_stores_connection`, `test_callback_without_code_shows_error`, `test_search_orders_sends_json_object_body`, `test_sync_paginates_and_sorts_newest_first`, `test_index_renders_and_reseller_forbidden`, `test_stock_funnel_buckets_by_status`, `test_cutoff_blocks_pre_opname_orders_from_deduction`, `test_deduct_all_only_takes_orders_from_cutoff`, `test_sync_uses_update_time_window_to_catch_status_changes`, `test_sync_falls_back_to_full_pull_when_filter_rejected`, `test_first_sync_has_no_window`, `test_backfill_pulls_past_the_old_500_order_cap`, `test_backfill_command_reports_growth`, `test_backfill_rejects_bad_range`, `test_sync_command_pulls_orders_and_auto_deducts`, `test_sync_command_is_quiet_when_not_connected`, `test_sync_stores_orders_and_matches_sku_to_product`, `test_deduct_reduces_stock_idempotently_and_reverses`, `test_deduct_all_only_processes_shipped_and_matched`, `test_toggle_auto_deduct`, `test_cannot_deduct_when_not_shipped_or_unmapped`, `test_sku_map_makes_order_matchable`, `test_recipe_multiplies_qty_and_handles_bundle`, `test_audit_explains_gap_by_listing_excluded_orders`, `test_audit_handles_empty_month`.

### 7.2 `tests/Feature/TikTokDescribeTest.php` (178 lines)
All settlement-`kind`-enrichment focused (the step that decides `iklan` vs `ongkir` routing in the settlement journal recipe):
| Test | Asserts |
|---|---|
| `test_mengisi_keterangan_yang_masih_kosong` | `php artisan tiktok:describe` fills `kind` for a settlement that has none, via a mocked `TikTokClient`. |
| `test_tanpa_tunggakan_tidak_menyentuh_api_sama_sekali` | If nothing needs describing, the command exits `0` **without calling `getStatementTransactions` at all** (`shouldNotReceive`) — API-quota discipline. |
| `test_keterangan_kabur_dicoba_lagi` | A settlement labeled `'Potongan lain'` (vague/guessed) gets re-described rather than skipped. |
| `test_gagal_semua_keluar_dengan_status_gagal` | If every `getStatementTransactions` call throws, the command exits `1` (`FAILURE`) with a message pointing at token/scope/rate-limit, distinguishing "broken" from "nothing to do". |
| `test_belum_terhubung_dilewati_tanpa_error` | No connection → exits `0` quietly (cron-friendly). |
| `test_limit_membatasi_jumlah_per_jalan` | `--limit=2` against 5 pending settlements only calls the API twice and reports `sisa: 3` remaining. |
| `test_dijadwalkan_tiap_jam` | Asserts the actual `Schedule` facade events contain a `tiktok:describe` entry with cron expression `0 * * * *` (hourly) — a live check against `routes/console.php`, not just a string search. |
| `test_service_dipakai_bersama_controller_dan_cron` | Structural/anti-drift test: asserts `TikTokSyncService::describeSettlements` exists and that `TikTokController::describeSettlements()`'s method body calls `$this->sync->describeSettlements(...)` and does **not** itself contain `getStatementTransactions`/`deriveKind` (i.e. the controller must delegate, not duplicate logic) — while explicitly allowing `settlementDetail()` (a different method) to call the API directly, since that one is intentionally a live single-statement view. |

### 7.3 No coverage found elsewhere
Searched `tests/Unit` and the rest of `tests/Feature` (`BackdatedSaleTest`, `ChannelSalesTest`, `MarketplaceBackdateMovementTest`, `MarketplaceMovementDateBackfillTest`, `StockReconcileHqTest`, `TimezoneShiftTest`, etc.) — none reference `TiktokReturn`, `TiktokSettlement`, or `TikTokAccountingService`. Those files touch TikTok only tangentially (generic cross-channel stock/date reconciliation). The two files above are the complete test surface for this feature set.

---

## Appendix — full file inventory touched by this research

**Models:** `app/Models/TiktokReturn.php`, `app/Models/TiktokSettlement.php`, `app/Models/TiktokOrder.php`, `app/Models/TiktokConnection.php`, `app/Models/TiktokSkuMap.php`, `app/Models/AccJournal.php`, `app/Models/AccJournalLine.php`, `app/Models/AccAccount.php`, `app/Models/AccBranch.php`

**Migrations:** `database/migrations/2026_01_01_000030_create_tiktok_connections_table.php`, `..._000031_fix_tiktok_expiry_columns_to_datetime.php`, `..._000032_create_tiktok_orders_table.php`, `..._000033_add_qty_to_tiktok_sku_maps.php`, `..._000034_add_auto_deduct_to_tiktok_connections.php`, `..._000035_create_tiktok_returns_table.php`, `..._000036_create_tiktok_settlements_table.php`, `..._000037_add_kind_to_tiktok_settlements.php`, `..._000038_add_deduct_from_to_tiktok_connections.php`, `..._000039_add_journal_tracking_to_tiktok_orders.php`, `..._000040_add_journal_enabled_to_tiktok_connections.php`

**Seeder:** `database/seeders/ChartOfAccountSeeder.php`

**Services:** `app/Services/TikTokClient.php`, `app/Services/TikTokReturnService.php`, `app/Services/TikTokSettlementService.php`, `app/Services/TikTokAccountingService.php`, `app/Services/TikTokOrderService.php`, `app/Services/TikTokSyncService.php`, `app/Services/AccountingService.php`

**Console:** `app/Console/Commands/TikTokSyncCommand.php`, `app/Console/Commands/TikTokDescribeCommand.php`, `routes/console.php`

**HTTP:** `app/Http/Controllers/TikTokController.php`, `routes/web.php`, `app/Support/Permissions.php`

**Views:** `resources/views/tiktok/returns.blade.php`, `resources/views/tiktok/settlements.blade.php`, `resources/views/tiktok/settlement_detail.blade.php`, `resources/views/tiktok/index.blade.php`, `resources/views/tiktok/orders.blade.php`, `resources/views/tiktok/stock.blade.php`

**Tests:** `tests/Feature/TikTokTest.php`, `tests/Feature/TikTokDescribeTest.php`

**Config:** `config/services.php` (`services.tiktok.*`)
