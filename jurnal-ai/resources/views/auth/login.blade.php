<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="grid min-h-screen place-items-center px-4">
    <div class="w-full max-w-sm">
        <div class="mb-6 text-center">
            <span class="inline-grid size-11 place-items-center rounded-xl bg-brand-600 text-white font-bold">JA</span>
            <h1 class="mt-3 text-xl font-bold text-ink-900">{{ config('app.name') }}</h1>
            <p class="text-sm text-ink-500">Pencatatan jurnal berbantuan AI</p>
        </div>

        <form method="POST" action="{{ route('login') }}" class="card p-6 space-y-4">
            @csrf
            @if ($errors->any())
                <p class="rounded-lg bg-rose-50 border border-rose-200 px-3 py-2 text-sm text-rose-800">
                    {{ $errors->first() }}
                </p>
            @endif

            <div>
                <label class="label" for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus class="field">
            </div>
            <div>
                <label class="label" for="password">Password</label>
                <input type="password" id="password" name="password" required class="field">
            </div>
            <label class="flex items-center gap-2 text-sm text-ink-600">
                <input type="checkbox" name="remember" value="1" class="rounded border-ink-300">
                Ingat saya
            </label>
            <button class="btn-primary w-full">Masuk</button>
        </form>
    </div>
</body>
</html>
