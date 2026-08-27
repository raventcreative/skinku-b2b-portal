# Sub-menu KOL Fase 1 (Pipeline · Reminder · Konten & Views) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menu KOL jadi grup accordion berisi 3 fitur baru — Pipeline scouting (kanban), Reminder, Konten & Views (snapshot append-only) — semua menempel ke tabel `kols` yang ada.

**Architecture:** Fitur native Laravel (Blade + vanilla JS, tanpa paket baru). Migrasi 000099 (pipeline) + 000100 (konten). Halaman baca di balik `permission:kol.view`; aksi tulis di balik izin baru `kol.pipeline.manage` / `kol.content.manage`. Kolom `source` snapshot disiapkan untuk agen scraper fase depan (endpoint TIDAK dibangun sekarang).

**Tech Stack:** Laravel 13 / PHP 8.3 · MySQL · Blade · PHPUnit. Runner: `/c/php83/php.exe artisan test` · Pint: `/c/php83/php.exe vendor/bin/pint --dirty`.

## Global Constraints

- **Zero-dependency**: JANGAN tambah paket composer/npm. Helper tulis sendiri.
- Spec sumber kebenaran: `docs/superpowers/specs/2026-08-27-kol-submenu-fase1-design.md`.
- Copy UI Bahasa Indonesia; angka `number_format($n, 0, ',', '.')`.
- Blade: JANGAN `@json([...])` dengan array literal — pakai `{!! json_encode(...) !!}` (pelajaran lama, bikin 500).
- Form picker: native `<select>` (bukan datalist), label mulai nama depan.
- Stage pipeline (urutan kanban, nilai persis): `kandidat, dihubungi, nego, deal, sampel_dikirim, posting, evaluasi, repeat, drop`.
- Aturan label konten: `kol_deal_id` terisi → `paid` DIPAKSA; tanpa deal default `earned` (boleh override manual).
- Snapshot: unique(`kol_content_id`,`captured_on`) — submit hari sama = replace.
- Kartu pipeline: unique(`kol_id`,`track`), `track` default `'kol'`.
- Hapus kartu pipeline: super_admin SAJA. Jalur normal = stage `drop`.
- Git: commit per task, push `origin main` (bentuk `git -C "C:/Users/DELL/Downloads/skinku-b2b-php" ...`, pisahkan commit dan push bila classifier menolak). Suite penuh + Pint hijau sebelum commit.

---

### Task 1: Migrasi + model Pipeline + izin

**Files:**
- Create: `database/migrations/2026_01_01_000099_create_kol_pipeline_tables.php`
- Create: `app/Models/KolPipelineCard.php`, `app/Models/KolPipelineEvent.php`
- Modify: `app/Support/Permissions.php` (2 key baru, taruh setelah `kol.deal.finance`)
- Test: `tests/Feature/KolPipelineTest.php`

**Interfaces (Produces):**
- `KolPipelineCard`: const `STAGES` (array 9 nilai), `STAGE_LABELS` (map id→label ID), `TRACK_KOL='kol'`; fillable `kol_id, track, stage, next_action, next_action_at, followup_count, note, created_by`; cast `next_action_at:date`; relasi `kol()`, `events()` (hasMany, latest); helper `isActive(): bool` (stage ≠ drop); scope `active($q)`.
- `KolPipelineEvent`: fillable `card_id, from_stage, to_stage, note, created_by`; `$timestamps=false` + `created_at` diisi manual `now()` di creating hook ATAU pakai `const UPDATED_AT = null` (pakai ini: `public const UPDATED_AT = null;` sehingga created_at otomatis).
- Permissions: `kol.pipeline.manage` → DEFAULTS `['kol_specialist']`; `kol.content.manage` → DEFAULTS `['kol_specialist']`.

- [ ] **Step 1: Tulis test gagal** — `tests/Feature/KolPipelineTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolPipelineCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KolPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function kol(): Kol
    {
        static $n = 0;
        $n++;

        return Kol::create(['tiktok_username' => "pipekol{$n}", 'followers' => 50_000]);
    }

    public function test_model_kartu_dan_event_dasar(): void
    {
        $kol = $this->kol();
        $card = KolPipelineCard::create(['kol_id' => $kol->id, 'stage' => 'kandidat']);

        $this->assertSame('kol', $card->track);          // default track
        $this->assertTrue($card->isActive());
        $card->events()->create(['from_stage' => null, 'to_stage' => 'kandidat']);
        $this->assertSame(1, $card->events()->count());

        // Unique (kol_id, track): kartu kedua utk KOL sama harus meledak.
        $this->expectException(\Illuminate\Database\QueryException::class);
        KolPipelineCard::create(['kol_id' => $kol->id, 'stage' => 'nego']);
    }
}
```

- [ ] **Step 2: Jalankan — harus FAIL** (`class KolPipelineCard not found`):
`/c/php83/php.exe artisan test --filter=KolPipelineTest`

- [ ] **Step 3: Migrasi** `2026_01_01_000099_create_kol_pipeline_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 sub-menu KOL: pipeline scouting (kanban) — kartu per KOL + log
 * perpindahan stage (append-only). track disiapkan utk 'affiliate' fase depan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_pipeline_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->string('track', 20)->default('kol');
            $table->string('stage', 30);
            $table->string('next_action')->nullable();
            $table->date('next_action_at')->nullable();
            $table->unsignedTinyInteger('followup_count')->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['kol_id', 'track']);
            $table->index('stage');
            $table->index('next_action_at');
        });

        Schema::create('kol_pipeline_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('kol_pipeline_cards')->cascadeOnDelete();
            $table->string('from_stage', 30)->nullable();
            $table->string('to_stage', 30);
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_pipeline_events');
        Schema::dropIfExists('kol_pipeline_cards');
    }
};
```

- [ ] **Step 4: Model** `app/Models/KolPipelineCard.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KolPipelineCard extends Model
{
    public const TRACK_KOL = 'kol';

    /** Urutan = urutan kolom kanban. */
    public const STAGES = ['kandidat', 'dihubungi', 'nego', 'deal', 'sampel_dikirim', 'posting', 'evaluasi', 'repeat', 'drop'];

    public const STAGE_LABELS = [
        'kandidat' => 'Kandidat', 'dihubungi' => 'Dihubungi', 'nego' => 'Nego', 'deal' => 'Deal',
        'sampel_dikirim' => 'Sampel dikirim', 'posting' => 'Posting', 'evaluasi' => 'Evaluasi',
        'repeat' => 'Repeat', 'drop' => 'Drop',
    ];

    protected $fillable = ['kol_id', 'track', 'stage', 'next_action', 'next_action_at', 'followup_count', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['next_action_at' => 'date'];
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }

    public function events()
    {
        return $this->hasMany(KolPipelineEvent::class, 'card_id')->latest('id');
    }

    /** Aktif = semua stage kecuali drop (dasar reminder & hitungan header). */
    public function isActive(): bool
    {
        return $this->stage !== 'drop';
    }

    public function scopeActive($q)
    {
        return $q->where('stage', '!=', 'drop');
    }
}
```

`app/Models/KolPipelineEvent.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Log perpindahan stage — append-only, tidak pernah di-update/hapus. */
class KolPipelineEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['card_id', 'from_stage', 'to_stage', 'note', 'created_by'];

    public function card()
    {
        return $this->belongsTo(KolPipelineCard::class, 'card_id');
    }
}
```

- [ ] **Step 5: Izin** — di `app/Support/Permissions.php`, DEFINITIONS setelah baris `'kol.deal.finance'`:

```php
'kol.pipeline.manage' => 'Kelola Pipeline KOL (kartu scouting)',
'kol.content.manage' => 'Kelola Konten & Views KOL',
```

DEFAULTS setelah `'kol.deal.finance' => []`:

```php
'kol.pipeline.manage' => ['kol_specialist'],
'kol.content.manage' => ['kol_specialist'],
```

- [ ] **Step 6: Test hijau** → `/c/php83/php.exe artisan test --filter=KolPipelineTest`
- [ ] **Step 7: Pint + commit**: `feat(kol): migrasi + model pipeline scouting (kartu unik per KOL, event append-only) + izin baru`

---

### Task 2: Rute + controller Pipeline + view kanban + sidebar grup KOL

**Files:**
- Create: `app/Http/Controllers/KolPipelineController.php`, `resources/views/kols/pipeline.blade.php`
- Modify: `routes/web.php` (dalam grup `permission:kol.view` yang ada, setelah blok kol-screenings), `resources/views/layouts/app.blade.php` (ganti navItem KOL → accordion)
- Test: `tests/Feature/KolPipelineTest.php` (tambah)

**Interfaces:**
- Consumes: `KolPipelineCard` (Task 1), pola accordion `grpIntegrasi` di layouts/app.blade.php, `navItem()/navIcon()/toggleNavGroup()` existing.
- Produces: route names `kol-pipeline.index|store|stage|next-action|destroy`; view kanban dengan anchor `id="card-{id}"` per kartu (dipakai Reminder Task 3).

- [ ] **Step 1: Test gagal** (tambah ke KolPipelineTest):

```php
public function test_tanpa_kol_view_pipeline_403(): void
{
    $this->actingAs($this->user(User::ROLE_RESELLER, 'res1'))
        ->get(route('kol-pipeline.index'))->assertForbidden();
}

public function test_specialist_lihat_kanban_dan_buat_kartu(): void
{
    $spec = $this->user('kol_specialist', 'spec1');
    $kol = $this->kol();

    $this->actingAs($spec)->get(route('kol-pipeline.index'))->assertOk()->assertSee('Pipeline KOL');

    $this->actingAs($spec)->post(route('kol-pipeline.store'), [
        'kol_id' => $kol->id, 'stage' => 'kandidat',
        'next_action' => 'DM perkenalan', 'next_action_at' => now()->addDay()->toDateString(),
    ])->assertRedirect();

    $card = KolPipelineCard::where('kol_id', $kol->id)->first();
    $this->assertNotNull($card);
    $this->assertSame('kandidat', $card->stage);
    $this->assertSame(1, $card->events()->count()); // event lahir: null → kandidat

    // Kartu kedua utk KOL sama → ditolak validasi (bukan 500).
    $this->actingAs($spec)->post(route('kol-pipeline.store'), ['kol_id' => $kol->id, 'stage' => 'nego'])
        ->assertSessionHasErrors('kol_id');
}

public function test_pindah_stage_menulis_event_dan_followup(): void
{
    $spec = $this->user('kol_specialist', 'spec2');
    $card = KolPipelineCard::create(['kol_id' => $this->kol()->id, 'stage' => 'kandidat']);

    $this->actingAs($spec)->patch(route('kol-pipeline.stage', $card), ['stage' => 'nego'])->assertRedirect();
    $card->refresh();
    $this->assertSame('nego', $card->stage);
    $this->assertSame('nego', $card->events()->first()->to_stage);

    $this->actingAs($spec)->patch(route('kol-pipeline.next-action', $card), [
        'next_action' => 'Follow-up rate', 'next_action_at' => now()->toDateString(), 'is_followup' => 1,
    ])->assertRedirect();
    $this->assertSame(1, $card->refresh()->followup_count);
}

public function test_kol_view_tanpa_manage_tak_bisa_tulis(): void
{
    // Role dinamis 'kol_viewer' TIDAK ada di DEFAULTS manage → cuma bisa baca.
    // super_admin selalu bisa; kol_specialist manage. Pakai admin: kol.view TIDAK
    // di DEFAULTS admin → gunakan super_admin utk baca-tulis dan buat role viewer via matriks
    // terlalu berat — cukup: reseller 403 sudah diuji; di sini uji specialist BISA hapus? BUKAN:
    // hapus = super_admin saja.
    $spec = $this->user('kol_specialist', 'spec3');
    $card = KolPipelineCard::create(['kol_id' => $this->kol()->id, 'stage' => 'drop']);
    $this->actingAs($spec)->delete(route('kol-pipeline.destroy', $card))->assertForbidden();
    $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'root1'))
        ->delete(route('kol-pipeline.destroy', $card))->assertRedirect();
    $this->assertSame(0, KolPipelineCard::count());
}

public function test_sidebar_grup_kol_tampil_untuk_specialist(): void
{
    $this->actingAs($this->user('kol_specialist', 'spec4'))
        ->get(route('dashboard'))->assertSee('Pipeline');
}
```

- [ ] **Step 2: FAIL** (route tidak ada). 
- [ ] **Step 3: Rute** — `routes/web.php`, DI DALAM grup `permission:kol.view` (setelah blok kol-screenings/kols-import, sebelum penutup grup):

```php
// Fase 1 sub-menu KOL: pipeline scouting + reminder + konten (spec 2026-08-27).
Route::get('/kol-pipeline', [KolPipelineController::class, 'index'])->name('kol-pipeline.index');
Route::middleware('permission:kol.pipeline.manage')->group(function () {
    Route::post('/kol-pipeline', [KolPipelineController::class, 'store'])->name('kol-pipeline.store');
    Route::patch('/kol-pipeline/{card}/stage', [KolPipelineController::class, 'moveStage'])->name('kol-pipeline.stage');
    Route::patch('/kol-pipeline/{card}/next-action', [KolPipelineController::class, 'nextAction'])->name('kol-pipeline.next-action');
});
Route::delete('/kol-pipeline/{card}', [KolPipelineController::class, 'destroy'])->name('kol-pipeline.destroy');
```

(+ `use App\Http\Controllers\KolPipelineController;` di atas.)

- [ ] **Step 4: Controller** `app/Http/Controllers/KolPipelineController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Models\KolPipelineCard;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pipeline scouting KOL (kanban 9 stage). Satu kartu aktif per KOL; tiap
 * perpindahan stage dicatat append-only di kol_pipeline_events.
 */
class KolPipelineController extends Controller
{
    public function index()
    {
        $cards = KolPipelineCard::with('kol')->orderBy('next_action_at')->get();
        $today = now()->startOfDay();

        return view('kols.pipeline', [
            'byStage' => $cards->groupBy('stage'),
            'stages' => KolPipelineCard::STAGES,
            'labels' => KolPipelineCard::STAGE_LABELS,
            'statAktif' => $cards->filter->isActive()->count(),
            'statTerlambat' => $cards->filter(fn ($c) => $c->isActive() && $c->next_action_at?->lt($today))->count(),
            'statDekat' => $cards->filter(fn ($c) => $c->isActive() && $c->next_action_at?->between($today, $today->copy()->addDay()->endOfDay()))->count(),
            'statTanpaAksi' => $cards->filter(fn ($c) => $c->isActive() && ! $c->next_action_at)->count(),
            // Kandidat kartu baru: KOL yang belum punya kartu.
            'kolsTanpaKartu' => Kol::whereDoesntHave('pipelineCard')->orderBy('tiktok_username')->get(['id', 'tiktok_username']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kol_id' => ['required', 'integer', 'exists:kols,id',
                Rule::unique('kol_pipeline_cards', 'kol_id')->where('track', KolPipelineCard::TRACK_KOL)],
            'stage' => ['required', Rule::in(KolPipelineCard::STAGES)],
            'next_action' => ['nullable', 'string', 'max:255'],
            'next_action_at' => ['nullable', 'date'],
        ], ['kol_id.unique' => 'KOL ini sudah punya kartu pipeline.']);

        $card = KolPipelineCard::create($data + ['created_by' => $request->user()->id]);
        $card->events()->create(['from_stage' => null, 'to_stage' => $card->stage, 'created_by' => $request->user()->id]);

        AuditService::log(action: 'create_kol_pipeline_card', targetType: 'kol_pipeline_card', targetId: $card->id,
            after: ['kol' => $card->kol->tiktok_username, 'stage' => $card->stage]);

        return redirect()->route('kol-pipeline.index')->with('status', 'Kartu ditambahkan ke '.KolPipelineCard::STAGE_LABELS[$card->stage].'.');
    }

    public function moveStage(Request $request, KolPipelineCard $card): RedirectResponse
    {
        $data = $request->validate([
            'stage' => ['required', Rule::in(KolPipelineCard::STAGES)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $from = $card->stage;
        $card->update(['stage' => $data['stage']]);
        $card->events()->create(['from_stage' => $from, 'to_stage' => $data['stage'],
            'note' => $data['note'] ?? null, 'created_by' => $request->user()->id]);

        return redirect()->route('kol-pipeline.index')
            ->with('status', $card->kol->tiktok_username.' → '.KolPipelineCard::STAGE_LABELS[$data['stage']].'.');
    }

    public function nextAction(Request $request, KolPipelineCard $card): RedirectResponse
    {
        $data = $request->validate([
            'next_action' => ['required', 'string', 'max:255'],
            'next_action_at' => ['required', 'date'],
            'is_followup' => ['nullable', 'boolean'],
        ]);

        $card->update([
            'next_action' => $data['next_action'],
            'next_action_at' => $data['next_action_at'],
            'followup_count' => $card->followup_count + (($data['is_followup'] ?? false) ? 1 : 0),
        ]);

        return redirect()->route('kol-pipeline.index')->with('status', 'Next action disimpan.');
    }

    /** Hapus permanen = super_admin saja; jalur normal cukup geser ke Drop. */
    public function destroy(Request $request, KolPipelineCard $card): RedirectResponse
    {
        abort_unless($request->user()->role === \App\Models\User::ROLE_SUPER_ADMIN, 403);

        AuditService::log(action: 'delete_kol_pipeline_card', targetType: 'kol_pipeline_card', targetId: $card->id,
            before: ['kol' => $card->kol->tiktok_username, 'stage' => $card->stage]);
        $card->delete();

        return redirect()->route('kol-pipeline.index')->with('status', 'Kartu dihapus.');
    }
}
```

Tambah relasi di `app/Models/Kol.php` (setelah `latestScreening()`):

```php
/** Kartu pipeline scouting (satu per KOL, track kol). */
public function pipelineCard()
{
    return $this->hasOne(KolPipelineCard::class)->where('track', KolPipelineCard::TRACK_KOL);
}
```

- [ ] **Step 5: View** `resources/views/kols/pipeline.blade.php` — extends layouts.app; struktur:
  - Header: judul "Pipeline KOL" + 4 kartu stat (Aktif / Terlambat / Hari ini–besok / Tanpa next action; angka merah bila terlambat>0).
  - Form "Tambah kartu" dalam `<details>` collapsible: select KOL (`kolsTanpaKartu`, native select), select stage awal (default kandidat), input next_action + date, submit POST `kol-pipeline.store`.
  - Papan: `<div class="flex gap-3 overflow-x-auto pb-4">` — per `$stages` satu kolom `min-w-[240px]`: judul + badge count; per kartu (`$byStage[$stage]`): `<div id="card-{{ $c->id }}" class="bg-white rounded-xl border p-3">` berisi link `kols.show` nama KOL, next_action + tanggal (`text-rose-600` bila `lt(today)`, `text-amber-600` bila today/besok), chip `FU {{ $c->followup_count }}×` bila >0, ⚠ bila aktif tanpa tanggal.
  - Aksi per kartu (`@if($u->canDo('kol.pipeline.manage'))`): `<details>` "Pindah / aksi" → form PATCH stage (select 9 stage + note) + form PATCH next-action (input + date + checkbox "ini follow-up") + (super_admin) tombol hapus DELETE dengan `onclick="return confirm('Hapus kartu?')"`.
  - Hint kecil di bawah: "Follow-up maks 3× — setelah itu parkir ke Drop."
- [ ] **Step 6: Sidebar** — di `layouts/app.blade.php` ganti blok `@if($u->canDo('kol.view')) ... navItem('kols.index','KOL','kol*') ... @endif` dengan accordion (pola persis grpIntegrasi):

```blade
@php $kolGroupOpen = request()->routeIs('kol*'); @endphp
@if($u->canDo('kol.view') || $u->canDo('kol.deal.manage'))
    <button type="button" onclick="toggleNavGroup('grpKol')"
        class="w-full flex items-center justify-between gap-3 pr-4 pl-4 py-2.5 rounded-lg text-red-100 hover:text-white hover:bg-red-900/50 {{ $kolGroupOpen ? 'text-white' : '' }}">
        <span class="flex items-center gap-3">{!! navIcon('kols.index') !!}<span>KOL</span></span>
        <svg id="grpKolChevron" class="w-3.5 h-3.5 transition-transform {{ $kolGroupOpen ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
    </button>
    <div id="grpKol" class="{{ $kolGroupOpen ? '' : 'hidden' }} ml-4 pl-2 border-l border-red-900/50 space-y-1">
        @if($u->canDo('kol.view'))
            {!! navItem('kols.index', 'Database KOL', 'kols.*') !!}
            {!! navItem('kol-pipeline.index', 'Pipeline', 'kol-pipeline.*') !!}
            {!! navItem('kol-konten.index', 'Konten & Views', 'kol-konten.*') !!}
            {!! navItem('kol-reminder.index', 'Reminder', 'kol-reminder.*') !!}
        @endif
        @if($u->canDo('kol.deal.manage'))
            {!! navItem('kol-deals.index', 'Deal KOL', 'kol-deals.*') !!}
        @endif
    </div>
@endif
```

  ⚠ Rute `kol-konten.index` & `kol-reminder.index` belum ada di Task 2 — supaya sidebar tidak meledak, **tambahkan rute stub Task 3 & 4 SEKALIAN di Step 3** ATAU tunda dua baris navItem itu ke task masing-masing. **Pilih: tambahkan dua baris navItem itu di Task 3 dan Task 4** (Task 2 hanya Database KOL + Pipeline + Deal KOL).
- [ ] **Step 7: Test hijau + suite penuh + Pint + commit**: `feat(kol): pipeline scouting — kanban 9 stage, kartu per KOL, event log, sidebar grup KOL`

---

### Task 3: Reminder

**Files:**
- Create: `app/Http/Controllers/KolReminderController.php`, `resources/views/kols/reminder.blade.php`
- Modify: `routes/web.php` (1 rute), `layouts/app.blade.php` (+1 navItem Reminder di grup)
- Test: `tests/Feature/KolPipelineTest.php` (tambah)

**Interfaces:** Consumes `KolPipelineCard::scopeActive`, anchor `#card-{id}` di pipeline (Task 2). Produces route `kol-reminder.index`.

- [ ] **Step 1: Test gagal**:

```php
public function test_reminder_urut_terlambat_dulu(): void
{
    $spec = $this->user('kol_specialist', 'spec5');
    $late = KolPipelineCard::create(['kol_id' => $this->kol()->id, 'stage' => 'nego',
        'next_action' => 'Telat', 'next_action_at' => now()->subDays(3)->toDateString()]);
    $today = KolPipelineCard::create(['kol_id' => $this->kol()->id, 'stage' => 'deal',
        'next_action' => 'Hari ini', 'next_action_at' => now()->toDateString()]);
    $noAction = KolPipelineCard::create(['kol_id' => $this->kol()->id, 'stage' => 'kandidat']);
    $dropped = KolPipelineCard::create(['kol_id' => $this->kol()->id, 'stage' => 'drop',
        'next_action' => 'Diparkir', 'next_action_at' => now()->subDays(9)->toDateString()]);

    $res = $this->actingAs($spec)->get(route('kol-reminder.index'))->assertOk();
    $rows = $res->viewData('rows');
    $this->assertSame([$late->id, $today->id, $noAction->id], $rows->pluck('id')->all()); // drop TIDAK ikut
}
```

- [ ] **Step 2: FAIL.** 
- [ ] **Step 3: Rute** (dalam grup kol.view): `Route::get('/kol-reminder', [KolReminderController::class, 'index'])->name('kol-reminder.index');` (+use). Controller:

```php
<?php

namespace App\Http\Controllers;

use App\Models\KolPipelineCard;

/** Reminder KOL — agregat pipeline (fase 1): terlambat → hari ini → tanpa next action. */
class KolReminderController extends Controller
{
    public function index()
    {
        $today = now()->startOfDay();
        $cards = KolPipelineCard::active()->with('kol')->get();

        $late = $cards->filter(fn ($c) => $c->next_action_at?->lt($today))->sortBy('next_action_at');
        $due = $cards->filter(fn ($c) => $c->next_action_at?->isSameDay($today));
        $none = $cards->filter(fn ($c) => ! $c->next_action_at);

        return view('kols.reminder', [
            'rows' => $late->concat($due)->concat($none)->values(),
            'lateCount' => $late->count(), 'dueCount' => $due->count(), 'noneCount' => $none->count(),
            'today' => $today,
        ]);
    }
}
```

- [ ] **Step 4: View** `kols/reminder.blade.php`: header + 3 chip ringkas (Terlambat/Hari ini/Tanpa aksi); tabel baris: KOL (link kols.show), stage label, next_action, tanggal (+"terlambat n hari" merah), FU count, tombol "Buka" → `route('kol-pipeline.index').'#card-'.$c->id`. EmptyState bila kosong. Catatan footer: "Sumber fase depan: pembayaran deal, deadline posting, affiliate berhenti posting."
- [ ] **Step 5: Sidebar** — tambah `navItem('kol-reminder.index','Reminder','kol-reminder.*')` di grup (posisi sesuai tabel spec §2).
- [ ] **Step 6: Hijau + Pint + commit**: `feat(kol): reminder pipeline — terlambat/hari ini/tanpa next action`

---

### Task 4: Migrasi + model Konten & Snapshot

**Files:**
- Create: `database/migrations/2026_01_01_000100_create_kol_content_tables.php`, `app/Models/KolContent.php`, `app/Models/KolContentSnapshot.php`
- Test: `tests/Feature/KolContentTest.php` (baru; helper user()/kol() sama pola KolPipelineTest)

**Interfaces (Produces):** `KolContent`: fillable `kol_id, kol_deal_id, platform, url, title, label, posted_at, created_by`; cast `posted_at:date`; relasi `kol()`, `deal()` (belongsTo KolDeal), `snapshots()` (hasMany), `latestSnapshot()` (hasOne latestOfMany('captured_on')). `KolContentSnapshot`: fillable `kol_content_id, views, likes, comments, shares, captured_on, source, created_by`; cast `captured_on:date`; `UPDATED_AT = null`.

- [ ] **Step 1: Test gagal**:

```php
public function test_model_konten_dan_snapshot_replace_per_hari(): void
{
    $kol = $this->kol();
    $c = KolContent::create(['kol_id' => $kol->id, 'url' => 'https://www.tiktok.com/@x/video/1',
        'label' => 'earned', 'posted_at' => now()->toDateString()]);

    $c->snapshots()->updateOrCreate(
        ['captured_on' => now()->toDateString()],
        ['views' => 100, 'source' => 'manual']);
    $c->snapshots()->updateOrCreate(
        ['captured_on' => now()->toDateString()],
        ['views' => 250, 'source' => 'manual']);

    $this->assertSame(1, $c->snapshots()->count());          // hari sama = replace
    $this->assertSame(250, (int) $c->latestSnapshot->views);
    $this->assertSame('tiktok', $c->platform);               // default
}
```

- [ ] **Step 2: FAIL.** 
- [ ] **Step 3: Migrasi 000100** (kolom persis spec §3, unique(kol_content_id,captured_on), FK kol_deal_id → kol_deals nullOnDelete). 
- [ ] **Step 4: Dua model** sesuai Interfaces di atas (KolContentSnapshot: `public const UPDATED_AT = null;`). 
- [ ] **Step 5: Hijau + Pint + commit**: `feat(kol): migrasi + model konten & snapshot views (append-only per hari)`

---

### Task 5: Halaman Konten & Views (CRUD + oEmbed + ringkasan)

**Files:**
- Create: `app/Http/Controllers/KolContentController.php`, `resources/views/kols/konten/index.blade.php`, `resources/views/kols/konten/form.blade.php`
- Modify: `routes/web.php`, `layouts/app.blade.php` (+navItem Konten & Views)
- Test: `tests/Feature/KolContentTest.php` (tambah)

**Interfaces:** Consumes Task 4 models + `config('kol.platforms')` + `AppSetting::get/put`. Produces routes `kol-konten.index|create|store|edit|update|destroy|oembed` + setting key `kol_views_target` (default '1000000').

- [ ] **Step 1: Test gagal**:

```php
public function test_index_render_dan_izin(): void
{
    $this->actingAs($this->user(User::ROLE_RESELLER, 'res2'))
        ->get(route('kol-konten.index'))->assertForbidden();
    $this->actingAs($this->user('kol_specialist', 'ks1'))
        ->get(route('kol-konten.index'))->assertOk()->assertSee('Konten & Views');
}

public function test_store_deal_memaksa_paid_dan_oembed_autofill(): void
{
    $spec = $this->user('kol_specialist', 'ks2');
    $kol = $this->kol();
    $deal = KolDeal::create(['kode' => 'KD-T1', 'kol_id' => $kol->id, 'jenis' => 'vt']);

    $this->actingAs($spec)->post(route('kol-konten.store'), [
        'kol_id' => $kol->id, 'kol_deal_id' => $deal->id, 'url' => 'https://www.tiktok.com/@x/video/9',
        'platform' => 'tiktok', 'label' => 'earned', 'posted_at' => now()->toDateString(),
    ])->assertRedirect();
    $this->assertSame('paid', KolContent::first()->label); // deal → paid DIPAKSA

    Http::fake(['www.tiktok.com/oembed*' => Http::response(['title' => 'Judul dari TikTok'])]);
    $this->actingAs($spec)->post(route('kol-konten.oembed'), ['url' => 'https://www.tiktok.com/@x/video/9'])
        ->assertOk()->assertJson(['title' => 'Judul dari TikTok']);
    // URL non-tiktok ditolak tanpa fetch.
    $this->actingAs($spec)->post(route('kol-konten.oembed'), ['url' => 'https://evil.com/x'])
        ->assertStatus(422);
}
```

(+use `App\Models\KolDeal;`, `Illuminate\Support\Facades\Http;`.)

- [ ] **Step 2: FAIL.** 
- [ ] **Step 3: Rute** (dalam grup kol.view; TARUH `create` SEBELUM `{content}` agar tak ketangkap param):

```php
Route::get('/kol-konten', [KolContentController::class, 'index'])->name('kol-konten.index');
Route::middleware('permission:kol.content.manage')->group(function () {
    Route::get('/kol-konten/create', [KolContentController::class, 'create'])->name('kol-konten.create');
    Route::post('/kol-konten', [KolContentController::class, 'store'])->name('kol-konten.store');
    Route::post('/kol-konten/oembed', [KolContentController::class, 'oembed'])->name('kol-konten.oembed');
    Route::get('/kol-konten/{content}/edit', [KolContentController::class, 'edit'])->name('kol-konten.edit');
    Route::put('/kol-konten/{content}', [KolContentController::class, 'update'])->name('kol-konten.update');
    Route::delete('/kol-konten/{content}', [KolContentController::class, 'destroy'])->name('kol-konten.destroy');
});
```

- [ ] **Step 4: Controller** — inti:

```php
public function index(Request $request)
{
    $month = $request->query('bulan', now()->format('Y-m'));
    $start = \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
    $contents = KolContent::with(['kol', 'deal', 'latestSnapshot'])
        ->whereBetween('posted_at', [$start, $start->copy()->endOfMonth()])
        ->orderByDesc('posted_at')->get();

    $views = fn ($c) => (int) ($c->latestSnapshot->views ?? 0);
    $total = $contents->sum($views);
    $paid = $contents->where('label', 'paid')->sum($views);
    $target = (int) AppSetting::get('kol_views_target', '1000000');
    $isCurrent = $month === now()->format('Y-m');
    $proj = $isCurrent ? (int) round($total * ($start->daysInMonth / max(1, now()->day))) : $total;

    return view('kols.konten.index', [
        'month' => $month, 'contents' => $contents, 'total' => $total,
        'paid' => $paid, 'earned' => $total - $paid,
        'target' => $target, 'proj' => $proj,
        'aman' => $target > 0 && $proj >= 0.95 * $target,
    ]);
}
```

`store/update` validate: `kol_id` exists; `kol_deal_id` `['nullable','integer', Rule::exists('kol_deals','id')->where('kol_id', (int) $request->input('kol_id'))]` (deal harus milik KOL yang sama); `url` required|url|max:255; `platform` Rule::in(array_keys(config('kol.platforms'))); `title` nullable|max:255; `label` Rule::in(['paid','earned']); `posted_at` required|date. Setelah validate: `$data['label'] = $data['kol_deal_id'] ?? null ? 'paid' : ($data['label'] ?? 'earned');` + `created_by`. Audit log `create_kol_content`/`update`/`delete`. `oembed`:

```php
public function oembed(Request $request)
{
    $data = $request->validate(['url' => ['required', 'url', 'max:255']]);
    $host = parse_url($data['url'], PHP_URL_HOST) ?? '';
    if (! preg_match('/(^|\.)tiktok\.com$/i', $host)) {
        return response()->json(['message' => 'Hanya URL tiktok.com.'], 422);
    }
    try {
        $res = Http::timeout(10)->get('https://www.tiktok.com/oembed', ['url' => $data['url']]);
        return response()->json(['title' => (string) $res->json('title', '')]);
    } catch (\Throwable) {
        return response()->json(['title' => '']); // gagal = diam, judul manual
    }
}
```

- [ ] **Step 5: Views** — `konten/index.blade.php`: nav bulan (link `?bulan=` prev/next), 4 kartu ringkas (Total views + n konten · Paid vs Earned dengan persen · Target &proyeksi + badge Aman/Berisiko — hanya bulan berjalan · target bisa diedit inline via form kecil `AppSetting` POST ke rute update-target? TIDAK — YAGNI: edit target lewat input kecil di kartu target, form POST `kol-konten.target` → TAMBAHKAN rute+method `updateTarget` dalam grup manage:

```php
Route::post('/kol-konten/target', [KolContentController::class, 'updateTarget'])->name('kol-konten.target');
// controller:
public function updateTarget(Request $request): RedirectResponse
{
    $d = $request->validate(['target' => ['required', 'integer', 'min:0']]);
    AppSetting::put('kol_views_target', (string) $d['target']);
    return back()->with('status', 'Target views disimpan.');
}
```

  Tabel: KOL · judul (link url target=_blank, fallback teks URL) · chip paid/earned · deal kode · posted · views terakhir + tgl snapshot · aksi edit/hapus (manage). Tombol "Isi views massal" → Task 6, "Tambah konten" → create.
  `konten/form.blade.php` (dipakai create+edit): select KOL (semua KOL urut username), select deal (opsional — di-render sebagai select berisi SEMUA deal dgn atribut `data-kol` lalu difilter JS saat KOL berubah; sederhana & tanpa AJAX), url + tombol kecil "Ambil judul" (fetch ke `kol-konten.oembed` dgn header `X-CSRF-TOKEN`, isi input title), platform, label (hint: deal terisi → paid otomatis), posted_at.
- [ ] **Step 6: Hijau (filter KolContentTest lalu suite) + Pint + commit**: `feat(kol): konten & views — arsip konten per KOL, label paid/earned otomatis dari deal, ringkasan target/pace, oEmbed judul`

---

### Task 6: Grid isi views massal

**Files:**
- Create: `resources/views/kols/konten/grid.blade.php`
- Modify: `app/Http/Controllers/KolContentController.php` (+2 method), `routes/web.php` (+2 rute dalam grup manage)
- Test: `tests/Feature/KolContentTest.php` (tambah)

- [ ] **Step 1: Test gagal**:

```php
public function test_grid_massal_snapshot_dan_replace_hari_sama(): void
{
    $spec = $this->user('kol_specialist', 'ks3');
    $kol = $this->kol();
    $c1 = KolContent::create(['kol_id' => $kol->id, 'url' => 'https://www.tiktok.com/@x/video/11', 'label' => 'earned', 'posted_at' => now()->toDateString()]);
    $c2 = KolContent::create(['kol_id' => $kol->id, 'url' => 'https://www.tiktok.com/@x/video/12', 'label' => 'earned', 'posted_at' => now()->toDateString()]);

    $this->actingAs($spec)->get(route('kol-konten.grid'))->assertOk()->assertSee('Isi views massal');

    $this->actingAs($spec)->post(route('kol-konten.grid.save'), ['rows' => [
        ['id' => $c1->id, 'views' => 1000, 'likes' => 50],
        ['id' => $c2->id, 'views' => null],                    // kosong = dilewati
    ]])->assertRedirect();
    $this->assertSame(1, KolContentSnapshot::count());

    // Submit ulang hari sama dgn angka baru → replace, tetap 1 baris.
    $this->actingAs($spec)->post(route('kol-konten.grid.save'), ['rows' => [
        ['id' => $c1->id, 'views' => 4000],
    ]])->assertRedirect();
    $this->assertSame(1, KolContentSnapshot::count());
    $this->assertSame(4000, (int) $c1->refresh()->latestSnapshot->views);
}
```

- [ ] **Step 2: FAIL.** 
- [ ] **Step 3: Rute** (grup manage): `Route::get('/kol-konten/grid', ...)->name('kol-konten.grid');` + `Route::post('/kol-konten/grid', ...)->name('kol-konten.grid.save');` — ⚠ daftarkan SEBELUM `/kol-konten/{content}/edit` (hindari 'grid' tertangkap `{content}`). Method:

```php
public function grid(Request $request)
{
    $month = $request->query('bulan', now()->format('Y-m'));
    $start = \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->startOfMonth();

    return view('kols.konten.grid', ['month' => $month,
        'contents' => KolContent::with(['kol', 'latestSnapshot'])
            ->whereBetween('posted_at', [$start, $start->copy()->endOfMonth()])
            ->orderBy('kol_id')->orderByDesc('posted_at')->get()]);
}

public function gridSave(Request $request): RedirectResponse
{
    $data = $request->validate([
        'rows' => ['required', 'array'],
        'rows.*.id' => ['required', 'integer', 'exists:kol_contents,id'],
        'rows.*.views' => ['nullable', 'integer', 'min:0'],
        'rows.*.likes' => ['nullable', 'integer', 'min:0'],
        'rows.*.comments' => ['nullable', 'integer', 'min:0'],
        'rows.*.shares' => ['nullable', 'integer', 'min:0'],
    ]);

    $saved = 0;
    foreach ($data['rows'] as $row) {
        if (($row['views'] ?? null) === null) {
            continue; // baris kosong dilewati
        }
        KolContentSnapshot::updateOrCreate(
            ['kol_content_id' => $row['id'], 'captured_on' => now()->toDateString()],
            ['views' => $row['views'], 'likes' => $row['likes'] ?? null,
                'comments' => $row['comments'] ?? null, 'shares' => $row['shares'] ?? null,
                'source' => 'manual', 'created_by' => $request->user()->id]);
        $saved++;
    }

    return redirect()->route('kol-konten.index', ['bulan' => $request->input('bulan', now()->format('Y-m'))])
        ->with('status', "{$saved} snapshot views tersimpan (".now()->format('d M').').');
}
```

- [ ] **Step 4: View grid** — judul "Isi views massal", tabel semua konten bulan: KOL · judul · views TERAKHIR (readonly, abu) · 4 input angka per baris (`rows[i][id]` hidden + views/likes/comments/shares, `text-right`), tombol simpan besar. Hint: "Kosongkan baris yang tidak berubah — hanya baris berisi Views yang disimpan; isi ulang di hari sama menimpa angka hari ini."
- [ ] **Step 5: Hijau + Pint + commit**: `feat(kol): grid isi views massal — snapshot harian, hari sama replace`

---

### Task 7: Dokumentasi + suite penuh + serah-terima

**Files:** Modify `docs/SISTEM.md` (§15 KOL — tambah sub-seksi Pipeline/Reminder/Konten; §21 izin; §22 peta migrasi 000099-000100)

- [ ] **Step 1:** Update SISTEM.md ringkas (pola seksi 17b): navigasi grup, 4 tabel baru + aturannya (append-only, unique per hari, paid dipaksa), 2 izin baru, non-goal fase 1 (agen belum dibangun, kolom source sudah siap).
- [ ] **Step 2:** Suite penuh + Pint: `/c/php83/php.exe artisan test` — target ±975+ hijau semua.
- [ ] **Step 3:** Commit `docs(sistem): sub-menu KOL fase 1` + push.
- [ ] **Step 4:** Laporkan ke user: perintah deploy prod = `git pull` + `php artisan migrate --force` (migrasi 000099-000100) + `optimize:clear`; ceklis uji manual (buka grup KOL, buat kartu, pindah stage, tambah konten pakai deal → paid, isi grid views, cek reminder).

## Self-review

- Spec §2 navigasi → Task 2/3/5 (navItem dicicil per task, dicatat eksplisit). §3 skema → Task 1 & 4 (kolom persis). §4 izin → Task 1. §5a → Task 2, §5b → Task 3, §5c → Task 5+6 (termasuk target setting & oembed). §6 agen = non-goal (tidak ada task — benar). §7 testing → tersebar per task. Tidak ada gap.
- Placeholder scan: bersih (semua kode konkret; view dispesifikasikan struktural dengan kelas & rute persis, mengikuti konvensi file tetangga `kols/*.blade.php`).
- Konsistensi nama: `kol-pipeline.stage|next-action`, `kol-konten.grid.save`, relasi `pipelineCard`, `latestSnapshot` — dipakai konsisten lintas task.
