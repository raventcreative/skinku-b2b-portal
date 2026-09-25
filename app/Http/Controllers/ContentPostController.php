<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ContentPost;
use App\Models\ContentPostTarget;
use App\Models\SocialConnection;
use App\Services\AuditService;
use App\Services\ContentPostService;
use App\Services\Social\MetaClient;
use App\Services\Social\TikTokContentClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Portal Content Creator — dashboard & konten milik creator (FR-10..FR-27).
 * Creator hanya melihat/mengubah kontennya sendiri; reviewer (content.review)
 * boleh melihat semua lewat halaman detail.
 */
class ContentPostController extends Controller
{
    public function __construct(private ContentPostService $service) {}

    public function dashboard(Request $request): View
    {
        $own = ContentPost::where('user_id', $request->user()->id);

        $counts = (clone $own)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $publishedThisMonth = ContentPostTarget::where('status', ContentPostTarget::PUBLISHED)
            ->where('published_at', '>=', now()->startOfMonth())
            ->whereHas('post', fn ($q) => $q->where('user_id', $request->user()->id))
            ->distinct('content_post_id')->count('content_post_id');

        return view('content.dashboard', [
            'cards' => [
                ['Draft', $counts[ContentPost::DRAFT] ?? 0, 'bg-stone-500'],
                ['Menunggu Review', $counts[ContentPost::IN_REVIEW] ?? 0, 'bg-amber-500'],
                ['Ditolak', $counts[ContentPost::REJECTED] ?? 0, 'bg-rose-500'],
                ['Terjadwal / Terbit', ($counts[ContentPost::SCHEDULED] ?? 0) + ($counts[ContentPost::PUBLISHING] ?? 0), 'bg-blue-500'],
                ['Terbit Bulan Ini', $publishedThisMonth, 'bg-emerald-500'],
            ],
            'recent' => (clone $own)->with('targets')->latest('id')->limit(10)->get(),
            'attention' => (clone $own)->whereIn('status', [ContentPost::REJECTED, ContentPost::FAILED, ContentPost::PARTIAL])
                ->latest('updated_at')->limit(5)->get(),
        ]);
    }

    public function index(Request $request): View
    {
        $posts = ContentPost::where('user_id', $request->user()->id)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->with('targets')->latest('id')->paginate(20)->withQueryString();

        return view('content.index', ['posts' => $posts, 'mine' => true]);
    }

    public function create(): View
    {
        return view('content.form', ['post' => new ContentPost(['type' => 'image']), 'selected' => [], 'captions' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $files] = $this->validated($request, null);
        $post = $this->service->save(null, $request->user(), $data, $files);

        return $this->afterSave($request, $post);
    }

    public function show(Request $request, ContentPost $post): View
    {
        $this->authorizeView($request, $post);
        $post->load(['targets', 'user', 'reviewer']);

        $history = AuditLog::where('target_type', 'content_post')->where('target_id', $post->id)
            ->orderBy('id')->get(['action', 'performed_by_email', 'after_data', 'created_at']);

        return view('content.show', ['post' => $post, 'media' => $post->filesIn(ContentPost::MEDIA)->get(), 'history' => $history,
            'tiktokInfo' => $this->tiktokCreatorInfo($request, $post)]);
    }

    /**
     * Info akun TikTok untuk form approve (pedoman UX TikTok: tampilkan nama akun,
     * opsi privacy dari API, matikan toggle yang dinonaktifkan kreator). Hanya
     * saat reviewer membuka konten in_review yang akan terbit ke TikTok via API.
     */
    private function tiktokCreatorInfo(Request $request, ContentPost $post): ?array
    {
        $target = $post->targets->firstWhere('platform', 'tiktok');
        if (! $target || $post->status !== ContentPost::IN_REVIEW || ! $request->user()->canDo('content.review') || $target->isManual()) {
            return null;
        }

        try {
            $client = app(TikTokContentClient::class);

            return $client->creatorInfo($client->freshToken(SocialConnection::for('tiktok')));
        } catch (\Throwable $e) {
            return ['error' => MetaClient::sanitize($e->getMessage())];
        }
    }

    public function edit(Request $request, ContentPost $post): View
    {
        $this->authorizeOwnerEdit($request, $post);

        return view('content.form', [
            'post' => $post,
            'selected' => $post->targets->pluck('platform')->all(),
            'captions' => $post->targets->pluck('caption_override', 'platform')->all(),
        ]);
    }

    public function update(Request $request, ContentPost $post): RedirectResponse
    {
        $this->authorizeOwnerEdit($request, $post);
        [$data, $files] = $this->validated($request, $post);
        $this->service->save($post, $request->user(), $data, $files);

        return $this->afterSave($request, $post);
    }

    public function destroy(Request $request, ContentPost $post): RedirectResponse
    {
        $this->authorizeOwnerEdit($request, $post);
        $post->filesIn(ContentPost::MEDIA)->get()->each->delete();
        $post->delete();
        AuditService::log(action: 'content.delete', targetType: 'content_post', targetId: $post->id, before: ['title' => $post->title]);

        return redirect()->route('content.index')->with('status', 'Konten dihapus.');
    }

    public function submit(Request $request, ContentPost $post): RedirectResponse
    {
        $this->authorizeOwner($request, $post);
        $this->service->submit($post);

        return redirect()->route('content.show', $post)->with('status', 'Konten diajukan untuk review.');
    }

    public function withdraw(Request $request, ContentPost $post): RedirectResponse
    {
        $this->authorizeOwner($request, $post);
        $this->service->withdraw($post);

        return redirect()->route('content.edit', $post)->with('status', 'Konten ditarik kembali ke draft.');
    }

    /** @return array{0:array,1:array} data tervalidasi + file upload baru */
    private function validated(Request $request, ?ContentPost $post): array
    {
        $platforms = array_keys(config('content.platforms'));
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(array_keys(ContentPost::TYPES))],
            'caption' => ['nullable', 'string', 'max:63206'],
            'platforms' => ['array'],
            'platforms.*' => [Rule::in($platforms)],
            'captions' => ['array'],
            'captions.*' => ['nullable', 'string', 'max:63206'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'creator_note' => ['nullable', 'string', 'max:2000'],
            'media' => ['array', 'max:'.config('content.carousel_max')],
            'media.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime,application/mp4', 'max:'.config('content.video_max_kb')],
        ], [
            'media.*.mimetypes' => 'Media harus JPG/PNG/WEBP atau video MP4/MOV.',
            'media.*.max' => 'File media terlalu besar.',
            'scheduled_at.after' => 'Jadwal terbit harus di masa depan.',
        ]);

        $data['platforms'] = array_values(array_unique($data['platforms'] ?? []));
        $data['captions'] = array_intersect_key($data['captions'] ?? [], array_flip($data['platforms']));
        $files = $request->file('media', []);

        // "Simpan & Ajukan": cek kelengkapan SEBELUM menyimpan agar error tampil di form (AC-6).
        if ($request->boolean('submit')) {
            $media = $files !== [] ? ContentPostService::describeUploads($files) : ($post ? ContentPostService::describeStored($post) : []);
            $errors = ContentPostService::submitErrors($data['type'], $media, $data['caption'] ?? null, $data['platforms'], $data['captions']);
            if ($errors !== []) {
                throw ValidationException::withMessages(['content' => $errors]);
            }
        }

        return [$data, $files];
    }

    private function afterSave(Request $request, ContentPost $post): RedirectResponse
    {
        if ($request->boolean('submit')) {
            $this->service->submit($post->fresh());

            return redirect()->route('content.show', $post)->with('status', 'Konten disimpan & diajukan untuk review.');
        }

        return redirect()->route('content.edit', $post)->with('status', 'Draft tersimpan.');
    }

    private function authorizeView(Request $request, ContentPost $post): void
    {
        $u = $request->user();
        abort_unless(($post->user_id === $u->id && $u->canDo('content.create')) || $u->canDo('content.review'), 403);
    }

    private function authorizeOwner(Request $request, ContentPost $post): void
    {
        abort_unless($post->user_id === $request->user()->id, 403);
    }

    private function authorizeOwnerEdit(Request $request, ContentPost $post): void
    {
        $this->authorizeOwner($request, $post);
        abort_unless($post->isEditable(), 403, 'Konten yang sedang direview / sudah disetujui tidak bisa diubah.');
    }
}
