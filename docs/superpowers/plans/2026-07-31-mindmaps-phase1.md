# Mindmaps Fase 1 (Inti) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kanvas mindmap/diagram kolaboratif internal (papan + node + garis), tersimpan di server, berbagi tim async, zero-dependency.

**Architecture:** Ikuti pola Kanban. 4 tabel (mindmaps, mindmap_members, mindmap_nodes, mindmap_edges). `MindmapController` melayani papan CRUD + anggota (RedirectResponse) dan elemen CRUD + state (JsonResponse untuk AJAX). Halaman kanvas = Blade + vanilla JS inline: node = `<div>`, garis = `<svg>`, pan/zoom = CSS transform. Tiap mutasi disimpan per-elemen via fetch; auto-refresh poll `updated_at`.

**Tech Stack:** Laravel 13 / PHP 8.3, Blade, vanilla JS (inline, tanpa build), Tailwind (CDN, sudah ada). SQLite in-memory untuk test, MySQL prod.

## Global Constraints

- **Zero-dependency:** JANGAN tambah paket composer/npm. JS inline; CDN hanya yang sudah dipakai (Tailwind/Chart.js). Deploy = `git pull`.
- **Test runner lokal:** `C:\php83\php.exe artisan test` (punya pdo_sqlite). Prod `/opt/alt/php83/usr/bin/php`.
- **Format:** `C:\php83\php.exe vendor/bin/pint --dirty` sebelum tiap commit.
- **Break-verify:** tiap task → Pint + `artisan test` (minimal filter task) hijau sebelum commit; full suite di task terakhir.
- **Gaya:** komentar/commit Bahasa Indonesia tajam. Commit lewat file pesan (`git commit -F file`), akhiri `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- **Blade pitfall:** echo array/objek pakai `{{ \Illuminate\Support\Js::from($var) }}`. Tambah render test (`get()->assertOk()`) tiap halaman Blade baru.
- **Akses:** semua route mindmap grup `permission:mindmap.view` + `internal` (mitra terblokir). Per-papan: `canView`/`canEdit`/`isOwner`.
- **Migrasi baru** → nomor `2026_01_01_000071_*`. Deploy butuh `migrate --force`.

---

### Task 1: Migrasi + model + helper akses

**Files:**
- Create: `database/migrations/2026_01_01_000071_create_mindmap_tables.php`
- Create: `app/Models/Mindmap.php`, `app/Models/MindmapMember.php`, `app/Models/MindmapNode.php`, `app/Models/MindmapEdge.php`
- Test: `tests/Feature/MindmapModelTest.php`

**Interfaces:**
- Produces:
  - `Mindmap` fillable `['title','created_by']`; relations `members()`, `nodes()`, `edges()`, `creator()`; methods `isOwner(User): bool`, `canView(User): bool`, `canEdit(User): bool`.
  - `MindmapMember` fillable `['mindmap_id','user_id','can_edit']`, cast `can_edit`=bool.
  - `MindmapNode` fillable `['mindmap_id','type','x','y','width','height','text','color','created_by']`, casts x/y/width/height numeric.
  - `MindmapEdge` fillable `['mindmap_id','from_node_id','to_node_id','label']`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Mindmap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MindmapModelTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => $u, 'username' => $u, 'email' => $u.'@skinku.test',
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_akses_owner_anggota_dan_cascade(): void
    {
        $owner = $this->user(User::ROLE_ADMIN, 'owner');
        $editor = $this->user(User::ROLE_ADMIN, 'editor');
        $viewer = $this->user(User::ROLE_ADMIN, 'viewer');
        $orang_lain = $this->user(User::ROLE_ADMIN, 'lain');

        $map = Mindmap::create(['title' => 'Papan', 'created_by' => $owner->id]);
        $map->members()->create(['user_id' => $editor->id, 'can_edit' => true]);
        $map->members()->create(['user_id' => $viewer->id, 'can_edit' => false]);

        // Owner: semua.
        $this->assertTrue($map->isOwner($owner));
        $this->assertTrue($map->canEdit($owner));
        // Editor: lihat + edit.
        $this->assertFalse($map->isOwner($editor));
        $this->assertTrue($map->canEdit($editor));
        // Viewer: lihat saja.
        $this->assertTrue($map->canView($viewer));
        $this->assertFalse($map->canEdit($viewer));
        // Orang lain: tidak bisa.
        $this->assertFalse($map->canView($orang_lain));

        // Hapus node → garisnya ikut (cascade FK).
        $a = $map->nodes()->create(['type' => 'sticky', 'x' => 0, 'y' => 0, 'created_by' => $owner->id]);
        $b = $map->nodes()->create(['type' => 'sticky', 'x' => 300, 'y' => 0, 'created_by' => $owner->id]);
        $map->edges()->create(['from_node_id' => $a->id, 'to_node_id' => $b->id]);
        $this->assertSame(1, $map->edges()->count());
        $a->delete();
        $this->assertSame(0, $map->edges()->count());

        // Hapus papan → node & anggota ikut.
        $map->delete();
        $this->assertSame(0, \App\Models\MindmapNode::count());
        $this->assertSame(0, \App\Models\MindmapMember::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:\php83\php.exe artisan test --filter=MindmapModelTest`
Expected: FAIL ("Class Mindmap not found" / no such table).

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_01_01_000071_create_mindmap_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mindmaps: kanvas serbaguna (mindmap/diagram/campaign). Papan + anggota +
 * node + garis. Tiap elemen baris sendiri supaya berbagi tim tak saling
 * menimpa. Hapus node -> garisnya ikut; hapus papan -> semua isinya ikut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mindmaps', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('mindmap_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mindmap_id')->constrained('mindmaps')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('can_edit')->default(true);
            $table->timestamps();
            $table->unique(['mindmap_id', 'user_id']);
        });

        Schema::create('mindmap_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mindmap_id')->constrained('mindmaps')->cascadeOnDelete();
            $table->string('type', 20)->default('sticky');
            $table->float('x')->default(0);
            $table->float('y')->default(0);
            $table->float('width')->default(200);
            $table->float('height')->default(120);
            $table->text('text')->nullable();
            $table->string('color', 20)->default('kuning');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('mindmap_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mindmap_id')->constrained('mindmaps')->cascadeOnDelete();
            $table->foreignId('from_node_id')->constrained('mindmap_nodes')->cascadeOnDelete();
            $table->foreignId('to_node_id')->constrained('mindmap_nodes')->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mindmap_edges');
        Schema::dropIfExists('mindmap_nodes');
        Schema::dropIfExists('mindmap_members');
        Schema::dropIfExists('mindmaps');
    }
};
```

- [ ] **Step 4: Write the models**

`app/Models/Mindmap.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mindmap extends Model
{
    /** Palet warna sticky (kunci -> dipakai di UI). */
    public const COLORS = ['kuning', 'hijau', 'biru', 'rose', 'stone', 'putih'];

    protected $fillable = ['title', 'created_by'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members()
    {
        return $this->hasMany(MindmapMember::class);
    }

    public function nodes()
    {
        return $this->hasMany(MindmapNode::class);
    }

    public function edges()
    {
        return $this->hasMany(MindmapEdge::class);
    }

    public function isOwner(User $user): bool
    {
        return $user->isSuperAdmin() || $this->created_by === $user->id;
    }

    public function canView(User $user): bool
    {
        return $this->isOwner($user)
            || $this->members()->where('user_id', $user->id)->exists();
    }

    public function canEdit(User $user): bool
    {
        return $this->isOwner($user)
            || $this->members()->where('user_id', $user->id)->where('can_edit', true)->exists();
    }
}
```

`app/Models/MindmapMember.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MindmapMember extends Model
{
    protected $fillable = ['mindmap_id', 'user_id', 'can_edit'];

    protected function casts(): array
    {
        return ['can_edit' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

`app/Models/MindmapNode.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MindmapNode extends Model
{
    protected $fillable = ['mindmap_id', 'type', 'x', 'y', 'width', 'height', 'text', 'color', 'created_by'];

    protected function casts(): array
    {
        return ['x' => 'float', 'y' => 'float', 'width' => 'float', 'height' => 'float'];
    }
}
```

`app/Models/MindmapEdge.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MindmapEdge extends Model
{
    protected $fillable = ['mindmap_id', 'from_node_id', 'to_node_id', 'label'];
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `C:\php83\php.exe artisan test --filter=MindmapModelTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add database/migrations/2026_01_01_000071_create_mindmap_tables.php app/Models/Mindmap.php app/Models/MindmapMember.php app/Models/MindmapNode.php app/Models/MindmapEdge.php tests/Feature/MindmapModelTest.php
git commit -F <pesan>   # "feat(mindmap): tabel + model + helper akses per-papan"
```

---

### Task 2: Izin + route + daftar papan + buat papan + menu

**Files:**
- Modify: `app/Support/Permissions.php` (tambah `mindmap.view`)
- Modify: `routes/web.php` (grup route mindmap)
- Create: `app/Http/Controllers/MindmapController.php` (index, store)
- Create: `resources/views/mindmaps/index.blade.php`
- Modify: `resources/views/layouts/app.blade.php` (menu sidebar)
- Test: `tests/Feature/MindmapAccessTest.php`

**Interfaces:**
- Consumes: `Mindmap` (Task 1).
- Produces: route `mindmaps.index` (GET /mindmaps), `mindmaps.store` (POST /mindmaps). `MindmapController::index(): View`, `store(Request): RedirectResponse`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Mindmap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MindmapAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => $u, 'username' => $u, 'email' => $u.'@skinku.test',
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_mitra_terblokir_staf_bisa_dan_buat_papan(): void
    {
        // Mitra terblokir (internal + izin).
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'dist'))
            ->get(route('mindmaps.index'))->assertForbidden();

        // Staf bisa buka daftar + buat papan.
        $admin = $this->user(User::ROLE_ADMIN, 'adm');
        $this->actingAs($admin)->get(route('mindmaps.index'))->assertOk();
        $this->actingAs($admin)->post(route('mindmaps.store'), ['title' => 'Rencana Q4'])->assertRedirect();

        $map = Mindmap::firstOrFail();
        $this->assertSame('Rencana Q4', $map->title);
        $this->assertSame($admin->id, $map->created_by);
    }

    public function test_daftar_hanya_papan_milik_atau_diikuti(): void
    {
        $a = $this->user(User::ROLE_ADMIN, 'a');
        $b = $this->user(User::ROLE_ADMIN, 'b');
        $milikA = Mindmap::create(['title' => 'Punya A', 'created_by' => $a->id]);
        $milikB = Mindmap::create(['title' => 'Punya B', 'created_by' => $b->id]);
        $milikB->members()->create(['user_id' => $a->id, 'can_edit' => false]); // A diundang ke B

        $this->actingAs($a)->get(route('mindmaps.index'))
            ->assertOk()->assertSee('Punya A')->assertSee('Punya B');

        $c = $this->user(User::ROLE_ADMIN, 'c');
        $this->actingAs($c)->get(route('mindmaps.index'))
            ->assertOk()->assertDontSee('Punya A')->assertDontSee('Punya B');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:\php83\php.exe artisan test --filter=MindmapAccessTest`
Expected: FAIL (route `mindmaps.index` not defined).

- [ ] **Step 3: Tambah izin `mindmap.view`**

Di `app/Support/Permissions.php`, dalam array `DEFINITIONS` (label) tambah baris (dekat `kanban.view`):

```php
        'mindmap.view' => 'Mindmaps (kanvas ide & diagram)',
```

Dalam array `DEFAULTS` (peran default) tambah (dekat `kanban.view`):

```php
        'mindmap.view' => [User::ROLE_ADMIN, User::ROLE_GUDANG],
```

- [ ] **Step 4: Tambah grup route**

Di `routes/web.php`, setelah grup Kanban (`Route::middleware(['permission:kanban.view', 'internal'])->group(...)`), tambah:

```php
    // Mindmaps — kanvas ide/diagram internal. 'internal' blokir mitra keras.
    Route::middleware(['permission:mindmap.view', 'internal'])->group(function () {
        Route::get('/mindmaps', [MindmapController::class, 'index'])->name('mindmaps.index');
        Route::post('/mindmaps', [MindmapController::class, 'store'])->name('mindmaps.store');
    });
```

Tambah import di atas file: `use App\Http\Controllers\MindmapController;`.

- [ ] **Step 5: Buat controller index + store**

`app/Http/Controllers/MindmapController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Mindmap;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MindmapController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        $maps = Mindmap::query()
            ->where('created_by', $user->id)
            ->orWhereHas('members', fn ($q) => $q->where('user_id', $user->id))
            ->with('creator:id,fullname,name')
            ->orderByDesc('updated_at')
            ->get();

        return view('mindmaps.index', ['maps' => $maps]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);
        $map = Mindmap::create(['title' => $data['title'], 'created_by' => $request->user()->id]);

        AuditService::log(action: 'create_mindmap', targetType: 'mindmap', targetId: $map->id,
            after: ['judul' => $map->title]);

        return redirect()->route('mindmaps.show', $map);
    }
}
```

> Catatan: `mindmaps.show` dibuat di Task 3. Sampai Task 3 selesai, tes Task 2 memakai `assertRedirect()` tanpa target spesifik (aman).

- [ ] **Step 6: Buat halaman daftar**

`resources/views/mindmaps/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Mindmaps')
@section('heading', 'Mindmaps')

@section('content')
<div class="max-w-5xl mx-auto">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <p class="text-sm text-stone-500">Kanvas ide, diagram, dan papan campaign — dibagikan ke tim.</p>
        <form method="POST" action="{{ route('mindmaps.store') }}" class="flex items-center gap-2">
            @csrf
            <input name="title" required maxlength="255" placeholder="Judul papan baru…"
                class="px-3 py-2 text-sm border border-stone-300 rounded-lg w-56">
            <button class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">+ Papan Baru</button>
        </form>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse($maps as $map)
            <a href="{{ route('mindmaps.show', $map) }}" class="block bg-white rounded-2xl border border-stone-200 p-5 hover:border-stone-400 hover:shadow-sm transition">
                <p class="text-sm font-bold text-stone-900 truncate">{{ $map->title }}</p>
                <p class="text-[11px] text-stone-400 mt-2">oleh {{ $map->creator?->fullname ?? $map->creator?->name ?? '—' }} · diperbarui {{ $map->updated_at->diffForHumans() }}</p>
            </a>
        @empty
            <p class="text-sm text-stone-400 col-span-full py-8 text-center">Belum ada papan. Buat papan baru untuk mulai.</p>
        @endforelse
    </div>
</div>
@endsection
```

- [ ] **Step 7: Menu sidebar**

Di `resources/views/layouts/app.blade.php`, dekat item Kanban, tambah (hanya staf yang punya izin):

```blade
            @if($u->canDo('mindmap.view'))
                {!! navItem('mindmaps.index', 'Mindmaps', 'mindmaps.*') !!}
            @endif
```

> Verifikasi signature `navItem(routeName, label, activePattern)` cocok dengan pemakaian Kanban di file yang sama; sesuaikan bila beda.

- [ ] **Step 8: Run test to verify it passes**

Run: `C:\php83\php.exe artisan test --filter=MindmapAccessTest`
Expected: PASS.

- [ ] **Step 9: Pint + commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Support/Permissions.php routes/web.php app/Http/Controllers/MindmapController.php resources/views/mindmaps/index.blade.php resources/views/layouts/app.blade.php tests/Feature/MindmapAccessTest.php
git commit -F <pesan>   # "feat(mindmap): izin + daftar papan + buat papan + menu"
```

---

### Task 3: Buka papan + rename + hapus + kelola anggota

**Files:**
- Modify: `app/Http/Controllers/MindmapController.php` (show, update, destroy, addMember, removeMember)
- Modify: `routes/web.php` (route tambahan)
- Create: `resources/views/mindmaps/show.blade.php` (placeholder minimal dulu; kanvas penuh di Task 6)
- Test: `tests/Feature/MindmapBoardTest.php`

**Interfaces:**
- Consumes: `Mindmap` helper akses (Task 1).
- Produces: route `mindmaps.show` (GET /mindmaps/{mindmap}), `mindmaps.update` (PATCH), `mindmaps.destroy` (DELETE), `mindmaps.members.store` (POST /mindmaps/{mindmap}/members), `mindmaps.members.destroy` (DELETE /mindmaps/{mindmap}/members/{user}). `show(Mindmap): View`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Mindmap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MindmapBoardTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => $u, 'username' => $u, 'email' => $u.'@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_akses_buka_dan_kelola_papan(): void
    {
        $owner = $this->user('own');
        $viewer = $this->user('vie');
        $orang_lain = $this->user('lain');
        $map = Mindmap::create(['title' => 'Papan', 'created_by' => $owner->id]);
        $map->members()->create(['user_id' => $viewer->id, 'can_edit' => false]);

        // Buka: owner & anggota bisa, orang lain 403.
        $this->actingAs($owner)->get(route('mindmaps.show', $map))->assertOk();
        $this->actingAs($viewer)->get(route('mindmaps.show', $map))->assertOk();
        $this->actingAs($orang_lain)->get(route('mindmaps.show', $map))->assertForbidden();

        // Rename: owner boleh, anggota tidak.
        $this->actingAs($viewer)->patch(route('mindmaps.update', $map), ['title' => 'X'])->assertForbidden();
        $this->actingAs($owner)->patch(route('mindmaps.update', $map), ['title' => 'Baru'])->assertRedirect();
        $this->assertSame('Baru', $map->fresh()->title);

        // Tambah anggota: owner boleh.
        $baru = $this->user('anggotabaru');
        $this->actingAs($owner)->post(route('mindmaps.members.store', $map), ['user_id' => $baru->id, 'can_edit' => true])->assertRedirect();
        $this->assertTrue($map->canEdit($baru->fresh()));

        // Hapus anggota: owner boleh.
        $this->actingAs($owner)->delete(route('mindmaps.members.destroy', [$map, $baru]))->assertRedirect();
        $this->assertFalse($map->fresh()->canView($baru));

        // Hapus papan: anggota tak boleh, owner boleh.
        $this->actingAs($viewer)->delete(route('mindmaps.destroy', $map))->assertForbidden();
        $this->actingAs($owner)->delete(route('mindmaps.destroy', $map))->assertRedirect();
        $this->assertNull(Mindmap::find($map->id));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:\php83\php.exe artisan test --filter=MindmapBoardTest`
Expected: FAIL (route `mindmaps.show` not defined).

- [ ] **Step 3: Tambah route**

Di grup mindmap `routes/web.php`, tambah setelah `mindmaps.store`:

```php
        Route::get('/mindmaps/{mindmap}', [MindmapController::class, 'show'])->name('mindmaps.show');
        Route::patch('/mindmaps/{mindmap}', [MindmapController::class, 'update'])->name('mindmaps.update');
        Route::delete('/mindmaps/{mindmap}', [MindmapController::class, 'destroy'])->name('mindmaps.destroy');
        Route::post('/mindmaps/{mindmap}/members', [MindmapController::class, 'addMember'])->name('mindmaps.members.store');
        Route::delete('/mindmaps/{mindmap}/members/{user}', [MindmapController::class, 'removeMember'])->name('mindmaps.members.destroy');
```

- [ ] **Step 4: Tambah method controller**

Di `MindmapController` tambah (import `use App\Models\User;`, `use App\Models\MindmapMember;`):

```php
    public function show(Mindmap $mindmap): View
    {
        abort_unless($mindmap->canView(auth()->user()), 403, 'Tidak punya akses ke papan ini.');
        $mindmap->load(['members.user:id,fullname,name', 'creator:id,fullname,name']);
        $members = $this->staffOptions();

        return view('mindmaps.show', [
            'map' => $mindmap,
            'canEdit' => $mindmap->canEdit(auth()->user()),
            'isOwner' => $mindmap->isOwner(auth()->user()),
            'staffOptions' => $members,
        ]);
    }

    public function update(Request $request, Mindmap $mindmap): RedirectResponse
    {
        abort_unless($mindmap->isOwner($request->user()), 403, 'Hanya pemilik papan yang bisa mengubah.');
        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);
        $mindmap->update(['title' => $data['title']]);

        return back()->with('status', 'Judul papan diperbarui.');
    }

    public function destroy(Request $request, Mindmap $mindmap): RedirectResponse
    {
        abort_unless($mindmap->isOwner($request->user()), 403, 'Hanya pemilik papan yang bisa menghapus.');
        $mindmap->delete();
        AuditService::log(action: 'delete_mindmap', targetType: 'mindmap', targetId: $mindmap->id);

        return redirect()->route('mindmaps.index')->with('status', 'Papan dihapus.');
    }

    public function addMember(Request $request, Mindmap $mindmap): RedirectResponse
    {
        abort_unless($mindmap->isOwner($request->user()), 403, 'Hanya pemilik papan yang bisa mengatur anggota.');
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'can_edit' => ['sometimes', 'boolean'],
        ]);
        if ($data['user_id'] === $mindmap->created_by) {
            return back(); // owner sudah otomatis akses penuh
        }
        MindmapMember::updateOrCreate(
            ['mindmap_id' => $mindmap->id, 'user_id' => $data['user_id']],
            ['can_edit' => (bool) ($data['can_edit'] ?? true)],
        );

        return back()->with('status', 'Anggota papan diperbarui.');
    }

    public function removeMember(Request $request, Mindmap $mindmap, User $user): RedirectResponse
    {
        abort_unless($mindmap->isOwner($request->user()), 403, 'Hanya pemilik papan yang bisa mengatur anggota.');
        $mindmap->members()->where('user_id', $user->id)->delete();

        return back()->with('status', 'Anggota dikeluarkan.');
    }

    /** Staf internal aktif (kandidat anggota papan). */
    private function staffOptions()
    {
        return User::query()
            ->whereIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_GUDANG])
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('fullname')
            ->get(['id', 'fullname', 'name']);
    }
```

- [ ] **Step 5: Buat halaman show minimal (placeholder — kanvas penuh di Task 6)**

`resources/views/mindmaps/show.blade.php`:

```blade
@extends('layouts.app')
@section('title', $map->title)
@section('heading', 'Mindmap — '.$map->title)

@section('content')
<div class="max-w-5xl mx-auto">
    <a href="{{ route('mindmaps.index') }}" class="text-xs text-stone-500 hover:text-red-600">← Semua papan</a>
    <h3 class="text-xl font-bold text-stone-900 mt-2">{{ $map->title }}</h3>
    <p class="text-xs text-stone-500 mt-1">{{ $canEdit ? 'Bisa edit' : 'Lihat saja' }}. Kanvas menyusul.</p>
</div>
@endsection
```

- [ ] **Step 6: Run test to verify it passes**

Run: `C:\php83\php.exe artisan test --filter=MindmapBoardTest`
Expected: PASS.

- [ ] **Step 7: Pint + commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/MindmapController.php routes/web.php resources/views/mindmaps/show.blade.php tests/Feature/MindmapBoardTest.php
git commit -F <pesan>   # "feat(mindmap): buka papan + rename/hapus + kelola anggota"
```

---

### Task 4: Endpoint state + node CRUD (JSON)

**Files:**
- Modify: `app/Http/Controllers/MindmapController.php` (state, storeNode, updateNode, destroyNode)
- Modify: `routes/web.php`
- Test: `tests/Feature/MindmapNodeTest.php`

**Interfaces:**
- Consumes: `Mindmap::canView/canEdit`, `MindmapNode`.
- Produces: route `mindmaps.state` (GET /mindmaps/{mindmap}/state → JSON), `mindmaps.nodes.store` (POST /mindmaps/{mindmap}/nodes), `mindmaps.nodes.update` (PATCH /mindmaps/{mindmap}/nodes/{node}), `mindmaps.nodes.destroy` (DELETE). State JSON shape: `{ nodes: [{id,type,x,y,width,height,text,color}], edges: [{id,from_node_id,to_node_id,label}], updated_at: <iso> }`. storeNode returns `{ ok: true, node: {...} }`; update/destroy return `{ ok: true }`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Mindmap;
use App\Models\MindmapNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MindmapNodeTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => $u, 'username' => $u, 'email' => $u.'@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_node_crud_dan_gerbang_edit(): void
    {
        $owner = $this->user('own');
        $viewer = $this->user('vie');
        $map = Mindmap::create(['title' => 'P', 'created_by' => $owner->id]);
        $map->members()->create(['user_id' => $viewer->id, 'can_edit' => false]);

        // Buat node (owner).
        $res = $this->actingAs($owner)->postJson(route('mindmaps.nodes.store', $map), [
            'type' => 'sticky', 'x' => 40, 'y' => 60, 'text' => 'Ide',
        ])->assertOk()->assertJson(['ok' => true]);
        $nodeId = $res->json('node.id');
        $this->assertNotNull($nodeId);

        // Viewer tak boleh buat/edit.
        $this->actingAs($viewer)->postJson(route('mindmaps.nodes.store', $map), ['type' => 'sticky', 'x' => 0, 'y' => 0])
            ->assertForbidden();

        // Update posisi.
        $this->actingAs($owner)->patchJson(route('mindmaps.nodes.update', [$map, $nodeId]), ['x' => 200, 'y' => 120])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(200.0, (float) MindmapNode::find($nodeId)->x);

        // State: viewer boleh baca.
        $this->actingAs($viewer)->getJson(route('mindmaps.state', $map))
            ->assertOk()->assertJsonStructure(['nodes', 'edges', 'updated_at'])
            ->assertJsonFragment(['id' => $nodeId]);

        // Hapus node.
        $this->actingAs($owner)->deleteJson(route('mindmaps.nodes.destroy', [$map, $nodeId]))
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertNull(MindmapNode::find($nodeId));

        // Non-anggota tak bisa baca state.
        $this->actingAs($this->user('lain'))->getJson(route('mindmaps.state', $map))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:\php83\php.exe artisan test --filter=MindmapNodeTest`
Expected: FAIL (route `mindmaps.state` not defined).

- [ ] **Step 3: Tambah route**

Di grup mindmap tambah:

```php
        Route::get('/mindmaps/{mindmap}/state', [MindmapController::class, 'state'])->name('mindmaps.state');
        Route::post('/mindmaps/{mindmap}/nodes', [MindmapController::class, 'storeNode'])->name('mindmaps.nodes.store');
        Route::patch('/mindmaps/{mindmap}/nodes/{node}', [MindmapController::class, 'updateNode'])->name('mindmaps.nodes.update');
        Route::delete('/mindmaps/{mindmap}/nodes/{node}', [MindmapController::class, 'destroyNode'])->name('mindmaps.nodes.destroy');
```

> Route-model-binding `{node}` mengikat `MindmapNode`. Di method, verifikasi node milik papan tsb (cegah lintas-papan).

- [ ] **Step 4: Tambah method controller**

Import `use App\Models\MindmapNode;`, `use Illuminate\Http\JsonResponse;`, `use Illuminate\Validation\Rule;`. Tambah:

```php
    public function state(Mindmap $mindmap): JsonResponse
    {
        abort_unless($mindmap->canView(auth()->user()), 403);

        return response()->json([
            'nodes' => $mindmap->nodes()->get(['id', 'type', 'x', 'y', 'width', 'height', 'text', 'color']),
            'edges' => $mindmap->edges()->get(['id', 'from_node_id', 'to_node_id', 'label']),
            'updated_at' => $mindmap->updated_at?->toIso8601String(),
        ]);
    }

    public function storeNode(Request $request, Mindmap $mindmap): JsonResponse
    {
        abort_unless($mindmap->canEdit($request->user()), 403);
        $data = $request->validate([
            'type' => ['required', Rule::in(['sticky', 'text'])],
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
            'width' => ['sometimes', 'numeric', 'min:60', 'max:2000'],
            'height' => ['sometimes', 'numeric', 'min:40', 'max:2000'],
            'text' => ['nullable', 'string', 'max:5000'],
            'color' => ['sometimes', Rule::in(Mindmap::COLORS)],
        ]);
        $node = $mindmap->nodes()->create($data + ['created_by' => $request->user()->id]);
        $mindmap->touch();

        return response()->json(['ok' => true, 'node' => $node->only(['id', 'type', 'x', 'y', 'width', 'height', 'text', 'color'])]);
    }

    public function updateNode(Request $request, Mindmap $mindmap, MindmapNode $node): JsonResponse
    {
        abort_unless($mindmap->canEdit($request->user()), 403);
        abort_unless($node->mindmap_id === $mindmap->id, 404);
        $data = $request->validate([
            'x' => ['sometimes', 'numeric'],
            'y' => ['sometimes', 'numeric'],
            'width' => ['sometimes', 'numeric', 'min:60', 'max:2000'],
            'height' => ['sometimes', 'numeric', 'min:40', 'max:2000'],
            'text' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'color' => ['sometimes', Rule::in(Mindmap::COLORS)],
        ]);
        $node->update($data);
        $mindmap->touch();

        return response()->json(['ok' => true]);
    }

    public function destroyNode(Request $request, Mindmap $mindmap, MindmapNode $node): JsonResponse
    {
        abort_unless($mindmap->canEdit($request->user()), 403);
        abort_unless($node->mindmap_id === $mindmap->id, 404);
        $node->delete(); // garis terkait ikut (cascade FK)
        $mindmap->touch();

        return response()->json(['ok' => true]);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `C:\php83\php.exe artisan test --filter=MindmapNodeTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/MindmapController.php routes/web.php tests/Feature/MindmapNodeTest.php
git commit -F <pesan>   # "feat(mindmap): endpoint state + node CRUD (JSON)"
```

---

### Task 5: Edge CRUD (JSON)

**Files:**
- Modify: `app/Http/Controllers/MindmapController.php` (storeEdge, updateEdge, destroyEdge)
- Modify: `routes/web.php`
- Test: `tests/Feature/MindmapEdgeTest.php`

**Interfaces:**
- Consumes: `Mindmap::canEdit`, `MindmapNode`, `MindmapEdge`.
- Produces: route `mindmaps.edges.store` (POST /mindmaps/{mindmap}/edges), `mindmaps.edges.update` (PATCH .../edges/{edge}), `mindmaps.edges.destroy` (DELETE). storeEdge returns `{ ok: true, edge: {id,from_node_id,to_node_id,label} }`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Mindmap;
use App\Models\MindmapEdge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MindmapEdgeTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::create([
            'name' => 'o', 'fullname' => 'o', 'username' => 'o', 'email' => 'o@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_edge_crud_dan_validasi_papan(): void
    {
        $owner = $this->owner();
        $map = Mindmap::create(['title' => 'P', 'created_by' => $owner->id]);
        $a = $map->nodes()->create(['type' => 'sticky', 'x' => 0, 'y' => 0, 'created_by' => $owner->id]);
        $b = $map->nodes()->create(['type' => 'sticky', 'x' => 300, 'y' => 0, 'created_by' => $owner->id]);

        // Node dari papan lain — tak boleh disambung.
        $lain = Mindmap::create(['title' => 'Q', 'created_by' => $owner->id]);
        $asing = $lain->nodes()->create(['type' => 'sticky', 'x' => 0, 'y' => 0, 'created_by' => $owner->id]);

        $res = $this->actingAs($owner)->postJson(route('mindmaps.edges.store', $map), [
            'from_node_id' => $a->id, 'to_node_id' => $b->id,
        ])->assertOk()->assertJson(['ok' => true]);
        $edgeId = $res->json('edge.id');

        // Sambung ke node papan lain → 422.
        $this->actingAs($owner)->postJson(route('mindmaps.edges.store', $map), [
            'from_node_id' => $a->id, 'to_node_id' => $asing->id,
        ])->assertStatus(422);

        // Beri label.
        $this->actingAs($owner)->patchJson(route('mindmaps.edges.update', [$map, $edgeId]), ['label' => 'lalu'])
            ->assertOk();
        $this->assertSame('lalu', MindmapEdge::find($edgeId)->label);

        // Hapus.
        $this->actingAs($owner)->deleteJson(route('mindmaps.edges.destroy', [$map, $edgeId]))->assertOk();
        $this->assertNull(MindmapEdge::find($edgeId));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:\php83\php.exe artisan test --filter=MindmapEdgeTest`
Expected: FAIL (route not defined).

- [ ] **Step 3: Tambah route**

```php
        Route::post('/mindmaps/{mindmap}/edges', [MindmapController::class, 'storeEdge'])->name('mindmaps.edges.store');
        Route::patch('/mindmaps/{mindmap}/edges/{edge}', [MindmapController::class, 'updateEdge'])->name('mindmaps.edges.update');
        Route::delete('/mindmaps/{mindmap}/edges/{edge}', [MindmapController::class, 'destroyEdge'])->name('mindmaps.edges.destroy');
```

- [ ] **Step 4: Tambah method controller**

Import `use App\Models\MindmapEdge;`. Tambah:

```php
    public function storeEdge(Request $request, Mindmap $mindmap): JsonResponse
    {
        abort_unless($mindmap->canEdit($request->user()), 403);
        $data = $request->validate([
            'from_node_id' => ['required', 'integer'],
            'to_node_id' => ['required', 'integer', 'different:from_node_id'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);
        // Kedua node WAJIB milik papan ini.
        $milik = $mindmap->nodes()->whereIn('id', [$data['from_node_id'], $data['to_node_id']])->count();
        if ($milik !== 2) {
            return response()->json(['ok' => false, 'error' => 'Node bukan bagian papan ini.'], 422);
        }
        $edge = $mindmap->edges()->create($data);
        $mindmap->touch();

        return response()->json(['ok' => true, 'edge' => $edge->only(['id', 'from_node_id', 'to_node_id', 'label'])]);
    }

    public function updateEdge(Request $request, Mindmap $mindmap, MindmapEdge $edge): JsonResponse
    {
        abort_unless($mindmap->canEdit($request->user()), 403);
        abort_unless($edge->mindmap_id === $mindmap->id, 404);
        $data = $request->validate(['label' => ['nullable', 'string', 'max:255']]);
        $edge->update($data);
        $mindmap->touch();

        return response()->json(['ok' => true]);
    }

    public function destroyEdge(Request $request, Mindmap $mindmap, MindmapEdge $edge): JsonResponse
    {
        abort_unless($mindmap->canEdit($request->user()), 403);
        abort_unless($edge->mindmap_id === $mindmap->id, 404);
        $edge->delete();
        $mindmap->touch();

        return response()->json(['ok' => true]);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `C:\php83\php.exe artisan test --filter=MindmapEdgeTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add app/Http/Controllers/MindmapController.php routes/web.php tests/Feature/MindmapEdgeTest.php
git commit -F <pesan>   # "feat(mindmap): edge CRUD (JSON) + validasi papan"
```

---

### Task 6: Halaman kanvas (Blade + vanilla JS)

**Files:**
- Modify: `resources/views/mindmaps/show.blade.php` (ganti placeholder dengan kanvas penuh)
- Test: `tests/Feature/MindmapCanvasRenderTest.php`

**Interfaces:**
- Consumes: route `mindmaps.state/nodes.*/edges.*/update/members.*` (Task 3-5). Variabel Blade: `$map`, `$canEdit`, `$isOwner`, `$staffOptions`.
- Produces: halaman kanvas fungsional. Deliverable teruji = render `assertOk` + memuat elemen kunci (canvas root, data routes). Interaksi JS diverifikasi manual di browser.

- [ ] **Step 1: Write the failing render test**

```php
<?php

namespace Tests\Feature;

use App\Models\Mindmap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MindmapCanvasRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_kanvas_render_untuk_yang_berhak(): void
    {
        $owner = User::create([
            'name' => 'o', 'fullname' => 'o', 'username' => 'o', 'email' => 'o@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
        $map = Mindmap::create(['title' => 'Papan Uji', 'created_by' => $owner->id]);

        $this->actingAs($owner)->get(route('mindmaps.show', $map))
            ->assertOk()
            ->assertSee('Papan Uji')
            ->assertSee('id="mmCanvas"', false)       // root kanvas
            ->assertSee($map->id.'/state');           // route state ter-embed
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:\php83\php.exe artisan test --filter=MindmapCanvasRenderTest`
Expected: FAIL (placeholder show.blade.php tak memuat `id="mmCanvas"`).

- [ ] **Step 3: Tulis halaman kanvas penuh**

Ganti isi `resources/views/mindmaps/show.blade.php` dengan berikut. Semua state route di-embed via `Js::from`; JS inline zero-dep. Node = `<div>` di `#mmWorld`; garis = `<svg id="mmSvg">`. Pan (drag latar), zoom (wheel/tombol), buat sticky (dblclick), edit (contenteditable, blur→PATCH), geser (drag→PATCH on drop), sambung (drag dari titik ke node lain→POST edge), warna, hapus, auto-refresh (poll updated_at).

```blade
@extends('layouts.app')
@section('title', $map->title)
@section('heading', 'Mindmap')

@section('content')
@php
    $routes = [
        'state'   => route('mindmaps.state', $map),
        'nodes'   => route('mindmaps.nodes.store', $map),
        'node'    => url('/mindmaps/'.$map->id.'/nodes'),   // + /{id} untuk PATCH/DELETE
        'edges'   => route('mindmaps.edges.store', $map),
        'edge'    => url('/mindmaps/'.$map->id.'/edges'),   // + /{id}
        'index'   => route('mindmaps.index'),
    ];
    $colors = ['kuning' => '#fef9c3', 'hijau' => '#dcfce7', 'biru' => '#dbeafe', 'rose' => '#ffe4e6', 'stone' => '#f5f5f4', 'putih' => '#ffffff'];
@endphp

<div class="flex flex-col h-[calc(100vh-8rem)]">
    <div class="flex flex-wrap items-center gap-2 mb-2">
        <a href="{{ route('mindmaps.index') }}" class="text-xs text-stone-500 hover:text-red-600">← Semua papan</a>
        <span class="text-stone-300">·</span>
        <h3 class="text-sm font-bold text-stone-900">{{ $map->title }}</h3>
        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $canEdit ? 'bg-emerald-100 text-emerald-700' : 'bg-stone-100 text-stone-500' }}">{{ $canEdit ? 'bisa edit' : 'lihat saja' }}</span>

        @if($canEdit)
        <div class="ml-auto flex items-center gap-1.5">
            <button id="mmAddSticky" class="px-3 py-1.5 text-xs bg-white border border-stone-300 rounded-lg hover:bg-stone-50 font-semibold">+ Sticky</button>
            <div class="flex items-center gap-1 px-2">
                @foreach($colors as $key => $hex)
                    <button class="mm-color w-5 h-5 rounded-full border border-stone-300" data-color="{{ $key }}" style="background: {{ $hex }}" title="{{ $key }}"></button>
                @endforeach
            </div>
            <button id="mmDelete" class="px-3 py-1.5 text-xs bg-white border border-stone-300 rounded-lg hover:bg-rose-50 text-rose-600 font-semibold">Hapus</button>
            <span class="text-stone-300">·</span>
        </div>
        @else
        <div class="ml-auto"></div>
        @endif
        <button id="mmZoomOut" class="w-7 h-7 bg-white border border-stone-300 rounded-lg text-sm">−</button>
        <button id="mmZoomFit" class="px-2 h-7 bg-white border border-stone-300 rounded-lg text-xs">fit</button>
        <button id="mmZoomIn" class="w-7 h-7 bg-white border border-stone-300 rounded-lg text-sm">+</button>
        <span id="mmRefreshChip" class="hidden px-2 py-1 text-[11px] bg-amber-100 text-amber-800 rounded-lg cursor-pointer">papan diperbarui — muat ulang</span>
    </div>

    <div id="mmCanvas" class="relative flex-1 overflow-hidden bg-stone-100 rounded-2xl border border-stone-200 select-none" style="cursor: grab;">
        <div id="mmWorld" class="absolute top-0 left-0" style="transform-origin: 0 0;">
            <svg id="mmSvg" class="absolute top-0 left-0 overflow-visible" style="pointer-events: none;" width="1" height="1">
                <defs>
                    <marker id="mmArrow" markerWidth="10" markerHeight="10" refX="8" refY="3" orient="auto">
                        <path d="M0,0 L8,3 L0,6 Z" fill="#78716c"></path>
                    </marker>
                </defs>
                <g id="mmEdges"></g>
            </svg>
            <div id="mmNodes"></div>
        </div>
        <p id="mmHint" class="absolute bottom-3 left-3 text-[11px] text-stone-400">{{ $canEdit ? 'Double-click kanvas = sticky baru · seret latar = geser · scroll = zoom · tarik titik biru node = sambung' : 'Mode lihat saja' }}</p>
    </div>
</div>

<script>
(function () {
    var R = {{ \Illuminate\Support\Js::from($routes) }};
    var COLORS = {{ \Illuminate\Support\Js::from($colors) }};
    var CAN_EDIT = {{ $canEdit ? 'true' : 'false' }};

    var canvas = document.getElementById('mmCanvas'),
        world = document.getElementById('mmWorld'),
        nodesLayer = document.getElementById('mmNodes'),
        edgesLayer = document.getElementById('mmEdges'),
        svg = document.getElementById('mmSvg'),
        chip = document.getElementById('mmRefreshChip');

    var view = { x: 0, y: 0, k: 1 };
    var nodes = {}, edges = {}, selected = null, lastUpdated = null, dirty = false;

    function csrf() { return document.querySelector('meta[name=csrf-token]').content; }
    function api(url, method, body) {
        return fetch(url, {
            method: method,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                       'X-CSRF-TOKEN': csrf(), 'Content-Type': 'application/json' },
            body: body ? JSON.stringify(body) : undefined,
        }).then(function (r) { return r.ok ? r.json() : Promise.reject(r); });
    }
    function applyView() { world.style.transform = 'translate(' + view.x + 'px,' + view.y + 'px) scale(' + view.k + ')'; }
    function toWorld(clientX, clientY) {
        var b = canvas.getBoundingClientRect();
        return { x: (clientX - b.left - view.x) / view.k, y: (clientY - b.top - view.y) / view.k };
    }

    // ---- render node ----
    function renderNode(n) {
        var el = document.getElementById('mmn-' + n.id);
        if (!el) {
            el = document.createElement('div');
            el.id = 'mmn-' + n.id;
            el.className = 'absolute rounded-xl border border-stone-300 shadow-sm p-2 text-xs text-stone-800 overflow-hidden';
            el.dataset.id = n.id;
            nodesLayer.appendChild(el);
            attachNode(el);
        }
        el.style.left = n.x + 'px'; el.style.top = n.y + 'px';
        el.style.width = n.width + 'px'; el.style.height = n.height + 'px';
        el.style.background = COLORS[n.color] || COLORS.kuning;
        if (document.activeElement !== el.querySelector('.mm-text')) {
            el.innerHTML = '';
            var t = document.createElement('div');
            t.className = 'mm-text w-full h-full outline-none whitespace-pre-wrap break-words';
            t.contentEditable = CAN_EDIT ? 'true' : 'false';
            t.textContent = n.text || '';
            el.appendChild(t);
            if (CAN_EDIT) {
                var h = document.createElement('div');
                h.className = 'mm-port absolute -right-1.5 top-1/2 -mt-1.5 w-3 h-3 rounded-full bg-blue-500 border-2 border-white cursor-crosshair';
                el.appendChild(h);
                attachPort(h, n.id);
            }
        }
    }
    function removeNodeEl(id) { var el = document.getElementById('mmn-' + id); if (el) el.remove(); }

    // ---- render edge ----
    function renderEdge(e) {
        var from = nodes[e.from_node_id], to = nodes[e.to_node_id];
        if (!from || !to) return;
        var g = document.getElementById('mme-' + e.id);
        if (!g) {
            g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            g.id = 'mme-' + e.id; g.dataset.id = e.id; g.style.pointerEvents = 'stroke';
            var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('fill', 'none'); path.setAttribute('stroke', '#78716c');
            path.setAttribute('stroke-width', '2'); path.setAttribute('marker-end', 'url(#mmArrow)');
            path.style.cursor = 'pointer';
            g.appendChild(path);
            edgesLayer.appendChild(g);
            if (CAN_EDIT) g.addEventListener('click', function () { editEdge(e.id); });
        }
        var x1 = from.x + from.width, y1 = from.y + from.height / 2;
        var x2 = to.x, y2 = to.y + to.height / 2;
        g.querySelector('path').setAttribute('d', 'M' + x1 + ',' + y1 + ' C' + (x1 + 60) + ',' + y1 + ' ' + (x2 - 60) + ',' + y2 + ' ' + x2 + ',' + y2);
    }
    function removeEdgeEl(id) { var g = document.getElementById('mme-' + id); if (g) g.remove(); }
    function redrawEdgesFor(nodeId) {
        Object.values(edges).forEach(function (e) { if (e.from_node_id == nodeId || e.to_node_id == nodeId) renderEdge(e); });
    }

    // ---- load & sync ----
    function load(initial) {
        api(R.state, 'GET').then(function (s) {
            if (!initial && s.updated_at === lastUpdated) return;
            if (!initial && dirty) { chip.classList.remove('hidden'); return; }
            lastUpdated = s.updated_at;
            nodesLayer.innerHTML = ''; edgesLayer.innerHTML = ''; nodes = {}; edges = {};
            s.nodes.forEach(function (n) { nodes[n.id] = n; renderNode(n); });
            s.edges.forEach(function (e) { edges[e.id] = e; renderEdge(e); });
        }).catch(function () {});
    }
    setInterval(function () { load(false); }, 10000);
    chip.addEventListener('click', function () { chip.classList.add('hidden'); dirty = false; load(true); });

    // ---- pan & zoom ----
    var panning = null;
    canvas.addEventListener('mousedown', function (ev) {
        if (ev.target === canvas || ev.target === world || ev.target === svg) {
            panning = { sx: ev.clientX, sy: ev.clientY, ox: view.x, oy: view.y };
            canvas.style.cursor = 'grabbing'; select(null);
        }
    });
    window.addEventListener('mousemove', function (ev) {
        if (panning) { view.x = panning.ox + (ev.clientX - panning.sx); view.y = panning.oy + (ev.clientY - panning.sy); applyView(); }
    });
    window.addEventListener('mouseup', function () { panning = null; canvas.style.cursor = 'grab'; });
    canvas.addEventListener('wheel', function (ev) {
        ev.preventDefault();
        var w = toWorld(ev.clientX, ev.clientY);
        var factor = ev.deltaY < 0 ? 1.1 : 0.9;
        view.k = Math.min(3, Math.max(0.2, view.k * factor));
        var b = canvas.getBoundingClientRect();
        view.x = (ev.clientX - b.left) - w.x * view.k;
        view.y = (ev.clientY - b.top) - w.y * view.k;
        applyView();
    }, { passive: false });
    document.getElementById('mmZoomIn').onclick = function () { view.k = Math.min(3, view.k * 1.2); applyView(); };
    document.getElementById('mmZoomOut').onclick = function () { view.k = Math.max(0.2, view.k / 1.2); applyView(); };
    document.getElementById('mmZoomFit').onclick = function () { view = { x: 0, y: 0, k: 1 }; applyView(); };

    // ---- select ----
    function select(el) {
        if (selected) selected.classList.remove('ring-2', 'ring-red-500');
        selected = el;
        if (el) el.classList.add('ring-2', 'ring-red-500');
    }

    if (!CAN_EDIT) { applyView(); load(true); return; }

    // ---- create sticky (double-click) ----
    canvas.addEventListener('dblclick', function (ev) {
        if (ev.target !== canvas && ev.target !== world && ev.target !== svg) return;
        var p = toWorld(ev.clientX, ev.clientY);
        api(R.nodes, 'POST', { type: 'sticky', x: Math.round(p.x), y: Math.round(p.y), color: 'kuning', text: '' })
            .then(function (res) { nodes[res.node.id] = res.node; renderNode(res.node); markDirty(); });
    });
    document.getElementById('mmAddSticky').onclick = function () {
        var p = toWorld(canvas.getBoundingClientRect().left + 200, canvas.getBoundingClientRect().top + 150);
        api(R.nodes, 'POST', { type: 'sticky', x: Math.round(p.x), y: Math.round(p.y), color: 'kuning', text: '' })
            .then(function (res) { nodes[res.node.id] = res.node; renderNode(res.node); markDirty(); });
    };

    function markDirty() { dirty = true; }

    // ---- node interactions (drag, edit, select) ----
    function attachNode(el) {
        var id = el.dataset.id, drag = null;
        el.addEventListener('mousedown', function (ev) {
            if (ev.target.classList.contains('mm-port')) return;         // sambung, bukan geser
            if (ev.target.classList.contains('mm-text') && document.activeElement === ev.target) return; // sedang edit
            ev.stopPropagation(); select(el);
            drag = { sx: ev.clientX, sy: ev.clientY, ox: nodes[id].x, oy: nodes[id].y };
        });
        window.addEventListener('mousemove', function (ev) {
            if (!drag) return;
            nodes[id].x = drag.ox + (ev.clientX - drag.sx) / view.k;
            nodes[id].y = drag.oy + (ev.clientY - drag.sy) / view.k;
            el.style.left = nodes[id].x + 'px'; el.style.top = nodes[id].y + 'px';
            redrawEdgesFor(id);
        });
        window.addEventListener('mouseup', function () {
            if (!drag) return; drag = null;
            api(R.node + '/' + id, 'PATCH', { x: Math.round(nodes[id].x), y: Math.round(nodes[id].y) }).then(markDirty);
        });
        el.addEventListener('blur', function (ev) {
            if (!ev.target.classList.contains('mm-text')) return;
            var txt = ev.target.textContent;
            if (txt !== nodes[id].text) { nodes[id].text = txt; api(R.node + '/' + id, 'PATCH', { text: txt }).then(markDirty); }
        }, true);
    }

    // ---- connect (drag from port to a node) ----
    var linking = null, tempPath = null;
    function attachPort(handle, fromId) {
        handle.addEventListener('mousedown', function (ev) {
            ev.stopPropagation();
            linking = { from: fromId };
            tempPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            tempPath.setAttribute('fill', 'none'); tempPath.setAttribute('stroke', '#3b82f6');
            tempPath.setAttribute('stroke-dasharray', '4'); tempPath.setAttribute('stroke-width', '2');
            edgesLayer.appendChild(tempPath);
        });
    }
    window.addEventListener('mousemove', function (ev) {
        if (!linking) return;
        var f = nodes[linking.from], p = toWorld(ev.clientX, ev.clientY);
        tempPath.setAttribute('d', 'M' + (f.x + f.width) + ',' + (f.y + f.height / 2) + ' L' + p.x + ',' + p.y);
    });
    window.addEventListener('mouseup', function (ev) {
        if (!linking) return;
        var target = ev.target.closest ? ev.target.closest('[data-id]') : null;
        var toId = target && target.parentNode === nodesLayer ? target.dataset.id : null;
        if (tempPath) { tempPath.remove(); tempPath = null; }
        if (toId && toId != linking.from) {
            api(R.edges, 'POST', { from_node_id: Number(linking.from), to_node_id: Number(toId) })
                .then(function (res) { edges[res.edge.id] = res.edge; renderEdge(res.edge); markDirty(); }).catch(function () {});
        }
        linking = null;
    });

    // ---- color / delete ----
    document.querySelectorAll('.mm-color').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!selected) return;
            var id = selected.dataset.id, c = btn.dataset.color;
            nodes[id].color = c; selected.style.background = COLORS[c];
            api(R.node + '/' + id, 'PATCH', { color: c }).then(markDirty);
        });
    });
    document.getElementById('mmDelete').onclick = function () {
        if (!selected) return;
        var id = selected.dataset.id;
        api(R.node + '/' + id, 'DELETE').then(function () {
            removeNodeEl(id); delete nodes[id];
            Object.values(edges).forEach(function (e) { if (e.from_node_id == id || e.to_node_id == id) { removeEdgeEl(e.id); delete edges[e.id]; } });
            select(null); markDirty();
        });
    };

    function editEdge(id) {
        var label = prompt('Label garis (kosongkan untuk hapus label):', edges[id].label || '');
        if (label === null) return;
        edges[id].label = label;
        api(R.edge + '/' + id, 'PATCH', { label: label }).then(markDirty);
    }

    applyView(); load(true);
})();
</script>
@endsection
```

- [ ] **Step 4: Run render test to verify it passes**

Run: `C:\php83\php.exe artisan test --filter=MindmapCanvasRenderTest`
Expected: PASS.

- [ ] **Step 5: Jalankan SELURUH suite**

Run: `C:\php83\php.exe artisan test`
Expected: semua PASS (tak ada regresi).

- [ ] **Step 6: Verifikasi manual di browser (catat hasil ke user)**

Login staf → menu **Mindmaps** → buat papan → buka → double-click bikin sticky → ketik → geser → tarik titik biru ke node lain (garis) → warnai → hapus → refresh halaman (state termuat). Konfirmasi ke user sebelum menandai selesai.

- [ ] **Step 7: Pint + commit**

```bash
C:\php83\php.exe vendor/bin/pint --dirty
git add resources/views/mindmaps/show.blade.php tests/Feature/MindmapCanvasRenderTest.php
git commit -F <pesan>   # "feat(mindmap): halaman kanvas (pan/zoom, node, garis, auto-save/refresh)"
```

---

## Self-Review (penulis rencana)

**1. Spec coverage:** Data model §3 → Task 1. Izin/akses §4 → Task 2 (izin) + helper Task 1 + gerbang tiap method. Routes §5 → Task 2-5. UI/interaksi §6 → Task 6. Sync §7 → Task 6 (auto-save per-elemen + poll updated_at + chip). Testing §9 → tiap task punya feature test + render test. Rollout §10 → migrasi 000071 (Task 1) + izin/menu (Task 2). **Fase 2 AI (§8) SENGAJA di luar rencana ini** (rencana terpisah nanti).

**2. Placeholder scan:** Tak ada TBD/TODO. Semua step berisi kode nyata (migrasi, model, controller, test, Blade+JS). Satu catatan wajar: Task 2 Step 5 memakai `route('mindmaps.show')` yang baru ada di Task 3 — dicatat eksplisit; tes Task 2 pakai `assertRedirect()` tanpa target agar hijau lebih dulu.

**3. Type consistency:** Nama method controller konsisten (`storeNode/updateNode/destroyNode`, `storeEdge/updateEdge/destroyEdge`, `addMember/removeMember`). Nama route konsisten (`mindmaps.nodes.store` dst). State JSON shape sama antara `state()` (Task 4) dan konsumen JS (Task 6): `nodes[{id,type,x,y,width,height,text,color}]`, `edges[{id,from_node_id,to_node_id,label}]`, `updated_at`. Helper `canView/canEdit/isOwner` (Task 1) dipakai konsisten di semua method.

**Catatan implementer:** verifikasi helper Blade `navItem(...)` (Task 2 Step 7) & pola sidebar sesuai file `layouts/app.blade.php` aktual; sesuaikan bila signature beda. `User::isSuperAdmin()`, `User::STATUS_ACTIVE`, `User::ROLE_*` dipakai seperti di KanbanController/AiTools yang sudah ada.
