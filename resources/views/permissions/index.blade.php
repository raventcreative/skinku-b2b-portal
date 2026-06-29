@extends('layouts.app')
@section('title', 'Manajemen Hak Akses')
@section('heading', 'Manajemen Hak Akses')

@section('content')
{{-- Role management (separate from the matrix form) --}}
<div class="bg-white rounded-2xl border border-stone-200 p-5 mb-5">
    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
            <h3 class="text-sm font-bold text-stone-800">Daftar Role</h3>
            <p class="text-[11px] text-stone-400 mt-0.5 mb-3">Role bawaan tidak bisa dihapus. Tambah role custom (mis. affiliator) sesuai kebutuhan.</p>
            <div class="flex flex-wrap gap-2">
                @foreach($roles as $role)
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs {{ $role->is_system ? 'bg-stone-100 text-stone-600' : 'bg-red-50 text-red-700 border border-red-200' }}">
                        {{ $role->label }}
                        @if($role->is_system)
                            <span class="text-[9px] text-stone-400">(bawaan)</span>
                        @else
                            <form method="POST" action="{{ route('roles.destroy', $role) }}" class="inline" onsubmit="return confirm('Hapus role {{ $role->label }}?')">
                                @csrf @method('DELETE')
                                <button class="text-rose-500 hover:text-rose-700 font-bold leading-none" title="Hapus role">✕</button>
                            </form>
                        @endif
                    </span>
                @endforeach
            </div>
        </div>
        <form method="POST" action="{{ route('roles.store') }}" class="flex items-end gap-2">
            @csrf
            <div>
                <label class="block text-[11px] font-semibold text-stone-600 mb-1">Tambah Role Baru</label>
                <input name="label" required placeholder="mis. Affiliator" class="px-3 py-2 text-sm border border-stone-300 rounded-lg w-48">
            </div>
            <button class="px-4 py-2 bg-stone-800 hover:bg-stone-900 text-white text-sm font-semibold rounded-lg">+ Role</button>
        </form>
    </div>
</div>

{{-- Permission matrix --}}
<form method="POST" action="{{ route('permissions.update') }}">
    @csrf
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-bold text-stone-800">Hak Akses per Role</h3>
                <p class="text-[11px] text-stone-400 mt-0.5">Centang = role boleh melakukan. Berlaku langsung ke menu &amp; akses setelah disimpan.</p>
            </div>
            <button class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-lg">Simpan</button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                    <tr>
                        <th class="text-left px-5 py-3 w-72">Hak Akses</th>
                        @foreach($roles as $role)
                            <th class="text-center px-3 py-3">{{ $role->label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($definitions as $key => $label)
                        <tr class="border-t border-stone-100 hover:bg-stone-50">
                            <td class="px-5 py-3 font-semibold text-stone-800">{{ $label }}
                                <span class="block text-[10px] font-normal text-stone-400">{{ $key }}</span>
                            </td>
                            @foreach($roles as $role)
                                @php $isSuper = $role->name === \App\Models\User::ROLE_SUPER_ADMIN; @endphp
                                <td class="text-center px-3 py-3">
                                    <input type="checkbox"
                                           name="perm[{{ $role->name }}][{{ $key }}]"
                                           value="on"
                                           class="w-4 h-4 accent-red-600 align-middle"
                                           @checked($matrix[$key][$role->name] ?? false)
                                           @disabled($isSuper)>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="px-5 py-3 border-t border-stone-100 flex items-center justify-between">
            <p class="text-[11px] text-stone-400">Kolom <strong>super_admin</strong> terkunci penuh — selalu punya semua akses agar Anda tidak terkunci dari sistem.</p>
            <button class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-lg">Simpan Perubahan</button>
        </div>
    </div>
</form>
@endsection
