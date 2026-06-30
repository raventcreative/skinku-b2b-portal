<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lupa Password · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-stone-100 flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-xl p-8 border border-stone-200">
        <h1 class="text-xl font-bold text-stone-900">Lupa Password</h1>
        <p class="text-xs text-stone-500 mt-1 mb-5">Masukkan email akun Anda. Kami akan mengirimkan tautan untuk mengatur ulang kata sandi.</p>

        @if(session('status'))
            <div class="mb-4 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="mb-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs">
                @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-stone-700 mb-1">Alamat Email Akun</label>
                <input type="email" name="email" value="{{ old('email') }}" required
                       class="w-full px-4 py-2.5 bg-white text-sm border border-stone-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-red-600"
                       placeholder="mitra@skinku.id">
            </div>
            <button type="submit" class="w-full py-3 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Kirim Link Reset Password</button>
            <a href="{{ route('login') }}" class="block text-center text-xs text-stone-500 hover:text-stone-800 hover:underline">Kembali ke Login</a>
        </form>
    </div>
</body>
</html>
