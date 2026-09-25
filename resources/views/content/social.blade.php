@extends('layouts.app')
@section('title', 'Akun Sosial Media')
@section('heading', 'Akun Sosial Media Brand')

@section('content')
@php
    $rows = [
        'facebook' => ['Facebook Page', 'meta', 'Lewat tombol Hubungkan Meta.'],
        'instagram' => ['Instagram Business', 'meta', 'Otomatis ikut Page yang tertaut ke akun IG Business.'],
        'threads' => ['Threads', 'threads', 'Login Threads terpisah.'],
        'tiktok' => ['TikTok', 'tiktok', 'Selama belum terhubung, konten TikTok diposting manual oleh admin (download media + salin caption).'],
    ];
@endphp
<div class="space-y-5 max-w-4xl">
    <p class="text-sm text-stone-500">Konten yang disetujui terbit ke akun-akun di bawah. Token tersimpan terenkripsi dan tidak pernah ditampilkan.
        Media diambil platform dari <b>{{ config('app.url') }}</b> — harus domain HTTPS publik.</p>

    @if($pages)
        <form method="POST" action="{{ route('social.page') }}" class="bg-amber-50 border border-amber-200 rounded-2xl p-5 space-y-3">@csrf
            <p class="text-sm font-bold text-amber-900">Pilih Facebook Page brand SKINKU</p>
            @foreach($pages as $p)
                <label class="flex items-center gap-2 text-sm text-stone-800">
                    <input type="radio" name="page_id" value="{{ $p['id'] }}" required> {{ $p['name'] }}
                    <span class="text-xs text-stone-500">{{ $p['ig'] ? 'IG @'.$p['ig'] : 'tanpa Instagram Business' }}</span>
                </label>
            @endforeach
            <button class="px-4 py-2 text-sm bg-stone-800 text-white rounded-lg font-semibold">Simpan Page</button>
        </form>
    @endif

    <div class="bg-white rounded-2xl border border-stone-200 divide-y divide-stone-100">
        @foreach($rows as $platform => [$label, $provider, $hint])
            @php $c = $connections[$platform] ?? null; @endphp
            <div class="px-5 py-4 flex items-center justify-between gap-4 flex-wrap">
                <div class="min-w-0">
                    <p class="text-sm font-bold text-stone-800">{{ $label }}</p>
                    @if($c)
                        <p class="text-xs text-stone-600">{{ $c->account_name ?? $c->account_id }} · dihubungkan {{ $c->connectedBy?->displayName() ?? '-' }} {{ $c->updated_at?->diffForHumans() }}</p>
                        <p class="text-[11px] {{ $c->isActive() && ! $c->expiringSoon() ? 'text-emerald-700' : 'text-rose-700' }}">
                            @if(! $c->isActive()) Bermasalah — hubungkan ulang. {{ $c->last_error }}
                            @elseif($c->access_expires_at) Token berlaku s/d {{ $c->access_expires_at->format('d M Y') }} (diperpanjang otomatis)
                            @else Aktif @endif
                        </p>
                    @else
                        <p class="text-xs text-stone-400">Belum terhubung. {{ $hint }}</p>
                    @endif
                </div>
                <div class="flex items-center gap-2">
                    @if($provider)
                        @php $ready = ['meta' => $metaReady, 'threads' => $threadsReady, 'tiktok' => $tiktokReady][$provider]; @endphp
                        @if($ready)
                            <a href="{{ route('social.connect', $provider) }}" class="px-3 py-1.5 text-xs bg-red-600 text-white rounded-lg font-semibold hover:bg-red-700">{{ $c ? 'Hubungkan ulang' : 'Hubungkan '.['meta' => 'Meta', 'threads' => 'Threads', 'tiktok' => 'TikTok'][$provider] }}</a>
                        @else
                            <span class="text-[11px] text-stone-400">Isi {{ ['meta' => 'META_APP_ID/SECRET', 'threads' => 'THREADS_APP_ID/SECRET', 'tiktok' => 'TIKTOK_CONTENT_CLIENT_KEY/SECRET'][$provider] }} di .env</span>
                        @endif
                    @endif
                    @if($c)
                        <form method="POST" action="{{ route('social.destroy', $platform) }}" onsubmit="return confirm('Putuskan {{ $label }}? Postingan terjadwal ke platform ini akan gagal.')">
                            @csrf @method('DELETE')
                            <button class="px-3 py-1.5 text-xs border border-stone-300 text-stone-600 rounded-lg hover:bg-stone-50">Putuskan</button>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
