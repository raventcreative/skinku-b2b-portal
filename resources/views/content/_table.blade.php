{{-- Tabel konten: $posts (dengan targets), $showCreator. --}}
@if($posts->isEmpty())
    <p class="px-5 py-8 text-sm text-stone-400 text-center">Belum ada konten.</p>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-stone-50 text-[11px] uppercase tracking-wide text-stone-500">
                <tr>
                    <th class="px-4 py-2 text-left">Konten</th>
                    @if($showCreator)<th class="px-4 py-2 text-left">Creator</th>@endif
                    <th class="px-4 py-2 text-left">Status</th>
                    <th class="px-4 py-2 text-left">Platform</th>
                    <th class="px-4 py-2 text-left">Jadwal</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @foreach($posts as $p)
                    <tr class="hover:bg-stone-50">
                        <td class="px-4 py-2.5">
                            <a href="{{ route('content.show', $p) }}" class="font-semibold text-stone-800 hover:text-red-700">{{ $p->title }}</a>
                            <p class="text-[11px] text-stone-400">{{ \App\Models\ContentPost::TYPES[$p->type] ?? $p->type }} · {{ $p->created_at->format('d M Y') }}</p>
                        </td>
                        @if($showCreator)<td class="px-4 py-2.5 text-stone-600">{{ $p->user?->displayName() }}</td>@endif
                        <td class="px-4 py-2.5">@include('content._badge', ['status' => $p->status, 'label' => $p->statusLabel()])</td>
                        <td class="px-4 py-2.5">
                            <div class="flex flex-wrap gap-1">
                                @foreach($p->targets as $t)
                                    @if($t->permalink)
                                        <a href="{{ $t->permalink }}" target="_blank" rel="noopener noreferrer" title="{{ $t->statusLabel() }}">@include('content._badge', ['status' => $t->status, 'label' => $t->platformLabel().' ↗'])</a>
                                    @else
                                        <span title="{{ $t->statusLabel() }}">@include('content._badge', ['status' => $t->status, 'label' => $t->platformLabel()])</span>
                                    @endif
                                @endforeach
                            </div>
                        </td>
                        <td class="px-4 py-2.5 text-stone-600 whitespace-nowrap">{{ $p->scheduled_at?->format('d M Y H:i') ?? 'Secepatnya' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
