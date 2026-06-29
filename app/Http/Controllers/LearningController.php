<?php

namespace App\Http\Controllers;

use App\Models\LearningModule;
use App\Models\Lesson;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LearningController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $canManage = $user->canDo('manage_learning');

        $modules = LearningModule::query()
            ->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (LearningModule $m) => $canManage || $m->is_published)
            ->values();

        $lessons = Lesson::query()
            ->orderBy('sort_order')->orderByDesc('id')->get()
            ->filter(fn (Lesson $l) => $canManage || $l->visibleTo($user))
            ->values();

        $audienceRoles = [User::ROLE_ADMIN, User::ROLE_GUDANG, User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER];

        return view('learning.index', compact('modules', 'lessons', 'canManage', 'audienceRoles'));
    }

    public function show(Request $request, Lesson $lesson)
    {
        $user = $request->user();
        abort_unless($lesson->visibleTo($user), 403, 'Materi ini tidak tersedia untuk Anda.');

        return view('learning.show', compact('lesson', 'user'));
    }

    /* ---------------- Lessons ---------------- */

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateLesson($request);
        $data['created_by'] = $request->user()->id;

        $lesson = Lesson::create($data);
        AuditService::log(action: 'create_lesson', targetType: 'lesson', targetId: $lesson->id, after: ['title' => $lesson->title]);

        return back()->with('status', "Materi \"{$lesson->title}\" berhasil ditambahkan.");
    }

    public function update(Request $request, Lesson $lesson): RedirectResponse
    {
        $lesson->update($this->validateLesson($request));
        AuditService::log(action: 'update_lesson', targetType: 'lesson', targetId: $lesson->id, after: ['title' => $lesson->title]);

        return back()->with('status', "Materi \"{$lesson->title}\" berhasil diperbarui.");
    }

    public function destroy(Request $request, Lesson $lesson): RedirectResponse
    {
        $title = $lesson->title;
        $lesson->delete();
        AuditService::log(action: 'delete_lesson', targetType: 'lesson', targetId: $lesson->id, after: ['title' => $title]);

        return redirect()->route('learning.index')->with('status', "Materi \"{$title}\" dihapus.");
    }

    /* ---------------- Modules ---------------- */

    public function storeModule(Request $request): RedirectResponse
    {
        $data = $this->validateModule($request);
        $data['created_by'] = $request->user()->id;

        $module = LearningModule::create($data);
        AuditService::log(action: 'create_module', targetType: 'learning_module', targetId: $module->id, after: ['title' => $module->title]);

        return back()->with('status', "Modul \"{$module->title}\" berhasil ditambahkan.");
    }

    public function updateModule(Request $request, LearningModule $module): RedirectResponse
    {
        $module->update($this->validateModule($request));
        AuditService::log(action: 'update_module', targetType: 'learning_module', targetId: $module->id, after: ['title' => $module->title]);

        return back()->with('status', "Modul \"{$module->title}\" berhasil diperbarui.");
    }

    public function destroyModule(Request $request, LearningModule $module): RedirectResponse
    {
        $title = $module->title;
        $module->delete(); // lessons keep, become ungrouped (FK nullOnDelete)
        AuditService::log(action: 'delete_module', targetType: 'learning_module', targetId: $module->id, after: ['title' => $title]);

        return redirect()->route('learning.index')->with('status', "Modul \"{$title}\" dihapus. Materinya dipindah ke \"Tanpa Modul\".");
    }

    /* ---------------- Validation ---------------- */

    private function validateLesson(Request $request): array
    {
        $validated = $request->validate([
            'module_id' => ['nullable', 'integer', 'exists:learning_modules,id'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'video_url' => ['required', 'url', 'max:255'],
            'audience' => ['nullable', 'array'],
            'audience.*' => [Rule::in([User::ROLE_ADMIN, User::ROLE_GUDANG, User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $validated['module_id'] = $validated['module_id'] ?? null;
        $validated['audience'] = $validated['audience'] ?? [];
        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        $validated['is_published'] = $request->boolean('is_published');

        return $validated;
    }

    private function validateModule(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        $validated['is_published'] = $request->boolean('is_published');

        return $validated;
    }
}
