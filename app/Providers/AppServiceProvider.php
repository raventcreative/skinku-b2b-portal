<?php

namespace App\Providers;

use App\Models\CommunityLink;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\Tools\BuatKartuKanbanTool;
use App\Services\Ai\Tools\RingkasDashboardTool;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\ImpersonationService;
use App\Services\ReportService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Otak AI aktif (lazy — baru dibuat saat dipakai, jadi key kosong tak
        // bikin halaman lain 500). Di-swap FakeAiProvider saat test.
        $this->app->bind(AiProvider::class, fn () => AiProviderFactory::make());

        // Daftar alat yang boleh dipakai asisten (disaring per izin di ToolRegistry).
        $this->app->bind(ToolRegistry::class, fn ($app) => new ToolRegistry([
            new RingkasDashboardTool($app->make(ReportService::class)),
            new BuatKartuKanbanTool,
        ]));
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
