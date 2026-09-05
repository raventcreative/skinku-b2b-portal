@extends('layouts.app')
@section('title', 'Dormansi Member')
@section('heading', 'Dormansi Member')

@section('content')
@php
    $roleLabel = [
        'grand_distributor' => 'Grand Distributor', 'distributor' => 'Distributor',
        'reseller' => 'Reseller', 'reseller_bronze' => 'Reseller Bronze',
        'reseller_gold' => 'Reseller Gold', 'sponsor' => 'Sponsor',
    ];
    $basisLabel = ['order' => 'Order / RO', 'login' => 'Login (last-online)', 'recruit' => 'Rekrut baru'];
@endphp

<div class="space-y-5 max-w-4xl">
    <p class="text-sm text-stone-500 -mt-1">
        Akun member yang tak ada pergerakan sesuai batas di bawah akan <strong>otomatis dibekukan</strong>
        (tak bisa login). Menghidupkan kembali <strong>hanya manual dari sini</strong>. Aturan default <strong>mati</strong> —
        nyalakan per-role saat siap.
    </p>

    {{-- Aturan per-role --}}
    <form method="POST" action="{{ route('member-dormancy.rules') }}" class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        @csrf
        <div class="px-4 py-3 border-b border-stone-200 bg-stone-50"><p class="text-sm font-semibold text-stone-700">Aturan per Role</p></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-stone-500 border-b border-stone-200">
                        <th class="px-4 py-2">Role</th>
                        <th class="px-4 py-2">Aktif</th>
                        <th class="px-4 py-2">Batas (bulan)</th>
                        <th class="px-4 py-2">Sinyal aktif</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($managedRoles as $role)
                        @php $r = $rules->get($role); @endphp
                        <tr>
                            <td class="px-4 py-2 font-medium text-stone-800">{{ $roleLabel[$role] ?? $role }}</td>
                            <td class="px-4 py-2">
                                <input type="checkbox" name="rules[{{ $role }}][enabled]" value="1" @checked($r?->enabled)>
                            </td>
                            <td class="px-4 py-2">
                                <input type="number" name="rules[{{ $role }}][inactive_months]" min="1" max="60" required
                                    value="{{ $r?->inactive_months ?? 3 }}" class="w-20 px-2 py-1 border border-stone-300 rounded-lg">
                            </td>
                            <td class="px-4 py-2">
                                <select name="rules[{{ $role }}][basis]" class="px-2 py-1 border border-stone-300 rounded-lg">
                                    @foreach($bases as $b)
                                        <option value="{{ $b }}" @selected(($r?->basis ?? 'login') === $b)>{{ $basisLabel[$b] ?? $b }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3 border-t border-stone-200 flex justify-end">
            <button class="rounded-xl bg-red-600 text-white px-5 py-2 text-sm font-semibold hover:bg-red-700">Simpan Aturan</button>
        </div>
    </form>

    {{-- Akan beku (≤ 14 hari) --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-stone-200 bg-amber-50"><p class="text-sm font-semibold text-amber-700">Akan dibekukan (≤ 14 hari) — {{ $atRisk->count() }}</p></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs text-stone-500 border-b border-stone-200">
                    <th class="px-4 py-2">Member</th><th class="px-4 py-2">Role</th><th class="px-4 py-2">Sinyal</th><th class="px-4 py-2 text-right">Sisa hari</th>
                </tr></thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse($atRisk as $row)
                        <tr>
                            <td class="px-4 py-2 text-stone-800">{{ '@'.$row['user']->username }} <span class="text-xs text-stone-400">{{ $row['user']->fullname }}</span></td>
                            <td class="px-4 py-2 text-stone-600">{{ $roleLabel[$row['user']->role] ?? $row['user']->role }}</td>
                            <td class="px-4 py-2 text-stone-500">{{ $basisLabel[$row['basis']] ?? $row['basis'] }}</td>
                            <td class="px-4 py-2 text-right font-semibold text-amber-700">{{ $row['days'] }} hr</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-stone-400">Tak ada yang mendekati batas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Ditahan — dorman tapi punya downline aktif --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-stone-200 bg-sky-50">
            <p class="text-sm font-semibold text-sky-700">Ditahan — punya downline aktif ({{ $held->count() }})</p>
            <p class="text-xs text-sky-600 mt-0.5">Dorman, tapi tak dibekukan karena masih punya downline aktif. Tindak manual bila perlu.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs text-stone-500 border-b border-stone-200">
                    <th class="px-4 py-2">Member</th><th class="px-4 py-2">Role</th><th class="px-4 py-2">Sinyal</th>
                </tr></thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse($held as $row)
                        <tr>
                            <td class="px-4 py-2 text-stone-800">{{ '@'.$row['user']->username }} <span class="text-xs text-stone-400">{{ $row['user']->fullname }}</span></td>
                            <td class="px-4 py-2 text-stone-600">{{ $roleLabel[$row['user']->role] ?? $row['user']->role }}</td>
                            <td class="px-4 py-2 text-stone-500">{{ $basisLabel[$row['basis']] ?? $row['basis'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-6 text-center text-stone-400">Tak ada.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Sudah beku --}}
    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-stone-200 bg-stone-50"><p class="text-sm font-semibold text-stone-700">Sudah dibekukan — {{ $frozen->count() }}</p></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs text-stone-500 border-b border-stone-200">
                    <th class="px-4 py-2">Member</th><th class="px-4 py-2">Role</th><th class="px-4 py-2">Dibekukan</th><th class="px-4 py-2 text-right"></th>
                </tr></thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse($frozen as $u)
                        <tr>
                            <td class="px-4 py-2 text-stone-800">{{ '@'.$u->username }} <span class="text-xs text-stone-400">{{ $u->fullname }}</span></td>
                            <td class="px-4 py-2 text-stone-600">{{ $roleLabel[$u->role] ?? $u->role }}</td>
                            <td class="px-4 py-2 text-stone-500 text-xs">{{ optional($u->disabled_at)->translatedFormat('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-2 text-right">
                                <form method="POST" action="{{ route('member-dormancy.reactivate', $u) }}" onsubmit="return confirm('Aktifkan kembali {{ '@'.$u->username }}?')">
                                    @csrf
                                    <button class="text-xs font-semibold rounded-lg bg-emerald-600 text-white px-3 py-1.5 hover:bg-emerald-700">Aktifkan lagi</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-stone-400">Belum ada akun beku.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-xs text-stone-400">Riwayat login lengkap ada di Audit Log (aksi "login"). Beku otomatis jalan tiap hari 03:00.</p>
</div>
@endsection
