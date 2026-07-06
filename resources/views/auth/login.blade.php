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
            <p class="text-[11px] text-red-200">Power by AIpreneurship</p>
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
                    <div>
                        <div class="flex justify-between items-center mb-1">
                            <label class="block text-xs font-semibold text-stone-700">Password</label>
                            <a href="{{ route('password.request') }}" class="text-xs text-stone-500 hover:text-stone-800 hover:underline">Lupa Password?</a>
                        </div>
                        <div class="relative">
                            <input type="password" name="password" id="password" required
                                   class="w-full px-4 py-2.5 pr-11 bg-white text-sm border border-stone-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-red-600"
                                   placeholder="password">
                            <button type="button" id="togglePassword" aria-label="Tampilkan password"
                                    class="absolute inset-y-0 right-0 flex items-center px-3 text-stone-400 hover:text-stone-700 focus:outline-none">
                                <svg id="eyeOpen" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="w-5 h-5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                                <svg id="eyeClosed" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="w-5 h-5 hidden">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.243 4.243L9.88 9.88" />
                                </svg>
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

    <script>
        (function () {
            var toggle = document.getElementById('togglePassword');
            var input = document.getElementById('password');
            var eyeOpen = document.getElementById('eyeOpen');
            var eyeClosed = document.getElementById('eyeClosed');
            if (!toggle || !input) return;
            toggle.addEventListener('click', function () {
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                eyeOpen.classList.toggle('hidden', show);
                eyeClosed.classList.toggle('hidden', !show);
                toggle.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
                input.focus();
            });
        })();
    </script>
</body>
</html>
