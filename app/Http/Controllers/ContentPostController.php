<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ContentPost;
use App\Models\ContentPostSnapshot;
use App\Models\ContentPostTarget;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ContentPostService;
use App\Services\Social\MetaClient;
use App\Services\Social\TikTokContentClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Content Pipeline — creator mengelola publikasi sendiri; admin melihat workspace lintas creator.
 */
class ContentPostController extends Controller
{
    public function __construct(private ContentPostService $service) {}

    public function dashboard(Request $request): RedirectResponse
    {
        return redirect()->route('content.index');
    }

    public function index(Request $request): View
    {
        $request->validate([
            'stage' => ['nullable', Rule::in(['all', 'draft', 'scheduled', 'publishing', 'attention', 'published'])],
            'q' => ['nullable', 'string', 'max:150'],
            'creator' => ['nullable', 'integer', 'exists:users,id'],
            'platform' => ['nullable', Rule::in(array_keys(config('content.platforms')))],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ]);
        $user = $request->user();
        $canManage = $this->canManage($user);
        $base = ContentPost::query()->when(! $canManage, fn ($q) => $q->where('user_id', $user->id));
        $stage = (string) $request->query('stage', 'all');
        $stageStatuses = [
            'draft' => [ContentPost::DRAFT],
            'scheduled' => [ContentPost::SCHEDULED],
            'publishing' => [ContentPost::PUBLISHING],
            'published' => [ContentPost::DONE],
        ];
        if ($stage === 'attention') {
            $base->where(function ($q) {
                $q->whereIn('status', [ContentPost::PARTIAL, ContentPost::FAILED])
                    ->orWhereHas('targets', fn ($t) => $t->whereIn('status', [ContentPostTarget::FAILED, ContentPostTarget::MANUAL_PENDING]));
            });
        } elseif (isset($stageStatuses[$stage])) {
            $base->whereIn('status', $stageStatuses[$stage]);
        }

        $posts = (clone $base)
            ->when($canManage && $request->filled('creator'), fn ($q) => $q->where('user_id', $request->query('creator')))
            ->when($request->filled('platform'), fn ($q) => $q->whereHas('targets', fn ($t) => $t->where('platform', $request->query('platform'))))
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->query('q').'%'))
            ->when($request->filled('dari'), fn ($q) => $q->whereDate('scheduled_at', '>=', $request->query('dari')))
            ->when($request->filled('sampai'), fn ($q) => $q->whereDate('scheduled_at', '<=', $request->query('sampai')))
            ->with(['targets', 'files', 'user'])->orderByRaw('scheduled_at is null')->orderBy('scheduled_at')->latest('id')->paginate(20)->withQueryString();

        $scoped = ContentPost::query()->when(! $canManage, fn ($q) => $q->where('user_id', $user->id));
        $counts = (clone $scoped)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $attentionCount = (clone $scoped)->where(function ($q) {
            $q->whereIn('status', [ContentPost::PARTIAL, ContentPost::FAILED])
                ->orWhereHas('targets', fn ($t) => $t->whereIn('status', [ContentPostTarget::FAILED, ContentPostTarget::MANUAL_PENDING]));
        })->count();

        return view('content.index', [
            'posts' => $posts,
            'canManage' => $canManage,
            'creators' => $canManage ? User::whereIn('id', ContentPost::select('user_id'))->orderBy('fullname')->get(['id', 'fullname', 'username']) : collect(),
            'counts' => $counts,
            'attentionCount' => $attentionCount,
            'stage' => $stage,
            'filters' => $request->only(['q', 'creator', 'platform', 'dari', 'sampai']),
        ]);
    }

    public function calendar(Request $request): View
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m'], 'creator' => ['nullable', 'integer', 'exists:users,id']]);
        $user = $request->user();
        $canManage = $this->canManage($user);
        abort_unless($canManage || ! $request->filled('creator'), 403);
        $month = Carbon::createFromFormat('!Y-m', (string) $request->query('month', now()->format('Y-m')));
        $start = $month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $end = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        $posts = ContentPost::query()
            ->when(! $canManage, fn ($q) => $q->where('user_id', $user->id))
            ->when($canManage && $request->filled('creator'), fn ($q) => $q->where('user_id', $request->query('creator')))
            ->whereBetween('scheduled_at', [$start, $end])
            ->with(['targets', 'user'])->orderBy('scheduled_at')->get()
            ->groupBy(fn ($post) => $post->scheduled_at->toDateString());

        return view('content.calendar', [
            'month' => $month, 'start' => $start, 'end' => $end, 'postsByDate' => $posts,
            'canManage' => $canManage,
            'creators' => $canManage ? User::whereIn('id', ContentPost::select('user_id'))->orderBy('fullname')->get(['id', 'fullname', 'username']) : collect(),
            'creatorFilter' => $request->query('creator'),
        ]);
    }

    public function create(Request $request): View
    {
        $request->validate(['scheduled_at' => ['nullable', 'date', 'after:now']]);
        $post = new ContentPost(['type' => 'image', 'scheduled_at' => $request->query('scheduled_at')]);

        return $this->formView($request, $post, [], []);
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
        $post->load(['targets.snapshots' => fn ($q) => $q->orderBy('captured_on'), 'user']);

        $history = AuditLog::where('target_type', 'content_post')->where('target_id', $post->id)
            ->orderBy('id')->get(['action', 'performed_by_email', 'after_data', 'created_at']);

        return view('content.show', [
            'post' => $post,
            'media' => $post->filesIn(ContentPost::MEDIA)->get(),
            'history' => $history,
            'canManage' => $this->canManage($request->user()),
            'canPublish' => $request->user()->canDo('content.publish.manage'),
            'insightChart' => $this->insightChart($post),
        ]);
    }

    /** Grafik views per platform untuk detail konten (FR-82). Null bila belum ada snapshot. */
    private function insightChart(ContentPost $post): ?array
    {
        $dates = $post->targets->flatMap->snapshots->map(fn ($s) => $s->captured_on->toDateString())->unique()->sort()->values();
        if ($dates->isEmpty()) {
            return null;
        }

        return [
            'labels' => $dates->map(fn ($d) => Carbon::parse($d)->format('d M'))->all(),
            'datasets' => $post->targets->filter(fn ($t) => $t->snapshots->isNotEmpty())->map(function ($t) use ($dates) {
                $byDate = $t->snapshots->keyBy(fn ($s) => $s->captured_on->toDateString());

                return ['label' => $t->platformLabel(), 'data' => $dates->map(fn ($d) => $byDate[$d]->views ?? null)->all()];
            })->values()->all(),
        ];
    }

    /** Info akun TikTok dipakai di form compose saat Direct Post aktif. */
    private function tiktokCreatorInfo(): ?array
    {
        $connection = SocialConnection::for('tiktok');
        if (! $connection || ! $connection->isActive()) {
            return null;
        }

        try {
            $client = app(TikTokContentClient::class);

            return $client->creatorInfo($client->freshToken($connection));
        } catch (\Throwable $e) {
            return ['error' => MetaClient::sanitize($e->getMessage())];
        }
    }

    private function formView(Request $request, ContentPost $post, array $selected, array $captions): View
    {
        return view('content.form', [
            'post' => $post,
            'selected' => $selected,
            'captions' => $captions,
            'tiktokInfo' => $this->tiktokCreatorInfo(),
            'connections' => SocialConnection::all()->keyBy('platform'),
        ]);
    }

    public function edit(Request $request, ContentPost $post): View
    {
        $this->authorizeOwnerEdit($request, $post);

        return $this->formView($request, $post, $post->targets->pluck('platform')->all(), $post->targets->pluck('caption_override', 'platform')->all());
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

    public function retry(Request $request, ContentPostTarget $target): RedirectResponse
    {
        $this->authorizeTargetManagement($request, $target);
        $this->service->retry($target);

        return back()->with('status', $target->platformLabel().' masuk antrean terbit lagi.');
    }

    public function markPublished(Request $request, ContentPostTarget $target): RedirectResponse
    {
        $this->authorizeTargetManagement($request, $target);
        if ($target->post->scheduled_at?->isFuture()) {
            throw ValidationException::withMessages(['permalink' => 'Posting manual dapat dicatat setelah waktu jadwal tiba.']);
        }
        $url = $request->validate(['permalink' => ['required', 'url:https', 'max:255']])['permalink'];
        $this->service->markPublished($target, $url);

        return back()->with('status', $target->platformLabel().' ditandai sudah terbit.');
    }

    /** @return array{0:array,1:array} data tervalidasi + file upload baru */
    private function validated(Request $request, ?ContentPost $post): array
    {
        $platforms = array_keys(config('content.platforms'));
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:150'], // opsional: kosong = diambil dari caption
            'type' => ['required', Rule::in(array_keys(ContentPost::TYPES))],
            'intent' => ['required', Rule::in(['draft', 'publish'])],
            'caption' => ['nullable', 'string', 'max:63206'],
            'platforms' => ['array'],
            'platforms.*' => [Rule::in($platforms)],
            'captions' => ['array'],
            'captions.*' => ['nullable', 'string', 'max:63206'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'creator_note' => ['nullable', 'string', 'max:2000'],
            'tiktok' => ['nullable', 'array'],
            'tiktok.privacy_level' => ['nullable', Rule::in(array_keys(TikTokContentClient::PRIVACY_LABELS))],
            'tiktok.allow_comment' => ['nullable', 'boolean'],
            'tiktok.allow_duet' => ['nullable', 'boolean'],
            'tiktok.allow_stitch' => ['nullable', 'boolean'],
            'tiktok.brand_organic' => ['nullable', 'boolean'],
            'tiktok.disclose' => ['nullable', 'boolean'],
            'tiktok.brand_content' => ['nullable', 'boolean'],
            'media' => ['array', 'max:'.config('content.carousel_max')],
            'media.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/x-m4v,application/mp4', 'max:'.config('content.video_max_kb')],
        ], [
            'media.*.mimetypes' => 'Media harus JPG/PNG/WEBP atau video MP4/MOV.',
            'media.*.max' => 'File media terlalu besar (maks '.intdiv(config('content.video_max_kb'), 1024).' MB).',
            'scheduled_at.after' => 'Jadwal terbit harus di masa depan.',
        ]);

        $data['platforms'] = array_values(array_unique($data['platforms'] ?? []));
        $data['captions'] = array_intersect_key($data['captions'] ?? [], array_flip($data['platforms']));
        $files = $request->file('media', []);

        if ($data['intent'] === 'publish') {
            $media = $files !== [] ? ContentPostService::describeUploads($files) : ($post ? ContentPostService::describeStored($post) : []);
            $errors = ContentPostService::submitErrors($data['type'], $media, $data['caption'] ?? null, $data['platforms'], $data['captions']);
            if ($errors !== []) {
                throw ValidationException::withMessages(['content' => $errors]);
            }
            $tiktok = SocialConnection::for('tiktok');
            $tiktokApi = in_array('tiktok', $data['platforms'], true) && $tiktok?->isActive() && config('content.platforms.tiktok.mode') === 'auto';
            if ($tiktokApi && empty($data['tiktok']['privacy_level'])) {
                throw ValidationException::withMessages(['tiktok.privacy_level' => 'Pilih privasi TikTok sebelum menerbitkan.']);
            }
        }

        return [$data, $files];
    }

    private function afterSave(Request $request, ContentPost $post): RedirectResponse
    {
        if ($request->input('intent') === 'publish') {
            $this->service->publish($post->fresh(), $request->input('tiktok', []));

            $note = $post->targets()->where('platform', 'tiktok')->exists() ? ' TikTok butuh beberapa menit untuk memproses video sebelum muncul di profil.' : '';

            return redirect()->route('content.show', $post)->with('status', 'Konten masuk pipeline publikasi.'.$note);
        }

        return redirect()->route('content.edit', $post)->with('status', 'Draft tersimpan.');
    }

    private function authorizeView(Request $request, ContentPost $post): void
    {
        $u = $request->user();
        abort_unless(($post->user_id === $u->id && $u->canDo('content.create')) || $this->canManage($u), 403);
    }

    private function authorizeOwner(Request $request, ContentPost $post): void
    {
        abort_unless($post->user_id === $request->user()->id, 403);
    }

    private function authorizeOwnerEdit(Request $request, ContentPost $post): void
    {
        $this->authorizeOwner($request, $post);
        abort_unless($post->isEditable(), 403, 'Konten yang sudah mulai terbit tidak dapat diubah.');
    }

    private function authorizeTargetManagement(Request $request, ContentPostTarget $target): void
    {
        $target->loadMissing('post');
        $user = $request->user();
        $owns = $target->post->user_id === $user->id && $user->canDo('content.create');
        abort_unless(($owns && $user->canDo('content.publish.manage')) || $this->canManage($user), 403);
    }

    private function canManage(User $user): bool
    {
        return $user->isSuperAdmin() || $user->canDo('content.manage');
    }
}
