<?php

namespace App\Http\Controllers;

use App\Models\ContentPost;
use App\Models\ContentPostTarget;
use App\Models\User;
use App\Services\ContentPostService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Review & publikasi konten semua creator (FR-31, FR-32, FR-55, FR-57, FR-72). */
class ContentReviewController extends Controller
{
    public function __construct(private ContentPostService $service) {}

    public function index(Request $request): View
    {
        $status = $request->query('status', ContentPost::IN_REVIEW);

        $posts = ContentPost::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->query('creator'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->query('platform'), fn ($q, $p) => $q->whereHas('targets', fn ($t) => $t->where('platform', $p)))
            ->when($request->query('dari'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->query('sampai'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->with(['targets', 'user'])
            ->orderByRaw('submitted_at is null, submitted_at asc')->latest('id')
            ->paginate(25)->withQueryString();

        return view('content.index', [
            'posts' => $posts,
            'mine' => false,
            'status' => $status,
            'creators' => User::whereIn('id', ContentPost::select('user_id'))->orderBy('fullname')->get(['id', 'fullname', 'username']),
            'manualCount' => ContentPostTarget::where('status', ContentPostTarget::MANUAL_PENDING)->count(),
            'failedCount' => ContentPostTarget::where('status', ContentPostTarget::FAILED)->count(),
        ]);
    }

    public function approve(Request $request, ContentPost $post): RedirectResponse
    {
        $edits = $request->validate([
            'caption' => ['nullable', 'string', 'max:63206'],
            'captions' => ['array'],
            'captions.*' => ['nullable', 'string', 'max:63206'],
            'scheduled_at' => ['nullable', 'date'],
        ]);
        $this->service->approve($post, $request->user(), $edits);

        return redirect()->route('content.show', $post)->with('status', 'Konten disetujui & masuk antrean terbit.');
    }

    public function reject(Request $request, ContentPost $post): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['required', 'string', 'min:5', 'max:2000']],
            ['review_note.required' => 'Alasan penolakan wajib diisi.', 'review_note.min' => 'Alasan penolakan minimal 5 karakter.']);
        $this->service->reject($post, $request->user(), $data['review_note']);

        return redirect()->route('content.show', $post)->with('status', 'Konten ditolak — creator akan melihat alasannya.');
    }

    public function retry(ContentPostTarget $target): RedirectResponse
    {
        $this->service->retry($target);

        return back()->with('status', $target->platformLabel().' masuk antrean terbit lagi.');
    }

    public function markPublished(Request $request, ContentPostTarget $target): RedirectResponse
    {
        $data = $request->validate(['permalink' => ['required', 'url:https', 'max:255']],
            ['permalink.*' => 'Tempel link postingan (https://...).']);
        $this->service->markPublished($target, $data['permalink']);

        return back()->with('status', $target->platformLabel().' ditandai sudah terbit.');
    }
}
