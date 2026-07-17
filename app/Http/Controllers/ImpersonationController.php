<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ImpersonationController extends Controller
{
    public function __construct(private ImpersonationService $impersonation) {}

    public function start(Request $request, User $user): RedirectResponse
    {
        try {
            $this->impersonation->start($request, $request->user(), $user);
        } catch (RuntimeException $e) {
            return back()->withErrors(['impersonate' => $e->getMessage()]);
        }

        return redirect()->route('dashboard')
            ->with('status', "Anda sekarang masuk sebagai {$user->fullname}. Klik \"Kembali ke akun saya\" di banner atas untuk berhenti.");
    }

    /**
     * Rute ini TIDAK boleh dibatasi permission:manage_users — yang memanggilnya
     * adalah pengguna yang sedang disamari (mis. reseller), yang justru tak punya
     * hak itu. Membatasinya akan menjebak admin di akun orang tanpa jalan pulang.
     */
    public function stop(Request $request): RedirectResponse
    {
        try {
            $actor = $this->impersonation->stop($request);
        } catch (RuntimeException $e) {
            return redirect()->route('dashboard')->withErrors(['impersonate' => $e->getMessage()]);
        }

        return redirect()->route('users.index')
            ->with('status', "Kembali sebagai {$actor->fullname}.");
    }
}
