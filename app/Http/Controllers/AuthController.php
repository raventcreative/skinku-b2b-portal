<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use App\Services\ImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    /**
     * Authenticate against the SQL `users` table only.
     * - Accepts username OR email in the same field.
     * - No Firestore, no fallback role, no auto-create, no dev bypass.
     * - inactive / deleted accounts can never log in.
     */
    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [], [
            'login' => 'Username/Email',
        ]);

        $identifier = trim($data['login']);
        $genericError = ['login' => 'Username atau password salah.'];

        // Resolve username -> user, or email -> user. Single source of truth: SQL.
        $query = User::query();
        if (str_contains($identifier, '@')) {
            $query->where('email', mb_strtolower($identifier));
        } else {
            $query->where('username', mb_strtolower($identifier));
        }

        /** @var User|null $user */
        $user = $query->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages($genericError);
        }

        if ($user->status === User::STATUS_DELETED) {
            throw ValidationException::withMessages([
                'login' => 'Akun ini telah dihapus dari sistem. Hubungi Super Admin.',
            ]);
        }

        if ($user->status !== User::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'login' => 'Sesi ditolak. Akun mitra SKINKU Anda dinonaktifkan oleh Administrator.',
            ]);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        AuditService::log(
            action: 'login',
            targetType: 'user',
            targetId: $user->id,
            targetUserId: $user->id,
            targetEmail: $user->email,
        );

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if ($user) {
            AuditService::log(
                action: 'logout',
                targetType: 'user',
                targetId: $user->id,
                targetUserId: $user->id,
                targetEmail: $user->email,
            );
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /* ---------------- Forgot / reset password (email link) ---------------- */

    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        // Sending may fail if SMTP isn't configured yet — never let that 500 the
        // page, and always respond generically so we don't leak whether an email exists.
        try {
            Password::sendResetLink($request->only('email'));
        } catch (\Throwable $e) {
            report($e);
        }

        return back()->with('status', 'Jika email terdaftar, link reset password akan dikirim ke email tersebut.');
    }

    public function showResetPassword(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                AuditService::log(
                    action: 'reset_password',
                    targetType: 'user',
                    targetId: $user->id,
                    targetUserId: $user->id,
                    targetEmail: $user->email,
                );
            }
        );

        if ($status === Password::PasswordReset) {
            return redirect()->route('login')->with('status', 'Password berhasil diubah. Silakan login.');
        }

        throw ValidationException::withMessages(['email' => __($status)]);
    }

    /* ---------------- Change own password (logged in) ---------------- */

    public function showChangePassword()
    {
        return view('auth.change-password');
    }

    public function changePassword(Request $request): RedirectResponse
    {
        // Mengganti password milik akun yang sedang disamari akan mengunci
        // pemilik aslinya di luar, dan di log tampak seolah DIA yang menggantinya.
        // Super admin yang memang perlu mereset punya jalur sendiri di Kelola
        // Anggota, yang tercatat atas namanya.
        if ($request->session()->get(ImpersonationService::SESSION_KEY)) {
            return back()->withErrors([
                'password' => 'Tidak bisa mengganti password saat menyamai pengguna lain. Kembali ke akun Anda dulu.',
            ]);
        }

        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $user = $request->user();
        $user->password = Hash::make($request->input('password'));
        $user->save();

        AuditService::log(
            action: 'change_password',
            targetType: 'user',
            targetId: $user->id,
            targetUserId: $user->id,
            targetEmail: $user->email,
        );

        return back()->with('status', 'Password Anda berhasil diperbarui.');
    }
}
