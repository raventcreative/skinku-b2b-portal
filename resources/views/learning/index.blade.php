@extends('layouts.app')
@section('title', 'Pembelajaran')
@section('heading', 'Pusat Pembelajaran')

@section('content')
<div class="flex justify-between items-center mb-4">
    <p class="text-xs text-stone-500">Materi video pelatihan SKINKU. Klik untuk menonton.</p>
    @if($canManage)
        <button onclick="openLesson()" class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">+ Tambah Materi</button>
    @endif
</div>

@if($lessons->isEmpty())
    <div class="bg-white rounded-2xl border border-stone-200 p-10 text-center text-stone-400 text-sm">
        Belum ada materi pembelajaran.@if($canManage) Klik "+ Tambah Materi" untuk menambah.@endif
    </div>
@else
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach($lessons as $lesson)
            <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden flex flex-col">
                <a href="{{ route('learning.show', $lesson) }}" class="block relative group">
                    <div class="aspect-video bg-stone-100 overflow-hidden">
                        @if($lesson->thumbnailUrl())
                            <img src="{{ $lesson->thumbnailUrl() }}" class="w-full h-full object-cover group-hover:scale-105 transition" alt="{{ $lesson->title }}">
                        @endif
                    </div>
                    <span class="absolute inset-0 flex items-center justify-center">
                        <span class="w-12 h-12 rounded-full bg-red-600/90 text-white flex items-center justify-center text-xl shadow-lg">▶</span>
                    </span>
                    @unless($lesson->is_published)<span class="absolute top-2 left-2 px-2 py-0.5 rounded-full bg-amber-500 text-white text-[10px] font-bold">DRAFT</span>@endunless
                </a>
                <div class="p-4 flex-1 flex flex-col">
                    @if($lesson->category)<span class="text-[10px] uppercase tracking-wide text-red-600 font-bold">{{ $lesson->category }}</span>@endif
                    <a href="{{ route('learning.show', $lesson) }}" class="font-bold text-stone-800 text-sm mt-0.5 hover:text-red-600 line-clamp-2">{{ $lesson->title }}</a>
                    @if($lesson->description)<p class="text-xs text-stone-500 mt-1 line-clamp-2">{{ $lesson->description }}</p>@endif
                    @if($canManage)
                        <div class="mt-3 pt-3 border-t border-stone-100 flex items-center gap-3 text-xs">
                            <button class="text-stone-500 hover:text-stone-900 font-semibold"
                                onclick='openLesson({{ json_encode($lesson->only(["id","title","description","video_url","category","sort_order","is_published"]) + ["audience" => $lesson->audience ?? []]) }})'>Edit</button>
                            <form method="POST" action="{{ route('learning.destroy', $lesson) }}" onsubmit="return confirm('Hapus materi ini?')">
                                @csrf @method('DELETE')
                                <button class="text-rose-600 hover:text-rose-800 font-semibold">Hapus</button>
                            </form>
                            @if($lesson->audience)<span class="text-[10px] text-stone-400 ml-auto">utk: {{ implode(', ', $lesson->audience) }}</span>@endif
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif

@if($canManage)
<div id="lessonModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 id="lessonModalTitle" class="text-sm font-bold text-stone-900">Tambah Materi</h3>
            <button onclick="toggleModal('lessonModal')" class="text-stone-400 hover:text-stone-700">✕</button>
        </div>
        <form method="POST" id="lessonForm" action="{{ route('learning.store') }}" class="space-y-3 text-sm">
            @csrf
            <input type="hidden" name="_method" id="lessonMethod" value="POST">
            <div>
                <label class="block text-xs font-semibold mb-1">Judul *</label>
                <input name="title" required class="w-full px-3 py-2 border border-stone-300 rounded-lg">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">Link YouTube *</label>
                <input name="video_url" required placeholder="https://www.youtube.com/watch?v=..." class="w-full px-3 py-2 border border-stone-300 rounded-lg">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="block text-xs font-semibold mb-1">Kategori/Modul</label><input name="category" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
                <div><label class="block text-xs font-semibold mb-1">Urutan</label><input type="number" name="sort_order" value="0" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">Deskripsi</label>
                <textarea name="description" rows="3" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></textarea>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">Tujukan untuk role <span class="text-stone-400 font-normal">(kosongkan = semua)</span></label>
                <div class="flex flex-wrap gap-3 text-xs">
                    @foreach($audienceRoles as $r)
                        <label class="flex items-center gap-1"><input type="checkbox" name="audience[]" value="{{ $r }}" class="lesson-aud accent-red-600"> {{ $r }}</label>
                    @endforeach
                </div>
            </div>
            <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="is_published" id="lessonPublished" value="1" checked class="accent-red-600"> Publikasikan (tampil ke user)</label>
            <div class="flex justify-end gap-2 mt-2">
                <button type="button" onclick="toggleModal('lessonModal')" class="px-4 py-2 text-stone-600 rounded-lg">Batal</button>
                <button class="px-5 py-2 bg-red-600 text-white rounded-lg">Simpan</button>
            </div>
        </form>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script>
    function openLesson(l) {
        const f = document.getElementById('lessonForm');
        if (!f) return;
        f.reset();
        f.querySelectorAll('.lesson-aud').forEach(c => c.checked = false);
        if (l) {
            f.action = '/learning/' + l.id;
            document.getElementById('lessonMethod').value = 'PUT';
            document.getElementById('lessonModalTitle').textContent = 'Edit Materi';
            for (const k of ['title','video_url','category','sort_order','description']) {
                if (f.querySelector('[name='+k+']')) f.querySelector('[name='+k+']').value = l[k] ?? '';
            }
            document.getElementById('lessonPublished').checked = !!l.is_published;
            (l.audience || []).forEach(role => {
                const cb = f.querySelector('.lesson-aud[value="'+role+'"]');
                if (cb) cb.checked = true;
            });
        } else {
            f.action = '{{ route('learning.store') }}';
            document.getElementById('lessonMethod').value = 'POST';
            document.getElementById('lessonModalTitle').textContent = 'Tambah Materi';
            document.getElementById('lessonPublished').checked = true;
        }
        toggleModal('lessonModal');
    }
</script>
@endpush
