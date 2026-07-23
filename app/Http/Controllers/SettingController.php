<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Services\Ai\AiProviderFactory;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SettingController extends Controller
{
    /**
     * System settings. Read-only environment summary for super_admin.
     * (Sensitive settings live in .env and are never editable from the UI.)
     */
    public function index(Request $request)
    {
        $info = [
            'app_name' => config('app.name'),
            'app_env' => config('app.env'),
            'app_url' => config('app.url'),
            'db_driver' => config('database.default'),
            'db_database' => config('database.connections.'.config('database.default').'.database'),
            'mail_mailer' => config('mail.default'),
            'filesystem' => config('filesystems.default'),
            'timezone' => config('app.timezone'),
            'locale' => config('app.locale'),
        ];

        $available = AiProviderFactory::available();

        return view('settings.index', [
            'info' => $info,
            'backups' => $this->backupList(),
            'ai' => [
                'available' => $available,
                'provider' => AppSetting::get('ai_provider', (string) config('services.ai.provider')),
                'model' => AppSetting::get('ai_model', (string) config('services.ai.default_model')),
            ],
        ]);
    }

    /**
     * Pilih otak Asisten AI: provider (hanya yang ada key-nya di .env) + model.
     * Key tetap di .env — di sini cuma memilih, bukan menyimpan kredensial.
     */
    public function saveAi(Request $request): RedirectResponse
    {
        $available = array_keys(AiProviderFactory::available());
        if ($available === []) {
            return back()->with('error', 'Belum ada provider AI yang siap. Isi OPENAI_API_KEY (atau ANTHROPIC_API_KEY) di .env server dulu.');
        }

        $data = $request->validate([
            'ai_provider' => ['required', Rule::in($available)],
            'ai_model' => ['required', 'string', 'max:100'],
        ]);

        AppSetting::put('ai_provider', $data['ai_provider']);
        AppSetting::put('ai_model', trim($data['ai_model']));

        AuditService::log(action: 'save_ai_settings', targetType: 'app_setting', after: ['provider' => $data['ai_provider'], 'model' => $data['ai_model']]);

        return back()->with('status', 'Pengaturan Asisten AI disimpan.');
    }

    /** Jalankan backup sekarang (selain jadwal harian 02:30). */
    public function backupNow(): RedirectResponse
    {
        $code = Artisan::call('db:backup');
        $out = trim(Artisan::output());

        return $code === 0
            ? back()->with('status', $out ?: 'Backup selesai.')
            : back()->with('error', 'Backup gagal: '.$out);
    }

    /**
     * Unduh 1 file backup. Backup di server yang sama tidak melindungi dari disk
     * mati — unduh berkala & simpan di luar server.
     */
    public function backupDownload(string $file): BinaryFileResponse
    {
        // Cocokkan ke daftar nyata: menutup path traversal (../) sekaligus.
        abort_unless(collect($this->backupList())->contains('name', $file), 404);

        return response()->download(storage_path('app/backups/'.$file));
    }

    /** @return array<int, array{name:string, size:string, at:string}> */
    private function backupList(): array
    {
        $dir = storage_path('app/backups');
        if (! File::isDirectory($dir)) {
            return [];
        }

        return collect(File::files($dir))
            ->filter(fn ($f) => str_ends_with($f->getFilename(), '.sql.gz'))
            ->sortByDesc(fn ($f) => $f->getFilename())
            ->map(fn ($f) => [
                'name' => $f->getFilename(),
                'size' => number_format($f->getSize() / 1048576, 2).' MB',
                'at' => date('d M Y H:i', $f->getMTime()),
            ])->values()->all();
    }
}
