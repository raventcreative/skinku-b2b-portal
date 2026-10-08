<?php

namespace App\Providers;

use App\Models\CommunityLink;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\Tools\AcademyTool;
use App\Services\Ai\Tools\AkunSosmedTool;
use App\Services\Ai\Tools\BahanBakuTool;
use App\Services\Ai\Tools\BuatKartuKanbanTool;
use App\Services\Ai\Tools\BuatMindmapTool;
use App\Services\Ai\Tools\DaftarPoTool;
use App\Services\Ai\Tools\DataKolTool;
use App\Services\Ai\Tools\DealKolTool;
use App\Services\Ai\Tools\DormansiMemberTool;
use App\Services\Ai\Tools\InsightKontenTool;
use App\Services\Ai\Tools\JaringanSayaTool;
use App\Services\Ai\Tools\KalkulatorRoiTool;
use App\Services\Ai\Tools\KomisiTool;
use App\Services\Ai\Tools\KontenViewsKolTool;
use App\Services\Ai\Tools\LaporanBisnisTool;
use App\Services\Ai\Tools\LaporanKeuanganTool;
use App\Services\Ai\Tools\LaporanPenjualanTool;
use App\Services\Ai\Tools\LaporanStokHqTool;
use App\Services\Ai\Tools\OkrTool;
use App\Services\Ai\Tools\OmzetMitraTool;
use App\Services\Ai\Tools\PaketJoinTool;
use App\Services\Ai\Tools\PemantauanStokTool;
use App\Services\Ai\Tools\PenarikanTool;
use App\Services\Ai\Tools\PenjualanDownlineTool;
use App\Services\Ai\Tools\PesananDownlineTool;
use App\Services\Ai\Tools\PesananMarketplaceTool;
use App\Services\Ai\Tools\PipelineKolTool;
use App\Services\Ai\Tools\PipelineKontenTool;
use App\Services\Ai\Tools\ProdukMasterTool;
use App\Services\Ai\Tools\ProduksiHppTool;
use App\Services\Ai\Tools\RekrutanSayaTool;
use App\Services\Ai\Tools\ReminderKolTool;
use App\Services\Ai\Tools\ReturTool;
use App\Services\Ai\Tools\RingkasDashboardTool;
use App\Services\Ai\Tools\RingkasKpiKanbanTool;
use App\Services\Ai\Tools\RingkasMindmapTool;
use App\Services\Ai\Tools\StokMarketplaceTool;
use App\Services\Ai\Tools\StokOpnameTool;
use App\Services\Ai\Tools\StrukturJaringanTool;
use App\Services\Ai\Tools\SupplierTool;
use App\Services\Ai\Tools\TambahMindmapTool;
use App\Services\Ai\Tools\TimGapokTool;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\Tools\ViewsHarianKolTool;
use App\Services\CommissionService;
use App\Services\Discovery\WebSearchFactory;
use App\Services\Discovery\WebSearchProvider;
use App\Services\HqStockReportService;
use App\Services\ImpersonationService;
use App\Services\KanbanKpiService;
use App\Services\MarketplaceMasterService;
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

        // Mesin pencari web untuk Rekomendasi AI (Tavily default, swappable).
        // Lazy + konstruksi tak melempar (hanya search() yang lempar bila key
        // kosong) → halaman lain aman. Di-swap FakeWebSearchProvider saat test.
        $this->app->bind(WebSearchProvider::class, fn () => WebSearchFactory::make());

        // Daftar alat yang boleh dipakai asisten (disaring per izin di ToolRegistry).
        $this->app->bind(ToolRegistry::class, fn ($app) => new ToolRegistry([
            new RingkasDashboardTool($app->make(ReportService::class)),
            new RingkasKpiKanbanTool($app->make(KanbanKpiService::class)),
            new BuatKartuKanbanTool,
            new RingkasMindmapTool,
            new BuatMindmapTool,
            new TambahMindmapTool,
            new LaporanStokHqTool($app->make(HqStockReportService::class)),
            new DaftarPoTool,
            new StokMarketplaceTool($app->make(MarketplaceMasterService::class)),
            new PesananMarketplaceTool,
            new KomisiTool($app->make(CommissionService::class)),
            $app->make(ViewsHarianKolTool::class),
            $app->make(DataKolTool::class),
            // Produk & Operasional
            new ProdukMasterTool,
            new PemantauanStokTool,
            new ReturTool,
            new BahanBakuTool,
            new ProduksiHppTool,
            new StokOpnameTool,
            // Laporan & Keuangan
            $app->make(LaporanPenjualanTool::class),
            $app->make(OmzetMitraTool::class),
            $app->make(PenjualanDownlineTool::class),
            $app->make(LaporanBisnisTool::class),
            $app->make(LaporanKeuanganTool::class),
            new PenarikanTool,
            // Mitra & Jaringan
            $app->make(StrukturJaringanTool::class),
            $app->make(JaringanSayaTool::class),
            $app->make(RekrutanSayaTool::class),
            $app->make(DormansiMemberTool::class),
            new PaketJoinTool,
            new PesananDownlineTool,
            // KOL lanjutan
            $app->make(TimGapokTool::class),
            new PipelineKolTool,
            $app->make(DealKolTool::class),
            $app->make(ReminderKolTool::class),
            $app->make(KontenViewsKolTool::class),
            // Konten
            new PipelineKontenTool,
            $app->make(InsightKontenTool::class),
            $app->make(AkunSosmedTool::class),
            // Lain-lain
            new SupplierTool,
            $app->make(KalkulatorRoiTool::class),
            new OkrTool,
            new AcademyTool,
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
