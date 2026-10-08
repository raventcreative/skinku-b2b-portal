@extends('layouts.app')
@section('title', 'COA')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-bold text-ink-900">Chart of Account</h2>
            <p class="text-xs text-ink-500">COA ini milik {{ $client->name }} saja — tidak tercampur klien lain.</p>
        </div>
        @if (auth()->user()->isAdmin())
            <form method="POST" action="{{ route('clients.coa.sync', $client) }}">
                @csrf
                <button class="btn-ghost btn-sm">Sinkron dari template</button>
            </form>
        @endif
    </div>

    @if (auth()->user()->isAdmin())
        <form method="POST" action="{{ route('accounts.store', $client) }}" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
            @csrf
            <div class="w-28">
                <label class="label" for="code">Kode</label>
                <input type="text" id="code" name="code" required class="field font-mono" placeholder="6117">
            </div>
            <div class="grow min-w-48">
                <label class="label" for="name">Nama akun</label>
                <input type="text" id="name" name="name" required class="field" placeholder="Beban Riset Produk">
            </div>
            <div class="w-40">
                <label class="label" for="type">Tipe</label>
                <select id="type" name="type" class="field">
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-36">
                <label class="label" for="subtype">Subtype</label>
                <input type="text" id="subtype" name="subtype" class="field font-mono text-xs" placeholder="opex">
            </div>
            <label class="flex items-center gap-2 pb-2 text-sm">
                <input type="checkbox" name="is_active" value="1" checked class="rounded border-ink-300"> Aktif
            </label>
            <button class="btn-primary">+ Tambah akun</button>
        </form>
    @endif

    @foreach ($types as $type => $typeLabel)
        @php $group = $accounts[$type] ?? collect(); @endphp
        @if ($group->isNotEmpty())
            <div class="card mb-4 overflow-hidden">
                <p class="border-b border-ink-200 bg-ink-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-ink-500">
                    {{ $typeLabel }} ({{ $group->count() }})
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <tbody>
                            @foreach ($group as $account)
                                <tr class="hover:bg-ink-50">
                                    @if (auth()->user()->isAdmin())
                                        <form method="POST" action="{{ route('accounts.update', [$client, $account]) }}" id="acc-{{ $account->id }}">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="type" value="{{ $account->type }}">
                                        </form>
                                        <td class="td w-28">
                                            <input form="acc-{{ $account->id }}" type="text" name="code" value="{{ $account->code }}" class="field font-mono text-xs">
                                        </td>
                                        <td class="td">
                                            <input form="acc-{{ $account->id }}" type="text" name="name" value="{{ $account->name }}" class="field">
                                        </td>
                                        <td class="td w-36">
                                            <input form="acc-{{ $account->id }}" type="text" name="subtype" value="{{ $account->subtype }}" class="field font-mono text-xs">
                                        </td>
                                        <td class="td w-24 text-xs text-ink-500">{{ $account->normal_balance }}</td>
                                        <td class="td w-24">
                                            <label class="flex items-center gap-1 text-xs">
                                                <input form="acc-{{ $account->id }}" type="checkbox" name="is_active" value="1" @checked($account->is_active) class="rounded border-ink-300">
                                                aktif
                                            </label>
                                        </td>
                                        <td class="td w-40 text-right">
                                            <button form="acc-{{ $account->id }}" class="btn-ghost btn-sm">Simpan</button>
                                            <button form="acc-del-{{ $account->id }}" class="btn-danger btn-sm"
                                                    onclick="return confirm('Hapus akun ini? Kalau sudah dipakai jurnal, akun hanya dinonaktifkan.')">Hapus</button>
                                        </td>
                                    @else
                                        <td class="td w-28 font-mono text-xs">{{ $account->code }}</td>
                                        <td class="td">{{ $account->name }}</td>
                                        <td class="td w-36 font-mono text-xs text-ink-500">{{ $account->subtype }}</td>
                                        <td class="td w-24 text-xs text-ink-500">{{ $account->normal_balance }}</td>
                                        <td class="td w-24">
                                            <span class="{{ $account->is_active ? 'badge-ok' : 'badge-mute' }}">{{ $account->is_active ? 'aktif' : 'nonaktif' }}</span>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endforeach

    @if (auth()->user()->isAdmin())
        @foreach ($accounts->flatten() as $account)
            <form method="POST" action="{{ route('accounts.destroy', [$client, $account]) }}" id="acc-del-{{ $account->id }}" class="hidden">
                @csrf @method('DELETE')
            </form>
        @endforeach
    @endif
@endsection
