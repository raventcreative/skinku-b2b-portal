@extends('layouts.app')
@section('title', 'Klien')

@section('content')
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <h1 class="text-xl font-bold text-ink-900">Database Klien</h1>
        @if (auth()->user()->isAdmin())
            <a href="{{ route('clients.create') }}" class="btn-primary">+ Klien baru</a>
        @endif
    </div>

    <form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <div class="grow min-w-48">
            <label class="label" for="q">Cari nama</label>
            <input type="search" id="q" name="q" value="{{ $q }}" class="field" placeholder="SKINKU, Rave Tailor, …">
        </div>
        <div>
            <label class="label" for="type">Jenis</label>
            <select id="type" name="type" class="field w-56">
                <option value="">Semua</option>
                @foreach (\App\Models\Client::TYPES as $value => $label)
                    <option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn-ghost">Filter</button>
    </form>

    <div class="card overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr>
                    <th class="th">Nama</th>
                    <th class="th">Jenis</th>
                    <th class="th">Kontak</th>
                    <th class="th num">Jurnal</th>
                    <th class="th">Status</th>
                    <th class="th"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($clients as $client)
                    <tr class="hover:bg-ink-50">
                        <td class="td">
                            <a href="{{ route('clients.show', $client) }}" class="font-semibold text-brand-700 hover:underline">{{ $client->name }}</a>
                            @if ($client->npwp)<p class="text-xs text-ink-400 font-mono">NPWP {{ $client->npwp }}</p>@endif
                        </td>
                        <td class="td">
                            <span class="{{ $client->type === \App\Models\Client::TYPE_INTERNAL ? 'badge-info' : 'badge-mute' }}">{{ $client->typeLabel() }}</span>
                        </td>
                        <td class="td text-ink-600">
                            {{ $client->contact_name ?: '—' }}
                            @if ($client->phone)<p class="text-xs text-ink-400">{{ $client->phone }}</p>@endif
                        </td>
                        <td class="td num">{{ number_format($client->journals_count, 0, ',', '.') }}</td>
                        <td class="td">
                            <span class="{{ $client->is_active ? 'badge-ok' : 'badge-bad' }}">{{ $client->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                        </td>
                        <td class="td text-right">
                            @if (auth()->user()->isAdmin())
                                <a href="{{ route('clients.edit', $client) }}" class="btn-ghost btn-sm">Ubah</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="td text-center text-ink-500 py-10">Belum ada klien yang cocok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $clients->links() }}</div>
@endsection
