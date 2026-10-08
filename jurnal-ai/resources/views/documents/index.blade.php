@extends('layouts.app')
@section('title', 'Dokumen')

@section('content')
    <form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <div>
            <label class="label" for="status">Status</label>
            <select id="status" name="status" class="field w-44">
                <option value="">Semua</option>
                @foreach (['uploaded' => 'Belum dibaca', 'extracted' => 'Perlu review', 'failed' => 'Gagal dibaca', 'posted' => 'Sudah diposting'] as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="kind">Jenis</label>
            <select id="kind" name="kind" class="field w-64">
                <option value="">Semua</option>
                @foreach (\App\Models\Document::KINDS as $value => $label)
                    <option value="{{ $value }}" @selected($kind === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn-ghost">Filter</button>
    </form>

    <div class="card overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr>
                    <th class="th">Dokumen</th>
                    <th class="th">Jenis</th>
                    <th class="th num">Total dibaca</th>
                    <th class="th num">Jurnal</th>
                    <th class="th">Status</th>
                    <th class="th">Diupload</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($documents as $document)
                    <tr class="hover:bg-ink-50">
                        <td class="td">
                            <a href="{{ route('documents.show', [$client, $document]) }}" class="font-medium text-brand-700 hover:underline">
                                {{ $document->title ?: $document->original_name ?: 'Teks tempel' }}
                            </a>
                            @if ($vendor = data_get($document->extraction, 'vendor'))
                                <p class="text-xs text-ink-400">{{ $vendor }}</p>
                            @endif
                        </td>
                        <td class="td text-xs text-ink-600">{{ $document->kindLabel() }}</td>
                        <td class="td num">
                            {{ ($total = data_get($document->extraction, 'total')) ? \App\Support\Rupiah::format((float) $total) : '—' }}
                        </td>
                        <td class="td num">{{ $document->journals_count ?: '—' }}</td>
                        <td class="td">@include('documents._status', ['document' => $document])</td>
                        <td class="td text-xs text-ink-500">{{ $document->created_at->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="td py-10 text-center text-sm text-ink-500">
                        Belum ada dokumen. <a href="{{ route('documents.create', $client) }}" class="font-medium text-brand-700 hover:underline">Upload yang pertama</a>.
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $documents->links() }}</div>
@endsection
