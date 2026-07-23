<?php

namespace App\Providers;

use App\Models\CommunityLink;
use App\Services\ImpersonationService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Banner "sedang menyamar" harus muncul di SETIAP halaman, bukan cuma di
        // dashboard: lupa sedang jadi orang lain = data uji coba masuk ke akun
        // mitra sungguhan atas nama mereka.
        View::composer('layouts.app', function ($view) {
            $svc = app(ImpersonationService::class);
            $request = request();

            $view->with('impersonator', $request ? $svc->impersonator($request) : null);

            // Tombol "Gabung Komunitas WA" di sidebar: link komunitas untuk role
            // user yang sedang login (null bila tak ada / nonaktif / super_admin).
            $user = $request?->user();
            $community = $user ? CommunityLink::where('role', $user->role)->first() : null;
            $view->with('sidebarCommunity', $community && $community->visible() ? $community : null);
        });
    }
}
