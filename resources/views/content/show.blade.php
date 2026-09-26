@extends('layouts.app')
@section('title', $post->title)
@section('heading', 'Detail Konten')

@section('content')
@php
    $u = auth()->user();
    $isOwner = $post->user_id === $u->id;
    $canReview = $u->canDo('content.review');
    $canPublish = $u->canDo('content.publish.manage');
    $actionLabels = [
        'content.create' => 'Dibuat', 'content.update' => 'Diubah', 'content.submit' => 'Diajukan untuk review',
        'content.withdraw' => 'Ditarik ke draft', 'content.approve' => 'Disetujui', 'content.reject' => 'Ditolak',
        'content.retry' => 'Retry publikasi', 'content.mark_published' => 'Ditandai terbit manual',
    ];
@endphp

<div class="grid lg:grid-cols-3 gap-5">
    <div class="lg:col-span-2 space-y-5">
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h2 class="text-lg font-bold text-stone-800">{{ $post->title }}</h2>
                    <p class="text-xs text-stone-500 mt-0.5">
                        {{ \App\Models\ContentPost::TYPES[$post->type] ?? $post->type }} · oleh {{ $post->user?->displayName() }}
                        · jadwal {{ $post->scheduled_at?->format('d M Y H:i') ?? 'secepatnya' }}
                    </p>
                </div>
                @include('content._badge', ['status' => $post->status, 'label' => $post->statusLabel()])
            </div>

            @if($post->review_note)
                <div class="mt-4 bg-rose-50 border border-rose-200 rounded-xl px-3 py-2 text-sm text-rose-800"><b>Catatan reviewer:</b> {{ $post->review_note }}</div>
            @endif
            @if($post->creator_note)
                <div class="mt-3 bg-stone-50 border border-stone-200 rounded-xl px-3 py-2 text-sm text-stone-700"><b>Catatan creator:</b> {{ $post->creator_note }}</div>
            @endif

            <div class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-3">
                @forelse($media as $f)
                    <div class="space-y-1">
                        @if($f->isImage())
                            <a href="{{ $f->url() }}" class="glightbox" data-gallery="content-{{ $post->id }}"><img src="{{ $f->url() }}" alt="" class="w-full aspect-square object-cover rounded-xl border border-stone-200"></a>
                        @else
                            <video src="{{ $f->url() }}" controls class="w-full aspect-square object-cover rounded-xl border border-stone-200 bg-black"></video>
                        @endif
                        <a href="{{ $f->url() }}" download class="text-[11px] font-semibold text-red-700 hover:underline">⬇ Download</a>
                    </div>
                @empty
                    <p class="text-sm text-stone-400">Belum ada media.</p>
                @endforelse
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <h3 class="px-5 py-3 border-b border-stone-100 text-sm font-bold text-stone-800">Status per platform</h3>
            <div class="divide-y divide-stone-100">
                @foreach($post->targets as $t)
                    <div class="px-5 py-4 space-y-2">
                        <div class="flex items-center justify-between gap-2 flex-wrap">
                            <p class="text-sm font-semibold text-stone-800">{{ $t->platformLabel() }}
                                @if($t->isManual())<span class="text-[10px] font-normal text-amber-700">(manual)</span>@endif</p>
                            <div class="flex items-center gap-2">
                                @if($t->permalink)<a href="{{ $t->permalink }}" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold text-red-700 hover:underline">Lihat postingan ↗</a>@endif
                                @include('content._badge', ['status' => $t->status, 'label' => $t->statusLabel()])
                            </div>
                        </div>
                        <div class="relative">
                            <p class="text-xs text-stone-600 whitespace-pre-line bg-stone-50 rounded-lg p-2 pr-16 wrap-break-word" data-caption>{{ $t->caption() }}</p>
                            <button type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent); this.textContent='Tersalin ✓'"
                                    class="absolute top-1.5 right-1.5 px-2 py-0.5 text-[10px] rounded-sm bg-white border border-stone-300 text-stone-600">Salin</button>
                        </div>
                        @if($t->attempts || $t->last_error)
                            <p class="text-[11px] text-rose-700">Percobaan {{ $t->attempts }}× @if($t->last_error)— {{ $t->last_error }}@endif
                                @if($t->next_attempt_at && $t->status === 'queued') · coba lagi {{ $t->next_attempt_at->diffForHumans() }}@endif</p>
                        @endif
                        @if($canPublish)
                            <div class="flex flex-wrap items-center gap-2">
                                @if($t->status === 'failed' && ! $t->isManual())
                                    <form method="POST" action="{{ route('content-targets.retry', $t) }}">@csrf
                                        <button class="px-3 py-1.5 text-xs bg-stone-800 text-white rounded-lg font-semibold">Retry</button>
                                    </form>
                                @endif
                                @if(in_array($t->status, ['manual_pending', 'failed'], true))
                                    <form method="POST" action="{{ route('content-targets.mark-published', $t) }}" class="flex flex-wrap gap-2">@csrf
                                        <input name="permalink" type="url" required placeholder="https://… link postingan {{ $t->platformLabel() }}" class="px-2 py-1.5 border border-stone-300 rounded-lg text-xs w-64">
                                        <button class="px-3 py-1.5 text-xs bg-emerald-600 text-white rounded-lg font-semibold">Tandai sudah terbit</button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="space-y-5">
        @if($isOwner && $post->isEditable())
            <div class="bg-white rounded-2xl border border-stone-200 p-5 flex flex-col gap-2">
                <form method="POST" action="{{ route('content.submit', $post) }}">@csrf
                    <button class="w-full px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold">Ajukan Review</button>
                </form>
                <a href="{{ route('content.edit', $post) }}" class="text-center px-5 py-2.5 text-sm border border-stone-300 text-stone-700 rounded-xl hover:bg-stone-50 font-semibold">Edit</a>
            </div>
        @elseif($isOwner && $post->status === 'in_review')
            <form method="POST" action="{{ route('content.withdraw', $post) }}" class="bg-white rounded-2xl border border-stone-200 p-5">@csrf
                <p class="text-xs text-stone-500 mb-2">Sedang direview. Perlu mengubah sesuatu?</p>
                <button class="w-full px-5 py-2.5 text-sm border border-stone-300 text-stone-700 rounded-xl hover:bg-stone-50 font-semibold">Tarik kembali ke draft</button>
            </form>
        @endif

        @if($canReview && $isOwner && $post->status === 'in_review')
            <p class="bg-amber-50 border border-amber-200 rounded-2xl p-4 text-xs text-amber-800">Ini konten kamu sendiri — review dilakukan kreator lain atau admin.</p>
        @endif
        @if($canReview && ! $isOwner && $post->status === 'in_review')
            <form method="POST" action="{{ route('content.approve', $post) }}" class="bg-white rounded-2xl border border-emerald-200 p-5 space-y-3">@csrf
                <p class="text-sm font-bold text-stone-800">Setujui</p>
                <label class="block">
                    <span class="text-[11px] font-semibold text-stone-600">Caption utama</span>
                    <textarea name="caption" rows="4" class="mt-1 block w-full px-2 py-1.5 border border-stone-300 rounded-lg text-xs">{{ old('caption', $post->caption) }}</textarea>
                </label>
                @foreach($post->targets as $t)
                    <label class="block">
                        <span class="text-[11px] font-semibold text-stone-600">Caption khusus {{ $t->platformLabel() }}</span>
                        <textarea name="captions[{{ $t->platform }}]" rows="2" class="mt-1 block w-full px-2 py-1.5 border border-stone-300 rounded-lg text-xs" placeholder="Kosong = caption utama">{{ old("captions.{$t->platform}", $t->caption_override) }}</textarea>
                    </label>
                @endforeach
                <label class="block">
                    <span class="text-[11px] font-semibold text-stone-600">Jadwal terbit</span>
                    <input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', $post->scheduled_at?->format('Y-m-d\TH:i')) }}" class="mt-1 block w-full px-2 py-1.5 border border-stone-300 rounded-lg text-xs">
                    <span class="text-[10px] text-stone-500">Kosong / sudah lewat = terbit di menit berikutnya.</span>
                </label>
                @if($tiktokInfo !== null)
                    {{-- Pedoman UX TikTok Content Posting API: tampilkan akun tujuan, privacy TANPA
                         default, toggle interaksi default mati (dan terkunci bila dimatikan kreator),
                         serta pernyataan Music Usage Confirmation. --}}
                    <div class="border border-stone-200 rounded-xl p-3 space-y-2">
                        <p class="text-xs font-bold text-stone-800">TikTok
                            @if(! empty($tiktokInfo['creator_nickname']))<span class="font-normal text-stone-500">— posting ke {{ $tiktokInfo['creator_nickname'] }}{{ ! empty($tiktokInfo['creator_username']) ? ' (@'.$tiktokInfo['creator_username'].')' : '' }}</span>@endif
                        </p>
                        @if(! empty($tiktokInfo['error']))
                            <p class="text-[11px] text-rose-700">Gagal membaca info akun TikTok: {{ $tiktokInfo['error'] }}</p>
                        @endif
                        @php
                            $privacyOptions = $tiktokInfo['privacy_level_options'] ?? array_keys(\App\Services\Social\TikTokContentClient::PRIVACY_LABELS);
                            $isVideo = $post->type === 'video';
                        @endphp
                        <label class="block">
                            <span class="text-[11px] font-semibold text-stone-600">Siapa yang bisa melihat? *</span>
                            <select name="tiktok[privacy_level]" required class="mt-1 block w-full px-2 py-1.5 border border-stone-300 rounded-lg text-xs">
                                <option value="" disabled @selected(! old('tiktok.privacy_level'))>— pilih —</option>
                                @foreach($privacyOptions as $opt)
                                    <option value="{{ $opt }}" @selected(old('tiktok.privacy_level') === $opt)>{{ \App\Services\Social\TikTokContentClient::PRIVACY_LABELS[$opt] ?? $opt }}</option>
                                @endforeach
                            </select>
                            <span class="text-[10px] text-stone-500">Selama app belum lolos audit TikTok, hanya "Hanya saya (private)" yang diterima.</span>
                        </label>
                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-stone-700">
                            <label class="flex items-center gap-1"><input type="checkbox" name="tiktok[allow_comment]" value="1" @disabled(! empty($tiktokInfo['comment_disabled']))> Izinkan komentar</label>
                            @if($isVideo)
                                <label class="flex items-center gap-1"><input type="checkbox" name="tiktok[allow_duet]" value="1" @disabled(! empty($tiktokInfo['duet_disabled']))> Duet</label>
                                <label class="flex items-center gap-1"><input type="checkbox" name="tiktok[allow_stitch]" value="1" @disabled(! empty($tiktokInfo['stitch_disabled']))> Stitch</label>
                                <label class="flex items-center gap-1"><input type="checkbox" name="tiktok[brand_organic]" value="1"> Konten promosi brand sendiri</label>
                            @endif
                        </div>
                        <label class="flex items-start gap-1.5 text-[11px] text-stone-700">
                            <input type="checkbox" name="tiktok[consent]" value="1" required class="mt-0.5">
                            <span>Dengan memposting, saya menyetujui <a href="https://www.tiktok.com/legal/page/global/music-usage-confirmation/en" target="_blank" rel="noopener noreferrer" class="underline">Music Usage Confirmation</a> TikTok. Setelah diposting, TikTok butuh beberapa menit untuk memproses konten.</span>
                        </label>
                    </div>
                @endif
                <button class="w-full px-5 py-2.5 text-sm bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 font-semibold">Setujui &amp; Jadwalkan</button>
            </form>

            <form method="POST" action="{{ route('content.reject', $post) }}" class="bg-white rounded-2xl border border-rose-200 p-5 space-y-3">@csrf
                <p class="text-sm font-bold text-stone-800">Tolak</p>
                <textarea name="review_note" rows="3" required minlength="5" placeholder="Alasan (wajib) — dibaca creator" class="block w-full px-2 py-1.5 border border-stone-300 rounded-lg text-xs">{{ old('review_note') }}</textarea>
                <button class="w-full px-5 py-2.5 text-sm bg-rose-600 text-white rounded-xl hover:bg-rose-700 font-semibold">Tolak</button>
            </form>
        @endif

        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <p class="text-sm font-bold text-stone-800 mb-3">Riwayat</p>
            <ol class="space-y-2 text-xs">
                @foreach($history as $h)
                    <li class="border-l-2 border-stone-200 pl-3">
                        <p class="font-semibold text-stone-700">{{ $actionLabels[$h->action] ?? $h->action }}</p>
                        <p class="text-stone-400">{{ $h->created_at?->format('d M Y H:i') }} · {{ $h->performed_by_email ?? 'sistem' }}</p>
                        @if($h->action === 'content.reject' && ! empty($h->after_data['note']))<p class="text-stone-500 italic">“{{ $h->after_data['note'] }}”</p>@endif
                    </li>
                @endforeach
                @foreach($post->targets->whereNotNull('published_at') as $t)
                    <li class="border-l-2 border-emerald-300 pl-3">
                        <p class="font-semibold text-emerald-700">Terbit di {{ $t->platformLabel() }}</p>
                        <p class="text-stone-400">{{ $t->published_at->format('d M Y H:i') }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </div>
</div>
@endsection
