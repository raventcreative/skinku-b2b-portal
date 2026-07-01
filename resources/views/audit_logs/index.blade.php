@extends('layouts.app')
@section('title', 'Audit Log')
@section('heading', 'Audit Log Sistem')

@section('content')
<form method="GET" class="flex flex-wrap gap-2 mb-4">
    <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cari aksi/email…" class="px-3 py-2 text-sm border border-stone-300 rounded-lg w-56">
    <select name="action" class="px-3 py-2 text-sm border border-stone-300 rounded-lg">
        <option value="">Semua Aksi</option>
        @foreach($actions as $a)<option value="{{ $a }}" @selected(($filters['action'] ?? '')===$a)>{{ $a }}</option>@endforeach
    </select>
    <button class="px-4 py-2 text-sm bg-stone-200 rounded-lg hover:bg-stone-300">Filter</button>
</form>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-3">Waktu</th>
                <th class="text-left">Aksi</th>
                <th class="text-left">Target</th>
                <th class="text-left">Dilakukan Oleh</th>
                <th class="text-left">IP</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                <tr class="border-t border-stone-100 hover:bg-stone-50">
                    <td class="px-4 py-2 text-stone-500 whitespace-nowrap">{{ $log->created_at?->format('d M Y H:i:s') }}</td>
                    <td><span class="px-2 py-0.5 rounded-full bg-stone-100 text-stone-700 font-semibold">{{ $log->action }}</span></td>
                    <td class="text-stone-600">{{ $log->target_type }}{{ $log->target_id ? ' #'.$log->target_id : '' }}<br><span class="text-[10px] text-stone-400">{{ $log->target_email }}</span></td>
                    <td class="text-stone-600">{{ $log->performed_by_email ?? ($log->performer->fullname ?? 'System') }}</td>
                    <td class="text-stone-400">{{ $log->ip_address }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-6 text-center text-stone-400">Belum ada log audit.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
<div class="mt-4">{{ $logs->links() }}</div>
@endsection
