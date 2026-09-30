@extends('layouts.app')
@section('title', 'Kanban · '.$board->name)
@section('heading', 'Papan: '.$board->name)

@push('head')
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
@endpush

@section('content')
@php $u = auth()->user(); @endphp

<div class="flex flex-wrap items-center gap-3 mb-4">
    <a href="{{ route('kanban.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Semua papan</a>
    <details class="relative">
        <summary class="text-xs text-stone-500 cursor-pointer select-none hover:text-stone-800">ubah nama papan</summary>
        <form method="POST" action="{{ route('kanban.update', $board) }}" class="absolute z-20 mt-1 bg-white border border-stone-200 rounded-lg shadow-sm p-2 flex gap-1">
            @csrf @method('PUT')
            <input name="name" value="{{ $board->name }}" required maxlength="150" class="px-2 py-1 border border-stone-300 rounded-sm text-xs w-48">
            <button class="px-2 py-1 bg-stone-700 text-white rounded-sm text-xs">Simpan</button>
        </form>
    </details>
    <span class="ml-auto text-[11px] text-stone-400">Klik kartu = detail & komentar · geser kartu = pindah kolom (tersimpan otomatis).</span>
</div>

@if($errors->any())
    <p class="mb-4 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs">{{ $errors->first() }}</p>
@endif

{{-- Papan: kolom berdampingan, scroll horizontal seperti Trello. --}}
<div id="boardColumns" class="flex gap-4 items-start overflow-x-auto pb-4">
    @foreach($board->columns as $column)
        <div class="w-72 shrink-0 bg-stone-100 rounded-2xl border border-stone-200" data-column="{{ $column->id }}">
            <div class="px-4 py-3 flex items-center gap-2 cursor-grab" data-col-handle>
                <p class="font-bold text-stone-800 text-sm flex-1">{{ $column->name }}
                    <span class="font-normal text-stone-400">({{ $column->cards->count() }})</span>
                </p>
                <button type="button" data-dialog-open="addCardModal-{{ $column->id }}" data-no-drag aria-label="Tambah kartu di {{ $column->name }}" title="Tambah kartu" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border-0 bg-transparent p-0 text-stone-500 hover:bg-red-50 hover:text-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500">
                    <svg aria-hidden="true" class="block h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 20 20"><path stroke-linecap="round" d="M10 2v16M2 10h16"/></svg>
                </button>
                <details class="relative">
                    <summary data-no-drag aria-label="Opsi kolom {{ $column->name }}" class="grid h-7 w-7 list-none cursor-pointer place-items-center rounded-lg text-stone-400 hover:bg-white hover:text-stone-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500">
                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><circle cx="4" cy="10" r="1.35"/><circle cx="10" cy="10" r="1.35"/><circle cx="16" cy="10" r="1.35"/></svg>
                    </summary>
                    <div class="absolute right-0 z-20 mt-1 bg-white border border-stone-200 rounded-lg shadow-sm p-2 w-52 space-y-2">
                        <button type="button" data-dialog-open="editColumnModal-{{ $column->id }}" data-no-drag class="inline-flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-xs font-semibold text-stone-700 hover:bg-stone-50">
                            @include('kanban._icon', ['n' => 'pencil']) Edit kolom
                        </button>
                        <form method="POST" action="{{ route('kanban.columns.destroy', $column) }}"
                            onsubmit="return confirm('Hapus kolom {{ $column->name }}? (hanya bisa bila kosong)')">
                            @csrf @method('DELETE')
                            <button class="inline-flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-xs font-semibold text-rose-600 hover:bg-rose-50">
                                <svg aria-hidden="true" class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16m-10 4v6m4-6v6M5 7l1 13h12l1-13M9 7V4h6v3"/></svg>
                                Hapus kolom <span class="ml-auto text-[10px] font-normal text-stone-400">kosong saja</span>
                            </button>
                        </form>
                    </div>
                </details>
            </div>

            <div class="px-2 pb-2 space-y-2 min-h-10 max-h-[60vh] overflow-y-auto" data-cards data-column-id="{{ $column->id }}">
                @foreach($column->cards as $card)
                    @php
                        $overdue = $card->due_date && $card->due_date->isPast();
                        $atts = $card->attachments();
                    @endphp
                    {{-- Muka kartu ala Trello: judul + badge. Klik → modal detail. --}}
                    @php [$prioLabel, $prioCls] = \App\Models\BoardCard::PRIORITIES[$card->priority] ?? \App\Models\BoardCard::PRIORITIES['normal']; @endphp
                    <div role="button" tabindex="0" aria-haspopup="dialog" aria-label="Buka kartu: {{ $card->title }}" class="bg-white rounded-2xl border border-stone-200 shadow-xs p-4 cursor-grab hover:border-stone-300 hover:shadow-sm transition"
                        data-card="{{ $card->id }}" data-opens="cardModal-{{ $card->id }}">
                        <div class="flex items-start justify-between gap-2 mb-2">
                            <div class="flex flex-wrap items-center gap-1.5 min-w-0">
                                <span class="px-2.5 py-0.5 rounded-full border text-[10px] font-bold uppercase tracking-wide {{ $prioCls }}">{{ $prioLabel }}</span>
                                @if($card->fromAi())
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-50 text-red-700 text-[10px] font-bold" title="Kartu ini dibuat oleh Asisten AI">@include('kanban._icon', ['n' => 'sparkles', 'c' => 'w-3 h-3']) AI</span>
                                @endif
                            </div>
                            <form method="POST" action="{{ route('kanban.cards.destroy', $card) }}" class="shrink-0" onsubmit="event.stopPropagation(); return confirm('Hapus kartu ini?')">
                                @csrf @method('DELETE')
                                <button type="submit" data-card-delete aria-label="Hapus kartu {{ $card->title }}" title="Hapus kartu" onclick="event.stopPropagation()" class="inline-flex h-8 w-8 items-center justify-center rounded-lg border-0 bg-transparent p-0 text-stone-500 hover:bg-rose-50 hover:text-rose-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500">
                                    <svg aria-hidden="true" class="block h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16m-10 4v6m4-6v6M5 7l1 13h12l1-13M9 7V4h6v3"/></svg>
                                </button>
                            </form>
                        </div>
                        <p class="text-[15px] font-bold leading-snug text-stone-900">{{ $card->title }}</p>
                        @if(filled($card->description))
                            <p class="mt-1.5 text-xs leading-relaxed text-stone-600 line-clamp-3">{{ \Illuminate\Support\Str::limit(strip_tags($card->description), 180) }}</p>
                        @endif
                        <div class="flex flex-wrap items-center gap-1.5 mt-2.5 text-[11px]">
                            @if($card->due_date)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md border {{ $overdue ? 'border-rose-200 bg-rose-50 text-rose-700 font-bold' : 'border-stone-200 text-stone-600' }}" title="Deadline">@include('kanban._icon', ['n' => 'calendar', 'c' => 'w-3 h-3']){{ $card->due_date->format('Y-m-d') }}{{ $overdue ? ' · lewat' : '' }}</span>
                            @endif
                            <span class="px-2 py-0.5 rounded-md bg-stone-100 text-stone-600">Dibuat {{ $card->created_at->format('d M Y') }}</span>
                            @if($card->comments->count())<span class="inline-flex items-center gap-0.5 text-stone-500" title="komentar">@include('kanban._icon', ['n' => 'comment']){{ $card->comments->count() }}</span>@endif
                            @if($atts->count())<span class="inline-flex items-center gap-0.5 text-stone-500" title="ada lampiran">@include('kanban._icon', ['n' => 'image']){{ $atts->count() }}</span>@endif
                        </div>
                        @if($card->assignee || $card->creator)
                            <div class="flex items-center justify-between gap-2 mt-3 pt-2.5 border-t border-stone-100 text-[11px]">
                            @if($card->assignee)
                                <span class="flex items-center gap-1.5 min-w-0 text-stone-700">
                                    <span class="w-5 h-5 rounded-full bg-red-100 text-red-700 text-[10px] font-bold flex items-center justify-center shrink-0">{{ mb_strtoupper(mb_substr($card->assignee->fullname, 0, 1)) }}</span>
                                    <span class="truncate">{{ $card->assignee->fullname }}</span>
                                </span>
                            @else
                                <span class="text-stone-400">Belum ada PJ</span>
                            @endif
                            @if($card->creator)<span class="text-stone-400 truncate">oleh {{ $card->creator->fullname }}</span>@endif
                            </div>
                        @endif
                    </div>

                    {{-- Modal detail kartu (native <dialog> — tanpa library). --}}
                    <dialog id="cardModal-{{ $card->id }}" class="fixed top-1/2 left-1/2 m-0 -translate-x-1/2 -translate-y-1/2 rounded-2xl p-0 w-[min(92vw,32rem)] max-h-[90vh] max-h-[90dvh] overflow-y-auto overscroll-contain backdrop:bg-black/40 border border-stone-200">
                        <div class="p-5">
                            <div class="flex items-start justify-between gap-3 mb-3">
                                <p class="text-[10px] uppercase tracking-wide text-stone-400 font-semibold">Kolom: {{ $column->name }}</p>
                                <button type="button" onclick="this.closest('dialog').close()" class="grid h-8 w-8 shrink-0 place-items-center rounded-lg border-0 bg-transparent p-0 text-stone-500 hover:bg-stone-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500" aria-label="Tutup">@include('kanban._icon', ['n' => 'x', 'c' => 'w-4 h-4'])</button>
                            </div>

                            <form method="POST" action="{{ route('kanban.cards.update', $card) }}" class="space-y-3">
                                @csrf @method('PUT')
                                <label class="sr-only" for="card-title-{{ $card->id }}">Nama kartu</label>
                                <input id="card-title-{{ $card->id }}" name="title" value="{{ $card->title }}" required maxlength="255"
                                    class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm font-semibold">
                                <div>
                                    <label for="card-description-{{ $card->id }}" class="flex items-center gap-1 text-[11px] font-semibold text-stone-500 mb-1">@include('kanban._icon', ['n' => 'text']) Deskripsi</label>
                                    <textarea id="card-description-{{ $card->id }}" name="description" rows="3" maxlength="5000" placeholder="Rincian tugas…" class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">{{ $card->description }}</textarea>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div class="col-span-2">
                                        <label for="card-priority-{{ $card->id }}" class="flex items-center gap-1 text-[11px] font-semibold text-stone-500 mb-1">@include('kanban._icon', ['n' => 'flag']) Prioritas</label>
                                        <select id="card-priority-{{ $card->id }}" name="priority" class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                                            @foreach(\App\Models\BoardCard::PRIORITIES as $key => [$label])
                                                <option value="{{ $key }}" @selected($card->priority === $key)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label for="card-assignee-{{ $card->id }}" class="flex items-center gap-1 text-[11px] font-semibold text-stone-500 mb-1">@include('kanban._icon', ['n' => 'user']) Penanggung jawab</label>
                                        <select id="card-assignee-{{ $card->id }}" name="assignee_user_id" class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                                            <option value="">— pilih —</option>
                                            @foreach($assignees as $a)
                                                <option value="{{ $a->id }}" @selected($card->assignee_user_id === $a->id)>{{ $a->fullname }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label for="card-deadline-{{ $card->id }}" class="flex items-center gap-1 text-[11px] font-semibold text-stone-500 mb-1">@include('kanban._icon', ['n' => 'calendar']) Deadline</label>
                                        <input id="card-deadline-{{ $card->id }}" type="date" name="due_date" value="{{ $card->due_date?->format('Y-m-d') }}"
                                            class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                                    </div>
                                </div>
                                <button class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-semibold hover:bg-red-700">Simpan Kartu</button>
                            </form>

                            {{-- Lampiran gambar — mockup, tangkapan layar, referensi. Form
                                 terpisah dari form kartu di atas (input file butuh multipart &
                                 route sendiri; HTML tak boleh form bersarang). --}}
                            <div class="mt-5 pt-4 border-t border-stone-100">
                                <p class="flex items-center gap-1 text-[11px] font-semibold text-stone-500 mb-2">@include('kanban._icon', ['n' => 'image']) Lampiran ({{ $atts->count() }}/8)</p>
                                @if($atts->count())
                                    <div class="grid grid-cols-3 gap-2 mb-3">
                                        @foreach($atts as $att)
                                            <div class="relative group">
                                                <a href="{{ $att->url() }}" target="_blank" rel="noopener">
                                                    <img src="{{ $att->url() }}" alt="{{ $att->original_name }}" loading="lazy"
                                                        class="w-full h-24 object-cover rounded-lg border border-stone-200">
                                                </a>
                                                <form method="POST" action="{{ route('kanban.attachments.destroy', $att) }}"
                                                    onsubmit="return confirm('Hapus lampiran ini?')" class="absolute top-1 right-1">
                                                    @csrf @method('DELETE')
                                                    <button class="w-6 h-6 rounded-full bg-black/60 text-white flex items-center justify-center hover:bg-rose-600" title="Hapus lampiran" aria-label="Hapus lampiran">@include('kanban._icon', ['n' => 'x', 'c' => 'w-3.5 h-3.5'])</button>
                                                </form>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                @if($atts->count() < 8)
                                    {{-- Pilih & pratinjau dulu (tile "Tambah Foto" ala galeri), lalu
                                         unggah sekaligus. File dimasukkan ke input via JS (DataTransfer)
                                         sebelum form submit normal — server tetap terima images[]. --}}
                                    <form method="POST" action="{{ route('kanban.cards.attachments.store', $card) }}"
                                        enctype="multipart/form-data" data-attach-form data-remaining="{{ 8 - $atts->count() }}">
                                        @csrf
                                        <input type="file" name="images[]" accept="image/*" multiple class="hidden" data-attach-input>
                                        <div class="grid grid-cols-3 gap-2" data-attach-preview>
                                            {{-- Pratinjau file terpilih disisipkan JS sebelum tile ini. --}}
                                            <button type="button" data-attach-add
                                                class="flex flex-col items-center justify-center h-24 rounded-lg border-2 border-dashed border-stone-300 text-stone-400 hover:border-stone-400 hover:text-stone-600">
                                                <span class="text-2xl leading-none">+</span>
                                                <span class="text-[10px] mt-1">Tambah Foto</span>
                                            </button>
                                        </div>
                                        <div class="flex items-center gap-2 mt-2">
                                            <button data-attach-submit disabled
                                                class="px-3 py-2 bg-stone-700 text-white rounded-lg text-xs hover:bg-stone-800 disabled:opacity-40 disabled:cursor-not-allowed whitespace-nowrap">
                                                Unggah <span data-attach-count></span>
                                            </button>
                                            <span class="text-[10px] text-stone-400">sisa {{ 8 - $atts->count() }} slot · jpg/png/webp/gif · auto-perkecil 1280px · atau tempel <b>Ctrl+V</b> screenshot</span>
                                        </div>
                                    </form>
                                @else
                                    <p class="text-[10px] text-stone-400">Batas 8 lampiran tercapai — hapus salah satu untuk menambah.</p>
                                @endif
                            </div>

                            {{-- Thread komentar — kronologis, seperti Trello. --}}
                            <div class="mt-5 pt-4 border-t border-stone-100">
                                <p class="flex items-center gap-1 text-[11px] font-semibold text-stone-500 mb-2">@include('kanban._icon', ['n' => 'comment']) Komentar ({{ $card->comments->count() }})</p>
                                <div class="space-y-3 max-h-56 overflow-y-auto">
                                    @forelse($card->comments as $comment)
                                        <div class="text-sm">
                                            <div class="flex items-baseline gap-2">
                                                <span class="font-semibold text-stone-800 text-xs">{{ $comment->author->fullname ?? '(akun terhapus)' }}</span>
                                                <span class="text-[10px] text-stone-400">{{ $comment->created_at->format('d M Y H:i') }}</span>
                                                @if($u->isSuperAdmin() || $comment->user_id === $u->id)
                                                    <form method="POST" action="{{ route('kanban.comments.destroy', $comment) }}" class="ml-auto"
                                                        onsubmit="return confirm('Hapus komentar ini?')">
                                                        @csrf @method('DELETE')
                                                        <button class="text-[10px] text-stone-300 hover:text-rose-600">hapus</button>
                                                    </form>
                                                @endif
                                            </div>
                                            <p class="text-xs text-stone-600 wrap-break-word">{!! $comment->bodyHtml() !!}</p>
                                        </div>
                                    @empty
                                        <p class="text-xs text-stone-300">Belum ada komentar.</p>
                                    @endforelse
                                </div>
                                <form method="POST" action="{{ route('kanban.comments.store', $card) }}" class="mt-3 flex gap-2">
                                    @csrf
                                    <input name="body" required maxlength="3000" placeholder="tulis komentar…"
                                        class="flex-1 px-3 py-2 border border-stone-300 rounded-lg text-sm">
                                    <button class="px-3 py-2 bg-stone-700 text-white rounded-lg text-sm hover:bg-stone-800">Kirim</button>
                                </form>
                            </div>

                        </div>
                    </dialog>
                @endforeach
            </div>

            <dialog id="editColumnModal-{{ $column->id }}" aria-labelledby="editColumnTitle-{{ $column->id }}" class="fixed top-1/2 left-1/2 m-0 -translate-x-1/2 -translate-y-1/2 w-[min(92vw,28rem)] max-h-[90dvh] overflow-y-auto rounded-2xl border border-stone-200 bg-white p-0 shadow-xl backdrop:bg-stone-950/40">
                <div class="p-5 sm:p-6">
                    <div class="mb-5 flex items-start justify-between gap-4">
                        <div><h2 id="editColumnTitle-{{ $column->id }}" class="text-lg font-bold text-stone-900">Edit kolom</h2><p class="mt-1 text-xs text-stone-500">Ubah nama tahap pada papan ini.</p></div>
                        <button type="button" data-dialog-close aria-label="Tutup" class="grid h-8 w-8 shrink-0 place-items-center rounded-lg border-0 bg-transparent p-0 text-stone-500 hover:bg-stone-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500">@include('kanban._icon', ['n' => 'x', 'c' => 'w-4 h-4'])</button>
                    </div>
                    <form method="POST" action="{{ route('kanban.columns.update', $column) }}" class="space-y-4">
                        @csrf @method('PUT')
                        <label class="block text-xs font-semibold text-stone-700">Nama kolom<input name="name" value="{{ $column->name }}" required maxlength="100" class="mt-1.5 w-full rounded-lg border border-stone-300 px-3 py-2.5 text-sm focus:border-red-500 focus:outline-none focus:ring-2 focus:ring-red-100"></label>
                        <div class="flex justify-end gap-2 border-t border-stone-100 pt-4">
                            <button type="button" data-dialog-close class="rounded-lg px-4 py-2 text-xs font-semibold text-stone-600 hover:bg-stone-100">Batal</button>
                            <button class="rounded-lg bg-red-700 px-4 py-2 text-xs font-semibold text-white hover:bg-red-800">Simpan perubahan</button>
                        </div>
                    </form>
                </div>
            </dialog>

            <dialog id="addCardModal-{{ $column->id }}" aria-labelledby="addCardTitle-{{ $column->id }}" data-card-composer data-draft-url="{{ route('kanban.cards.draft', $column) }}" class="fixed top-1/2 left-1/2 m-0 -translate-x-1/2 -translate-y-1/2 w-[min(92vw,38rem)] max-h-[90dvh] overflow-y-auto rounded-2xl border border-stone-200 bg-white p-0 shadow-xl backdrop:bg-stone-950/40">
                <div class="p-5 sm:p-6">
                    <div class="mb-5 flex items-start justify-between gap-4">
                        <div><p class="text-[10px] font-semibold uppercase tracking-[.12em] text-red-700">{{ $column->name }}</p><h2 id="addCardTitle-{{ $column->id }}" class="mt-1 text-lg font-bold text-stone-900">Tambah kartu</h2><p class="mt-1 text-xs text-stone-500">Buat tugas manual atau susun draft dengan Agent AI.</p></div>
                        <button type="button" data-dialog-close aria-label="Tutup" class="grid h-8 w-8 shrink-0 place-items-center rounded-lg border-0 bg-transparent p-0 text-stone-500 hover:bg-stone-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500">@include('kanban._icon', ['n' => 'x', 'c' => 'w-4 h-4'])</button>
                    </div>
                    <div role="group" aria-label="Cara membuat kartu" class="mb-5 grid grid-cols-2 gap-1 rounded-xl bg-stone-100 p-1">
                        <button type="button" data-card-mode="manual" aria-pressed="true" class="rounded-lg px-3 py-2 text-xs font-semibold bg-white text-stone-900 shadow-xs">Manual</button>
                        <button type="button" data-card-mode="agent" aria-pressed="false" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold text-stone-600 hover:text-red-700">
                            @include('kanban._icon', ['n' => 'sparkles']) Agent AI
                        </button>
                    </div>

                    <form method="POST" action="{{ route('kanban.cards.store', $column) }}" data-card-manual class="space-y-4">@csrf
                        <label class="block text-xs font-semibold text-stone-700">Judul tugas<input name="title" required maxlength="255" placeholder="Contoh: Siapkan materi promo Oktober" class="mt-1.5 w-full rounded-lg border border-stone-300 px-3 py-2.5 text-sm focus:border-red-500 focus:outline-none focus:ring-2 focus:ring-red-100"></label>
                        <label class="block text-xs font-semibold text-stone-700">Deskripsi <span class="font-normal text-stone-400">opsional</span><textarea name="description" rows="3" maxlength="5000" placeholder="Tambahkan konteks atau hasil yang diharapkan" class="mt-1.5 w-full resize-y rounded-lg border border-stone-300 px-3 py-2.5 text-sm focus:border-red-500 focus:outline-none focus:ring-2 focus:ring-red-100"></textarea></label>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <label class="block text-xs font-semibold text-stone-700">Penanggung jawab<select name="assignee_user_id" class="mt-1.5 w-full rounded-lg border border-stone-300 bg-white px-3 py-2.5 text-sm"><option value="">Belum ditentukan</option>@foreach($assignees as $assignee)<option value="{{ $assignee->id }}">{{ $assignee->fullname }}</option>@endforeach</select></label>
                            <label class="block text-xs font-semibold text-stone-700">Tenggat<input type="date" name="due_date" class="mt-1.5 w-full rounded-lg border border-stone-300 px-3 py-2.5 text-sm"></label>
                            <label class="block text-xs font-semibold text-stone-700">Prioritas<select name="priority" class="mt-1.5 w-full rounded-lg border border-stone-300 bg-white px-3 py-2.5 text-sm">@foreach(\App\Models\BoardCard::PRIORITIES as $key => [$label])<option value="{{ $key }}" @selected($key === 'normal')>{{ $label }}</option>@endforeach</select></label>
                        </div>
                        <div class="flex justify-end gap-2 border-t border-stone-100 pt-4">
                            <button type="button" data-dialog-close class="rounded-lg px-4 py-2 text-xs font-semibold text-stone-600 hover:bg-stone-100">Batal</button>
                            <button class="inline-flex items-center gap-2 rounded-lg bg-red-700 px-4 py-2 text-xs font-semibold text-white hover:bg-red-800">@include('kanban._icon', ['n' => 'sparkles']) Buat kartu</button>
                        </div>
                    </form>

                    <section data-card-agent hidden class="space-y-4">
                        <label class="block text-xs font-semibold text-stone-700">Tugas apa yang mau diberikan?<textarea rows="4" maxlength="2000" data-agent-prompt placeholder="Jelaskan pekerjaan, hasil yang diharapkan, tenggat, atau penanggung jawab…" class="mt-1.5 w-full resize-y rounded-lg border border-stone-300 px-3 py-2.5 text-sm focus:border-red-500 focus:outline-none focus:ring-2 focus:ring-red-100"></textarea></label>
                        <button type="button" data-agent-generate class="inline-flex items-center gap-2 rounded-lg bg-red-700 px-4 py-2.5 text-xs font-semibold text-white hover:bg-red-800 disabled:cursor-wait disabled:opacity-60">
                            @include('kanban._icon', ['n' => 'sparkles']) <span data-agent-button-label>Buat draft dengan AI</span>
                        </button>
                        <p data-agent-error role="alert" class="hidden rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-xs text-red-700"></p>
                        <form method="POST" action="{{ route('kanban.cards.store', $column) }}" data-agent-review hidden class="space-y-4 border-t border-stone-200 pt-4">@csrf
                            <input type="hidden" name="ai_draft" value="1">
                            <h3 class="text-sm font-bold text-stone-800">Periksa draft</h3>
                            <label class="block text-xs font-semibold text-stone-700">Judul tugas<input name="title" required maxlength="255" class="mt-1.5 w-full rounded-lg border border-stone-300 px-3 py-2.5 text-sm"></label>
                            <label class="block text-xs font-semibold text-stone-700">Deskripsi<textarea name="description" rows="3" maxlength="5000" class="mt-1.5 w-full rounded-lg border border-stone-300 px-3 py-2.5 text-sm"></textarea></label>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <label class="block text-xs font-semibold text-stone-700">Penanggung jawab<select name="assignee_user_id" class="mt-1.5 w-full rounded-lg border border-stone-300 bg-white px-3 py-2.5 text-sm"><option value="">Belum ditentukan</option>@foreach($assignees as $assignee)<option value="{{ $assignee->id }}">{{ $assignee->fullname }}</option>@endforeach</select></label>
                                <label class="block text-xs font-semibold text-stone-700">Tenggat<input type="date" name="due_date" class="mt-1.5 w-full rounded-lg border border-stone-300 px-3 py-2.5 text-sm"></label>
                                <label class="block text-xs font-semibold text-stone-700">Prioritas<select name="priority" class="mt-1.5 w-full rounded-lg border border-stone-300 bg-white px-3 py-2.5 text-sm">@foreach(\App\Models\BoardCard::PRIORITIES as $key => [$label])<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                            </div>
                            <div class="flex justify-end gap-2 border-t border-stone-100 pt-4">
                                <button type="button" data-dialog-close class="rounded-lg px-4 py-2 text-xs font-semibold text-stone-600 hover:bg-stone-100">Batal</button>
                                <button class="inline-flex items-center gap-2 rounded-lg bg-red-700 px-4 py-2 text-xs font-semibold text-white hover:bg-red-800">@include('kanban._icon', ['n' => 'sparkles']) Buat kartu</button>
                            </div>
                        </form>
                    </section>
                </div>
            </dialog>
        </div>
    @endforeach

    {{-- Tambah kolom --}}
    <form method="POST" action="{{ route('kanban.columns.store', $board) }}" class="w-64 shrink-0">@csrf
        <input name="name" required maxlength="100" placeholder="+ tambah kolom…"
            class="w-full px-3 py-2.5 bg-stone-100 border border-dashed border-stone-300 rounded-2xl text-sm placeholder-stone-400 focus:bg-white">
    </form>
</div>

{{-- KPI per anggota (di bawah papan) --}}
<div class="mt-8 bg-white rounded-2xl border border-stone-200 p-5">
    <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
        <h3 class="text-base font-bold text-stone-900">Statistik / KPI Anggota</h3>
        <span class="text-xs text-stone-400">Selesai = kartu masuk kolom Done · Telat = lewat deadline</span>
    </div>

    @if(count($kpi['rows']))
        <div class="grid lg:grid-cols-[auto_1fr] gap-6 items-start">
            <div class="overflow-x-auto">
                <table class="text-sm whitespace-nowrap">
                    <thead class="text-stone-500 uppercase text-xs border-b border-stone-100">
                        <tr>
                            <th class="text-left py-2 pr-4">Anggota</th>
                            <th class="text-right px-3">Total</th>
                            <th class="text-right px-3">Selesai</th>
                            <th class="text-right px-3">Berjalan</th>
                            <th class="text-right px-3">Telat</th>
                            <th class="text-left px-3 w-32">Skor</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($kpi['rows'] as $r)
                            <tr class="border-b border-stone-50">
                                <td class="py-2 pr-4 font-semibold text-stone-700">{{ $r['nama'] }}</td>
                                <td class="text-right px-3 text-stone-600">{{ $r['total'] }}</td>
                                <td class="text-right px-3 text-emerald-700 font-semibold">{{ $r['selesai'] }}</td>
                                <td class="text-right px-3 text-amber-700">{{ $r['berjalan'] }}</td>
                                <td class="text-right px-3 {{ $r['telat'] ? 'text-rose-700 font-bold' : 'text-stone-400' }}">{{ $r['telat'] }}</td>
                                <td class="px-3">
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 h-2 bg-stone-100 rounded-full overflow-hidden"><div class="h-full bg-emerald-500" style="width: {{ $r['skor'] }}%"></div></div>
                                        <span class="text-[11px] font-bold text-stone-600 w-10 text-right">{{ $r['skor'] }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="h-56"><canvas id="kpiChart"></canvas></div>
        </div>
    @else
        <p class="text-xs text-stone-400 py-4 text-center">Belum ada kartu. KPI dikelompokkan dari nama kolom (mis. "To Do List Budi" / "Done Budi" → Budi).</p>
    @endif

    <p class="text-[11px] text-stone-400 mt-3">Dihitung per <b>nama kolom</b> ("To Do List X" / "Done X" → X). Selesai = kartu di kolom Done orang itu.</p>
</div>

@if(count($kpi['rows']))
<script>
(function () {
    const el = document.getElementById('kpiChart');
    if (!el || !window.Chart) return;
    const KPI = {{ \Illuminate\Support\Js::from($kpiChart) }};
    new Chart(el, {
        type: 'bar',
        data: {
            labels: KPI.labels,
            datasets: [
                { label: 'Selesai', data: KPI.selesai, backgroundColor: '#10b981' },
                { label: 'Berjalan', data: KPI.berjalan, backgroundColor: '#f59e0b' },
                { label: 'Telat', data: KPI.telat, backgroundColor: '#ef4444' },
            ],
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { precision: 0, font: { size: 12 } } }, x: { ticks: { font: { size: 12 } } } },
            plugins: { legend: { position: 'bottom', labels: { font: { size: 12 } } } },
        },
    });
})();
</script>
@endif

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const post = (url, body) => fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
    body: JSON.stringify(body),
}).then(r => { if (!r.ok) { alert('Gagal menyimpan perpindahan — muat ulang halaman.'); location.reload(); } });

document.querySelectorAll('[data-dialog-open]').forEach(button => {
    button.addEventListener('click', () => {
        button.closest('details')?.removeAttribute('open');
        document.getElementById(button.dataset.dialogOpen)?.showModal();
    });
});
document.querySelectorAll('[data-dialog-close]').forEach(button => {
    button.addEventListener('click', () => button.closest('dialog')?.close());
});

document.querySelectorAll('[data-card-composer]').forEach(composer => {
    const manual = composer.querySelector('[data-card-manual]');
    const agent = composer.querySelector('[data-card-agent]');
    const review = composer.querySelector('[data-agent-review]');
    const prompt = composer.querySelector('[data-agent-prompt]');
    const error = composer.querySelector('[data-agent-error]');
    const generate = composer.querySelector('[data-agent-generate]');
    const label = composer.querySelector('[data-agent-button-label]');
    const modes = composer.querySelectorAll('[data-card-mode]');

    const setMode = mode => {
        const useAgent = mode === 'agent';
        manual.hidden = useAgent;
        agent.hidden = !useAgent;
        modes.forEach(button => {
            const selected = button.dataset.cardMode === mode;
            button.setAttribute('aria-pressed', String(selected));
            button.classList.toggle('bg-white', selected && !useAgent);
            button.classList.toggle('text-stone-900', selected && !useAgent);
            button.classList.toggle('shadow-xs', selected && !useAgent);
            button.classList.toggle('bg-red-700', selected && useAgent);
            button.classList.toggle('text-white', selected && useAgent);
            button.classList.toggle('text-stone-600', !selected);
        });
        if (useAgent) prompt.focus();
    };
    modes.forEach(button => button.addEventListener('click', () => setMode(button.dataset.cardMode)));

    generate.addEventListener('click', async () => {
        error.textContent = '';
        error.classList.add('hidden');
        if (!prompt.value.trim()) {
            error.textContent = 'Jelaskan tugas yang ingin dibuat terlebih dahulu.';
            error.classList.remove('hidden');
            prompt.focus();
            return;
        }

        generate.disabled = true;
        label.textContent = 'Menyusun draft…';
        try {
            const response = await fetch(composer.dataset.draftUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: JSON.stringify({ prompt: prompt.value.trim() }),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.errors?.prompt?.[0] || result.message || 'Draft gagal dibuat. Coba lagi.');
            for (const [name, value] of Object.entries(result.draft)) {
                const field = review.elements.namedItem(name);
                if (field) field.value = value ?? '';
            }
            review.hidden = false;
            review.querySelector('[name="title"]').focus();
        } catch (failure) {
            error.textContent = failure.message || 'Draft gagal dibuat. Coba lagi.';
            error.classList.remove('hidden');
        } finally {
            generate.disabled = false;
            label.textContent = 'Buat draft dengan AI';
        }
    });
});

// Klik kartu buka modal — tapi JANGAN saat kartu baru saja di-drag.
let justDragged = false;
document.querySelectorAll('[data-opens]').forEach(el => {
    el.addEventListener('click', () => {
        if (justDragged) return;
        const dlg = document.getElementById(el.dataset.opens);
        dlg.showModal();
    });
    el.addEventListener('keydown', event => {
        if (event.target !== el) return;
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        el.click();
    });
});

// Drag kartu antar/dalam kolom.
document.querySelectorAll('[data-cards]').forEach(el => {
    new Sortable(el, {
        group: 'cards',
        animation: 150,
        draggable: '[data-card]',
        filter: '[data-card-delete], form, button',
        preventOnFilter: false,
        onStart: () => { justDragged = true; },
        onEnd: (evt) => {
            setTimeout(() => { justDragged = false; }, 150);
            const cardId = evt.item.dataset.card;
            const target = evt.to;
            const orderedIds = [...target.querySelectorAll('[data-card]')].map(c => c.dataset.card);
            post(`{{ url('/kanban-cards') }}/${cardId}/move`, {
                column_id: parseInt(target.dataset.columnId),
                ordered_ids: orderedIds,
            });
        },
    });
});

// Drag urutan kolom (pegang header-nya).
new Sortable(document.getElementById('boardColumns'), {
    animation: 150,
    handle: '[data-col-handle]',
    draggable: '[data-column]',
    filter: '[data-no-drag], button, summary, input, form',
    preventOnFilter: false,
    onEnd: () => {
        const ids = [...document.querySelectorAll('[data-column]')].map(c => c.dataset.column);
        post(`{{ route('kanban.columns.reorder', $board) }}`, { ordered_ids: ids });
    },
});

// Lampiran ala "Tambah Foto": klik tile -> pilih file -> pratinjau (bisa dibuang
// buang) -> "Unggah" sekali untuk semua. File dimasukkan ke input lewat
// DataTransfer sebelum form submit normal, jadi server tetap terima images[].
document.querySelectorAll('[data-attach-form]').forEach(form => {
    const input   = form.querySelector('[data-attach-input]');
    const grid    = form.querySelector('[data-attach-preview]');
    const addTile = form.querySelector('[data-attach-add]');
    const submit  = form.querySelector('[data-attach-submit]');
    const countEl = form.querySelector('[data-attach-count]');
    const remaining = parseInt(form.dataset.remaining) || 0;
    let pending = [];   // File[] yang menunggu diunggah

    const render = () => {
        grid.querySelectorAll('[data-preview-tile]').forEach(t => {
            URL.revokeObjectURL(t.querySelector('img').src);
            t.remove();
        });
        pending.forEach((file, i) => {
            const tile = document.createElement('div');
            tile.dataset.previewTile = '';
            tile.className = 'relative h-24 rounded-lg border border-stone-200 overflow-hidden';
            const img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            img.className = 'w-full h-full object-cover';
            const x = document.createElement('button');
            x.type = 'button';
            x.innerHTML = @json(view('kanban._icon', ['n' => 'x', 'c' => 'w-3.5 h-3.5'])->render());
            x.title = 'Buang';
            x.setAttribute('aria-label', 'Buang');
            x.className = 'absolute top-1 right-1 w-6 h-6 rounded-full bg-black/60 text-white flex items-center justify-center hover:bg-rose-600';
            x.addEventListener('click', () => { pending.splice(i, 1); render(); });
            tile.append(img, x);
            grid.insertBefore(tile, addTile);
        });
        addTile.style.display = pending.length >= remaining ? 'none' : '';
        countEl.textContent = pending.length ? `${pending.length} foto` : '';
        submit.disabled = pending.length === 0;
    };

    // Tambah 1 gambar ke antrean (dipakai input file & tempel/paste clipboard).
    const addFile = (file) => {
        if (!file || !file.type.startsWith('image/')) return false;
        if (pending.length >= remaining) { alert(`Maksimal ${remaining} foto lagi untuk kartu ini.`); return false; }
        pending.push(file);
        return true;
    };
    // Dipakai handler paste: tambah gambar dari clipboard lalu refresh pratinjau.
    form.addPastedImage = (file) => { const ok = addFile(file); if (ok) render(); return ok; };

    addTile.addEventListener('click', () => input.click());

    input.addEventListener('change', () => {
        for (const file of input.files) {
            if (!file.type.startsWith('image/')) { alert(`"${file.name}" bukan gambar — dilewati.`); continue; }
            if (!addFile(file)) break;
        }
        input.value = '';   // reset agar file sama bisa dipilih lagi; kita kontrol via DataTransfer
        render();
    });

    form.addEventListener('submit', (e) => {
        if (pending.length === 0) { e.preventDefault(); return; }
        const dt = new DataTransfer();
        pending.forEach(f => dt.items.add(f));
        input.files = dt.files;   // isi input dengan file pending sebelum submit
    });
});

// Tempel (Ctrl+V) screenshot langsung jadi Lampiran kartu yang sedang dibuka.
// Cukup ambil gambar dari clipboard; paste teks (mis. ke kolom komentar) lewat
// begitu saja karena tak ada item gambar.
document.addEventListener('paste', (e) => {
    const dlg = document.querySelector('dialog[open]');
    if (!dlg) return;
    const form = dlg.querySelector('[data-attach-form]');
    if (!form || !form.addPastedImage) return;   // kartu penuh 8/8 → form tak ada
    let added = false;
    for (const it of (e.clipboardData && e.clipboardData.items) || []) {
        if (it.kind === 'file' && it.type.startsWith('image/')) {
            const file = it.getAsFile();
            if (file && form.addPastedImage(file)) added = true;
        }
    }
    if (added) e.preventDefault();   // jangan biarkan gambar coba masuk ke textarea
});
</script>
@endsection
