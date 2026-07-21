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
        <form method="POST" action="{{ route('kanban.update', $board) }}" class="absolute z-20 mt-1 bg-white border border-stone-200 rounded-lg shadow p-2 flex gap-1">
            @csrf @method('PUT')
            <input name="name" value="{{ $board->name }}" required maxlength="150" class="px-2 py-1 border border-stone-300 rounded text-xs w-48">
            <button class="px-2 py-1 bg-stone-700 text-white rounded text-xs">Simpan</button>
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
                <details class="relative">
                    <summary class="text-stone-400 hover:text-stone-700 cursor-pointer select-none text-xs px-1">⋯</summary>
                    <div class="absolute right-0 z-20 mt-1 bg-white border border-stone-200 rounded-lg shadow p-2 w-52 space-y-2">
                        <form method="POST" action="{{ route('kanban.columns.update', $column) }}" class="flex gap-1">
                            @csrf @method('PUT')
                            <input name="name" value="{{ $column->name }}" required maxlength="100" class="flex-1 px-2 py-1 border border-stone-300 rounded text-xs">
                            <button class="px-2 py-1 bg-stone-700 text-white rounded text-xs">OK</button>
                        </form>
                        <form method="POST" action="{{ route('kanban.columns.destroy', $column) }}"
                            onsubmit="return confirm('Hapus kolom {{ $column->name }}? (hanya bisa bila kosong)')">
                            @csrf @method('DELETE')
                            <button class="w-full text-left text-[11px] text-rose-500 hover:text-rose-700">hapus kolom (harus kosong)</button>
                        </form>
                    </div>
                </details>
            </div>

            <div class="px-2 pb-2 space-y-2 min-h-[2.5rem]" data-cards data-column-id="{{ $column->id }}">
                @foreach($column->cards as $card)
                    @php
                        $overdue = $card->due_date && $card->due_date->isPast();
                        $atts = $card->attachments();
                    @endphp
                    {{-- Muka kartu ala Trello: judul + badge. Klik → modal detail. --}}
                    <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-3 cursor-grab hover:border-stone-300"
                        data-card="{{ $card->id }}" data-opens="cardModal-{{ $card->id }}">
                        <p class="text-sm font-semibold text-stone-800">{{ $card->title }}</p>
                        <div class="flex flex-wrap items-center gap-2 mt-1.5 text-[10px]">
                            @if($card->due_date)
                                <span class="px-1.5 py-0.5 rounded {{ $overdue ? 'bg-rose-100 text-rose-700 font-bold' : 'bg-stone-100 text-stone-500' }}">📅 {{ $card->due_date->format('d M') }}{{ $overdue ? ' — lewat!' : '' }}</span>
                            @endif
                            @if($card->description)<span class="text-stone-400" title="ada deskripsi">≡</span>@endif
                            @if($card->comments->count())<span class="text-stone-500">💬 {{ $card->comments->count() }}</span>@endif
                            @if($atts->count())<span class="text-stone-500" title="ada lampiran">🖼️ {{ $atts->count() }}</span>@endif
                            @if($card->assignee)
                                <span class="ml-auto px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-700 font-semibold">{{ $card->assignee->fullname }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- Modal detail kartu (native <dialog> — tanpa library). --}}
                    <dialog id="cardModal-{{ $card->id }}" class="rounded-2xl p-0 w-[92vw] max-w-lg backdrop:bg-black/40 border border-stone-200">
                        <div class="p-5">
                            <div class="flex items-start justify-between gap-3 mb-3">
                                <p class="text-[10px] uppercase tracking-wide text-stone-400 font-semibold">Kolom: {{ $column->name }}</p>
                                <button type="button" onclick="this.closest('dialog').close()" class="text-stone-400 hover:text-stone-700 text-lg leading-none">✕</button>
                            </div>

                            <form method="POST" action="{{ route('kanban.cards.update', $card) }}" class="space-y-3">
                                @csrf @method('PUT')
                                <input name="title" value="{{ $card->title }}" required maxlength="255"
                                    class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm font-semibold">
                                <div>
                                    <label class="block text-[11px] font-semibold text-stone-500 mb-1">≡ Deskripsi</label>
                                    <textarea name="description" rows="3" maxlength="5000" placeholder="rincian tugas…"
                                        class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">{{ $card->description }}</textarea>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[11px] font-semibold text-stone-500 mb-1">👤 Penanggung jawab</label>
                                        <select name="assignee_user_id" class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                                            <option value="">— pilih —</option>
                                            @foreach($assignees as $a)
                                                <option value="{{ $a->id }}" @selected($card->assignee_user_id === $a->id)>{{ $a->fullname }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-semibold text-stone-500 mb-1">📅 Deadline</label>
                                        <input type="date" name="due_date" value="{{ $card->due_date?->format('Y-m-d') }}"
                                            class="w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                                    </div>
                                </div>
                                <button class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-semibold hover:bg-red-700">Simpan Kartu</button>
                            </form>

                            {{-- Lampiran gambar — mockup, tangkapan layar, referensi. Form
                                 terpisah dari form kartu di atas (input file butuh multipart &
                                 route sendiri; HTML tak boleh form bersarang). --}}
                            <div class="mt-5 pt-4 border-t border-stone-100">
                                <p class="text-[11px] font-semibold text-stone-500 mb-2">🖼️ Lampiran ({{ $atts->count() }}/8)</p>
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
                                                    <button class="w-6 h-6 rounded-full bg-black/60 text-white text-xs leading-none hover:bg-rose-600" title="Hapus lampiran">✕</button>
                                                </form>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                @if($atts->count() < 8)
                                    <form method="POST" action="{{ route('kanban.cards.attachments.store', $card) }}" enctype="multipart/form-data" class="flex items-center gap-2">
                                        @csrf
                                        <input type="file" name="images[]" accept="image/*" multiple required class="flex-1 text-xs">
                                        <button class="px-3 py-2 bg-stone-700 text-white rounded-lg text-xs hover:bg-stone-800 whitespace-nowrap">Unggah</button>
                                    </form>
                                    <p class="text-[10px] text-stone-400 mt-1">bisa pilih beberapa sekaligus · jpg/png/webp/gif · otomatis diperkecil (maks 1280px) · sisa {{ 8 - $atts->count() }} slot</p>
                                @else
                                    <p class="text-[10px] text-stone-400">Batas 8 lampiran tercapai — hapus salah satu untuk menambah.</p>
                                @endif
                            </div>

                            {{-- Thread komentar — kronologis, seperti Trello. --}}
                            <div class="mt-5 pt-4 border-t border-stone-100">
                                <p class="text-[11px] font-semibold text-stone-500 mb-2">💬 Komentar ({{ $card->comments->count() }})</p>
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
                                            <p class="text-xs text-stone-600 whitespace-pre-line">{{ $comment->body }}</p>
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

                            <form method="POST" action="{{ route('kanban.cards.destroy', $card) }}" class="mt-4 text-right"
                                onsubmit="return confirm('Hapus kartu {{ $card->title }}?')">
                                @csrf @method('DELETE')
                                <button class="text-[11px] text-rose-500 hover:text-rose-700">hapus kartu</button>
                            </form>
                        </div>
                    </dialog>
                @endforeach
            </div>

            <form method="POST" action="{{ route('kanban.cards.store', $column) }}" class="p-2 pt-0">@csrf
                <input name="title" required maxlength="255" placeholder="+ tambah kartu… (Enter)"
                    class="w-full px-3 py-2 bg-transparent border border-dashed border-stone-300 rounded-xl text-xs placeholder-stone-400 focus:bg-white">
                <p class="px-1 pt-1 text-[10px] text-stone-400">deadline, deskripsi & komentar: klik kartunya setelah dibuat</p>
            </form>
        </div>
    @endforeach

    {{-- Tambah kolom --}}
    <form method="POST" action="{{ route('kanban.columns.store', $board) }}" class="w-64 shrink-0">@csrf
        <input name="name" required maxlength="100" placeholder="+ tambah kolom…"
            class="w-full px-3 py-2.5 bg-stone-100 border border-dashed border-stone-300 rounded-2xl text-sm placeholder-stone-400 focus:bg-white">
    </form>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const post = (url, body) => fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
    body: JSON.stringify(body),
}).then(r => { if (!r.ok) { alert('Gagal menyimpan perpindahan — muat ulang halaman.'); location.reload(); } });

// Klik kartu buka modal — tapi JANGAN saat kartu baru saja di-drag.
let justDragged = false;
document.querySelectorAll('[data-opens]').forEach(el => {
    el.addEventListener('click', () => {
        if (justDragged) return;
        document.getElementById(el.dataset.opens).showModal();
    });
});

// Drag kartu antar/dalam kolom.
document.querySelectorAll('[data-cards]').forEach(el => {
    new Sortable(el, {
        group: 'cards',
        animation: 150,
        draggable: '[data-card]',
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
    onEnd: () => {
        const ids = [...document.querySelectorAll('[data-column]')].map(c => c.dataset.column);
        post(`{{ route('kanban.columns.reorder', $board) }}`, { ordered_ids: ids });
    },
});
</script>
@endsection
