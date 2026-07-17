<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * "Masuk sebagai" pengguna lain untuk keperluan uji coba — tanpa password.
 *
 * Ini pengganti gagasan "password master". Password master punya satu cacat yang
 * tak bisa ditambal: di log, admin dan pengguna asli jadi TAK TERBEDAKAN. Kalau
 * mitra protes "saya tak pernah membuat PO ini", tak ada cara membuktikan itu
 * admin yang testing atau dia sendiri. Ia juga satu titik kegagalan: satu rahasia
 * bocor = semua akun jatuh, dan rahasia bersama selalu bocor pada akhirnya.
 *
 * Di sini tidak ada rahasia baru sama sekali. Yang dipakai adalah sesi super
 * admin yang SUDAH terautentikasi, id aslinya disimpan di sesi, dan tiap awal
 * maupun akhir tercatat di Audit Log dengan dua nama sekaligus.
 */
class ImpersonationService
{
    /** Kunci sesi berisi id super admin asli selama menyamar. */
    public const SESSION_KEY = 'impersonator_id';

    public function start(Request $request, User $actor, User $target): void
    {
        if (! $actor->isSuperAdmin()) {
            throw new RuntimeException('Hanya Super Admin yang boleh masuk sebagai pengguna lain.');
        }

        // Sudah menyamar lalu menyamar lagi akan menimpa id asli di sesi dan
        // menjebak admin di akun orang tanpa jalan pulang.
        if ($this->isImpersonating($request)) {
            throw new RuntimeException('Anda sedang menyamar. Kembali ke akun Anda dulu sebelum berpindah.');
        }

        if ($actor->id === $target->id) {
            throw new RuntimeException('Anda sudah masuk sebagai diri sendiri.');
        }

        // Menyamar sesama super admin = memakai akun yang setara kuasanya atas
        // nama orang lain. Tak ada alasan uji coba yang membenarkan itu.
        if ($target->isSuperAdmin()) {
            throw new RuntimeException('Akun Super Admin tidak bisa disamari.');
        }

        if (! $target->isActive()) {
            throw new RuntimeException('Akun ini tidak aktif, tidak bisa disamari.');
        }

        AuditService::log(
            action: 'impersonate_start',
            targetType: 'user',
            targetId: $target->id,
            after: ['sebagai' => $target->username, 'peran' => $target->role],
            targetUserId: $target->id,
            targetEmail: $target->email,
        );

        // Id asli disimpan SEBELUM login() supaya tak hilang saat sesi berganti.
        $actorId = $actor->id;
        Auth::login($target);
        $request->session()->put(self::SESSION_KEY, $actorId);
    }

    /**
     * Kembali ke akun super admin asli.
     *
     * Sengaja TIDAK memeriksa peran pemanggil: yang login saat ini justru
     * pengguna yang disamari (mis. reseller). Yang dipercaya adalah kunci sesi,
     * dan kunci itu hanya bisa ada kalau start() yang menaruhnya.
     */
    public function stop(Request $request): User
    {
        $actorId = $request->session()->get(self::SESSION_KEY);

        if (! $actorId) {
            throw new RuntimeException('Anda tidak sedang menyamar.');
        }

        $actor = User::find($actorId);

        if (! $actor || ! $actor->isSuperAdmin() || ! $actor->isActive()) {
            // Akun aslinya hilang/dinonaktifkan saat menyamar: jangan biarkan
            // sesi tetap hidup sebagai orang lain — putus total.
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new RuntimeException('Akun asli Anda sudah tidak berlaku. Silakan login ulang.');
        }

        $samaran = Auth::user();

        $request->session()->forget(self::SESSION_KEY);
        Auth::login($actor);

        AuditService::log(
            action: 'impersonate_stop',
            targetType: 'user',
            targetId: $samaran?->id,
            after: ['selesai_sebagai' => $samaran?->username],
            targetUserId: $samaran?->id,
            targetEmail: $samaran?->email,
        );

        return $actor;
    }

    public function isImpersonating(Request $request): bool
    {
        return (bool) $request->session()->get(self::SESSION_KEY);
    }

    /** Super admin asli di balik sesi samaran, untuk ditampilkan di banner. */
    public function impersonator(Request $request): ?User
    {
        $id = $request->session()->get(self::SESSION_KEY);

        return $id ? User::find($id) : null;
    }
}
