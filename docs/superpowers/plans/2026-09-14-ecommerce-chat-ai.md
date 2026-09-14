# Modul Chat E-commerce (AI Auto-reply) — Rencana Implementasi

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Balas chat pembeli TikTok Shop dari dalam SKINKU — AI menyusun draft tiap pesan masuk (via webhook Customer Service API), auto-send untuk FAQ/kebijakan bila kill switch ON, sisanya diteruskan ke staf lewat menu Chat E-commerce.

**Architecture:** Webhook publik `POST /webhooks/tiktok/chat` memverifikasi tanda tangan HMAC, dedupe pesan, balas 200 cepat, lalu memproses AI setelah respon (pola `fastcgi_finish_request` seperti `TelegramWebhookController`). `EcomChatDrafter` (provider-agnostik lewat `AiProvider`) menghasilkan `{reply, decision, reason}`; `EcomChatService` (facade channel-agnostic) menyimpan pesan & mengirim balasan via `TikTokClient`. Pengetahuan chat = tab baru channel-agnostic di Pengetahuan AI (kolom `group` di `ai_knowledge`).

**Tech Stack:** Laravel 13 / PHP 8.3, Blade + vanilla JS (zero-dependency), `AiProviderFactory` (OpenAI), `TikTokClient` (request bertanda-tangan), SQLite/MySQL migrasi.

## Global Constraints

- **Zero-dependency:** TANPA paket composer/npm baru. Webhook, HMAC, AI call, JSON parse — semua bawaan Laravel + HTTP client.
- **Migrasi mulai `000127`** (terakhir `000126`). Dua migrasi: `000127_create_ecom_chat_tables`, `000128_add_group_to_ai_knowledge`.
- **Runner tes:** `/c/php83/php.exe artisan test` (satu file: `--filter=NamaTest`). **Format:** `/c/php83/php.exe vendor/bin/pint --dirty` sebelum tiap commit.
- **Semua panggilan API TikTok di tes WAJIB `Http::fake`** — tak ada jaringan nyata.
- **Fail-safe (ragu = JANGAN kirim):** AI gagal/timeout/parse-error/kosong → `to_staff`, tak kirim apa pun.
- **Kill switch `ecom_chat_autosend` default `'0'` (MATI):** saat MATI semua hasil AI jadi draft, tak ada auto-send.
- **Izin baru `manage_ecommerce_chat`** (default: admin) menggembok menu + semua aksi Chat.
- **Anti-loop:** abaikan event pesan `sender=seller`/system.
- **Branch:** kerja di `feat/ecom-chat-ai` (fork dari `main`). Jangan mulai di `main`.
- **Deploy split:** Claude push dari lokal; user pull+migrate di prod. Jangan jalankan `git push` ke sesi lain.

## File Structure

**Baru:**
- `database/migrations/2026_01_01_000127_create_ecom_chat_tables.php` — tabel percakapan + pesan.
- `database/migrations/2026_01_01_000128_add_group_to_ai_knowledge.php` — kolom `group`.
- `app/Models/EcomChatConversation.php`, `app/Models/EcomChatMessage.php` — model + relasi.
- `app/Services/EcomChatService.php` — facade channel-agnostic (sync masuk, kirim, status auto-send).
- `app/Services/Ai/EcomChatDrafter.php` — drafter AI (JSON `{reply,decision,reason}` + fail-safe).
- `app/Http/Controllers/TikTokChatWebhookController.php` — penerima webhook (HMAC + ack cepat + proses).
- `app/Http/Controllers/EcomChatController.php` — inbox/detail/kirim/redraft/toggle.
- `resources/views/ecom-chat/index.blade.php`, `resources/views/ecom-chat/show.blade.php` — UI.
- Tes: `tests/Feature/AiKnowledgeGroupTest.php`, `tests/Feature/EcomChatKnowledgeTabTest.php`, `tests/Feature/TikTokChatClientTest.php`, `tests/Feature/EcomChatServiceTest.php`, `tests/Feature/EcomChatDrafterTest.php`, `tests/Feature/TikTokChatWebhookTest.php`, `tests/Feature/EcomChatControllerTest.php`, `tests/Feature/EcomChatUiRenderTest.php`.

**Diubah:**
- `app/Models/AiKnowledge.php` — grup + `sectionsOf()` + `document(string $group)`.
- `app/Http/Controllers/AiAssistantController.php:127-151` — knowledge()/saveKnowledge() bergrup.
- `resources/views/ai/knowledge.blade.php` — tab switcher Sistem | Chat E-commerce.
- `app/Models/AppSetting.php` — const `ECOM_CHAT_AUTOSEND`.
- `app/Services/TikTokClient.php` — method IM `getConversations/getConversationMessages/sendMessage`.
- `app/Support/Permissions.php` — izin `manage_ecommerce_chat` (DEFINITIONS + DEFAULTS).
- `bootstrap/app.php:27` — kecualikan `webhooks/tiktok/chat` dari CSRF.
- `routes/web.php` — route webhook publik + grup `permission:manage_ecommerce_chat`.
- `resources/views/layouts/app.blade.php:300-308` — nav "Chat E-commerce" di grup Integrasi.

---

### Task 1: Data model — migrasi 000127 + model EcomChat

**Files:**
- Create: `database/migrations/2026_01_01_000127_create_ecom_chat_tables.php`
- Create: `app/Models/EcomChatConversation.php`
- Create: `app/Models/EcomChatMessage.php`
- Test: `tests/Feature/EcomChatModelTest.php`

**Interfaces:**
- Produces:
  - `EcomChatConversation` fillable: `channel, external_conversation_id, buyer_name, buyer_id, last_message_at, last_message_preview, status, ai_draft, ai_decision, ai_reason`. Casts: `last_message_at => datetime`. Relasi `messages(): HasMany`. Konstanta status: `STATUS_OPEN='open'`, `STATUS_NEEDS_STAFF='needs_staff'`, `STATUS_REPLIED='replied'`, `STATUS_CLOSED='closed'`.
  - `EcomChatMessage` fillable: `conversation_id, channel, external_message_id, sender, via, text, sent_at`. Casts: `sent_at => datetime`. Relasi `conversation(): BelongsTo`. Konstanta: `SENDER_BUYER='buyer'`, `SENDER_SELLER='seller'`, `SENDER_SYSTEM='system'`, `VIA_AI='ai'`, `VIA_STAFF='staff'`, `VIA_BUYER='buyer'`.
  - Tabel `ecom_chat_conversations` unik (`channel`,`external_conversation_id`); `ecom_chat_messages` unik (`channel`,`external_message_id`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EcomChatModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EcomChatConversation;
use App\Models\EcomChatMessage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EcomChatModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_percakapan_punya_banyak_pesan(): void
    {
        $conv = EcomChatConversation::create([
            'channel' => 'tiktok', 'external_conversation_id' => 'CONV1',
            'buyer_name' => 'Budi', 'status' => EcomChatConversation::STATUS_OPEN,
        ]);
        $conv->messages()->create([
            'channel' => 'tiktok', 'external_message_id' => 'M1',
            'sender' => EcomChatMessage::SENDER_BUYER, 'via' => EcomChatMessage::VIA_BUYER, 'text' => 'Halo',
        ]);

        $this->assertSame(1, $conv->messages()->count());
        $this->assertSame('Budi', $conv->messages->first()->conversation->buyer_name);
    }

    public function test_message_id_unik_per_channel(): void
    {
        $conv = EcomChatConversation::create([
            'channel' => 'tiktok', 'external_conversation_id' => 'CONV1', 'status' => 'open',
        ]);
        $conv->messages()->create([
            'channel' => 'tiktok', 'external_message_id' => 'DUP',
            'sender' => 'buyer', 'via' => 'buyer', 'text' => 'a',
        ]);

        $this->expectException(QueryException::class);
        $conv->messages()->create([
            'channel' => 'tiktok', 'external_message_id' => 'DUP',
            'sender' => 'buyer', 'via' => 'buyer', 'text' => 'b',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=EcomChatModelTest`
Expected: FAIL — class `EcomChatConversation` not found.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_01_01_000127_create_ecom_chat_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecom_chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('channel')->default('tiktok');
            $table->string('external_conversation_id');
            $table->string('buyer_name')->nullable();
            $table->string('buyer_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 255)->nullable();
            $table->string('status')->default('open'); // open|needs_staff|replied|closed
            $table->text('ai_draft')->nullable();
            $table->string('ai_decision')->nullable();  // auto_send|to_staff
            $table->text('ai_reason')->nullable();
            $table->timestamps();
            $table->unique(['channel', 'external_conversation_id']);
            $table->index(['status', 'last_message_at']);
        });

        Schema::create('ecom_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ecom_chat_conversations')->cascadeOnDelete();
            $table->string('channel')->default('tiktok');
            $table->string('external_message_id');
            $table->string('sender');   // buyer|seller|system
            $table->string('via');      // ai|staff|buyer
            $table->text('text')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['channel', 'external_message_id']);
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecom_chat_messages');
        Schema::dropIfExists('ecom_chat_conversations');
    }
};
```

- [ ] **Step 4: Write the models**

Create `app/Models/EcomChatConversation.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EcomChatConversation extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_NEEDS_STAFF = 'needs_staff';

    public const STATUS_REPLIED = 'replied';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'channel', 'external_conversation_id', 'buyer_name', 'buyer_id',
        'last_message_at', 'last_message_preview', 'status',
        'ai_draft', 'ai_decision', 'ai_reason',
    ];

    protected $casts = ['last_message_at' => 'datetime'];

    public function messages(): HasMany
    {
        return $this->hasMany(EcomChatMessage::class, 'conversation_id');
    }
}
```

Create `app/Models/EcomChatMessage.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EcomChatMessage extends Model
{
    public const SENDER_BUYER = 'buyer';

    public const SENDER_SELLER = 'seller';

    public const SENDER_SYSTEM = 'system';

    public const VIA_AI = 'ai';

    public const VIA_STAFF = 'staff';

    public const VIA_BUYER = 'buyer';

    protected $fillable = [
        'conversation_id', 'channel', 'external_message_id',
        'sender', 'via', 'text', 'sent_at',
    ];

    protected $casts = ['sent_at' => 'datetime'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(EcomChatConversation::class, 'conversation_id');
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `/c/php83/php.exe artisan test --filter=EcomChatModelTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add database/migrations/2026_01_01_000127_create_ecom_chat_tables.php app/Models/EcomChatConversation.php app/Models/EcomChatMessage.php tests/Feature/EcomChatModelTest.php
git commit -m "feat(ecom-chat): tabel & model percakapan/pesan chat e-commerce (000127)"
```

---

### Task 2: Kolom `group` di ai_knowledge + refactor model AiKnowledge

**Files:**
- Create: `database/migrations/2026_01_01_000128_add_group_to_ai_knowledge.php`
- Modify: `app/Models/AiKnowledge.php`
- Test: `tests/Feature/AiKnowledgeGroupTest.php`

**Interfaces:**
- Consumes: `AiKnowledge::map()` (tetap ada, keyed by `section`).
- Produces:
  - `AiKnowledge::GROUPS` = `['sistem' => 'Sistem', 'chat' => 'Chat E-commerce']`.
  - `AiKnowledge::SECTIONS` — bertambah 4 key chat: `chat_products`, `chat_policy`, `chat_faq`, `chat_tone` (bentuk tiap entri tetap `[title, question, placeholder]`).
  - `AiKnowledge::sectionsOf(string $group): array` — subset SECTIONS untuk grup itu (keyed by section).
  - `AiKnowledge::groupOf(string $key): string` — grup sebuah section ('sistem' default).
  - `AiKnowledge::document(string $group = 'sistem'): string` — hanya section grup itu (default menjaga perilaku lama).
  - Kolom `ai_knowledge.group` (string, default `'sistem'`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AiKnowledgeGroupTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AiKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiKnowledgeGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_sistem_tak_menyertakan_pengetahuan_chat(): void
    {
        AiKnowledge::create(['section' => 'rules', 'content' => 'Aturan sistem X', 'group' => 'sistem']);
        AiKnowledge::create(['section' => 'chat_faq', 'content' => 'FAQ untuk pembeli Y', 'group' => 'chat']);

        $sistem = AiKnowledge::document(); // default 'sistem'
        $this->assertStringContainsString('Aturan sistem X', $sistem);
        $this->assertStringNotContainsString('FAQ untuk pembeli Y', $sistem);
    }

    public function test_document_chat_hanya_pengetahuan_chat(): void
    {
        AiKnowledge::create(['section' => 'rules', 'content' => 'Aturan sistem X', 'group' => 'sistem']);
        AiKnowledge::create(['section' => 'chat_faq', 'content' => 'FAQ untuk pembeli Y', 'group' => 'chat']);

        $chat = AiKnowledge::document('chat');
        $this->assertStringContainsString('FAQ untuk pembeli Y', $chat);
        $this->assertStringNotContainsString('Aturan sistem X', $chat);
    }

    public function test_sectionsOf_memisahkan_grup(): void
    {
        $this->assertArrayHasKey('rules', AiKnowledge::sectionsOf('sistem'));
        $this->assertArrayNotHasKey('chat_faq', AiKnowledge::sectionsOf('sistem'));
        $this->assertArrayHasKey('chat_faq', AiKnowledge::sectionsOf('chat'));
        $this->assertSame('chat', AiKnowledge::groupOf('chat_faq'));
        $this->assertSame('sistem', AiKnowledge::groupOf('rules'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=AiKnowledgeGroupTest`
Expected: FAIL — `sectionsOf`/`groupOf` tidak ada; kolom `group` tak ada.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_01_01_000128_add_group_to_ai_knowledge.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_knowledge', function (Blueprint $table) {
            $table->string('group')->default('sistem')->after('section');
        });
    }

    public function down(): void
    {
        Schema::table('ai_knowledge', function (Blueprint $table) {
            $table->dropColumn('group');
        });
    }
};
```

- [ ] **Step 4: Refactor the model — add fillable, SECTIONS chat entries, group helpers**

In `app/Models/AiKnowledge.php`, change the fillable:

```php
    protected $fillable = ['section', 'content'];
```
to:
```php
    protected $fillable = ['section', 'group', 'content'];
```

Add the `GROUPS` const and `SECTION_GROUP` map immediately BEFORE the `public const SECTIONS = [` line:

```php
    /** Grup pengetahuan → label tab (urutan = urutan tab). */
    public const GROUPS = ['sistem' => 'Sistem', 'chat' => 'Chat E-commerce'];

    /** section key => grup. Key yang tak tercantum dianggap 'sistem'. */
    private const SECTION_GROUP = [
        'chat_products' => 'chat',
        'chat_policy' => 'chat',
        'chat_faq' => 'chat',
        'chat_tone' => 'chat',
    ];
```

Inside `SECTIONS`, APPEND these 4 entries just before the closing `];` (after the existing `'notes' => [ ... ],` entry):

```php
        'chat_products' => [
            'Produk (untuk customer)',
            'Info produk yang boleh dibagikan ke pembeli: nama, varian, ukuran, kegunaan, harga jual, stok/pre-order.',
            'Contoh: Day Cream 30ml Rp89.000 — brightening, aman ibu hamil. Serum 20ml Rp120.000. Ready stock kecuali varian Acne (PO 3 hari)…',
        ],
        'chat_policy' => [
            'Kebijakan kirim/retur/batal',
            'Aturan yang AI boleh sampaikan sendiri: jam & estimasi kirim, kurir, syarat retur/refund, cara & batas waktu pembatalan.',
            'Contoh: Kirim H+1 (order sebelum jam 3 sore). Kurir JNE/J&T. Retur 3 hari bila rusak/salah kirim, wajib video unboxing. Batal hanya sebelum dikirim…',
        ],
        'chat_faq' => [
            'FAQ',
            'Pertanyaan yang sering ditanya pembeli + jawaban bakunya.',
            'Contoh: "BPOM?" → semua produk terdaftar BPOM. "COD?" → belum ada COD, transfer/e-wallet. "Bisa grosir?" → min 12 pcs harga reseller…',
        ],
        'chat_tone' => [
            'Gaya bahasa ke pembeli',
            'Nada & aturan bicara saat membalas pembeli (beda dari gaya internal).',
            'Contoh: Ramah, singkat, pakai "Kak". Selalu ucap terima kasih. Jangan janji diskon tanpa promo resmi. Kalau ragu, arahkan ke admin…',
        ],
```

Replace the `document()` method entirely with a group-aware version, and add the two helpers just above it:

```php
    /** Section untuk satu grup (keyed by section key). */
    public static function sectionsOf(string $group): array
    {
        return array_filter(
            self::SECTIONS,
            fn ($key) => self::groupOf($key) === $group,
            ARRAY_FILTER_USE_KEY
        );
    }

    /** Grup sebuah section ('sistem' bila tak terdaftar). */
    public static function groupOf(string $key): string
    {
        return self::SECTION_GROUP[$key] ?? 'sistem';
    }

    /**
     * Rangkai bagian terisi grup ini jadi satu blok teks buat system-prompt.
     * Default 'sistem' → perilaku lama (asisten internal & OKR tak berubah).
     * Kosong → '' . Dipotong di MAX_CHARS.
     */
    public static function document(string $group = 'sistem'): string
    {
        $map = static::map();
        $parts = [];
        foreach (self::sectionsOf($group) as $key => [$title]) {
            $content = trim((string) ($map[$key] ?? ''));
            if ($content !== '') {
                $parts[] = "## {$title}\n{$content}";
            }
        }

        if ($parts === []) {
            return '';
        }

        return Str::limit(implode("\n\n", $parts), self::MAX_CHARS, ' …(dipotong)');
    }
```

- [ ] **Step 5: Run tests to verify pass (new + existing AiKnowledge)**

Run: `/c/php83/php.exe artisan test --filter=AiKnowledgeGroupTest`
Expected: PASS (3 tests).

Run: `/c/php83/php.exe artisan test --filter=AiKnowledgeTest`
Expected: PASS — perilaku `document()` lama tetap (default 'sistem').

- [ ] **Step 6: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add database/migrations/2026_01_01_000128_add_group_to_ai_knowledge.php app/Models/AiKnowledge.php tests/Feature/AiKnowledgeGroupTest.php
git commit -m "feat(ecom-chat): kolom group + section chat channel-agnostic di AiKnowledge (000128)"
```

---

### Task 3: Tab "Chat E-commerce" di halaman Pengetahuan AI

**Files:**
- Modify: `app/Http/Controllers/AiAssistantController.php:127-151`
- Modify: `resources/views/ai/knowledge.blade.php`
- Test: `tests/Feature/EcomChatKnowledgeTabTest.php`

**Interfaces:**
- Consumes: `AiKnowledge::GROUPS`, `AiKnowledge::sectionsOf($group)`, `AiKnowledge::groupOf($key)`, `AiKnowledge::map()`.
- Produces: `knowledge()` view menerima `groups` (=`AiKnowledge::GROUPS`), `sectionsByGroup` (`[group => sectionsOf]`), `values` (=`map()`). `saveKnowledge()` menulis `group` per section.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EcomChatKnowledgeTabTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AiKnowledge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EcomChatKnowledgeTabTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'A', 'fullname' => 'A', 'username' => 'admin', 'email' => 'a@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_halaman_menampilkan_dua_tab(): void
    {
        $this->actingAs($this->admin())->get('/asisten/pengetahuan')->assertOk()
            ->assertSee('Chat E-commerce')
            ->assertSee('Gaya bahasa ke pembeli');
    }

    public function test_simpan_grup_chat_tak_menghapus_sistem(): void
    {
        AiKnowledge::create(['section' => 'rules', 'content' => 'Aturan lama', 'group' => 'sistem']);

        $this->actingAs($this->admin())->post('/asisten/pengetahuan', [
            'group' => 'chat',
            'content' => ['chat_faq' => 'BPOM semua terdaftar'],
        ])->assertRedirect();

        // Chat tersimpan dgn grup benar
        $faq = AiKnowledge::where('section', 'chat_faq')->first();
        $this->assertNotNull($faq);
        $this->assertSame('chat', $faq->group);
        $this->assertSame('BPOM semua terdaftar', $faq->content);

        // Grup sistem tak tersentuh saat menyimpan tab chat
        $this->assertSame('Aturan lama', AiKnowledge::where('section', 'rules')->first()->content);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=EcomChatKnowledgeTabTest`
Expected: FAIL — view belum punya tab "Chat E-commerce"; saveKnowledge belum per-grup.

- [ ] **Step 3: Update the controller**

In `app/Http/Controllers/AiAssistantController.php`, replace the `knowledge()` method:

```php
    /** Halaman "Pengetahuan AI" — kotak terpandu konteks bisnis (memori asisten). */
    public function knowledge()
    {
        $sectionsByGroup = [];
        foreach (array_keys(AiKnowledge::GROUPS) as $group) {
            $sectionsByGroup[$group] = AiKnowledge::sectionsOf($group);
        }

        return view('ai.knowledge', [
            'groups' => AiKnowledge::GROUPS,
            'sectionsByGroup' => $sectionsByGroup,
            'values' => AiKnowledge::map(),
        ]);
    }
```

And replace `saveKnowledge()`:

```php
    public function saveKnowledge(Request $request): RedirectResponse
    {
        $request->validate([
            'group' => ['required', 'string'],
            'content' => ['array'],
            'content.*' => ['nullable', 'string', 'max:8000'],
        ]);

        $group = (string) $request->input('group');
        abort_unless(array_key_exists($group, AiKnowledge::GROUPS), 422);

        $input = (array) $request->input('content', []);
        // HANYA tulis section milik grup yang sedang disimpan → tab lain tak tersentuh.
        foreach (array_keys(AiKnowledge::sectionsOf($group)) as $key) {
            $val = trim((string) ($input[$key] ?? ''));
            AiKnowledge::updateOrCreate(
                ['section' => $key],
                ['content' => $val !== '' ? $val : null, 'group' => $group],
            );
        }

        AuditService::log(action: 'save_ai_knowledge', targetType: 'ai_knowledge', after: ['grup' => $group, 'terisi' => count(array_filter($input, fn ($v) => filled($v)))]);

        return redirect()->route('ai.knowledge', ['tab' => $group])->with('status', 'Pengetahuan disimpan. Langsung dipakai di obrolan/chat berikutnya.');
    }
```

- [ ] **Step 4: Rewrite the view with a tab switcher**

Replace the whole `@section('content') ... @endsection` block in `resources/views/ai/knowledge.blade.php`:

```blade
@section('content')
@php($activeTab = request('tab', 'sistem'))
@php($activeTab = array_key_exists($activeTab, $groups) ? $activeTab : 'sistem')
<div class="max-w-3xl">
    <div class="bg-indigo-50 border border-indigo-200 rounded-2xl p-4 mb-5">
        <p class="text-sm font-bold text-indigo-900">Ini "memori" asisten kamu 🧠</p>
        <p class="text-xs text-indigo-700 mt-1"><b>Sistem</b> = konteks buat Asisten AI internal & OKR. <b>Chat E-commerce</b> = pengetahuan buat balas chat pembeli (TikTok, nanti Shopee). Isi seadanya dulu juga nggak apa — makin lengkap, makin pintar.</p>
    </div>

    <div class="flex gap-1 mb-4 border-b border-stone-200">
        @foreach($groups as $gkey => $glabel)
            <a href="{{ route('ai.knowledge', ['tab' => $gkey]) }}"
               class="px-4 py-2 text-sm font-semibold rounded-t-lg -mb-px border-b-2 {{ $activeTab === $gkey ? 'border-red-600 text-red-700 bg-white' : 'border-transparent text-stone-500 hover:text-stone-800' }}">
                {{ $glabel }}
            </a>
        @endforeach
    </div>

    <form method="POST" action="{{ route('ai.knowledge.save') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="group" value="{{ $activeTab }}">
        @foreach($sectionsByGroup[$activeTab] as $key => $meta)
            @php([$title, $question, $placeholder] = $meta)
            <div class="bg-white rounded-2xl border border-stone-200 p-5">
                <label class="block">
                    <span class="text-sm font-bold text-stone-800">{{ $title }}</span>
                    <span class="block text-[11px] text-stone-500 mt-0.5">{{ $question }}</span>
                    <textarea name="content[{{ $key }}]" rows="3" maxlength="8000" placeholder="{{ $placeholder }}"
                        class="mt-2 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">{{ old('content.'.$key, $values[$key] ?? '') }}</textarea>
                </label>
            </div>
        @endforeach

        <div class="flex items-center gap-2 sticky bottom-4">
            <button class="px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold shadow">Simpan {{ $groups[$activeTab] }}</button>
            <span class="text-[11px] text-stone-400">Tersimpan langsung dipakai di obrolan/chat berikutnya.</span>
        </div>
    </form>
</div>
@endsection
```

- [ ] **Step 5: Run test to verify it passes**

Run: `/c/php83/php.exe artisan test --filter=EcomChatKnowledgeTabTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/AiAssistantController.php resources/views/ai/knowledge.blade.php tests/Feature/EcomChatKnowledgeTabTest.php
git commit -m "feat(ecom-chat): tab Chat E-commerce di Pengetahuan AI (grup sistem/chat)"
```

---

### Task 4: Method IM di TikTokClient (getConversations/getConversationMessages/sendMessage)

**Files:**
- Modify: `app/Services/TikTokClient.php`
- Test: `tests/Feature/TikTokChatClientTest.php`

**Interfaces:**
- Consumes: `TikTokClient::request($method, $path, $accessToken, $shopCipher, $extraQuery, $body)` (sudah ada).
- Produces (semua kembalikan `array` = `data` dari respons TikTok):
  - `getConversations(string $accessToken, string $shopCipher, int $pageSize = 20, string $pageToken = ''): array`
  - `getConversationMessages(string $accessToken, string $shopCipher, string $conversationId, int $pageSize = 20, string $pageToken = ''): array`
  - `sendMessage(string $accessToken, string $shopCipher, string $conversationId, string $text): array`

> **Catatan implementer (verifikasi ke dokumentasi TikTok saat build):** path/versi Customer Service API. Default yang dipakai plan ini = keluarga `/customer_service/202309/...` (versi GA IM TikTok Shop) dan body Send Message `{"type":"TEXT","content":<text>}`. Bila dokumentasi terbaru berbeda, ubah HANYA string path & bentuk body di tiga method ini — tes memakai `Http::fake` yang mengikuti apa pun yang di-request, jadi tetap hijau; kebenaran lapangan = mencocokkan konstanta ini dengan docs.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TikTokChatClientTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\TikTokClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TikTokChatClientTest extends TestCase
{
    public function test_send_message_memanggil_endpoint_conversation_dengan_teks(): void
    {
        config()->set('services.tiktok.app_key', 'testkey');
        config()->set('services.tiktok.app_secret', 'testsecret');
        config()->set('services.tiktok.api_base', 'https://open-api.tiktokglobalshop.com');

        Http::fake([
            '*/customer_service/*' => Http::response(['code' => 0, 'message' => 'ok', 'data' => ['message_id' => 'NEW1']]),
        ]);

        $client = app(TikTokClient::class);
        $data = $client->sendMessage('acc-token', 'cipher-1', 'CONV9', 'Halo kak, terima kasih');

        $this->assertSame('NEW1', $data['message_id']);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/customer_service/')
                && str_contains($request->url(), 'CONV9')
                && str_contains($request->body(), 'Halo kak, terima kasih')
                && $request->hasHeader('x-tts-access-token', 'acc-token');
        });
    }

    public function test_get_conversation_messages_mengembalikan_data(): void
    {
        config()->set('services.tiktok.app_key', 'testkey');
        config()->set('services.tiktok.app_secret', 'testsecret');
        config()->set('services.tiktok.api_base', 'https://open-api.tiktokglobalshop.com');

        Http::fake([
            '*/customer_service/*' => Http::response(['code' => 0, 'data' => ['messages' => [['id' => 'M1']]]]),
        ]);

        $data = app(TikTokClient::class)->getConversationMessages('t', 'c', 'CONV9', 20);
        $this->assertSame('M1', $data['messages'][0]['id']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=TikTokChatClientTest`
Expected: FAIL — `sendMessage`/`getConversationMessages` tidak ada.

- [ ] **Step 3: Add the three IM methods**

In `app/Services/TikTokClient.php`, add after the `searchReturns()` method (anchor: setelah blok `searchReturns` return statement):

```php
    /**
     * Customer Service API — daftar percakapan (TERBARU dulu). Satu halaman.
     * NB: path/versi = keluarga /customer_service/202309; cocokkan dgn docs TikTok.
     */
    public function getConversations(string $accessToken, string $shopCipher, int $pageSize = 20, string $pageToken = ''): array
    {
        $query = ['page_size' => $pageSize];
        if ($pageToken !== '') {
            $query['page_token'] = $pageToken;
        }

        return $this->request('GET', '/customer_service/202309/conversations', $accessToken, $shopCipher, $query);
    }

    /** Pesan dalam satu percakapan (TERBARU dulu). Satu halaman. */
    public function getConversationMessages(string $accessToken, string $shopCipher, string $conversationId, int $pageSize = 20, string $pageToken = ''): array
    {
        $query = ['page_size' => $pageSize];
        if ($pageToken !== '') {
            $query['page_token'] = $pageToken;
        }

        return $this->request('GET', "/customer_service/202309/conversations/{$conversationId}/messages", $accessToken, $shopCipher, $query);
    }

    /** Kirim balasan teks ke satu percakapan. */
    public function sendMessage(string $accessToken, string $shopCipher, string $conversationId, string $text): array
    {
        return $this->request(
            'POST',
            "/customer_service/202309/conversations/{$conversationId}/messages",
            $accessToken,
            $shopCipher,
            [],
            ['type' => 'TEXT', 'content' => $text],
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `/c/php83/php.exe artisan test --filter=TikTokChatClientTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Services/TikTokClient.php tests/Feature/TikTokChatClientTest.php
git commit -m "feat(ecom-chat): method IM Customer Service API di TikTokClient"
```

---

### Task 5: EcomChatService (facade) + kill switch AppSetting

**Files:**
- Modify: `app/Models/AppSetting.php`
- Create: `app/Services/EcomChatService.php`
- Test: `tests/Feature/EcomChatServiceTest.php`

**Interfaces:**
- Consumes: `TikTokClient::sendMessage()`, `TikTokSyncService::freshToken()`, `TiktokConnection::latest('id')->first()`, `AppSetting::get()/put()`.
- Produces:
  - `AppSetting::ECOM_CHAT_AUTOSEND = 'ecom_chat_autosend'`.
  - `EcomChatService::autosendEnabled(): bool` — `AppSetting::get(ECOM_CHAT_AUTOSEND, '0') === '1'`.
  - `EcomChatService::syncIncoming(string $channel, array $msg): ?EcomChatMessage` — `$msg` = `['conversation_id','message_id','text','buyer_name','buyer_id','sender','sent_at'(epoch|null)]`. Upsert conversation, dedupe by (`channel`,`external_message_id`), simpan pesan buyer, update `last_message_*` + set status `open`. Return null bila duplikat atau sender bukan buyer (anti-loop).
  - `EcomChatService::send(EcomChatConversation $conv, string $text, string $via): EcomChatMessage` — kirim via `TikTokClient::sendMessage` (token segar), simpan pesan `sender=seller,via=$via`, set conversation `status=replied`, `last_message_*`. Melempar bila tak ada koneksi TikTok.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EcomChatServiceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\EcomChatConversation;
use App\Models\EcomChatMessage;
use App\Models\TiktokConnection;
use App\Services\EcomChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcomChatServiceTest extends TestCase
{
    use RefreshDatabase;

    private function connect(): void
    {
        TiktokConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(),
        ]);
        config()->set('services.tiktok.app_key', 'k');
        config()->set('services.tiktok.app_secret', 's');
        config()->set('services.tiktok.api_base', 'https://open-api.tiktokglobalshop.com');
    }

    public function test_sync_incoming_dedupe_dan_upsert_percakapan(): void
    {
        $svc = app(EcomChatService::class);
        $payload = [
            'conversation_id' => 'CONV1', 'message_id' => 'M1', 'text' => 'Halo',
            'buyer_name' => 'Budi', 'buyer_id' => 'B1', 'sender' => 'buyer', 'sent_at' => null,
        ];

        $first = $svc->syncIncoming('tiktok', $payload);
        $dup = $svc->syncIncoming('tiktok', $payload);

        $this->assertNotNull($first);
        $this->assertNull($dup); // dedupe
        $this->assertSame(1, EcomChatMessage::count());
        $conv = EcomChatConversation::first();
        $this->assertSame('Budi', $conv->buyer_name);
        $this->assertSame('Halo', $conv->last_message_preview);
    }

    public function test_sync_incoming_abaikan_pesan_penjual(): void
    {
        $svc = app(EcomChatService::class);
        $out = $svc->syncIncoming('tiktok', [
            'conversation_id' => 'CONV1', 'message_id' => 'S1', 'text' => 'balasan toko',
            'sender' => 'seller', 'sent_at' => null,
        ]);
        $this->assertNull($out);
        $this->assertSame(0, EcomChatMessage::count());
    }

    public function test_send_memanggil_api_dan_menyimpan_pesan_seller(): void
    {
        $this->connect();
        Http::fake(['*/customer_service/*' => Http::response(['code' => 0, 'data' => ['message_id' => 'X']])]);

        $conv = EcomChatConversation::create([
            'channel' => 'tiktok', 'external_conversation_id' => 'CONV1', 'status' => 'open',
        ]);
        $svc = app(EcomChatService::class);
        $msg = $svc->send($conv, 'Terima kasih kak', EcomChatMessage::VIA_STAFF);

        $this->assertSame('seller', $msg->sender);
        $this->assertSame('staff', $msg->via);
        $this->assertSame('replied', $conv->fresh()->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'CONV1'));
    }

    public function test_autosend_default_mati(): void
    {
        $this->assertFalse(app(EcomChatService::class)->autosendEnabled());
        AppSetting::put(AppSetting::ECOM_CHAT_AUTOSEND, '1');
        $this->assertTrue(app(EcomChatService::class)->autosendEnabled());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=EcomChatServiceTest`
Expected: FAIL — `EcomChatService` & `AppSetting::ECOM_CHAT_AUTOSEND` tak ada.

- [ ] **Step 3: Add the AppSetting constant**

In `app/Models/AppSetting.php`, after the `PO_DEDUCT_FROM` const, add:

```php
    /** Kill switch auto-send Chat E-commerce: '1'=nyala, selain itu MATI. */
    public const ECOM_CHAT_AUTOSEND = 'ecom_chat_autosend';
```

- [ ] **Step 4: Write the service**

Create `app/Services/EcomChatService.php`:

```php
<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\EcomChatConversation;
use App\Models\EcomChatMessage;
use App\Models\TiktokConnection;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Facade chat channel-agnostic. UI & webhook lewat sini, bukan langsung ke
 * TikTokClient — supaya menambah Shopee dll nanti tinggal cabang di send()/sync.
 */
class EcomChatService
{
    public function __construct(
        private TikTokClient $tiktok,
        private TikTokSyncService $sync,
    ) {}

    public function autosendEnabled(): bool
    {
        return AppSetting::get(AppSetting::ECOM_CHAT_AUTOSEND, '0') === '1';
    }

    /**
     * Simpan pesan MASUK dari pembeli. Dedupe by (channel, message_id); abaikan
     * pesan non-buyer (anti-loop). Return pesan baru, atau null bila diabaikan/dobel.
     *
     * @param  array{conversation_id:string,message_id:string,text:?string,buyer_name?:?string,buyer_id?:?string,sender?:string,sent_at?:?int}  $msg
     */
    public function syncIncoming(string $channel, array $msg): ?EcomChatMessage
    {
        if (($msg['sender'] ?? 'buyer') !== 'buyer') {
            return null; // anti-loop: hanya proses pesan pembeli
        }

        if (EcomChatMessage::where('channel', $channel)->where('external_message_id', $msg['message_id'])->exists()) {
            return null; // dedupe
        }

        $sentAt = ! empty($msg['sent_at']) ? Carbon::createFromTimestamp($msg['sent_at']) : now();
        $text = (string) ($msg['text'] ?? '');

        $conv = EcomChatConversation::firstOrNew([
            'channel' => $channel,
            'external_conversation_id' => $msg['conversation_id'],
        ]);
        $conv->buyer_name = $msg['buyer_name'] ?? $conv->buyer_name;
        $conv->buyer_id = $msg['buyer_id'] ?? $conv->buyer_id;
        $conv->last_message_at = $sentAt;
        $conv->last_message_preview = mb_substr($text, 0, 255);
        $conv->status = EcomChatConversation::STATUS_OPEN;
        $conv->save();

        return $conv->messages()->create([
            'channel' => $channel,
            'external_message_id' => $msg['message_id'],
            'sender' => EcomChatMessage::SENDER_BUYER,
            'via' => EcomChatMessage::VIA_BUYER,
            'text' => $text,
            'sent_at' => $sentAt,
        ]);
    }

    /**
     * Kirim balasan KELUAR ke pembeli lewat API channel, lalu catat pesan seller
     * & tandai percakapan "replied". $via = 'ai' (auto-send) atau 'staff'.
     */
    public function send(EcomChatConversation $conv, string $text, string $via): EcomChatMessage
    {
        $conn = TiktokConnection::latest('id')->first();
        if (! $conn || ! $conn->shop_cipher) {
            throw new RuntimeException('Belum terhubung ke TikTok Shop.');
        }

        $access = $this->sync->freshToken($conn);
        $res = $this->tiktok->sendMessage($access, $conn->shop_cipher, $conv->external_conversation_id, $text);
        $externalId = (string) ($res['message_id'] ?? ('local-'.uniqid()));

        $msg = $conv->messages()->create([
            'channel' => $conv->channel,
            'external_message_id' => $externalId,
            'sender' => EcomChatMessage::SENDER_SELLER,
            'via' => $via,
            'text' => $text,
            'sent_at' => now(),
        ]);

        $conv->update([
            'status' => EcomChatConversation::STATUS_REPLIED,
            'last_message_at' => now(),
            'last_message_preview' => mb_substr($text, 0, 255),
        ]);

        return $msg;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `/c/php83/php.exe artisan test --filter=EcomChatServiceTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Models/AppSetting.php app/Services/EcomChatService.php tests/Feature/EcomChatServiceTest.php
git commit -m "feat(ecom-chat): EcomChatService (sync/send) + kill switch autosend"
```

---

### Task 6: EcomChatDrafter (AI → {reply, decision, reason})

**Files:**
- Create: `app/Services/Ai/EcomChatDrafter.php`
- Test: `tests/Feature/EcomChatDrafterTest.php`

**Interfaces:**
- Consumes: `AiProvider` (container `AiProvider::class`), `AiKnowledge::document('chat')`, `EcomChatConversation->messages`.
- Produces: `EcomChatDrafter::draft(EcomChatConversation $conv): array` → `['reply' => string, 'decision' => 'auto_send'|'to_staff', 'reason' => string]`. Fail-safe: provider error / JSON tak terparse / reply kosong / decision tak dikenal → `['reply' => '', 'decision' => 'to_staff', 'reason' => '...']`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EcomChatDrafterTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AiKnowledge;
use App\Models\EcomChatConversation;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiTurn;
use App\Services\Ai\EcomChatDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

class EcomChatDrafterTest extends TestCase
{
    use RefreshDatabase;

    private function conv(string $buyerText): EcomChatConversation
    {
        $c = EcomChatConversation::create([
            'channel' => 'tiktok', 'external_conversation_id' => 'CONV1', 'status' => 'open',
        ]);
        $c->messages()->create([
            'channel' => 'tiktok', 'external_message_id' => 'M'.uniqid(),
            'sender' => 'buyer', 'via' => 'buyer', 'text' => $buyerText,
        ]);

        return $c;
    }

    private function fakeAi(string $text): void
    {
        $this->app->instance(AiProvider::class, new FakeAiProvider([new AiTurn(text: $text)]));
    }

    public function test_faq_menghasilkan_auto_send(): void
    {
        AiKnowledge::create(['section' => 'chat_faq', 'content' => 'BPOM: semua terdaftar', 'group' => 'chat']);
        $this->fakeAi('{"reply":"Semua produk kami sudah BPOM kak 😊","decision":"auto_send","reason":"FAQ umum"}');

        $out = app(EcomChatDrafter::class)->draft($this->conv('Produknya BPOM ga kak?'));

        $this->assertSame('auto_send', $out['decision']);
        $this->assertStringContainsString('BPOM', $out['reply']);
    }

    public function test_kasus_spesifik_ke_staf(): void
    {
        $this->fakeAi('{"reply":"","decision":"to_staff","reason":"minta batal order spesifik"}');
        $out = app(EcomChatDrafter::class)->draft($this->conv('Tolong batalin pesanan saya #12345'));
        $this->assertSame('to_staff', $out['decision']);
    }

    public function test_json_rusak_dipaksa_ke_staf(): void
    {
        $this->fakeAi('maaf ini bukan json sama sekali');
        $out = app(EcomChatDrafter::class)->draft($this->conv('halo'));
        $this->assertSame('to_staff', $out['decision']);
        $this->assertSame('', $out['reply']);
    }

    public function test_reply_dibungkus_code_fence_tetap_terparse(): void
    {
        $this->fakeAi("```json\n{\"reply\":\"Halo kak\",\"decision\":\"auto_send\",\"reason\":\"sapaan\"}\n```");
        $out = app(EcomChatDrafter::class)->draft($this->conv('halo'));
        $this->assertSame('auto_send', $out['decision']);
        $this->assertSame('Halo kak', $out['reply']);
    }

    public function test_decision_tak_dikenal_dipaksa_ke_staf(): void
    {
        $this->fakeAi('{"reply":"apa saja","decision":"kirim_langsung","reason":"x"}');
        $out = app(EcomChatDrafter::class)->draft($this->conv('halo'));
        $this->assertSame('to_staff', $out['decision']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=EcomChatDrafterTest`
Expected: FAIL — `EcomChatDrafter` tak ada.

- [ ] **Step 3: Write the drafter**

Create `app/Services/Ai/EcomChatDrafter.php`:

```php
<?php

namespace App\Services\Ai;

use App\Models\AiKnowledge;
use App\Models\EcomChatConversation;
use Throwable;

/**
 * Menyusun draft balasan CS dari pengetahuan Chat E-commerce + riwayat pesan.
 * Output selalu terstruktur {reply, decision, reason}. Fail-safe: apa pun yang
 * meragukan (error provider, JSON rusak, reply kosong, decision asing) → to_staff,
 * TAK PERNAH mengarang auto-send.
 */
class EcomChatDrafter
{
    /** Jumlah pesan terakhir yang diberikan sebagai konteks. */
    private const HISTORY = 12;

    public function __construct(private AiProvider $provider) {}

    /** @return array{reply:string,decision:string,reason:string} */
    public function draft(EcomChatConversation $conv): array
    {
        try {
            $turn = $this->provider->chat($this->messages($conv), []);
            $parsed = $this->parse((string) ($turn->text ?? ''));
        } catch (Throwable $e) {
            return $this->escalate('AI gagal: '.$e->getMessage());
        }

        if ($parsed === null) {
            return $this->escalate('Balasan AI tak terbaca (JSON rusak).');
        }

        $decision = $parsed['decision'] ?? '';
        $reply = trim((string) ($parsed['reply'] ?? ''));

        if ($decision === 'auto_send' && $reply !== '') {
            return ['reply' => $reply, 'decision' => 'auto_send', 'reason' => (string) ($parsed['reason'] ?? '')];
        }

        // to_staff (atau apa pun selain auto_send valid): simpan reply sbg draft usulan.
        return ['reply' => $reply, 'decision' => 'to_staff', 'reason' => (string) ($parsed['reason'] ?? 'diteruskan ke staf')];
    }

    /** @return array<int,array<string,string>> */
    private function messages(EcomChatConversation $conv): array
    {
        $knowledge = AiKnowledge::document('chat');
        $system = $this->systemPrompt($knowledge);

        $out = [['role' => 'system', 'content' => $system]];
        $history = $conv->messages()->orderBy('id')->get()->slice(-self::HISTORY);
        foreach ($history as $m) {
            $role = $m->sender === 'buyer' ? 'user' : 'assistant';
            $out[] = ['role' => $role, 'content' => (string) $m->text];
        }

        return $out;
    }

    private function systemPrompt(string $knowledge): string
    {
        $kb = $knowledge !== '' ? $knowledge : '(Belum ada pengetahuan chat diisi.)';

        return <<<TXT
        Kamu customer service SKINKU (skincare) yang membalas chat pembeli di marketplace.
        Jawab HANYA berdasarkan PENGETAHUAN di bawah. Ramah, singkat, Bahasa Indonesia, panggil "Kak".

        # PENGETAHUAN
        {$kb}

        # ATURAN KEPUTUSAN (decision)
        - "auto_send" HANYA untuk pertanyaan umum/kebijakan/FAQ yang jawabannya JELAS ada di pengetahuan
          (cara batal umum, kebijakan retur/refund/ongkir, cara lacak, jam kirim, info produk).
        - "to_staff" untuk: pesanan/komplain SPESIFIK milik pembeli (mis. "batalin order SAYA #123",
          "barang saya rusak"), butuh data order/tindakan, nego harga, pembeli minta bicara manusia/CS,
          ATAU kamu ragu / jawabannya tak ada di pengetahuan. Kalau ragu → to_staff.
        - JANGAN mengarang fakta/janji/diskon yang tak ada di pengetahuan.

        # FORMAT OUTPUT (WAJIB JSON valid, tanpa teks lain)
        {"reply": "<balasan buat pembeli>", "decision": "auto_send" | "to_staff", "reason": "<alasan singkat>"}
        Untuk to_staff, "reply" boleh berisi usulan draft (staf akan meninjau) atau string kosong.
        TXT;
    }

    /** @return array<string,mixed>|null */
    private function parse(string $text): ?array
    {
        $text = trim($text);
        // Buang code fence ```json ... ```
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
        // Ambil dari { pertama sampai } terakhir (jaga bila ada teks pembungkus).
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $json = substr($text, $start, $end - $start + 1);
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /** @return array{reply:string,decision:string,reason:string} */
    private function escalate(string $reason): array
    {
        return ['reply' => '', 'decision' => 'to_staff', 'reason' => $reason];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `/c/php83/php.exe artisan test --filter=EcomChatDrafterTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Services/Ai/EcomChatDrafter.php tests/Feature/EcomChatDrafterTest.php
git commit -m "feat(ecom-chat): EcomChatDrafter (AI JSON decision + fail-safe to_staff)"
```

---

### Task 7: Webhook penerima + pemrosesan setelah respon

**Files:**
- Modify: `app/Services/EcomChatService.php` (tambah `processDraft()`)
- Create: `app/Http/Controllers/TikTokChatWebhookController.php`
- Modify: `bootstrap/app.php:27` (CSRF except)
- Modify: `routes/web.php` (route publik)
- Test: `tests/Feature/TikTokChatWebhookTest.php`

**Interfaces:**
- Consumes: `EcomChatService::syncIncoming()`, `EcomChatService::send()`, `EcomChatService::autosendEnabled()`, `EcomChatDrafter::draft()`.
- Produces:
  - `EcomChatService::processDraft(EcomChatConversation $conv): void` — jalankan drafter, simpan `ai_draft/ai_decision/ai_reason`; jika `decision=auto_send` DAN `autosendEnabled()` → `send($conv, reply, 'ai')`; selain itu status `needs_staff`.
  - Route `POST /webhooks/tiktok/chat` → `TikTokChatWebhookController@handle`. Verifikasi HMAC; invalid → 401. Dedupe + simpan lewat `syncIncoming`. Ack 200 cepat lalu `processDraft` setelah respon (pola `fastcgi_finish_request`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TikTokChatWebhookTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\EcomChatConversation;
use App\Models\EcomChatMessage;
use App\Models\TiktokConnection;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

class TikTokChatWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'topsecret';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.tiktok.app_key', 'k');
        config()->set('services.tiktok.app_secret', $this->secret);
        config()->set('services.tiktok.api_base', 'https://open-api.tiktokglobalshop.com');
    }

    private function payload(string $messageId = 'M1', string $conv = 'CONV1'): array
    {
        return [
            'type' => 14,
            'shop_id' => 'S',
            'data' => [
                'conversation_id' => $conv,
                'message_id' => $messageId,
                'content' => 'Produknya BPOM ga kak?',
                'sender' => ['role' => 'BUYER', 'im_user_id' => 'B1', 'nickname' => 'Budi'],
                'create_time' => 1757000000,
            ],
        ];
    }

    private function post(array $payload)
    {
        $body = json_encode($payload);
        $sign = hash_hmac('sha256', 'k'.$body, $this->secret);

        return $this->call('POST', '/webhooks/tiktok/chat', [], [], [], [
            'HTTP_AUTHORIZATION' => $sign,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_tanda_tangan_valid_menyimpan_pesan_dan_membuat_draft(): void
    {
        $this->app->instance(AiProvider::class, new FakeAiProvider([
            new AiTurn(text: '{"reply":"Sudah BPOM kak","decision":"auto_send","reason":"FAQ"}'),
        ]));

        $this->post($this->payload())->assertOk();

        $this->assertSame(1, EcomChatMessage::where('sender', 'buyer')->count());
        $conv = EcomChatConversation::first();
        // Kill switch default MATI → auto_send TIDAK terkirim, hanya draft + needs_staff.
        $this->assertSame('auto_send', $conv->ai_decision);
        $this->assertSame('needs_staff', $conv->status);
        $this->assertSame(0, EcomChatMessage::where('sender', 'seller')->count());
    }

    public function test_kill_switch_nyala_auto_send_terkirim(): void
    {
        AppSetting::put(AppSetting::ECOM_CHAT_AUTOSEND, '1');
        TiktokConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(),
        ]);
        Http::fake(['*/customer_service/*' => Http::response(['code' => 0, 'data' => ['message_id' => 'OUT1']])]);
        $this->app->instance(AiProvider::class, new FakeAiProvider([
            new AiTurn(text: '{"reply":"Sudah BPOM kak","decision":"auto_send","reason":"FAQ"}'),
        ]));

        $this->post($this->payload())->assertOk();

        $this->assertSame(1, EcomChatMessage::where('sender', 'seller')->where('via', 'ai')->count());
        $this->assertSame('replied', EcomChatConversation::first()->status);
    }

    public function test_tanda_tangan_salah_ditolak_401(): void
    {
        $body = json_encode($this->payload());

        $this->call('POST', '/webhooks/tiktok/chat', [], [], [], [
            'HTTP_AUTHORIZATION' => 'salah', 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);

        $this->assertSame(0, EcomChatMessage::count());
    }

    public function test_message_id_dobel_didedupe(): void
    {
        $this->app->instance(AiProvider::class, new FakeAiProvider([
            new AiTurn(text: '{"reply":"x","decision":"to_staff","reason":"y"}'),
            new AiTurn(text: '{"reply":"x","decision":"to_staff","reason":"y"}'),
        ]));

        $this->post($this->payload('DUP'))->assertOk();
        $this->post($this->payload('DUP'))->assertOk();

        $this->assertSame(1, EcomChatMessage::where('external_message_id', 'DUP')->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=TikTokChatWebhookTest`
Expected: FAIL — route `webhooks/tiktok/chat` 404 (belum ada).

- [ ] **Step 3: Add `processDraft()` to EcomChatService**

In `app/Services/EcomChatService.php`, add `use App\Services\Ai\EcomChatDrafter;` at the top, then add this method (after `send()`):

```php
    /**
     * Jalankan drafter untuk sebuah percakapan, simpan draft/keputusan, dan
     * auto-send bila keputusan auto_send DAN kill switch nyala. Selain itu →
     * needs_staff (draft menunggu ditinjau staf). Aman dipanggil dari webhook
     * (dibungkus try/catch di controller).
     */
    public function processDraft(EcomChatConversation $conv): void
    {
        $draft = app(EcomChatDrafter::class)->draft($conv);
        $conv->update([
            'ai_draft' => $draft['reply'],
            'ai_decision' => $draft['decision'],
            'ai_reason' => $draft['reason'],
        ]);

        if ($draft['decision'] === 'auto_send' && $draft['reply'] !== '' && $this->autosendEnabled()) {
            $this->send($conv, $draft['reply'], EcomChatMessage::VIA_AI);

            return;
        }

        $conv->update(['status' => EcomChatConversation::STATUS_NEEDS_STAFF]);
    }
```

- [ ] **Step 4: Write the webhook controller**

Create `app/Http/Controllers/TikTokChatWebhookController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\EcomChatConversation;
use App\Services\EcomChatService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Penerima webhook Customer Service TikTok (NEW_MESSAGE = event type 14).
 * Publik (tanpa auth); keamanan lewat verifikasi tanda tangan HMAC app_secret.
 *
 * Ack cepat: begitu tanda tangan & pesan tersimpan, kirim 200 lalu tutup koneksi
 * FastCGI SEBELUM memanggil AI drafter (pola sama TelegramWebhookController) —
 * TikTok butuh 200 < 3 detik; pemrosesan AI/kirim jalan setelahnya via
 * fastcgi_finish_request, tak butuh queue worker. Di PHPUnit (CLI) drafter jalan
 * inline sebelum return → alur bisa dites end-to-end.
 */
class TikTokChatWebhookController extends Controller
{
    public function handle(Request $request, EcomChatService $chat): Response
    {
        if (! $this->verifySignature($request)) {
            abort(401);
        }

        $data = (array) $request->input('data', []);
        $sender = (array) ($data['sender'] ?? []);
        $role = strtolower((string) ($sender['role'] ?? 'buyer'));

        $message = $chat->syncIncoming('tiktok', [
            'conversation_id' => (string) ($data['conversation_id'] ?? ''),
            'message_id' => (string) ($data['message_id'] ?? ''),
            'text' => (string) ($data['content'] ?? ''),
            'buyer_name' => $sender['nickname'] ?? null,
            'buyer_id' => $sender['im_user_id'] ?? null,
            'sender' => $role === 'buyer' ? 'buyer' : 'seller',
            'sent_at' => isset($data['create_time']) ? (int) $data['create_time'] : null,
        ]);

        // Pesan diabaikan/dobel/non-buyer → tak ada yang perlu diproses.
        if ($message === null) {
            return response('', 200);
        }

        $conversationId = $message->conversation_id;

        if (function_exists('fastcgi_finish_request')) {
            response('', 200)->send();
            fastcgi_finish_request();
        }

        try {
            $conv = EcomChatConversation::find($conversationId);
            if ($conv) {
                $chat->processDraft($conv);
            }
        } catch (\Throwable $e) {
            Log::error('ecom-chat webhook process', ['e' => $e->getMessage()]);
        }

        return response('', 200);
    }

    /**
     * Verifikasi tanda tangan webhook TikTok Shop.
     * NB (verifikasi ke docs TikTok saat deploy): default = HMAC-SHA256 dari
     * (app_key + raw body) memakai app_secret, hex, di header Authorization.
     * Bila docs berbeda, ubah HANYA baris perhitungan $expected di sini.
     */
    private function verifySignature(Request $request): bool
    {
        $secret = (string) config('services.tiktok.app_secret');
        $appKey = (string) config('services.tiktok.app_key');
        if ($secret === '') {
            return false;
        }

        $provided = (string) $request->header('Authorization', '');
        $expected = hash_hmac('sha256', $appKey.$request->getContent(), $secret);

        return $provided !== '' && hash_equals($expected, $provided);
    }
}
```

- [ ] **Step 5: Register the route (public) + CSRF exclusion**

In `routes/web.php`, after the Telegram webhook line (`Route::post('/telegram/webhook', ...)`), add:

```php
// Webhook chat TikTok (Customer Service — NEW_MESSAGE). Publik: keamanan lewat
// verifikasi tanda tangan HMAC di controller, bukan auth/CSRF.
Route::post('/webhooks/tiktok/chat', [\App\Http\Controllers\TikTokChatWebhookController::class, 'handle'])->name('webhooks.tiktok.chat');
```

In `bootstrap/app.php`, extend the CSRF exception array (line ~27):

```php
        $middleware->validateCsrfTokens(except: [
            'telegram/webhook',
            'api/kol-agent/*',
            'webhooks/tiktok/chat',
        ]);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `/c/php83/php.exe artisan test --filter=TikTokChatWebhookTest`
Expected: PASS (4 tests).

- [ ] **Step 7: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Services/EcomChatService.php app/Http/Controllers/TikTokChatWebhookController.php bootstrap/app.php routes/web.php tests/Feature/TikTokChatWebhookTest.php
git commit -m "feat(ecom-chat): webhook TikTok chat (HMAC + ack cepat + proses AI setelah respon)"
```

---

### Task 8: Izin + route + controller Chat E-commerce (backend inbox/kirim/redraft/toggle)

**Files:**
- Modify: `app/Support/Permissions.php`
- Create: `app/Http/Controllers/EcomChatController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/EcomChatControllerTest.php`

**Interfaces:**
- Consumes: `EcomChatService::send()`, `EcomChatService::processDraft()`, `AppSetting::put()`, `EcomChatConversation`.
- Produces:
  - Izin `manage_ecommerce_chat` (DEFINITIONS + DEFAULTS `[User::ROLE_ADMIN]`).
  - Routes (grup `permission:manage_ecommerce_chat`): `GET /ecom-chat` (`ecom-chat.index`), `GET /ecom-chat/{conversation}` (`ecom-chat.show`), `POST /ecom-chat/{conversation}/send` (`ecom-chat.send`), `POST /ecom-chat/{conversation}/redraft` (`ecom-chat.redraft`), `POST /ecom-chat/autosend` (`ecom-chat.autosend`).
  - `EcomChatController` methods: `index`, `show`, `send`, `redraft`, `toggleAutosend`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EcomChatControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\EcomChatConversation;
use App\Models\EcomChatMessage;
use App\Models\TiktokConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcomChatControllerTest extends TestCase
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

    private function conn(): void
    {
        TiktokConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(),
        ]);
        config()->set('services.tiktok.app_key', 'k');
        config()->set('services.tiktok.app_secret', 's');
        config()->set('services.tiktok.api_base', 'https://open-api.tiktokglobalshop.com');
    }

    public function test_non_staf_ditolak(): void
    {
        $conv = EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'C1', 'status' => 'open']);
        $this->actingAs($this->user(User::ROLE_RESELLER))->get('/ecom-chat')->assertForbidden();
        $this->actingAs($this->user(User::ROLE_RESELLER))->post("/ecom-chat/{$conv->id}/send", ['text' => 'x'])->assertForbidden();
    }

    public function test_admin_lihat_inbox_dan_detail(): void
    {
        EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'C1', 'buyer_name' => 'Budi', 'status' => 'needs_staff']);
        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/ecom-chat')->assertOk()->assertSee('Budi');
    }

    public function test_staf_kirim_balasan(): void
    {
        $this->conn();
        Http::fake(['*/customer_service/*' => Http::response(['code' => 0, 'data' => ['message_id' => 'OUT']])]);
        $conv = EcomChatConversation::create(['channel' => 'tiktok', 'external_conversation_id' => 'C1', 'status' => 'needs_staff']);

        $this->actingAs($this->user(User::ROLE_ADMIN))->post("/ecom-chat/{$conv->id}/send", ['text' => 'Terima kasih kak'])->assertRedirect();

        $this->assertSame(1, EcomChatMessage::where('sender', 'seller')->where('via', 'staff')->count());
        $this->assertSame('replied', $conv->fresh()->status);
    }

    public function test_toggle_autosend(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->post('/ecom-chat/autosend', ['on' => '1'])->assertRedirect();
        $this->assertSame('1', AppSetting::get(AppSetting::ECOM_CHAT_AUTOSEND));
        $this->actingAs($admin)->post('/ecom-chat/autosend', ['on' => '0'])->assertRedirect();
        $this->assertSame('0', AppSetting::get(AppSetting::ECOM_CHAT_AUTOSEND));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=EcomChatControllerTest`
Expected: FAIL — izin & route belum ada.

- [ ] **Step 3: Add the permission**

In `app/Support/Permissions.php`, add to `DEFINITIONS` (after `'manage_member_dormancy' => ...`):

```php
        'manage_ecommerce_chat' => 'Chat E-commerce (Balas Chat Marketplace)',
```

And to `DEFAULTS` (matching key):

```php
        'manage_ecommerce_chat' => [User::ROLE_ADMIN],
```

- [ ] **Step 4: Write the controller**

Create `app/Http/Controllers/EcomChatController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\EcomChatConversation;
use App\Services\EcomChatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EcomChatController extends Controller
{
    public function __construct(private EcomChatService $chat) {}

    public function index()
    {
        $conversations = EcomChatConversation::orderByDesc('last_message_at')->limit(100)->get();

        return view('ecom-chat.index', [
            'conversations' => $conversations,
            'autosend' => $this->chat->autosendEnabled(),
        ]);
    }

    public function show(EcomChatConversation $conversation)
    {
        $conversation->load(['messages' => fn ($q) => $q->orderBy('id')]);

        return view('ecom-chat.show', [
            'conversation' => $conversation,
            'autosend' => $this->chat->autosendEnabled(),
        ]);
    }

    public function send(Request $request, EcomChatConversation $conversation): RedirectResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:4000']]);
        $this->chat->send($conversation, $data['text'], \App\Models\EcomChatMessage::VIA_STAFF);

        return redirect()->route('ecom-chat.show', $conversation)->with('status', 'Balasan terkirim.');
    }

    public function redraft(EcomChatConversation $conversation): RedirectResponse
    {
        $this->chat->processDraft($conversation);

        return redirect()->route('ecom-chat.show', $conversation)->with('status', 'Draft dibuat ulang.');
    }

    public function toggleAutosend(Request $request): RedirectResponse
    {
        $on = $request->input('on') === '1' ? '1' : '0';
        AppSetting::put(AppSetting::ECOM_CHAT_AUTOSEND, $on);

        return redirect()->back()->with('status', $on === '1' ? 'Auto-send DINYALAKAN.' : 'Auto-send DIMATIKAN.');
    }
}
```

- [ ] **Step 5: Register routes**

In `routes/web.php`, inside the `Route::middleware(['auth', 'role'])->group(...)` block (near the AI assistant routes, e.g. after line 715), add:

```php
    // Chat E-commerce — balas chat pembeli marketplace (TikTok; Shopee nanti).
    Route::middleware('permission:manage_ecommerce_chat')->group(function () {
        Route::get('/ecom-chat', [\App\Http\Controllers\EcomChatController::class, 'index'])->name('ecom-chat.index');
        Route::post('/ecom-chat/autosend', [\App\Http\Controllers\EcomChatController::class, 'toggleAutosend'])->name('ecom-chat.autosend');
        Route::get('/ecom-chat/{conversation}', [\App\Http\Controllers\EcomChatController::class, 'show'])->name('ecom-chat.show');
        Route::post('/ecom-chat/{conversation}/send', [\App\Http\Controllers\EcomChatController::class, 'send'])->name('ecom-chat.send');
        Route::post('/ecom-chat/{conversation}/redraft', [\App\Http\Controllers\EcomChatController::class, 'redraft'])->name('ecom-chat.redraft');
    });
```

> Urutan penting: daftarkan `/ecom-chat/autosend` SEBELUM `/ecom-chat/{conversation}` agar tak tertangkap route model binding.

- [ ] **Step 6: Add minimal stub views so controller tests render**

Create `resources/views/ecom-chat/index.blade.php` (stub — diperkaya di Task 9):

```blade
@extends('layouts.app')
@section('title', 'Chat E-commerce')
@section('heading', 'Chat E-commerce')
@section('content')
<div>
    @foreach($conversations as $c)
        <a href="{{ route('ecom-chat.show', $c) }}">{{ $c->buyer_name ?? 'Pembeli' }}</a>
    @endforeach
</div>
@endsection
```

Create `resources/views/ecom-chat/show.blade.php` (stub):

```blade
@extends('layouts.app')
@section('title', 'Percakapan')
@section('heading', 'Percakapan')
@section('content')
<div>
    @foreach($conversation->messages as $m)
        <p>{{ $m->sender }}: {{ $m->text }}</p>
    @endforeach
</div>
@endsection
```

- [ ] **Step 7: Run test to verify it passes**

Run: `/c/php83/php.exe artisan test --filter=EcomChatControllerTest`
Expected: PASS (4 tests).

- [ ] **Step 8: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add app/Support/Permissions.php app/Http/Controllers/EcomChatController.php routes/web.php resources/views/ecom-chat/index.blade.php resources/views/ecom-chat/show.blade.php tests/Feature/EcomChatControllerTest.php
git commit -m "feat(ecom-chat): izin + route + controller inbox/kirim/redraft/toggle autosend"
```

---

### Task 9: UI inbox/detail + nav "Chat E-commerce"

**Files:**
- Modify: `resources/views/ecom-chat/index.blade.php`
- Modify: `resources/views/ecom-chat/show.blade.php`
- Modify: `resources/views/layouts/app.blade.php:292,300-308`
- Test: `tests/Feature/EcomChatUiRenderTest.php`

**Interfaces:**
- Consumes: `$conversations`, `$conversation`, `$autosend` dari `EcomChatController`; route names dari Task 8; izin `manage_ecommerce_chat` (`$u->canDo(...)`).
- Produces: halaman inbox (daftar + badge status + toggle auto-send) & detail (bubble + kotak draft editable + Kirim + Buat ulang draft); item nav di grup Integrasi.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EcomChatUiRenderTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EcomChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EcomChatUiRenderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'A', 'fullname' => 'A', 'username' => 'adminui', 'email' => 'adminui@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_inbox_menampilkan_badge_dan_toggle(): void
    {
        EcomChatConversation::create([
            'channel' => 'tiktok', 'external_conversation_id' => 'C1', 'buyer_name' => 'Budi',
            'status' => 'needs_staff', 'last_message_preview' => 'Produknya BPOM?',
        ]);

        $this->actingAs($this->admin())->get('/ecom-chat')->assertOk()
            ->assertSee('Budi')
            ->assertSee('Perlu staf')
            ->assertSee('Auto-send');
    }

    public function test_detail_menampilkan_draft_dan_tombol(): void
    {
        $conv = EcomChatConversation::create([
            'channel' => 'tiktok', 'external_conversation_id' => 'C1', 'buyer_name' => 'Budi', 'status' => 'needs_staff',
            'ai_draft' => 'Sudah BPOM kak', 'ai_decision' => 'to_staff', 'ai_reason' => 'perlu cek',
        ]);
        $conv->messages()->create([
            'channel' => 'tiktok', 'external_message_id' => 'M1', 'sender' => 'buyer', 'via' => 'buyer', 'text' => 'BPOM ga?',
        ]);

        $this->actingAs($this->admin())->get("/ecom-chat/{$conv->id}")->assertOk()
            ->assertSee('Sudah BPOM kak')
            ->assertSee('Kirim')
            ->assertSee('Buat ulang draft');
    }

    public function test_nav_ada_untuk_staf(): void
    {
        $this->actingAs($this->admin())->get('/ecom-chat')->assertOk()->assertSee('Chat E-commerce');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/php83/php.exe artisan test --filter=EcomChatUiRenderTest`
Expected: FAIL — stub views belum punya 'Perlu staf'/'Auto-send'/'Buat ulang draft'.

- [ ] **Step 3: Build the inbox view**

Replace `resources/views/ecom-chat/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Chat E-commerce')
@section('heading', 'Chat E-commerce')

@section('content')
@php
    $badge = [
        'needs_staff' => ['Perlu staf', 'bg-amber-100 text-amber-800'],
        'replied' => ['Terbalas', 'bg-emerald-100 text-emerald-800'],
        'open' => ['Baru', 'bg-sky-100 text-sky-800'],
        'closed' => ['Selesai', 'bg-stone-100 text-stone-600'],
    ];
@endphp
<div class="max-w-3xl">
    @if(session('status'))
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-xl px-4 py-2 mb-4">{{ session('status') }}</div>
    @endif

    <div class="flex items-center justify-between bg-white border border-stone-200 rounded-2xl p-4 mb-4">
        <div>
            <p class="text-sm font-bold text-stone-800">Auto-send balasan AI</p>
            <p class="text-[11px] text-stone-500">Saat MATI, AI tetap buat draft — kamu yang kirim. Nyalakan kalau sudah percaya kualitasnya.</p>
        </div>
        <form method="POST" action="{{ route('ecom-chat.autosend') }}">
            @csrf
            <input type="hidden" name="on" value="{{ $autosend ? '0' : '1' }}">
            <button class="px-4 py-2 text-sm font-semibold rounded-xl {{ $autosend ? 'bg-emerald-600 text-white' : 'bg-stone-200 text-stone-700' }}">
                {{ $autosend ? 'Auto-send: NYALA' : 'Auto-send: MATI' }}
            </button>
        </form>
    </div>

    <div class="bg-white border border-stone-200 rounded-2xl divide-y divide-stone-100">
        @forelse($conversations as $c)
            @php([$label, $cls] = $badge[$c->status] ?? ['—', 'bg-stone-100 text-stone-600'])
            <a href="{{ route('ecom-chat.show', $c) }}" class="flex items-center gap-3 p-4 hover:bg-stone-50">
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-stone-800 truncate">{{ $c->buyer_name ?? 'Pembeli TikTok' }}</p>
                    <p class="text-xs text-stone-500 truncate">{{ $c->last_message_preview }}</p>
                </div>
                <span class="text-[10px] font-bold px-2 py-1 rounded-full {{ $cls }}">{{ $label }}</span>
                <span class="text-[10px] text-stone-400 whitespace-nowrap">{{ optional($c->last_message_at)->diffForHumans() }}</span>
            </a>
        @empty
            <p class="p-6 text-sm text-stone-400 text-center">Belum ada percakapan masuk.</p>
        @endforelse
    </div>
</div>
@endsection
```

- [ ] **Step 4: Build the detail view**

Replace `resources/views/ecom-chat/show.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Percakapan')
@section('heading', $conversation->buyer_name ?? 'Percakapan')

@section('content')
<div class="max-w-2xl">
    @if(session('status'))
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-xl px-4 py-2 mb-4">{{ session('status') }}</div>
    @endif
    <a href="{{ route('ecom-chat.index') }}" class="text-xs text-stone-500 hover:text-stone-800">&larr; Kembali ke inbox</a>

    <div class="bg-white border border-stone-200 rounded-2xl p-4 my-4 space-y-2">
        @foreach($conversation->messages as $m)
            @php($mine = $m->sender !== 'buyer')
            <div class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
                <div class="max-w-[80%] px-3 py-2 rounded-2xl text-sm {{ $mine ? 'bg-red-600 text-white' : 'bg-stone-100 text-stone-800' }}">
                    {{ $m->text }}
                    @if($mine)
                        <span class="block text-[9px] opacity-70 mt-0.5">{{ $m->via === 'ai' ? 'AI otomatis' : 'Dikirim staf' }}</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    @if($conversation->ai_reason)
        <p class="text-[11px] text-stone-400 mb-2">Penilaian AI: <b>{{ $conversation->ai_decision }}</b> — {{ $conversation->ai_reason }}</p>
    @endif

    <form method="POST" action="{{ route('ecom-chat.send', $conversation) }}" class="bg-white border border-stone-200 rounded-2xl p-4">
        @csrf
        <label class="block text-xs font-semibold text-stone-600 mb-1">Draft balasan (boleh diedit sebelum kirim)</label>
        <textarea name="text" rows="4" maxlength="4000" class="block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">{{ old('text', $conversation->ai_draft) }}</textarea>
        <div class="flex items-center gap-2 mt-3">
            <button class="px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold">Kirim</button>
        </div>
    </form>

    <form method="POST" action="{{ route('ecom-chat.redraft', $conversation) }}" class="mt-2">
        @csrf
        <button class="text-xs text-stone-500 hover:text-stone-800 underline">Buat ulang draft</button>
    </form>
</div>
@endsection
```

- [ ] **Step 5: Add the nav item**

In `resources/views/layouts/app.blade.php`, update the Integrasi group condition (line ~292 and ~294) to include the chat permission, and add the nav item inside the group. Change:

```php
                $integrasiGroupOpen = request()->routeIs('tiktok.*') || request()->routeIs('shopee.*');
```
to:
```php
                $integrasiGroupOpen = request()->routeIs('tiktok.*') || request()->routeIs('shopee.*') || request()->routeIs('ecom-chat.*');
```

Change:
```blade
            @if($u->canDo('manage_tiktok') || $u->canDo('manage_shopee'))
```
to:
```blade
            @if($u->canDo('manage_tiktok') || $u->canDo('manage_shopee') || $u->canDo('manage_ecommerce_chat'))
```

Inside the `<div id="grpIntegrasi" ...>` block, after the Shopee `@endif` (line ~307), add:

```blade
                    @if($u->canDo('manage_ecommerce_chat'))
                        {!! navItem('ecom-chat.index', 'Chat E-commerce', 'ecom-chat.*') !!}
                    @endif
```

- [ ] **Step 6: Run test to verify it passes**

Run: `/c/php83/php.exe artisan test --filter=EcomChatUiRenderTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Run the full suite (no regressions)**

Run: `/c/php83/php.exe artisan test`
Expected: PASS — semua tes lama + baru hijau.

- [ ] **Step 8: Format & commit**

```bash
/c/php83/php.exe vendor/bin/pint --dirty
git add resources/views/ecom-chat/index.blade.php resources/views/ecom-chat/show.blade.php resources/views/layouts/app.blade.php tests/Feature/EcomChatUiRenderTest.php
git commit -m "feat(ecom-chat): UI inbox/detail (bubble, draft editable, toggle) + nav Integrasi"
```

---

## Self-Review

**1. Spec coverage:**
- Data model (`ecom_chat_conversations`/`messages` + model) → Task 1. ✓
- Webhook penerima (publik, HMAC, dedupe, ack 200 cepat, proses setelah respon, abaikan non-buyer) → Task 7. ✓
- Method IM TikTokClient (getConversations/getConversationMessages/sendMessage) + `EcomChatService` facade → Task 4 & 5. ✓
- AI Drafter (input riwayat + pengetahuan chat, system prompt guardrail, output JSON `{reply,decision,reason}`, fail-safe to_staff) → Task 6. ✓
- Menu "Chat E-commerce" (inbox/detail/send/redraft, gate izin, vanilla, badge AI-vs-staf) → Task 8 & 9. ✓
- Pengetahuan Chat (kolom `group` 000128, SECTIONS bergrup, tab switcher, drafter baca grup chat) → Task 2, 3, 6. ✓
- Kill switch AppSetting default MATI + izin `manage_ecommerce_chat` → Task 5, 8. ✓
- Error/batas: fail-safe (Task 6), dedupe/401 (Task 7), kill switch MATI = draft-only (Task 7 test), anti-loop seller (Task 5/7). ✓ — *Catatan: retry Send API & tanda "perlu re-auth" saat token gagal disebut spec sebagai penanganan; di v1 kegagalan send melempar exception yang ditangkap & dicatat (webhook) atau tampil sbg error validasi (aksi staf). Retry/backoff mengikuti pola sync order = perbaikan lanjutan, bukan pemblokir v1.*

**2. Placeholder scan:** Tak ada "TBD/TODO". Dua konstanta TikTok yang bergantung dokumentasi (path IM `/customer_service/202309`, formula tanda tangan HMAC) ditandai eksplisit sebagai "verifikasi ke docs saat build/deploy, ubah hanya di satu tempat" — bukan placeholder kode; tes tetap deterministik & hijau apa pun nilainya.

**3. Type consistency:**
- `EcomChatService::syncIncoming()` return `?EcomChatMessage`; webhook mengecek `null` → konsisten (Task 5 ↔ 7).
- `EcomChatDrafter::draft()` return `array{reply,decision,reason}`; `processDraft()` membaca ketiga key → konsisten (Task 6 ↔ 7).
- `AiKnowledge::document(string $group='sistem')` default menjaga pemanggil lama (`AiAgentService`, `OkrAiService`) — verifikasi via `AiKnowledgeTest` di Task 2 Step 5.
- Route model binding `{conversation}` → `EcomChatConversation` (param name cocok controller) — Task 8.
- `send()` `$via` menerima `EcomChatMessage::VIA_STAFF`/`VIA_AI`; controller pakai `VIA_STAFF`, processDraft pakai `VIA_AI` → konsisten.

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-09-14-ecommerce-chat-ai.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — execute tasks in this session using executing-plans, batch execution with checkpoints.

**Which approach?**
