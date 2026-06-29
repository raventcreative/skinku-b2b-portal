<?php

namespace App\Http\Controllers;

use App\Models\LearningModule;
use App\Models\Lesson;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class LearningController extends Controller
{
    public function __construct(private ImageService $images) {}

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

        $audienceRoles = Role::ordered()->where('name', '!=', User::ROLE_SUPER_ADMIN)->get();

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

        if (empty($data['video_url']) && ! $request->hasFile('document_file')) {
            return back()->withErrors(['document_file' => 'Isi minimal salah satu: link video YouTube ATAU file dokumen (PPT/Word/PDF).'])->withInput();
        }
        $data['created_by'] = $request->user()->id;

        $lesson = Lesson::create(Arr::except($data, ['document_file', 'cover_image', 'remove_document']));
        $this->attachLessonFiles($request, $lesson);

        AuditService::log(action: 'create_lesson', targetType: 'lesson', targetId: $lesson->id, after: ['title' => $lesson->title]);

        return back()->with('status', "Materi \"{$lesson->title}\" berhasil ditambahkan.");
    }

    public function update(Request $request, Lesson $lesson): RedirectResponse
    {
        $data = $this->validateLesson($request);

        // After this update, will the lesson still have a video or a document?
        $keepsDocument = ($lesson->documentFile() && ! $request->boolean('remove_document')) || $request->hasFile('document_file');
        if (empty($data['video_url']) && ! $keepsDocument) {
            return back()->withErrors(['document_file' => 'Materi harus punya minimal satu: link video YouTube ATAU file dokumen.'])->withInput();
        }

        $lesson->update(Arr::except($data, ['document_file', 'cover_image', 'remove_document']));
        $this->attachLessonFiles($request, $lesson);

        AuditService::log(action: 'update_lesson', targetType: 'lesson', targetId: $lesson->id, after: ['title' => $lesson->title]);

        return back()->with('status', "Materi \"{$lesson->title}\" berhasil diperbarui.");
    }

    /** Attach/replace the document file and optional cover image. A materi may keep both a video and a document. */
    private function attachLessonFiles(Request $request, Lesson $lesson): void
    {
        if ($request->boolean('remove_document')) {
            $lesson->filesIn(Lesson::DOC)->get()->each->delete();
        }
        if ($request->hasFile('document_file')) {
            $lesson->filesIn(Lesson::DOC)->get()->each->delete();
            $this->images->attach($lesson, $request->file('document_file'), Lesson::DOC);
        }
        if ($request->hasFile('cover_image')) {
            $lesson->filesIn(Lesson::COVER)->get()->each->delete();
            $this->images->attach($lesson, $request->file('cover_image'), Lesson::COVER, 800);
        }
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
            'video_url' => ['nullable', 'url', 'max:255'],
            'document_file' => ['nullable', 'file', 'mimes:pdf,ppt,pptx,doc,docx,xls,xlsx', 'max:51200'],
            'cover_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'remove_document' => ['nullable', 'boolean'],
            'audience' => ['nullable', 'array'],
            'audience.*' => [Rule::in(Role::where('name', '!=', User::ROLE_SUPER_ADMIN)->pluck('name')->all())],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $validated['module_id'] = $validated['module_id'] ?? null;
        $validated['audience'] = $validated['audience'] ?? [];
        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        $validated['is_published'] = $request->boolean('is_published');
        // `type` is kept for display/filtering; derive it from whether a video link is set.
        $validated['type'] = ! empty($validated['video_url']) ? Lesson::TYPE_VIDEO : Lesson::TYPE_DOCUMENT;

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
