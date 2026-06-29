<?php

namespace App\Http\Controllers;

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

        $lessons = Lesson::query()
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Lesson $l) => $canManage || $l->visibleTo($user))
            ->values();

        $audienceRoles = [User::ROLE_ADMIN, User::ROLE_GUDANG, User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER];

        return view('learning.index', compact('lessons', 'canManage', 'audienceRoles'));
    }

    public function show(Request $request, Lesson $lesson)
    {
        $user = $request->user();
        abort_unless($lesson->visibleTo($user), 403, 'Materi ini tidak tersedia untuk Anda.');

        return view('learning.show', compact('lesson', 'user'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['created_by'] = $request->user()->id;

        $lesson = Lesson::create($data);

        AuditService::log(action: 'create_lesson', targetType: 'lesson', targetId: $lesson->id, after: ['title' => $lesson->title]);

        return back()->with('status', "Materi \"{$lesson->title}\" berhasil ditambahkan.");
    }

    public function update(Request $request, Lesson $lesson): RedirectResponse
    {
        $lesson->update($this->validateData($request));

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

    private function validateData(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'video_url' => ['required', 'url', 'max:255'],
            'category' => ['nullable', 'string', 'max:80'],
            'audience' => ['nullable', 'array'],
            'audience.*' => [Rule::in([User::ROLE_ADMIN, User::ROLE_GUDANG, User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $validated['audience'] = $validated['audience'] ?? [];
        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        $validated['is_published'] = $request->boolean('is_published');

        return $validated;
    }
}
