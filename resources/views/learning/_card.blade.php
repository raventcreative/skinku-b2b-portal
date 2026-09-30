<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden flex flex-col">
    <a href="{{ route('learning.show', $lesson) }}" class="block relative group">
        <div class="aspect-video bg-stone-100 overflow-hidden flex items-center justify-center">
            @if($lesson->thumbnailUrl())
                <img src="{{ $lesson->thumbnailUrl() }}" class="w-full h-full object-cover group-hover:scale-105 transition" alt="{{ $lesson->title }}">
            @else
                <div class="flex h-full w-full flex-col items-center justify-center gap-2 bg-linear-to-br from-red-50 via-stone-50 to-stone-100 text-stone-400">
                    @if($lesson->isVideo())
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-10 w-10"><rect x="3" y="5" width="13" height="14" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="m16 10 5-3v10l-5-3"/></svg>
                        <span class="text-xs font-semibold">Video pelatihan</span>
                    @else
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-10 w-10"><path stroke-linecap="round" stroke-linejoin="round" d="M6 3.75h8.25L19.5 9v11.25A1.75 1.75 0 0 1 17.75 22h-10A1.75 1.75 0 0 1 6 20.25V3.75Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M14 4v5h5M9 14h7m-7 3h7"/></svg>
                        <span class="text-xs font-semibold">Dokumen belajar</span>
                    @endif
                </div>
                @if($lesson->isDocument())
                    <span class="absolute bottom-2 right-2 rounded-md bg-stone-800 px-2 py-1 text-[10px] font-bold text-white">{{ strtoupper($lesson->documentExtension() ?? 'DOC') }}</span>
                @endif
            @endif
            @if($lesson->isVideo() && $lesson->isDocument())
                <span class="absolute top-2 right-2 px-2 py-0.5 rounded-sm bg-stone-800 text-white text-[10px] font-bold">+ DOK</span>
            @endif
        </div>
        @if($lesson->isVideo())
            <span class="absolute inset-0 flex items-center justify-center" aria-hidden="true">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-red-700/90 text-white shadow-lg transition group-hover:scale-105">
                    <svg viewBox="0 0 24 24" fill="currentColor" class="ml-0.5 h-5 w-5"><path d="M8 5.75v12.5a.75.75 0 0 0 1.14.64l10-6.25a.75.75 0 0 0 0-1.28l-10-6.25A.75.75 0 0 0 8 5.75Z"/></svg>
                </span>
            </span>
        @endif
        @unless($lesson->is_published)<span class="absolute top-2 left-2 px-2 py-0.5 rounded-full bg-amber-500 text-white text-[10px] font-bold">DRAFT</span>@endunless
    </a>
    <div class="p-4 flex-1 flex flex-col">
        <a href="{{ route('learning.show', $lesson) }}" class="font-bold text-stone-800 text-sm hover:text-red-600 line-clamp-2">{{ $lesson->title }}</a>
        @if($lesson->description)<p class="text-xs text-stone-500 mt-1 line-clamp-2">{{ $lesson->description }}</p>@endif
        @if($canManage)
            <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-stone-100 pt-3 text-xs">
                <button class="inline-flex min-h-8 items-center rounded-lg border border-stone-200 px-3 font-semibold text-stone-600 hover:bg-stone-50"
                    onclick='openLesson({{ json_encode($lesson->only(["id","module_id","type","title","description","video_url","sort_order","is_published"]) + ["audience" => $lesson->audience ?? [], "doc_name" => $lesson->documentName()]) }})'>Edit</button>
                <form method="POST" action="{{ route('learning.destroy', $lesson) }}" onsubmit="return confirm('Hapus materi ini?')">
                    @csrf @method('DELETE')
                    <button class="inline-flex min-h-8 items-center rounded-lg border border-rose-200 px-3 font-semibold text-rose-700 hover:bg-rose-50">Hapus materi</button>
                </form>
                @if($lesson->audience)<span class="text-[10px] text-stone-400 ml-auto">utk: {{ implode(', ', $lesson->audience) }}</span>@endif
            </div>
        @endif
    </div>
</div>
