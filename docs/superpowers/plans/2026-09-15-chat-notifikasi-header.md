# Notifikasi Chat E-commerce (Badge Unread Per-Staf + Suara) — Rencana Implementasi

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tombol Chat E-commerce di kanan header dengan badge merah jumlah percakapan belum-dibaca **per staf** + notifikasi suara saat chat pembeli baru masuk.

**Architecture:** Lacak "sudah dibaca" lewat tabel `ecom_chat_reads` (user×percakapan) + kolom `last_incoming_at` (waktu pesan pembeli). `EcomChatService::unreadCountFor(user)` hitung percakapan unread; buka detail memanggil `markRead`. Endpoint JSON + poller vanilla-JS (25 dtk) update badge; suara "ding" via Web Audio API saat count naik (mute-able).

**Tech Stack:** Laravel 13 / PHP 8.3, Blade + vanilla JS, Web Audio API. Zero-dependency.

## Global Constraints

- **Zero-dependency:** TANPA paket composer/npm. Suara pakai Web Audio API bawaan browser (disintesis, tanpa file audio).
- **Migrasi baru `000129`** (terakhir `000128`).
- **Izin:** semua rute & tombol digembok `manage_ecommerce_chat` (super_admin & admin lolos).
- **Badge = unread PER-STAF:** difilter `user_id` login; tak bocor antar staf.
- **`last_incoming_at`** khusus pesan PEMBELI (dipisah dari `last_message_at` yang juga gerak saat staf balas).
- **Buka DETAIL** (`/ecom-chat/{conversation}`) yang menandai read — bukan daftar inbox.
- **Percakapan `closed` tak dihitung** unread.
- Route `ecom-chat.unread-count` (GET) **didaftarkan SEBELUM** `ecom-chat.show` (`/ecom-chat/{conversation}`) — sama-sama GET, urutan menentukan.
- Semua akses `localStorage`/AudioContext dibungkus try/catch (degradasi mulus).
- **Runner tes:** `/c/php83/php.exe artisan test` (fokus `--filter=NamaTest`). **Format:** `/c/php83/php.exe vendor/bin/pint --dirty` sebelum tiap commit.
- **Branch:** kerja di `feat/ecom-chat-notif` (fork dari `main`). Jangan mulai di `main`.

## File Structure

- Create: `database/migrations/2026_01_01_000129_add_ecom_chat_reads_and_last_incoming.php` — tabel read + kolom.
- Create: `app/Models/EcomChatRead.php` — model penanda baca.
- Modify: `app/Models/EcomChatConversation.php` — fillable/cast `last_incoming_at` + relasi `reads()`.
- Modify: `app/Services/EcomChatService.php` — `syncIncoming` (+last_incoming_at), `unreadCountFor()`, `markRead()`.
- Modify: `app/Http/Controllers/EcomChatController.php` — `show()` markRead, `unreadCount()` endpoint.
- Modify: `routes/web.php` — route `ecom-chat.unread-count`.
- Modify: `resources/views/layouts/app.blade.php` — tombol header + badge + `<script>` poller/beep/mute.
- Test: `tests/Feature/EcomChatLastIncomingTest.php` (Task 1), `tests/Feature/EcomChatUnreadTest.php` (Task 2), `tests/Feature/EcomChatNotifRenderTest.php` (Task 3).

---

### Task 1: Migrasi 000129 + model read + `last_incoming_at`

**Files:**
- Create: `database/migrations/2026_01_01_000129_add_ecom_chat_reads_and_last_incoming.php`
- Create: `app/Models/EcomChatRead.php`
- Modify: `app/Models/EcomChatConversation.php`
- Modify: `app/Services/EcomChatService.php` (method `syncIncoming`)
- Test: `tests/Feature/EcomChatLastIncomingTest.php`

**Interfaces:**
- Consumes: `EcomChatConversation` (Task chat lama: fillable `channel,external_conversation_id,buyer_name,buyer_id,last_message_at,last_message_preview,status,ai_draft,ai_decision,ai_reason`), `EcomChatService::syncIncoming(string $channel, array $msg): ?EcomChatMessage`.
- Produces:
  - Kolom `ecom_chat_conversations.last_incoming_at` (timestamp, nullable).
  - Tabel `ecom_chat_reads` (`user_id`,`conversation_id`,`last_read_at`, unik pasangan).
  - `EcomChatRead` model (fillable `user_id,conversation_id,last_read_at`; cast `last_read_at` datetime).
  - `EcomChatConversation::reads(): HasMany`; fillable+cast `last_incoming_at`.
  - `syncIncoming()` mengisi `last_incoming_at = $sentAt`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EcomChatLastIncomingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EcomChatConversation;
use App\Models\EcomChatRead;
use App\Models\User;
use App\Services\EcomChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EcomChatLastIncomingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_incoming_mengisi_last_incoming_at(): void
    {
        $epoch = 1_757_000_000;
        app(EcomChatService::class)->syncIncoming('tiktok', [
            'conversation_id' => 'C1', 'message_id' => 'M1', 'text' => 'halo',
            'sender' => 'buyer', 'sent_at' => $epoch,
        ]);

        $conv = EcomChatConversation::first();
        $this->assertNotNull($conv->last_incoming_at);
        $this->assertSame($epoch, $conv->last_incoming_at->timestamp);
    }

    public function test_read_terhubung_ke_percakapan_dan_unik(): void
    {
        $user = User::create([
            'name' => 'A', 'fullname' => 'A', 'username' => 'a', 'email' => 'a@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
        $conv = EcomChatConversation::create([
            'channel' => 'tiktok', 'external_conversation_id' => 'C1', 'status' => 'open',
        ]);

        $conv->reads()->create(['user_id' => $user->id, 'last_read_at' => now()]);

        $this->assertSame(1, $conv->reads()->count());
        $this->assertSame(1, EcomChatRead::where('user_id', $user->id)->where('conversation_id', $conv->id)->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=EcomChatLastIncomingTest`
Expected: FAIL — kolom `last_incoming_at` & model `EcomChatRead`/relasi `reads()` belum ada.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_01_01_000129_add_ecom_chat_reads_and_last_incoming.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecom_chat_conversations', function (Blueprint $table) {
            $table->timestamp('last_incoming_at')->nullable()->after('last_message_at');
        });

        Schema::create('ecom_chat_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('ecom_chat_conversations')->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecom_chat_reads');
        Schema::table('ecom_chat_conversations', function (Blueprint $table) {
            $table->dropColumn('last_incoming_at');
        });
    }
};
```

- [ ] **Step 4: Write the `EcomChatRead` model**

Create `app/Models/EcomChatRead.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EcomChatRead extends Model
{
    protected $fillable = ['user_id', 'conversation_id', 'last_read_at'];

    protected $casts = ['last_read_at' => 'datetime'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(EcomChatConversation::class, 'conversation_id');
    }
}
```

- [ ] **Step 5: Update `EcomChatConversation`**

In `app/Models/EcomChatConversation.php`:

1. Add `'last_incoming_at'` to `$fillable` (e.g. after `'last_message_at'`):

```php
        'last_message_at', 'last_incoming_at', 'last_message_preview', 'status',
```

2. Change the casts line:

```php
    protected $casts = ['last_message_at' => 'datetime'];
```
to:
```php
    protected $casts = ['last_message_at' => 'datetime', 'last_incoming_at' => 'datetime'];
```

3. Add the `reads()` relation (after the existing `messages()` method); ensure `use Illuminate\Database\Eloquent\Relations\HasMany;` is present (it already is for `messages()`):

```php
    public function reads(): HasMany
    {
        return $this->hasMany(EcomChatRead::class, 'conversation_id');
    }
```

- [ ] **Step 6: Set `last_incoming_at` in `syncIncoming()`**

In `app/Services/EcomChatService.php`, in `syncIncoming()`, immediately after the line `$conv->last_message_at = $sentAt;`, add:

```php
        $conv->last_incoming_at = $sentAt;
```

- [ ] **Step 7: Run test to verify it passes**

Run: `/c/php83/php.exe artisan test --filter=EcomChatLastIncomingTest`
Expected: PASS (2 tests).

- [ ] **Step 8: Full suite (no regressions)**

Run: `/c/php83/php.exe artisan test`
Expected: PASS — semua tes lama (chat module incl.) tetap hijau.

- [ ] **Step 9: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add database/migrations/2026_01_01_000129_add_ecom_chat_reads_and_last_incoming.php app/Models/EcomChatRead.php app/Models/EcomChatConversation.php app/Services/EcomChatService.php tests/Feature/EcomChatLastIncomingTest.php
git commit -m "feat(ecom-chat): tabel read per-staf + last_incoming_at (000129)"
```

---

### Task 2: `unreadCountFor()` + `markRead()` + endpoint + tandai-read saat buka detail

**Files:**
- Modify: `app/Services/EcomChatService.php`
- Modify: `app/Http/Controllers/EcomChatController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/EcomChatUnreadTest.php`

**Interfaces:**
- Consumes: `EcomChatConversation` (+`last_incoming_at`, `status`, const `STATUS_CLOSED`), `EcomChatRead` (Task 1), `App\Models\User`.
- Produces:
  - `EcomChatService::unreadCountFor(User $user): int` — jumlah percakapan unread bagi user itu.
  - `EcomChatService::markRead(User $user, EcomChatConversation $conv): void` — upsert baris read `last_read_at=now()`.
  - `EcomChatController::show(Request $request, EcomChatConversation $conversation)` — memanggil `markRead` sebelum render.
  - `EcomChatController::unreadCount(Request $request): JsonResponse` — `{count}` untuk user login.
  - Route `GET /ecom-chat/unread-count` → `ecom-chat.unread-count`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EcomChatUnreadTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EcomChatConversation;
use App\Models\EcomChatRead;
use App\Models\User;
use App\Services\EcomChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EcomChatUnreadTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => "U$n", 'fullname' => "U$n", 'username' => "u$role$n", 'email' => "u$role$n@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function convWithIncoming(string $ext, \Illuminate\Support\Carbon $when, string $status = 'needs_staff'): EcomChatConversation
    {
        return EcomChatConversation::create([
            'channel' => 'tiktok', 'external_conversation_id' => $ext, 'status' => $status,
            'last_message_at' => $when, 'last_incoming_at' => $when,
        ]);
    }

    public function test_belum_dibaca_terhitung_lalu_markRead_nol(): void
    {
        $svc = app(EcomChatService::class);
        $admin = $this->user(User::ROLE_ADMIN);
        $conv = $this->convWithIncoming('C1', now()->subMinute());

        $this->assertSame(1, $svc->unreadCountFor($admin));

        $svc->markRead($admin, $conv);
        $this->assertSame(0, $svc->unreadCountFor($admin));
    }

    public function test_pesan_baru_sesudah_dibaca_jadi_unread_lagi(): void
    {
        $svc = app(EcomChatService::class);
        $admin = $this->user(User::ROLE_ADMIN);
        $conv = $this->convWithIncoming('C1', now()->subMinutes(5));
        $svc->markRead($admin, $conv);
        $this->assertSame(0, $svc->unreadCountFor($admin));

        // Pesan pembeli baru → last_incoming_at maju melewati last_read_at.
        $conv->update(['last_incoming_at' => now()->addMinute()]);
        $this->assertSame(1, $svc->unreadCountFor($admin));
    }

    public function test_dua_staf_independen(): void
    {
        $svc = app(EcomChatService::class);
        $u1 = $this->user(User::ROLE_ADMIN);
        $u2 = $this->user(User::ROLE_SUPER_ADMIN);
        $conv = $this->convWithIncoming('C1', now()->subMinute());

        $svc->markRead($u1, $conv);
        $this->assertSame(0, $svc->unreadCountFor($u1));
        $this->assertSame(1, $svc->unreadCountFor($u2));
    }

    public function test_percakapan_closed_tak_dihitung(): void
    {
        $svc = app(EcomChatService::class);
        $admin = $this->user(User::ROLE_ADMIN);
        $this->convWithIncoming('C1', now()->subMinute(), 'closed');

        $this->assertSame(0, $svc->unreadCountFor($admin));
    }

    public function test_markRead_idempoten(): void
    {
        $svc = app(EcomChatService::class);
        $admin = $this->user(User::ROLE_ADMIN);
        $conv = $this->convWithIncoming('C1', now()->subMinute());

        $svc->markRead($admin, $conv);
        $svc->markRead($admin, $conv);

        $this->assertSame(1, EcomChatRead::where('user_id', $admin->id)->where('conversation_id', $conv->id)->count());
    }

    public function test_buka_detail_menandai_read(): void
    {
        $svc = app(EcomChatService::class);
        $admin = $this->user(User::ROLE_ADMIN);
        $conv = $this->convWithIncoming('C1', now()->subMinute());
        $this->assertSame(1, $svc->unreadCountFor($admin));

        $this->actingAs($admin)->get("/ecom-chat/{$conv->id}")->assertOk();

        $this->assertSame(0, $svc->unreadCountFor($admin->fresh()));
    }

    public function test_endpoint_unread_count_per_user_dan_gate(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->convWithIncoming('C1', now()->subMinute());

        $this->actingAs($admin)->getJson('/ecom-chat/unread-count')
            ->assertOk()->assertExactJson(['count' => 1]);

        // Non-staf → 403.
        $this->actingAs($this->user(User::ROLE_RESELLER))->getJson('/ecom-chat/unread-count')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=EcomChatUnreadTest`
Expected: FAIL — `unreadCountFor`/`markRead`/endpoint belum ada.

- [ ] **Step 3: Add `unreadCountFor()` and `markRead()` to the service**

In `app/Services/EcomChatService.php`, add `use App\Models\EcomChatRead;` and `use App\Models\User;` at the top (near the other `use App\Models\...`). Then add these methods (e.g. after `autosendEnabled()`):

```php
    /**
     * Jumlah percakapan yang punya pesan pembeli BELUM dilihat $user (unread per-staf).
     * Unread = last_incoming_at ada, status != closed, dan lebih baru dari last_read_at
     * user itu (atau user belum pernah buka). Difilter per user → tak bocor antar staf.
     */
    public function unreadCountFor(User $user): int
    {
        return EcomChatConversation::query()
            ->leftJoin('ecom_chat_reads', function ($join) use ($user) {
                $join->on('ecom_chat_reads.conversation_id', '=', 'ecom_chat_conversations.id')
                    ->where('ecom_chat_reads.user_id', '=', $user->id);
            })
            ->whereNotNull('ecom_chat_conversations.last_incoming_at')
            ->where('ecom_chat_conversations.status', '!=', EcomChatConversation::STATUS_CLOSED)
            ->whereRaw("ecom_chat_conversations.last_incoming_at > COALESCE(ecom_chat_reads.last_read_at, '1970-01-01 00:00:00')")
            ->count('ecom_chat_conversations.id');
    }

    /** Tandai percakapan sudah dibaca oleh $user (idempoten). */
    public function markRead(User $user, EcomChatConversation $conv): void
    {
        EcomChatRead::updateOrCreate(
            ['user_id' => $user->id, 'conversation_id' => $conv->id],
            ['last_read_at' => now()],
        );
    }
```

> Catatan: `leftJoin` + unik `(user_id,conversation_id)` → maksimal satu baris read per percakapan, jadi `count()` tak menggandakan. Banding datetime string ('Y-m-d H:i:s') benar secara kronologis di SQLite & MySQL.

- [ ] **Step 4: Wire the controller**

In `app/Http/Controllers/EcomChatController.php`:

1. Add `use Illuminate\Http\JsonResponse;` (near the other `use Illuminate\Http\...`).

2. Replace `show()`:

```php
    public function show(EcomChatConversation $conversation)
    {
        $conversation->load(['messages' => fn ($q) => $q->orderBy('id')]);

        return view('ecom-chat.show', [
            'conversation' => $conversation,
            'autosend' => $this->chat->autosendEnabled(),
        ]);
    }
```
with:
```php
    public function show(Request $request, EcomChatConversation $conversation)
    {
        $this->chat->markRead($request->user(), $conversation);
        $conversation->load(['messages' => fn ($q) => $q->orderBy('id')]);

        return view('ecom-chat.show', [
            'conversation' => $conversation,
            'autosend' => $this->chat->autosendEnabled(),
        ]);
    }
```

3. Add the endpoint method (e.g. after `show()`):

```php
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['count' => $this->chat->unreadCountFor($request->user())]);
    }
```

- [ ] **Step 5: Register the route (BEFORE the `{conversation}` route)**

In `routes/web.php`, inside the `Route::middleware('permission:manage_ecommerce_chat')->group(...)` block, add the unread-count route **before** the `GET /ecom-chat/{conversation}` line:

```php
        Route::get('/ecom-chat/unread-count', [\App\Http\Controllers\EcomChatController::class, 'unreadCount'])->name('ecom-chat.unread-count');
```

Resulting order in that group: `index` → `autosend` (POST) → `unread-count` (GET) → `show` (`{conversation}`) → `send` → `redraft`.

- [ ] **Step 6: Run test to verify it passes**

Run: `/c/php83/php.exe artisan test --filter=EcomChatUnreadTest`
Expected: PASS (7 tests).

- [ ] **Step 7: Full suite**

Run: `/c/php83/php.exe artisan test`
Expected: PASS — existing EcomChatControllerTest (show route still works) + all others green.

- [ ] **Step 8: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Services/EcomChatService.php app/Http/Controllers/EcomChatController.php routes/web.php tests/Feature/EcomChatUnreadTest.php
git commit -m "feat(ecom-chat): unreadCountFor/markRead + endpoint unread-count + tandai-read saat buka detail"
```

---

### Task 3: Tombol header + badge + poller JS + suara + mute

**Files:**
- Modify: `resources/views/layouts/app.blade.php`
- Test: `tests/Feature/EcomChatNotifRenderTest.php`

**Interfaces:**
- Consumes: `EcomChatService::unreadCountFor($u)` (Task 2), route `ecom-chat.unread-count` + `ecom-chat.index`, `$u = auth()->user()` (sudah didefinisikan di layout baris ~45), izin `manage_ecommerce_chat`.
- Produces: tombol header (id badge `ecomChatBadge`, tombol mute `ecomChatMute`) + `<script>` poller/beep/mute.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EcomChatNotifRenderTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EcomChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EcomChatNotifRenderTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => "U$n", 'fullname' => "U$n", 'username' => "u$role$n", 'email' => "u$role$n@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_staf_lihat_tombol_badge_dan_poller(): void
    {
        EcomChatConversation::create([
            'channel' => 'tiktok', 'external_conversation_id' => 'C1', 'status' => 'needs_staff',
            'last_message_at' => now()->subMinute(), 'last_incoming_at' => now()->subMinute(),
        ]);

        $res = $this->actingAs($this->user(User::ROLE_ADMIN))->get('/dashboard')->assertOk();
        $res->assertSee('ecomChatBadge');                 // badge ada
        $res->assertSee('ecomChatMute');                  // toggle mute ada
        $res->assertSee('ecom-chat/unread-count');        // URL poller tertanam
    }

    public function test_non_staf_tak_lihat_tombol(): void
    {
        $this->actingAs($this->user(User::ROLE_RESELLER))->get('/dashboard')->assertOk()
            ->assertDontSee('ecomChatBadge');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=EcomChatNotifRenderTest`
Expected: FAIL — belum ada `ecomChatBadge` di layout.

- [ ] **Step 3: Add the header button + badge + mute**

In `resources/views/layouts/app.blade.php`, replace the header's right-side line (the app-name div, ~line 497):

```blade
            <div class="text-[11px] text-stone-400 font-mono hidden sm:block">{{ config('app.name') }}</div>
```

with:

```blade
            <div class="flex items-center gap-3">
                @if($u->canDo('manage_ecommerce_chat'))
                    @php($ecomUnread = app(\App\Services\EcomChatService::class)->unreadCountFor($u))
                    <a href="{{ route('ecom-chat.index') }}" class="relative w-9 h-9 flex items-center justify-center rounded-lg border border-stone-200 text-stone-700 hover:bg-stone-100" title="Chat E-commerce" aria-label="Chat E-commerce">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.83L3 20l1.17-3.5A7.6 7.6 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                        <span id="ecomChatBadge" class="{{ $ecomUnread > 0 ? '' : 'hidden' }} absolute -top-1.5 -right-1.5 min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-white text-[10px] font-bold flex items-center justify-center">{{ $ecomUnread }}</span>
                    </a>
                    <button id="ecomChatMute" type="button" class="w-7 h-7 flex items-center justify-center rounded-lg text-stone-400 hover:text-stone-700" title="Bunyi notifikasi" aria-label="Toggle bunyi notifikasi">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.536 8.464a5 5 0 010 7.072M12 6l-4 4H4v4h4l4 4V6z"/></svg>
                    </button>
                @endif
                <div class="text-[11px] text-stone-400 font-mono hidden sm:block">{{ config('app.name') }}</div>
            </div>
```

- [ ] **Step 4: Add the poller/beep/mute script**

In `resources/views/layouts/app.blade.php`, immediately BEFORE the `@stack('scripts')` line (~603), add:

```blade
@if($u->canDo('manage_ecommerce_chat'))
<script>
(function () {
    var badge = document.getElementById('ecomChatBadge');
    if (!badge) return;
    var muteBtn = document.getElementById('ecomChatMute');
    var COUNT_URL = @json(route('ecom-chat.unread-count'));
    var POLL_MS = 25000;
    var audioCtx = null;

    function muted() { try { return localStorage.getItem('ecomChatMute') === '1'; } catch (e) { return false; } }
    function setMuted(v) { try { localStorage.setItem('ecomChatMute', v ? '1' : '0'); } catch (e) {} renderMute(); }
    function renderMute() {
        if (!muteBtn) return;
        muteBtn.classList.toggle('text-red-600', muted());
        muteBtn.title = muted() ? 'Bunyi notifikasi: MATI' : 'Bunyi notifikasi: NYALA';
    }
    function lastSeen() { try { return parseInt(localStorage.getItem('ecomChatUnread') || '0', 10) || 0; } catch (e) { return 0; } }
    function setLastSeen(n) { try { localStorage.setItem('ecomChatUnread', String(n)); } catch (e) {} }
    function unlockAudio() {
        try {
            if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            if (audioCtx.state === 'suspended') audioCtx.resume();
        } catch (e) {}
    }
    function beep() {
        try {
            unlockAudio();
            if (!audioCtx) return;
            var o = audioCtx.createOscillator(), g = audioCtx.createGain();
            o.type = 'sine'; o.frequency.value = 880; g.gain.value = 0.12;
            o.connect(g); g.connect(audioCtx.destination);
            o.start(); o.stop(audioCtx.currentTime + 0.15);
        } catch (e) {}
    }
    function render(count) { badge.textContent = count; badge.classList.toggle('hidden', count <= 0); }
    function poll() {
        fetch(COUNT_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || typeof d.count !== 'number') return;
                if (d.count > lastSeen() && !muted()) beep();
                setLastSeen(d.count);
                render(d.count);
            })
            .catch(function () {});
    }

    ['click', 'keydown'].forEach(function (ev) { window.addEventListener(ev, unlockAudio, { once: true }); });
    if (muteBtn) muteBtn.addEventListener('click', function () { setMuted(!muted()); });
    renderMute();
    setLastSeen(parseInt(badge.textContent, 10) || 0); // selaraskan dgn badge awal → tak beep palsu saat load
    setInterval(poll, POLL_MS);
})();
</script>
@endif
```

- [ ] **Step 5: Run test to verify it passes**

Run: `/c/php83/php.exe artisan test --filter=EcomChatNotifRenderTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Full suite**

Run: `/c/php83/php.exe artisan test`
Expected: PASS — semua tes hijau.

- [ ] **Step 7: Manual verification note (client-side, tak di-unit-test)**

Muat portal sebagai staf, buka Console — tak ada error JS. Simulasikan chat masuk (atau ubah `last_incoming_at` sebuah percakapan ke `now()` di DB), tunggu ≤25 dtk → badge naik + terdengar "ding" (bila tak mute & sudah pernah klik halaman). Klik ikon mute → tak ada suara di kenaikan berikutnya. Buka detail percakapan → badge turun di poll berikutnya.

- [ ] **Step 8: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add resources/views/layouts/app.blade.php tests/Feature/EcomChatNotifRenderTest.php
git commit -m "feat(ecom-chat): tombol header + badge unread + poller + suara (mute-able)"
```

---

## Self-Review

**1. Spec coverage:**
- `ecom_chat_reads` + `last_incoming_at` (migrasi 000129) + model → Task 1. ✓
- `syncIncoming` mengisi `last_incoming_at` → Task 1 Step 6. ✓
- `unreadCountFor` + `markRead` → Task 2. ✓
- `show()` menandai read + endpoint `unread-count` + route (sebelum `{conversation}`) → Task 2. ✓
- Tombol header + badge + poller 25 dtk + suara Web Audio + mute → Task 3. ✓
- Gate izin `manage_ecommerce_chat` (endpoint + tombol) → Task 2 (route group) + Task 3 (`@if canDo`). ✓
- Rencana Tes spec (unreadCountFor semua skenario, markRead idempoten, show menandai read, endpoint per-user+gate, render staf vs non-staf) → Task 2 + Task 3. ✓
- Kasus batas: closed tak dihitung (Task 2 test), degradasi localStorage/audio (Task 3 try/catch), poll gagal (`.catch`). ✓

**2. Placeholder scan:** Tak ada TBD/TODO. Suara/JS sisi-klien diverifikasi manual (Task 3 Step 7) — bukan placeholder, memang tak bisa di-unit-test tanpa browser.

**3. Type consistency:** `unreadCountFor(User): int` & `markRead(User, EcomChatConversation): void` dipakai konsisten di controller (Task 2) & layout (Task 3). `last_incoming_at` (cast datetime) di-set di `syncIncoming` (Task 1) & dibaca di query (Task 2) & seed test. Route name `ecom-chat.unread-count` konsisten (route Task 2, JS Task 3). Badge id `ecomChatBadge` / mute id `ecomChatMute` konsisten (markup + JS + tes render Task 3).

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-09-15-chat-notifikasi-header.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — dispatch a fresh subagent per task, review between tasks.

**2. Inline Execution** — execute tasks in this session using executing-plans.

**Which approach?**
