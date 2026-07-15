@extends('layouts.app')
@section('title', 'Pengaturan Sistem')
@section('heading', 'Pengaturan Sistem')

@section('content')
<div class="max-w-2xl">
    <div class="bg-white rounded-2xl border border-stone-200 p-6">
        <h3 class="text-sm font-bold text-stone-900 mb-1">Ringkasan Lingkungan</h3>
        <p class="text-xs text-stone-500 mb-5">Konfigurasi sensitif dikelola melalui file <code class="bg-stone-100 px-1 rounded">.env</code> dan tidak dapat diubah dari UI demi keamanan.</p>
        <dl class="divide-y divide-stone-100 text-sm">
            @foreach($info as $key => $value)
                <div class="flex justify-between py-2.5">
                    <dt class="text-stone-500 uppercase text-xs tracking-wide">{{ str_replace('_', ' ', $key) }}</dt>
                    <dd class="font-semibold text-stone-800">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    {{-- Backup DB — jaring pengaman terakhir --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-6 mt-6">
        <div class="flex flex-wrap items-center gap-3 mb-3">
            <h3 class="text-sm font-bold text-stone-900">Backup Database</h3>
            <form method="POST" action="{{ route('settings.backup') }}" class="ml-auto">@csrf
                <button class="px-3 py-1.5 text-xs bg-stone-800 text-white rounded-lg hover:bg-stone-900">⬇ Backup Sekarang</button>
            </form>
        </div>

        <div class="px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-[11px] mb-3">
            ⚠️ Backup otomatis tiap malam <b>02:30</b>, disimpan 14 terakhir. Tapi filenya ada di <b>server yang sama</b> —
            itu melindungi dari salah hapus, <b>bukan</b> dari server rusak. <b>Unduh berkala</b> dan simpan di Drive/laptop.
        </div>

        @if(count($backups))
            <div class="divide-y divide-stone-100">
                @foreach($backups as $b)
                    <div class="flex items-center gap-3 py-2 text-xs">
                        <span class="font-mono text-stone-700">{{ $b['name'] }}</span>
                        <span class="text-stone-400">{{ $b['size'] }}</span>
                        <span class="text-stone-400">{{ $b['at'] }}</span>
                        <a href="{{ route('settings.backup.download', $b['name']) }}"
                            class="ml-auto text-indigo-600 hover:text-indigo-800 underline">unduh</a>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-xs text-stone-400">Belum ada backup. Klik "Backup Sekarang" untuk membuat yang pertama.</p>
        @endif
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 p-6 mt-6">
        <h3 class="text-sm font-bold text-stone-900 mb-2">Catatan Keamanan</h3>
        <ul class="text-xs text-stone-600 space-y-1.5 list-disc list-inside">
            <li>Sumber kebenaran user adalah tabel SQL <code class="bg-stone-100 px-1 rounded">users</code> — tidak ada Firestore.</li>
            <li>Password disimpan ter-hash (bcrypt). Tidak ada plaintext.</li>
            <li>Semua aksi sensitif tercatat di Audit Log.</li>
            <li>Soft delete diterapkan pada user, produk, dan PO untuk menjaga histori.</li>
            <li>Untuk beralih ke PostgreSQL, ubah <code class="bg-stone-100 px-1 rounded">DB_CONNECTION=pgsql</code> di .env.</li>
        </ul>
    </div>
</div>
@endsection
