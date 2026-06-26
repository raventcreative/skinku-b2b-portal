<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-stone-100 flex items-center justify-center p-4">
    <div class="w-full max-w-4xl bg-white rounded-3xl shadow-xl overflow-hidden grid md:grid-cols-2 min-h-[520px] border border-stone-200">
        {{-- Brand side --}}
        <div class="hidden md:flex bg-red-800 text-white p-12 flex-col justify-between">
            <div>
                <h1 class="text-3xl font-bold tracking-tight">SKINKU<span class="text-white text-4xl">.</span></h1>
                <p class="text-[11px] uppercase tracking-widest text-red-200 mt-2">B2B Distributor Portal</p>
                <p class="mt-10 text-2xl font-serif leading-snug text-red-50">Sinergi Keindahan &amp; Sistem Distribusi Cerdas.</p>
                <div class="w-16 h-[2px] bg-white mt-6"></div>
            </div>
        </div>

        {{-- Form side --}}
        <div class="p-10 md:p-12 flex flex-col justify-center bg-stone-50">
            <div class="max-w-sm w-full mx-auto">
                <h2 class="text-2xl font-bold tracking-tight text-stone-900">Portal Log Masuk</h2>
                <p class="text-xs text-stone-500 mt-1 mb-6">Masuk menggunakan akun keanggotaan SKINKU Anda.</p>

                @if(session('status'))
                    <div class="mb-4 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs">{{ session('status') }}</div>
                @endif
                @if($errors->any())
                    <div class="mb-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs">
                        @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('login') }}" class="space-y-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-semibold text-stone-700 mb-1">Username / Email</label>
                        <input name="login" value="{{ old('login') }}" required autofocus
                               class="w-full px-4 py-2.5 bg-white text-sm border border-stone-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-red-600"
                               placeholder="username atau email">
                    </div>
                    <div class="relative">
                          <div>
                        <div class="flex justify-between items-center mb-1">
                            <label class="block text-xs font-semibold text-stone-700">Password</label>
                            <a href="{{ route('password.request') }}" class="text-xs text-stone-500 hover:text-stone-800 hover:underline">Lupa Password?</a>
                        </div>
                        <input type="password" name="password" required
                               class="w-full px-4 py-2.5 bg-white text-sm border border-stone-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-red-600"
                               placeholder="password">
                        <button type="button" 
                        onclick="onst i=this.previousElementSibling; i.type=i.type==='password'?'text':'password'"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            👁️
                        </button>
                    </div>
                    </div>
                    <label class="flex items-center gap-2 text-xs text-stone-600">
                        <input type="checkbox" name="remember" class="rounded border-stone-300"> Ingat saya
                    </label>
                    <button type="submit" class="w-full py-3 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl transition">Log Masuk Sekarang</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
