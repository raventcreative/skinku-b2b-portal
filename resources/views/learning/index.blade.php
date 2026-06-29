@extends('layouts.app')
@section('title', 'Pembelajaran')
@section('heading', 'Pusat Pembelajaran')

@section('content')
<div class="flex justify-between items-center mb-5">
    <p class="text-xs text-stone-500">Materi video pelatihan SKINKU, tersusun per modul. Klik untuk menonton.</p>
    @if($canManage)
        <div class="flex gap-2">
            <button onclick="openModule()" class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg hover:bg-stone-900">+ Modul</button>
            <button onclick="openLesson()" class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">+ Materi</button>
        </div>
    @endif
</div>

@php $ungrouped = $lessons->filter(fn ($l) => ! $l->module_id); @endphp

@if($modules->isEmpty() && $lessons->isEmpty())
    <div class="bg-white rounded-2xl border border-stone-200 p-10 text-center text-stone-400 text-sm">
        Belum ada modul/materi.@if($canManage) Mulai dengan "+ Modul", lalu tambah "+ Materi".@endif
    </div>
@endif

@foreach($modules as $module)
    @php $mLessons = $lessons->where('module_id', $module->id); @endphp
    <section class="mb-8">
        <div class="flex justify-between items-start mb-3">
            <div>
                <h3 class="text-base font-bold text-stone-900">{{ $module->title }}
                    @unless($module->is_published)<span class="ml-2 px-2 py-0.5 rounded-full bg-amber-500 text-white text-[10px] font-bold align-middle">DRAFT</span>@endunless
                </h3>
                @if($module->description)<p class="text-xs text-stone-500 mt-0.5 max-w-2xl">{{ $module->description }}</p>@endif
            </div>
            @if($canManage)
                <div class="flex items-center gap-3 text-xs shrink-0">
                    <button class="text-stone-500 hover:text-stone-900 font-semibold"
                        onclick='openModule({{ json_encode($module->only(["id","title","description","sort_order","is_published"])) }})'>Edit Modul</button>
                    <form method="POST" action="{{ route('learning.modules.destroy', $module) }}" onsubmit="return confirm('Hapus modul ini? Materinya tidak ikut terhapus (jadi Tanpa Modul).')">
                        @csrf @method('DELETE')
                        <button class="text-rose-600 hover:text-rose-800 font-semibold">Hapus</button>
                    </form>
                </div>
            @endif
        </div>
        @if($mLessons->isEmpty())
            <p class="text-xs text-stone-400 italic">Belum ada materi di modul ini.</p>
        @else
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach($mLessons as $lesson)
                    @include('learning._card', ['lesson' => $lesson, 'canManage' => $canManage])
                @endforeach
            </div>
        @endif
    </section>
@endforeach

@if($ungrouped->isNotEmpty())
    <section class="mb-8">
        <h3 class="text-base font-bold text-stone-900 mb-3">Tanpa Modul</h3>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
            @foreach($ungrouped as $lesson)
                @include('learning._card', ['lesson' => $lesson, 'canManage' => $canManage])
            @endforeach
        </div>
    </section>
@endif

@if($canManage)
{{-- Module modal --}}
<div id="moduleModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-md p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 id="moduleModalTitle" class="text-sm font-bold text-stone-900">Tambah Modul</h3>
            <button onclick="toggleModal('moduleModal')" class="text-stone-400 hover:text-stone-700">✕</button>
        </div>
        <form method="POST" id="moduleForm" action="{{ route('learning.modules.store') }}" class="space-y-3 text-sm">
            @csrf
            <input type="hidden" name="_method" id="moduleMethod" value="POST">
            <div><label class="block text-xs font-semibold mb-1">Judul Modul *</label><input name="title" required class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div><label class="block text-xs font-semibold mb-1">Deskripsi / Tujuan Modul</label><textarea name="description" rows="3" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></textarea></div>
            <div><label class="block text-xs font-semibold mb-1">Urutan</label><input type="number" name="sort_order" value="0" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="is_published" id="modulePublished" value="1" checked class="accent-red-600"> Publikasikan</label>
            <div class="flex justify-end gap-2 mt-2">
                <button type="button" onclick="toggleModal('moduleModal')" class="px-4 py-2 text-stone-600 rounded-lg">Batal</button>
                <button class="px-5 py-2 bg-red-600 text-white rounded-lg">Simpan</button>
            </div>
        </form>
    </div>
</div>

{{-- Lesson modal --}}
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
                <label class="block text-xs font-semibold mb-1">Modul</label>
                <select name="module_id" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
                    <option value="">— Tanpa Modul —</option>
                    @foreach($modules as $m)<option value="{{ $m->id }}">{{ $m->title }}</option>@endforeach
                </select>
            </div>
            <div><label class="block text-xs font-semibold mb-1">Judul Materi *</label><input name="title" required class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div><label class="block text-xs font-semibold mb-1">Link YouTube *</label><input name="video_url" required placeholder="https://www.youtube.com/watch?v=..." class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div><label class="block text-xs font-semibold mb-1">Urutan</label><input type="number" name="sort_order" value="0" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div><label class="block text-xs font-semibold mb-1">Deskripsi</label><textarea name="description" rows="3" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></textarea></div>
            <div>
                <label class="block text-xs font-semibold mb-1">Tujukan untuk role <span class="text-stone-400 font-normal">(kosongkan = semua)</span></label>
                <div class="flex flex-wrap gap-3 text-xs">
                    @foreach($audienceRoles as $r)
                        <label class="flex items-center gap-1"><input type="checkbox" name="audience[]" value="{{ $r->name }}" class="lesson-aud accent-red-600"> {{ $r->label }}</label>
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
    function openModule(m) {
        const f = document.getElementById('moduleForm');
        if (!f) return;
        f.reset();
        if (m) {
            f.action = '/learning-modules/' + m.id;
            document.getElementById('moduleMethod').value = 'PUT';
            document.getElementById('moduleModalTitle').textContent = 'Edit Modul';
            f.querySelector('[name=title]').value = m.title ?? '';
            f.querySelector('[name=description]').value = m.description ?? '';
            f.querySelector('[name=sort_order]').value = m.sort_order ?? 0;
            document.getElementById('modulePublished').checked = !!m.is_published;
        } else {
            f.action = '{{ route('learning.modules.store') }}';
            document.getElementById('moduleMethod').value = 'POST';
            document.getElementById('moduleModalTitle').textContent = 'Tambah Modul';
            document.getElementById('modulePublished').checked = true;
        }
        toggleModal('moduleModal');
    }

    function openLesson(l) {
        const f = document.getElementById('lessonForm');
        if (!f) return;
        f.reset();
        f.querySelectorAll('.lesson-aud').forEach(c => c.checked = false);
        if (l) {
            f.action = '/learning/' + l.id;
            document.getElementById('lessonMethod').value = 'PUT';
            document.getElementById('lessonModalTitle').textContent = 'Edit Materi';
            for (const k of ['title','video_url','sort_order','description']) {
                if (f.querySelector('[name='+k+']')) f.querySelector('[name='+k+']').value = l[k] ?? '';
            }
            f.querySelector('[name=module_id]').value = l.module_id ?? '';
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
