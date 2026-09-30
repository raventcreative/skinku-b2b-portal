{{-- Reusable content pipeline rows. Receives $posts and $canManage. --}}
@if($posts->isEmpty())
    <div class="px-6 py-16 text-center">
        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-red-50 text-red-700">
            <svg aria-hidden="true" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6.75A2.75 2.75 0 0 1 6.75 4h10.5A2.75 2.75 0 0 1 20 6.75v10.5A2.75 2.75 0 0 1 17.25 20H6.75A2.75 2.75 0 0 1 4 17.25V6.75Z"/><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15 4.25-4.25a1.75 1.75 0 0 1 2.475 0L15 14.5m-1.5-1.5 1.275-1.275a1.75 1.75 0 0 1 2.475 0L20 14.5M9 8.5h.01"/></svg>
        </span>
        <p class="mt-4 text-sm font-semibold text-stone-800">Belum ada konten di tahap ini</p>
        <p class="mt-1 text-xs text-stone-500">Unggah materi lalu pilih simpan draft atau jadwalkan publikasi.</p>
        <a href="{{ route('content.create') }}" class="mt-5 inline-flex min-h-10 items-center gap-2 rounded-lg bg-red-700 px-4 text-sm font-semibold text-white hover:bg-red-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700">
            <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 20 20"><path stroke-linecap="round" d="M10 4v12M4 10h12"/></svg>Buat konten
        </a>
    </div>
@else
    <div class="divide-y divide-stone-100">
        @foreach($posts as $post)
            @php
                $thumb = $post->files->firstWhere('collection', \App\Models\ContentPost::MEDIA);
                $targetAttention = $post->targets->contains(fn ($target) => in_array($target->status, ['failed', 'manual_pending'], true));
            @endphp
            <article class="group grid gap-3 px-4 py-4 transition-colors hover:bg-stone-50/70 sm:px-5 lg:grid-cols-[minmax(0,1fr)_12rem_12rem_auto] lg:items-center lg:gap-5">
                <div class="flex min-w-0 items-center gap-3">
                    @if($thumb)
                        @if($thumb->isImage())<img src="{{ $thumb->url() }}" alt="" class="h-14 w-14 shrink-0 rounded-xl border border-stone-200 object-cover">@else
                            <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl border border-stone-200 bg-stone-100 text-stone-500"><svg aria-hidden="true" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="m10 9 5 3-5 3V9Z"/></svg></span>
                        @endif
                    @else
                        <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl border border-stone-200 bg-stone-50 text-stone-400"><svg aria-hidden="true" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6.75A2.75 2.75 0 0 1 6.75 4h10.5A2.75 2.75 0 0 1 20 6.75v10.5A2.75 2.75 0 0 1 17.25 20H6.75A2.75 2.75 0 0 1 4 17.25V6.75Z"/><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15 4.25-4.25a1.75 1.75 0 0 1 2.475 0L15 14.5m-1.5-1.5 1.275-1.275a1.75 1.75 0 0 1 2.475 0L20 14.5"/></svg></span>
                    @endif
                    <div class="min-w-0">
                        <a href="{{ route('content.show', $post) }}" class="block truncate text-sm font-semibold text-stone-900 hover:text-red-700">{{ $post->title }}</a>
                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-stone-500">
                            <span>{{ \App\Models\ContentPost::TYPES[$post->type] ?? $post->type }}</span>
                            <span aria-hidden="true" class="text-stone-300">·</span>
                            <span>{{ $post->scheduled_at?->format('d M Y, H:i') ?? 'Tanpa jadwal' }}</span>
                            @if($canManage)<span aria-hidden="true" class="text-stone-300">·</span><span>{{ $post->user?->displayName() ?? '—' }}</span>@endif
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-1.5 pl-[4.25rem] lg:pl-0">
                    @foreach($post->targets as $target)
                        <span class="inline-flex items-center rounded-md border border-stone-200 bg-white px-2 py-1 text-[10px] font-medium text-stone-600">{{ $target->platformLabel() }}</span>
                    @endforeach
                </div>

                <div class="pl-[4.25rem] lg:pl-0">
                    @include('content._badge', ['status' => $post->status, 'label' => $post->statusLabel()])
                    @if($targetAttention && $post->status !== 'failed' && $post->status !== 'partial')
                        <span class="ml-1 text-[10px] font-semibold text-amber-700">Tindakan manual</span>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2 pl-[4.25rem] lg:justify-end lg:pl-0">
                    <a href="{{ route('content.show', $post) }}" class="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-stone-200 bg-white px-3 text-xs font-semibold text-stone-700 hover:border-stone-300 hover:bg-stone-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700">
                        <svg aria-hidden="true" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M1.8 10s2.8-5 8.2-5 8.2 5 8.2 5-2.8 5-8.2 5-8.2-5-8.2-5Z"/><circle cx="10" cy="10" r="2.2"/></svg>Lihat
                    </a>
                    @if($post->user_id === auth()->id() && $post->isEditable())
                        <a href="{{ route('content.edit', $post) }}" class="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-stone-200 bg-white px-3 text-xs font-semibold text-stone-700 hover:border-stone-300 hover:bg-stone-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700">
                            <svg aria-hidden="true" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="m12.5 3.5 4 4M4 16l1-4 8.5-8.5a1.4 1.4 0 0 1 2 2L7 14l-3 2Z"/></svg>Edit
                        </a>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
@endif
