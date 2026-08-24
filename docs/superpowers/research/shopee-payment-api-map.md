# Shopee Open Platform v2 — Payment/Finance API Map

Purpose: map Shopee Open Platform v2's payment/finance/settlement endpoints so a "Shopee settlement" feature can be designed to mirror the **outcome** of the existing TikTok settlement (know net cash paid out, fees, and categorize non-sales deductions like ads/shipping/refund for accounting). Read-only research — no source files were modified.

Repo root: `C:\Users\DELL\Downloads\skinku-b2b-php`

---

## 0. Source & confidence notice — READ THIS FIRST

**`open.shopee.com` was completely unreachable.** Every attempt (root domain, specific doc paths like `/documents/v2/v2.payment.get_escrow_detail`) was refused outright by the fetch tool ("unable to fetch from open.shopee.com"). `web.archive.org` was also refused for the same reason (one narrow exception: the Wayback "availability" JSON API responded, but reported **no archived snapshot** for the escrow-detail doc URL). `WebSearch` found no indexed field-level content either — Shopee's doc site is a JS SPA that search engines don't seem to index below the module level.

Given that, **every field/enum/path in this document is sourced from a third-party open-source SDK, not Shopee's own docs**, specifically:

- **Primary source**: [`congminh1254/shopee-sdk`](https://github.com/congminh1254/shopee-sdk) (TypeScript, MIT, actively maintained). Two files were downloaded **in full** and read directly (not summarized by a tool):
  - `src/schemas/payment.ts` (105,703 bytes / 2,665 lines) — every request/response TypeScript interface, with JSDoc comments.
  - `src/managers/payment.manager.ts` (370 lines) — the actual HTTP method + path + `auth` flag for every call.
  - `src/schemas/fetch.ts` — the common response envelope.
- **Cross-checked against**: `teacat/shopeego` (Go SDK) and `mu-hanz/shoapi` (Laravel SDK) — both confirm the same method names exist, but added no new field detail.

**Why this is trustworthy but not gospel**: the JSDoc comments in `payment.ts` read as verbatim reproductions of Shopee's official reference — they include the literal `escrow_amount` arithmetic formula, numbered `transaction_type` enum codes (`ESCROW_VERIFIED_ADD = 101`, etc.), and region-specific asides ("only for BR local seller", "Malaysia SST") that no hand-written community SDK would fabricate. This is almost certainly text scraped/generated from Shopee's real API reference at some past date. **Confidence: HIGH for field names/shapes and endpoint paths, MEDIUM for completeness and current-ness** (Shopee revises fields; this SDK could lag). Treat this whole document as "what to test against the sandbox first," not as a substitute for a live call. Every section below is unambiguously **DOCS** (from the SDK source) — nothing here is silently inferred except the explicitly-labeled **INFERRED** callouts in §5.

---

## 1. Repo context — what already exists to reuse

### `app/Services/ShopeeClient.php`
Full source read. Key facts:
- Sign scheme: `HMAC-SHA256(partner_id + path + timestamp [+ access_token + shop_id], partner_key)` — values concatenated **sequentially**, NOT sorted-by-key like TikTok. Verified byte-identical against Shopee's sandbox API Test Tool (per the class docblock, 2026-08-24).
- Two call shapes already implemented: `publicCall()` (no `access_token`/`shop_id` in the sign — used today only for token exchange) and `shopCall(method, path, accessToken, shopId, params)` (shop-level, signs `access_token` + `shop_id` too). **Every payment/finance endpoint in this doc needs `shopCall()`** — see §3.
- Error handling: Shopee signals errors via a non-empty `error` string field in the JSON body (not HTTP status) — `handle()` already throws on this. This matches the `FetchResponse<T> { request_id, error, message, response, result? }` envelope confirmed in the SDK's `fetch.ts` — good cross-check that the SDK source is accurate.
- Access token lifetime ~4 hours (vs TikTok's 7 days) — any new settlement puller/cron needs the same `freshToken()`-before-call pattern `ShopeeConnection::accessExpiringSoon()` already supports (10-minute buffer).
- **No payment/finance methods exist yet** — `getOrderList`, `getOrderDetail`, `getReturnList`, `getReturnDetail`, auth, and `getShopsByPartner` only. Clean slate for this feature.
- `config('services.shopee.api_base')` defaults to `https://partner.shopeemobile.com` (live); sandbox is `https://openplatform.sandbox.test-stable.shopee.sg` (verified working 2026-08-24, per `config/services.php` comment — note this is *not* the `partner.test-stable.shopeemobile.com` host you'd guess).

### `app/Models/ShopeeOrder.php`
`order_sn` is already the fillable join key (`'order_sn', 'status', 'total_amount', 'hpp_amount', 'currency', 'line_items', 'stock_status', ...`). This is exactly the key every payment endpoint below keys off of — no schema change needed to link a settlement/escrow row back to an order.

### `app/Models/ShopeeConnection.php`
Holds `access_token`/`refresh_token` (hidden), `access_expires_at` (10-min refresh buffer, tighter than TikTok's 5-min because Shopee tokens are much shorter-lived), `auto_deduct`, `deduct_from` (cutoff date, same role as TikTok's `deduct_from`). No `journal_enabled` switch yet (TikTok has one) — would need to be added alongside whatever `ShopeeAccountingService` gets built.

### Also present (found while checking, not requested but relevant): `app/Models/ShopeeReturn.php` and migration `2026_01_01_000093_create_shopee_returns_table.php` — Shopee's returns mirror (parallel to `TiktokReturn`) is **already built**. There is **no `shopee_settlements`/income table or migration yet** — that gap is exactly what this doc is scoping.

### TikTok settlement recipe (what we're mirroring the *outcome* of)
Full detail lives in `docs/superpowers/research/tiktok-fase2-4-map.md` §3.2 and §3.3.4; summarized here for reference:
- `TiktokSettlement` = **one row per TikTok "statement"** (an aggregate payout unit from `GET /finance/202309/statements`), columns: `revenue_amount` (bruto), `fee_amount` (always stored positive), `adjustment_amount`, `settlement_amount` (net — literally the bank cash movement), `statement_time`, `paid_time`, `kind` (derived label), `raw` (full API payload).
- `kind` is a **heuristic guess**: `revenue_amount > 0` → `"Penjualan"`; else `shipping_cost_amount != 0` → `"Ongkir / logistik"`; else defaults to `"Iklan TikTok"` (confirmed by the business owner that unexplained ~daily deductions are ad spend). Optionally refined by pulling a statement's `statement_transactions` and majority-voting the sub-transaction `type` field (`deriveKind()`).
- Accounting (`TikTokAccountingService::previewSettlement()`) is a **control-account pattern**: `revenue_amount > 0` branch bulk-clears the `Piutang TikTok` receivable by the statement's own bruto figure — **no per-order matching required**. `revenue_amount <= 0` branch is a pure expense-vs-cash line, booked to Iklan (default) or Ongkir account based on `kind`.
- This control-account trick exists *because TikTok's statement API gives no per-order fee breakdown* — the lump `fee_amount`/`kind` heuristic is a workaround for missing data, not a design ideal. **This matters for the recommendation in §5** — Shopee's data shape is different enough that copying this workaround verbatim may throw away better data Shopee actually provides.

---

## 2. Endpoint map

All endpoints below live under path prefix **`/payment/*`** (full path e.g. `/api/v2/payment/get_escrow_detail` — the manager source only stores the `/payment/...` suffix, base + `/api/v2` prefix presumably added by the SDK's own base client, consistent with every other module in `ShopeeClient.php` which hardcodes the full `/api/v2/...` path). **Sign type: every single one is shop-level** (`auth: true` in the SDK — requires `access_token` + `shop_id`, i.e. `ShopeeClient::shopCall()`), **none are public-call**. The one documentation wrinkle: `get_payment_method_list`'s JSDoc says *"Obtain payment method (no authentication required)"* but the code still sets `auth: true` — code wins as ground truth, but this is a minor/non-settlement endpoint so it's not worth chasing further.

### 2.1 `get_escrow_detail` — per-order fee/income breakdown

| | |
|---|---|
| Path | `GET /api/v2/payment/get_escrow_detail` |
| Sign type | Shop-level |
| Purpose (docblock) | "Use this API to fetch the accounting detail of order." |
| Params | `order_sn` (string, **required**) — one order per call |
| Pagination | None (single order) |

**Response** (`response.order_sn`, `response.buyer_user_name`, `response.return_order_sn_list[]`, `response.order_income{...}`, `response.buyer_payment_info{...}`).

`order_income` has **~90 fields** (this is Shopee's most granular endpoint by far). Grouped by role — the full raw list is preserved in the downloaded schema (`src/schemas/payment.ts` lines 517–920-ish of the fetched file) if a field below is missing:

| Group | Fields | Notes |
|---|---|---|
| **Headline / totals** | `escrow_amount`, `buyer_total_amount`, `order_original_price`/`original_price`, `order_discounted_price`, `order_selling_price`, `cost_of_goods_sold`, `original_cost_of_goods_sold` | `escrow_amount` = "total amount seller is expected to receive"; full arithmetic formula is documented (see callout below) |
| **Platform fees** | `commission_fee`, `service_fee`, `seller_transaction_fee`, `buyer_transaction_fee`, `campaign_fee`, `order_ams_commission_fee`, `credit_card_transaction_fee`, `seller_order_processing_fee`, `fbs_fee` | These are the ones the user asked about by name — all confirmed present. `campaign_fee` = "campaign fee charged by Shopee platform" — this is Shopee's equivalent of TikTok's "ads" deduction, but charged **per order** rather than as a separate lump statement line |
| **Net fee variants (BR-local only)** | `net_commission_fee`, `net_service_fee`, `net_commission_fee_info_list[]{rule_id, fee_amount, rule_display_name}`, `net_service_fee_info_list[]{...+category}`, `seller_product_rebate{amount, commission_fee_offset, service_fee_offset}` | Skip for Indonesia — Brazil-specific per docblock |
| **Discounts / vouchers** | `seller_discount`, `shopee_discount`, `original_shopee_discount`, `order_seller_discount`, `voucher_from_seller`, `voucher_from_shopee`, `voucher_from_external_party`, `seller_voucher_code[]`, `coins`, `seller_coin_cash_back`, `credit_card_promotion`, `payment_promotion`, `remaining_voucher` | |
| **Shipping** | `actual_shipping_fee`, `buyer_paid_shipping_fee`, `estimated_shipping_fee`, `final_shipping_fee`, `shopee_shipping_rebate`, `shipping_fee_discount_from_3pl`, `seller_shipping_discount`, `order_chargeable_weight`, `reverse_shipping_fee`, `final_return_to_seller_shipping_fee`, `overseas_return_service_fee` | Shipping economics live *inside* the order escrow, not as a separate ledger event — see §5 open question |
| **Returns / adjustments** | `drc_adjustable_refund`, `seller_return_refund`, `order_adjustment[]{amount, date, currency, adjustment_reason}`, `total_adjustment_amount`, `escrow_amount_after_adjustment` | **`adjustment_reason` is free text but documented as one of**: "Return Refund deduction or compensation", "logistic issue deduction or compensation", "marketing fee deduction", "payment related fee" — this is Shopee's own categorization hook, directly analogous to what TikTok's `translateType()` heuristic tries to reconstruct by guessing |
| **Tax / withholding** | `vat`(via buyer_payment_info)/`escrow_tax` ("Cross-border tax imposed by **Indonesian government**" — relevant!), `withholding_tax` ("PH/ID regulations" — relevant!), `sales_tax_on_lvg` (Malaysia), `shipping_fee_sst`/`reverse_shipping_fee_sst` (Malaysia SST), `vat_on_imported_goods` (Thailand/Vietnam), `withholding_vat_tax`/`withholding_pit_tax`/`withholding_cit_tax` (Vietnam), `th_import_duty` (Thailand), `tax_registration_code`, `final_product_vat_tax`/`final_shipping_vat_tax` (EU), `final_escrow_product_gst`/`final_escrow_shipping_gst` (Singapore) | **Bold = actually relevant to an Indonesian shop.** Everything else here should be dead/zero for SKINKU's shop and can be ignored or stored generically in `raw` |
| **Cross-border / multi-currency** | `escrow_amount_pri`, `buyer_total_amount_pri`, `original_price_pri`, `commission_fee_pri`, `service_fee_pri`, `pri_currency`, `aff_currency`, `exchange_rate`, `sip_subsidy`/`sip_subsidy_pri` | Only populated for CB/SIP-affiliate shops — see §5, likely N/A |
| **Insurance / protection programs** | `final_product_protection`, `rsf_seller_protection_fee_claim_amount`, `fsf_seller_protection_fee_claim_amount`, `shipping_seller_protection_fee_amount`, `delivery_seller_protection_fee_premium_amount`, `seller_lost_compensation` | |
| **Misc / other-region (BR/AR)** | `pix_discount`, `prorated_pix_discount_offset_return_items`, `bcrs_deposit` ("$0.10 per container deposit fee"), `buyer_paid_packaging_fee`, `trade_in_bonus_by_seller`, `ads_escrow_top_up_fee_or_technical_support_fee`, `instalment_plan`, `tenure_info_list[]{payment_channel_name, instalment_plan}`, `prorated_coins_value_offset_return_items`, `prorated_shopee_voucher_offset_return_items`, `prorated_seller_voucher_offset_return_items`, `prorated_payment_channel_promo_bank_offset_return_items`, `prorated_payment_channel_promo_shopee_offset_return_items` | Long tail, mostly skippable |
| **`items[]`** | `item_sku`/`model_sku`, `item_name`/`model_name`, `quantity_purchased`, `original_price`, `sale_price`, `discounted_price`, `is_wholesale`, `weight`, `is_add_on_deal`, `is_main_item`, `seller_discount`, `shopee_discount`, plus a `GetEscrowDetailKitItem[]` sub-array for BR bundle components | Line-item level, probably not needed for settlement (order-level total is enough), useful only if per-SKU margin is ever wanted |
| **`buyer_payment_info{}`** | `buyer_payment_method`, `buyer_service_fee`, `buyer_tax_amount`, `buyer_total_amount`, `is_paid_by_credit_card`, `shipping_fee`, `shopee_voucher`, `seller_voucher`, `shopee_coins_redeemed`, `credit_card_promotion`, `merchant_subtotal`, plus several BR-only tax fields (`icms_tax_amount`, `iof_tax_amount`, `import_tax_amount`) | Buyer-side snapshot — not seller income, informational only |

**`escrow_amount` formula (quoted verbatim from the SDK's docblock, which itself is almost certainly Shopee's own text)**:
> *"For non cb sip affiliate shop (new formula): `escrow_amount = original_cost_of_goods_sold − original_shopee_discount + seller_return_refund + shopee_discount − voucher_from_seller − seller_coin_cash_back + buyer_paid_shipping_fee − actual_shipping_fee + shopee_shipping_rebate + shipping_fee_discount_from_3pl − reverse_shipping_fee + rsf_seller_protection_fee_claim_amount − final_return_to_seller_shipping_fee − seller_transaction_fee − service_fee − commission_fee − campaign_fee − shipping_seller_protection_fee_amount − delivery_seller_protection_fee_premium_amount − final_escrow_product_gst − order_ams_commission_fee − escrow_tax − sales_tax_on_lvg − reverse_shipping_fee_sst − shipping_fee_sst − withholding_tax − overseas_return_service_fee − vat_on_imported_goods − withholding_vat_tax − withholding_pit_tax − withholding_cit_tax − seller_order_processing_fee + buyer_paid_packaging_fee − trade_in_bonus_by_seller − fbs_fee − ads_escrow_top_up_fee_or_technical_support_fee − th_import_duty`. For cb sip affiliate shop: `escrow_amount = escrow_amount_pri × exchange_rate`."*

This formula is genuinely useful: it tells you exactly which fields are additive vs subtractive, i.e. which ones are "fees/deductions" (subtracted) vs "compensation/rebate" (added) — a ready-made sign convention for journal-line generation, no guessing needed.

---

### 2.2 `get_escrow_list` — discover released orders by time window

| | |
|---|---|
| Path | `GET /api/v2/payment/get_escrow_list` |
| Sign type | Shop-level |
| Purpose (docblock) | "Use this API to fetch the accounting list of order." |
| Params | `release_time_from` (required, timestamp), `release_time_to` (required, timestamp), `page_size` (optional, max 100, default 40), `page_no` (optional, min 1, default 1) |
| Pagination | `page_no` (page-number style, **not** cursor) + response `more: boolean` |

**Response**: `response.escrow_list[]{ order_sn, payout_amount, escrow_release_time }`, `response.more`.

This is the **lightweight discovery call** — no fee breakdown, just "which orders were released, when, and their net payout." This is exactly the endpoint that answers the user's Q4 ("avoid one call per order") when paired with §2.3.

---

### 2.3 `get_escrow_detail_batch` — fee breakdown for up to 50 known orders

| | |
|---|---|
| Path | `POST /api/v2/payment/get_escrow_detail_batch` |
| Sign type | Shop-level |
| Purpose (docblock) | "Use this API to fetch the details of order income by batch." |
| Params | `order_sn_list` (string[], **required**, limit 1–50) |
| Pagination | None — caller chunks `order_sn_list` into ≤50-item batches |

**Response**: `response` is an array of `{ order_sn, buyer_user_name, return_order_sn_list[], order_income{...same ~90-field shape as §2.1}, buyer_payment_info{...same as §2.1} }`.

**Practical pairing with §2.2**: call `get_escrow_list(release_time_from, release_time_to)` for a sync window → collect `order_sn`s → chunk into groups of ≤50 → call `get_escrow_detail_batch` for the full fee breakdown. This is the efficient "N+1 avoidance" pattern the user asked about, and it's a **two-endpoint** pattern, not one.

---

### 2.4 `get_wallet_transaction_list` — seller wallet/balance ledger

| | |
|---|---|
| Path | `GET /api/v2/payment/get_wallet_transaction_list` |
| Sign type | Shop-level |
| Purpose (docblock) | **"Use this API to get the transaction records of wallet. Only applicable for local shops"** — this restriction is explicit in the docs, see §4 |
| Params | `page_no` (required, default 0), `page_size` (required, default 40, ≤100), `create_time_from`/`create_time_to` (optional, **max 15-day range** — same limit as `get_order_list`), `wallet_type` (optional), `transaction_type` (optional filter), `money_flow` (optional filter: `MONEY_IN`/`MONEY_OUT`), `transaction_tab_type` (optional filter — see enum below) |
| Pagination | `page_no` (**page-number style**, not cursor/offset-token) + response `more: boolean`. No `next_cursor` field on this endpoint (unlike the newer payout/income endpoints — verify empirically, see §5) |

**Response**: `response.transaction_list[]`, `response.more`.

Per-transaction fields:

| Field | Type | Notes |
|---|---|---|
| `status` | enum | `FAILED` \| `COMPLETED` \| `PENDING` \| `INITIAL` |
| `transaction_type` | string (numeric-coded enum) | Full list below — this is the categorization the whole feature hinges on |
| `txn_title` | string | "sent by client (Adjustment Center) for adjustments, only for ID local sellers for now" — **ID-specific field** |
| `amount` | number | Signed? Sign convention not explicitly documented — verify in sandbox (see §5) |
| `current_balance` | number | Running wallet balance **after** this transaction — TikTok has no equivalent; useful for reconciliation/audit |
| `create_time` | number (unix ts) | |
| `order_sn` | string, optional | Present for order-linked transactions |
| `refund_sn` | string, optional | Present for refund-linked transactions |
| `withdrawal_type` | string, optional | |
| `transaction_fee` | number, optional | Fee charged on the transaction itself (e.g. a withdrawal fee) |
| `description` | string, optional | "detailed description of TOPUP SUCCESS and TOPUP FAILED" |
| `buyer_name` | string, optional | |
| `pay_order_list` | array `{order_sn, shop_name}[]`, optional | **Suggests a single wallet transaction can bundle multiple orders** — see §5 |
| `shop_name` | string, optional | |
| `withdrawal_id` / `root_withdrawal_id` | number, optional | "Use root_withdrawal_id to indicate the event where a withdrawal is split into several withdrawals due to the withdrawal limit" |
| `reason` | string, optional | "The reason for ADJUSTMENT_ADD and ADJUSTMENT_MINUS" — free text, same role as `order_adjustment.adjustment_reason` in §2.1 |
| `transaction_tab_type` | enum | see below |
| `money_flow` | enum | `MONEY_IN` (addition) / `MONEY_OUT` (deduction) — "special case for TW JKO Pay, money_flow is ignored" |
| `outlet_shop_name` | string, optional | Indonesia "Instant Mart" outlet redirection context |

**`transaction_type` — full enum, quoted verbatim from the docblock (this directly answers the user's Q2)**:

| Code | Value | Meaning (quoted) |
|---|---|---|
| 101 | `ESCROW_VERIFIED_ADD` | "Escrow has been verified and paid to seller" |
| 102 | `ESCROW_VERIFIED_MINUS` | "Escrow has been verified and charged from seller as escrow amount is negative" |
| 201 | `WITHDRAWAL_CREATED` | "The seller has created a withdrawal, so it's deducted from balance" |
| 202 | `WITHDRAWAL_COMPLETED` | "The withdrawal has been completed, so the ongoing amount decreases" |
| 203 | `WITHDRAWAL_CANCELLED` | "The withdrawal has been canceled, so the amount is added back to the seller balance" |
| 401 | `ADJUSTMENT_ADD` | "One adjustment item has been paid to seller" |
| 402 | `ADJUSTMENT_MINUS` | "One adjustment item has been charged from seller" |
| 404 | `FBS_ADJUSTMENT_ADD` | "Adjustment item related to Shopee fulfillment order is added to seller" |
| 405 | `FBS_ADJUSTMENT_MINUS` | "...deducted from seller" |
| 406 | `ADJUSTMENT_CENTER_ADD` | "One adjustment item has been added to seller wallet" |
| 407 | `ADJUSTMENT_CENTER_DEDUCT` | "...deducted from seller wallet" |
| 408 | `FSF_COST_PASSING_DEDUCT` | "FSF cost passing for canceled/invalid orders" |
| 409 | `PERCEPTION_VAT_TAX_DEDUCT` | Argentina-specific |
| 410 | `PERCEPTION_TURNOVER_TAX_DEDUCT` | Argentina-specific |
| 450 | `PAID_ADS_CHARGE` | "Paid ads are charged from seller" — **this is Shopee's explicit ads-deduction type**, unlike TikTok which has no such explicit type and must be guessed |
| 451 | `PAID_ADS_REFUND` | "Paid ads are refunded to seller" |
| 452 | `FAST_ESCROW_DISBURSE` | "The first disbursement of fast escrow has been paid to seller" |
| 455 | `AFFILIATE_ADS_SELLER_FEE` | "Affiliate ads seller fee is charged from seller" |
| 456 | `AFFILIATE_ADS_SELLER_FEE_REFUND` | "...refunded to seller" |
| 458 | `FAST_ESCROW_DEDUCT` | "Fast escrow is deducted from seller balance in the event of return and refund" |
| 459 | `FAST_ESCROW_DISBURSE_REMAIN` | "The second disbursement of fast escrow has been paid to seller" |
| 460 | `AFFILIATE_FEE_DEDUCT` | "Affiliate MKT fee is charged from seller for using affiliate MKT services" |

Note: **no explicit "shipping" transaction type exists** in this list — shipping economics appear to be netted inside the escrow amount at the order level (§2.1's shipping fields), not posted as their own wallet ledger line. This should be verified (§5) since it affects whether "Ongkir / logistik" can even be categorized from wallet data alone the way TikTok's mirror does.

**`transaction_tab_type` enum** (a coarser client-side filter grouping, per docblock): `Default`, `wallet_order_income`, `wallet_adjustment_filter`, `wallet_wallet_payment`, `wallet_refund_from_order`, `wallet_withdrawals`, `fast_escrow_repayment`, `fast_pay`, `seller_loan`, `corporate_loan`, `pix_transactions_filter`, `open_finance_transactions_filter` (note: only one value can be passed per request — comma-separated values are NOT supported, unlike some other Shopee list filters).

---

### 2.5 Payout / billing statement family — **Cross-Border sellers only**

This is the closest thing Shopee has to TikTok's "statement" concept — a payout-batch-level record — but **the docblocks explicitly restrict it to Cross-Border (CB) sellers**, which is a critical fork (see §4).

#### `get_payout_detail` — **DEPRECATED**
| | |
|---|---|
| Path | `GET /api/v2/payment/get_payout_detail` |
| Sign type | Shop-level |
| Purpose (docblock) | "This API is applicable for **Cross Border (CB) sellers only** to get the shop's payout data, such as the payout amount, currency, FX rate, the payout's associated order income and adjustment records etc." |
| Params | `payout_time_from`/`payout_time_to` (timestamps), `page_size` (max 100), `page_no` (min 1, default 1) |
| Pagination | `page_no` + `more: boolean` |

Response: `response.payout_list[]` where each item = `{ payout_info: {from_currency, payout_currency, from_amount, payout_amount, exchange_rate, payout_time, pay_service, payee_id}, escrow_list: [{escrow_amount, currency, order_sn}], offline_adjustment_list: [{adjustment_amount, module, remark, scenario, adjustment_level, order_sn}] }`. **This nested shape (payout batch → constituent order escrows + adjustments) is structurally the closest analog to TikTok's statement + statement_transactions pair** — but it's deprecated and CB-only.

#### `get_payout_info` — replacement for the above
| | |
|---|---|
| Path | `GET /api/v2/payment/get_payout_info` |
| Sign type | Shop-level |
| Purpose (docblock) | "This is a new API which applicable for **Cross Border (CB) sellers only**... will be used for the original API v2.get_payout_details replacement" |
| Params | `payout_time_from`/`payout_time_to`, `page_size` (max 100), `cursor` (string — **cursor-based, not page_no**, unlike the deprecated version) |
| Pagination | `cursor` / `next_cursor` + `more: boolean` |

Response: `response.payout_list[]{ from_currency, payout_currency, from_amount, payout_amount, exchange_rate, payout_time, pay_service, payee_id, encrypted_payout_id }`. **Note this flattened response no longer nests `escrow_list`/`offline_adjustment_list`** — to get the per-order/per-adjustment lines for a given payout, you now pair it with:

#### `get_billing_transaction_info` — per-payout line items
| | |
|---|---|
| Path | `POST /api/v2/payment/get_billing_transaction_info` |
| Sign type | Shop-level |
| Purpose (docblock) | "This API is applicable for **Cross Border (CB) sellers only** to get the detailed payout transaction data, both released and to-be released transaction can be found in here" |
| Params | `billing_transaction_info_type` (int, **1 = TO_RELEASE, 2 = RELEASED**), `encrypted_payout_ids` (string[], optional, max 100 — filters to specific payouts from `get_payout_info`), `cursor`, `page_size` (max 100) |
| Pagination | `cursor` / `next_cursor` + `more: boolean` |

Response: `response.transactions{ amount, currency, order_sn, cost_header, scenario, remark, level, billing_transaction_type, billing_transaction_status }`.

**`get_payout_info` + `get_billing_transaction_info` together = the real TikTok-statement mirror, but only reachable if SKINKU's shop is a CB shop** (almost certainly not — see §4).

---

### 2.6 `get_income_detail` / `get_income_overview` — newer, unified Local+CB income view

These are **not deprecated** and, per their docblocks, are meant to mirror Seller Center's own "Income Details"/"Income Overview" pages — richer and newer than the wallet/payout APIs above.

#### `get_income_detail`
| | |
|---|---|
| Path | `GET /api/v2/payment/get_income_detail` |
| Sign type | Shop-level |
| Purpose (docblock) | "Retrieves detailed order-level income information across various income statuses for a specified time period... consistent with Seller Center's 'Income Details' view... dynamically adapts data fields based on the seller's shop type (Local or Cross Border)" — **works for both shop types** |
| Params | `date_from`/`date_to` (string `YYYY-MM-DD` — only meaningful when `income_status` = Released; **max 14-day range**, `date_to` must be later than `date_from`), `income_status` (number — **Local: 1=Released, 2=Pending; CB: 0=To Release, 1=Released, 2=Pending** — note the enum differs by shop type!), `cursor`, `page_size` |
| Pagination | `cursor` via nested `income_detail_list[].next_page{cursor, page_size}` (unusual shape — cursor is nested one level deeper than most other endpoints here) |

Response per item (`income_detail_list_item[]`): `payment_method, order_sn, description ("Order Income, Adjustment etc"), status, currency, estimated_escrow_amount, estimated_payout_time, to_release_amount, creation_date, released_amount, actual_payout_time`.

This is order-level (like `get_escrow_detail`) but status-segmented (Pending/To Release/Released) and gives you `released_amount` + `actual_payout_time` directly — i.e. it can answer "was this order's money actually paid out, and when" without the two-step `get_escrow_list` → `get_escrow_detail_batch` dance. It does **not** give the fee breakdown (`commission_fee`, etc.) though — only `estimated_escrow_amount`/`released_amount` totals. Best read as a lighter-weight status tracker, not a fee-categorization source.

#### `get_income_overview`
| | |
|---|---|
| Path | `GET /api/v2/payment/get_income_overview` |
| Sign type | Shop-level |
| Purpose (docblock) | "Retrieves a consolidated snapshot of the seller's income amounts categorized by income status... Historical income results are **not retrievable**" |
| Params | `income_status` (optional — omit to get all statuses) |
| Pagination | None — single snapshot object |

Response: `{ latest_payout_date (CN shops only), total_income: { pending_amount, to_release_amount, released_amount } }`. This is a **dashboard snapshot, not a queryable ledger** — not useful for building historical settlement records, only for a "current state" widget.

---

### 2.7 `get_income_report` / `get_income_statement` — async file export

Two independent pairs, both: call `generate_*` with a time range → get back an `id` → poll `get_*` with that `id` until `status` flips to downloadable → fetch `file_link`.

| | `income_report` | `income_statement` |
|---|---|---|
| Generate params | `release_time_from`, `release_time_to` (epoch) | `release_time_from`, `release_time_to`, **`statement_type`** (1=WEEKLY, 2=MONTHLY — periods must align: Monday for weekly, 1st-of-month for monthly) |
| Generate response | `{ id }` | `{ id }` |
| Poll params | `income_report_id` | `income_statement_id` |
| Poll response | `{ id, file_name, status, generated_time, file_link }` | same shape |
| `status` enum | `0=INVALID, 1=PROCESSING, 2=DOWNLOADABLE, 3=DOWNLOADED, 4=FAILED` | same |
| HTTP method | GET (both generate and poll) | GET (both) |

This is a fundamentally different integration pattern from everything else in this doc: **poll-then-download-a-file** (presumably CSV/XLSX, format not specified in the schema) rather than a queryable JSON list. This is Shopee's literal "downloadable settlement statement" — closest in *spirit* to what a human finance user would export from Seller Center manually — but requires a file-parsing step in the pipeline, not just JSON mapping. Not recommended as the primary sync mechanism (can't easily resync/backfill/idempotently re-check a specific order this way), but worth keeping in mind as a monthly reconciliation cross-check source.

---

### 2.8 Out of scope, listed for completeness only

`get_payment_method_list` (public-ish, no settlement data), `get_shop_installment_status`/`set_shop_installment_status`/`get_item_installment_status`/`set_item_installment_status` (TH/TW-only installment config, not a SKINKU market) — none of these carry money-movement data relevant to a settlement feature.

---

## 3. Response envelope (applies to all endpoints above)

```ts
interface FetchResponse<T> {
  request_id: string;
  error: string;      // non-empty string = error (matches ShopeeClient::handle() already)
  message: string;
  response: T;         // the payload documented per-endpoint above
  result?: T;           // some endpoints/SDK versions alias this
  [key: string]: any;
}
```

This matches `ShopeeClient::handle()`'s existing `if (!empty($json['error']))` check exactly — no changes needed there for any new payment method.

---

## 4. The Local vs Cross-Border fork — the single most important finding

Shopee's API splits sellers into two categories with **structurally different finance APIs**:

- **`get_wallet_transaction_list`** (§2.4): docblock says *"Only applicable for local shops."*
- **`get_payout_detail` / `get_payout_info` / `get_billing_transaction_info`** (§2.5): docblock says *"applicable for Cross Border (CB) sellers only"* on all three.
- **`get_escrow_detail`/`get_escrow_list`/`get_escrow_detail_batch`** (§2.1–2.3) and **`get_income_detail`/`get_income_overview`** (§2.6): no such restriction mentioned — appear to work for both.

SKINKU's Shopee integration is (almost certainly) an **Indonesian domestic ("local") shop** — nothing in `ShopeeClient.php`/`ShopeeConnection`/`ShopeeOrder` suggests cross-border fulfillment. **This means the CB-only payout/billing family in §2.5 — which is the *structurally* closest mirror to TikTok's statement API — is probably not callable for this shop at all.** This must be verified before any design leans on it (§5, item 1).

If local-shop-only is confirmed, the *only* endpoints available for a settlement feature are: `get_escrow_detail`(+batch/list) and `get_wallet_transaction_list`, plus the newer `get_income_detail`/`get_income_overview`. This directly answers the user's original Q3 ("Does Shopee expose an aggregate 'statement' like TikTok, or only per-order escrow + wallet movements?"): **for a local shop, only per-order escrow + wallet movements — there is no aggregate payout-batch API available.**

A second, related fork worth flagging: Shopee appears to have a genuine **two-stage cash model** that TikTok does not. Reading the `transaction_type` semantics literally (§2.4): `ESCROW_VERIFIED_ADD` ("paid to seller") credits something the docs call the seller's **balance** — money accrues into a Shopee-held wallet balance first. Only later does the seller (or an auto-withdraw schedule) trigger `WITHDRAWAL_CREATED` → `WITHDRAWAL_COMPLETED`, which is worded as the money actually leaving that balance. TikTok's statement model, by contrast, appears to pay straight to bank per statement with no intermediate custodial-balance concept exposed via the API. **If this reading is correct** (flagged INFERRED, see §5), "net cash paid out" for Shopee is only truly represented by `WITHDRAWAL_COMPLETED` rows — an `ESCROW_VERIFIED_ADD` row is money *earned into the wallet*, not yet cash in the bank. This has direct implications for which account a Shopee accounting mirror should treat as "Kas Shopee" vs an intermediate "Saldo/Dompet Shopee" balance account.

---

## 5. Recommendation — how should `ShopeeSettlement` be shaped?

### The three options

**Option A — one row per wallet transaction** (`get_wallet_transaction_list`, literal mirror of `TiktokSettlement` = one row per statement)

- Closest 1:1 structural mirror of the existing TikTok table: `transaction_type` ≈ `kind` (but **explicit enum from Shopee, not a heuristic guess** — strictly better data than TikTok gives), `amount` ≈ `settlement_amount`, `create_time` ≈ `statement_time`. Bonus field TikTok has no equivalent of: `current_balance` (a running balance, useful for audit/reconciliation the TikTok mirror can't offer).
- **Pro**: fastest to ship, reuses the exact `TikTokSettlementService`/`TikTokAccountingService` control-account pattern almost line-for-line, lowest design risk, "only applicable to local shops" is exactly SKINKU's case.
- **Con**: a wallet transaction's fee categorization is coarse — `transaction_type=ESCROW_VERIFIED_ADD` tells you "this was an order payout" but not the commission/service/campaign/shipping split *within* it. To get ads-vs-shipping-vs-refund granularity (which the task explicitly asks for) you still need a second, order-linked call — meaning "simple" is a bit illusory unless the design is fine with coarse categories (Penjualan / Iklan / Adjustment / Withdrawal) and nothing finer.
- **Con**: doesn't cleanly capture the two-stage cash model from §4 — booking every `ESCROW_VERIFIED_ADD` straight to a "Kas Shopee" cash account would overstate cash if money is still sitting in Shopee's wallet, unwithdrawn.

**Option B — one row per order escrow** (`get_escrow_list` for discovery + `get_escrow_detail_batch` for detail)

- Gives the **richest, already-categorized fee breakdown per order** — commission_fee, service_fee, campaign_fee (ads), shipping deltas, and tax fields **simultaneously on one record**, no majority-vote heuristic needed at all (unlike TikTok, which structurally cannot offer this because its per-order data has no fee fields). `order_adjustment[].adjustment_reason` even gives a documented small set of categories (refund/logistics/marketing/payment) for free-text-but-bounded classification.
- **Pro**: this is a strict data-quality upgrade over what TikTok's API allows — Shopee is telling you *exactly* how much of an order's payout was commission vs ads vs shipping, where TikTok forces a guess.
- **Con**: doesn't capture the actual bank cash-out event (`WITHDRAWAL_COMPLETED`) or wallet-only adjustments with no `order_sn` (loan events, batched FBS adjustments) — "net cash paid out" can't be fully answered from this alone.
- **Con**: `escrow_release_time` (from `get_escrow_list`) may not equal the moment money actually left Shopee's platform to the seller's bank — that's still a wallet/withdrawal event. Sourcing "cash paid out" from escrow data risks being technically wrong.

**Option C — both, split by role** (escrow detail owns *fee categorization*, wallet transactions own *cash movement*)

- `shopee_order_incomes` (one row per order, from §2.1–2.3): drives precise, already-labeled expense postings (Beban Komisi, Beban Ongkir, Beban Iklan, etc. — no heuristic needed, unlike TikTok's `iklan`-by-default fallback).
- `shopee_wallet_transactions` (one row per wallet transaction, from §2.4): drives the actual cash ledger — confirms `WITHDRAWAL_COMPLETED` as the real "Kas Shopee" debit, and catches wallet-only entries an order-scoped table would miss (loans, batched adjustments, `PAID_ADS_CHARGE` rows not tied to one `order_sn`).
- **Pro**: most accurate to reality; matches the two-stage cash model in §4 properly (Piutang → Saldo Shopee wallet balance → Kas Shopee on withdrawal, vs. TikTok's single-hop Piutang → Kas); best fee granularity.
- **Con**: real design cost — needs a `source`/precedence rule so a fee is never booked twice (once from the order-income table's detailed postings, again from the wallet transaction's lump amount touching the same expense account). This split needs its own SDD, not a copy-paste of the TikTok recipe.

### Recommendation

**Ship Option A first (pure wallet-transaction mirror), design Option C as the target state, treat Option B as a lazy enrichment step — mirroring exactly how the TikTok integration itself evolved (`tiktok:describe` enriches `kind` after the fact, on a schedule, only for ambiguous rows).**

Concretely:
1. **v1 — `shopee_settlements`, one row per `get_wallet_transaction_list` entry.** `transaction_type` → `kind` directly (a lookup table, not a heuristic — Shopee already hands you `PAID_ADS_CHARGE`, `ESCROW_VERIFIED_ADD`, `WITHDRAWAL_COMPLETED`, etc. by name). This alone is a strict improvement over TikTok's `kindFromStatement()` guesswork and ships fast with the same control-account accounting shape already proven by `TikTokAccountingService`.
2. **v1.5 — enrichment pass, same shape as `tiktok:describe`.** For `ESCROW_VERIFIED_ADD`/`ESCROW_VERIFIED_MINUS` rows with a populated `order_sn`, lazily call `get_escrow_detail(order_sn)` (or batch several via §2.3 when catching up) to split the lump amount into commission/service/campaign(ads)/shipping sub-lines instead of one generic "Beban Biaya E-commerce" line — this is where Option B's richer data gets folded in, incrementally, without a big-bang redesign.
3. Explicitly model the **two-stage cash flow** from day one even in v1: `ESCROW_VERIFIED_ADD`/`ADJUSTMENT_ADD`/etc. should NOT hit a literal "Kas Shopee" cash account — they should hit an intermediate **`Saldo Shopee`** balance-sheet asset account (new code needed, distinct from `1001 Kas Shopee` which is already seeded in `ChartOfAccountSeeder.php`). Only `WITHDRAWAL_COMPLETED` rows should debit the real `1001 Kas Shopee`, crediting `Saldo Shopee`. This is the one place this recommendation deliberately does **not** copy TikTok 1:1, because the underlying platform behavior genuinely differs (§4) — copying TikTok's single-hop Piutang→Kas pattern would silently misrepresent un-withdrawn Shopee wallet balance as cash-in-bank.

This is a design judgment call, not a documented fact — flag it for discussion before locking the SDD.

---

## 6. MUST verify against the live sandbox before finalizing a design

Ranked by how much they'd change the design if wrong:

1. **Local vs Cross-Border shop type.** Call `get_wallet_transaction_list` and `get_payout_info` both against the sandbox-connected shop. Whichever one returns real data (vs. a shop-type/permission error) settles §4 definitively — the entire recommendation in §5 assumes "local," and if that's wrong the whole design flips toward Option B/§2.5 instead.
2. **The two-stage cash model (§4).** Is `ESCROW_VERIFIED_ADD` really "money enters a wallet balance, not yet bank cash," confirmed by watching `current_balance` change across a sequence of transactions and cross-checking against Shopee Seller Center's own balance page? This is currently an **INFERRED** reading of the docblock wording, not a confirmed fact.
3. **`amount` sign convention** on `get_wallet_transaction_list` rows — always positive with `money_flow` carrying the direction, or signed (negative for `MONEY_OUT`)? Docs don't say explicitly.
4. **Whether one wallet transaction can bundle multiple orders.** The `pay_order_list[]` sub-array suggests yes — if true, `order_sn` (singular) may be null/misleading on batched rows and `pay_order_list` should be the real join, not `order_sn`.
5. **`get_escrow_list`'s max time-range window.** Not stated in the scraped schema (unlike `get_wallet_transaction_list`'s explicit 15-day cap and `get_income_detail`'s explicit 14-day cap) — assume 15 days to match the sibling endpoints until proven otherwise, to avoid a silent-truncation bug like the one `get_order_list`'s docblock already warns about in `ShopeeClient.php`.
6. **Whether `get_income_detail`/`get_income_overview` are enabled for this partner app.** These read as newer additions (richer, Seller-Center-parity docblocks) — older partner-app registrations/API scopes might not have them turned on. Check via the sandbox's API Test Tool (already used successfully for sign verification per the `ShopeeClient.php` docblock, 2026-08-24) before depending on them.
7. **Whether shipping ever appears as its own wallet `transaction_type`.** None of the 21 enum values in §2.4 look shipping-specific — if confirmed absent, "Ongkir / logistik" as a `kind` category (mirroring TikTok's) can only come from the escrow-level shipping fields (§2.1), not from the wallet ledger, which changes where that categorization logic has to live.
8. **`get_payment_method_list`'s auth requirement** (docblock says none needed, code sends `auth: true`) — low priority, not settlement-critical, but worth a 5-minute sandbox check if that endpoint is ever wired up.
9. **General source caveat**: since `open.shopee.com` itself could not be reached by any tool available this session, **none of the field names, paths, or enum values above have been cross-checked against Shopee's own current documentation** — only against a third-party SDK's scraped/generated schema. A first sandbox call to each endpoint intended for use should be diffed against this document before the shapes are hard-coded into migrations/models.

---

## 7. Quick-reference: endpoint → user's original question

| User's question | Answer |
|---|---|
| Q1: `get_escrow_detail` fee fields | Yes to all four named examples (`commission_fee`, `service_fee`, `seller_transaction_fee`, `buyer_total_amount`, `escrow_amount`) plus ~85 more — see §2.1 |
| Q2: `get_wallet_transaction_list` transaction_type enum | 21 documented values, all quoted verbatim in §2.4 — `ESCROW_VERIFIED_ADD`/`WITHDRAWAL_CREATED`/`ADJUSTMENT_*` all confirmed real, plus an explicit ads type (`PAID_ADS_CHARGE`) TikTok has no equivalent of |
| Q2: pagination shape | `page_no` + `more: boolean` — **no cursor** on this specific endpoint (unlike the newer payout/income endpoints, which do use `cursor`/`next_cursor`) |
| Q3: aggregate "statement" like TikTok? | Only for **Cross-Border** shops (`get_payout_info` + `get_billing_transaction_info`, §2.5) — almost certainly not applicable to SKINKU's local ID shop. For local shops: only per-order escrow + wallet movements, confirmed |
| Q4: batch/list-by-time to avoid one-call-per-order | Yes, **two** endpoints: `get_escrow_list` (discovery, by `release_time`) + `get_escrow_detail_batch` (detail, ≤50 order_sns/call) — §2.2/§2.3 |
