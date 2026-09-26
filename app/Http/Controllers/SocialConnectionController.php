<?php

namespace App\Http\Controllers;

use App\Models\SocialConnection;
use App\Services\AuditService;
use App\Services\Social\MetaClient;
use App\Services\Social\TikTokContentClient;
use App\Support\SocialCredentials;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Hubungkan akun brand SKINKU (FR-40..46): Meta (Facebook Page + Instagram Business
 * yang tertaut) dan Threads. OAuth dilindungi parameter state acak di sesi.
 */
class SocialConnectionController extends Controller
{
    public const PLATFORMS = ['facebook', 'instagram', 'threads', 'tiktok'];

    public const PROVIDERS = ['meta', 'threads', 'tiktok'];

    public function __construct(private MetaClient $meta, private TikTokContentClient $tiktok) {}

    public function index(Request $request): View
    {
        return view('content.social', [
            'connections' => SocialConnection::with('connectedBy')->get()->keyBy('platform'),
            'pages' => collect($this->pendingPages($request))->map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'] ?? $p['id'], 'ig' => $p['instagram_business_account']['username'] ?? null])->all(),
            'credentials' => SocialCredentials::status(),
            'metaReady' => $this->meta->metaConfigured(),
            'threadsReady' => $this->meta->threadsConfigured(),
            'tiktokReady' => $this->tiktok->configured(),
        ]);
    }

    /** Isi kredensial app dari portal (alternatif .env). Rahasia tak pernah ditampilkan balik. */
    public function saveCredentials(Request $request): RedirectResponse
    {
        $data = $request->validate(array_fill_keys(array_keys(SocialCredentials::FIELDS), ['nullable', 'string', 'max:255']));
        $changed = SocialCredentials::save($data);
        if ($changed === []) {
            return redirect()->route('social.index')->with('error', 'Tidak ada kredensial yang diisi.');
        }
        // Audit tanpa nilai — hanya kolom mana yang diubah.
        AuditService::log(action: 'social.credentials', targetType: 'app_setting', after: ['diubah' => $changed]);

        return redirect()->route('social.index')->with('status', 'Kredensial disimpan: '.implode(', ', $changed).'.');
    }

    public function connect(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
        $ready = match ($provider) {
            'meta' => $this->meta->metaConfigured(),
            'threads' => $this->meta->threadsConfigured(),
            'tiktok' => $this->tiktok->configured(),
        };
        if (! $ready) {
            $env = ['meta' => 'META_APP_ID/SECRET', 'threads' => 'THREADS_APP_ID/SECRET', 'tiktok' => 'TIKTOK_CONTENT_CLIENT_KEY/SECRET'][$provider];

            return redirect()->route('social.index')->with('error', "{$env} belum diisi di .env server.");
        }

        $state = Str::random(40);
        $request->session()->put("oauth_state_{$provider}", $state);
        $redirect = route('social.callback', $provider);

        return redirect()->away(match ($provider) {
            'meta' => $this->meta->facebookAuthorizeUrl($redirect, $state),
            'threads' => $this->meta->threadsAuthorizeUrl($redirect, $state),
            'tiktok' => $this->tiktok->authorizeUrl($redirect, $state),
        });
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
        $expected = $request->session()->pull("oauth_state_{$provider}");
        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect()->route('social.index')->with('error', 'Sesi otorisasi tidak valid (state tidak cocok). Ulangi hubungkan akun.');
        }
        if (! $code = $request->query('code')) {
            return redirect()->route('social.index')->with('error', 'Otorisasi dibatalkan.');
        }

        $redirect = route('social.callback', $provider);
        try {
            if ($provider === 'tiktok') {
                $t = $this->tiktok->exchangeCode($code, $redirect);
                $user = $this->tiktok->userInfo($t['access_token']);
                $creator = $this->tiktok->creatorInfo($t['access_token']);
                $this->store('tiktok', $t['open_id'], $user['display_name'] ?? ($creator['creator_nickname'] ?? null), $t['access_token'],
                    now()->addSeconds($t['expires_in']), $request,
                    ['username' => $creator['creator_username'] ?? null, 'scope' => $t['scope'] ?? null,
                        'refresh_expires_at' => now()->addSeconds($t['refresh_expires_in'] ?? 0)->toDateTimeString()],
                    $t['refresh_token']);

                return redirect()->route('social.index')->with('status', 'TikTok terhubung.');
            }
            if ($provider === 'threads') {
                $t = $this->meta->threadsToken($code, $redirect);
                $this->store('threads', $t['user_id'], $t['username'] ? '@'.$t['username'] : null, $t['token'],
                    $t['expires_in'] ? now()->addSeconds($t['expires_in']) : null, $request);

                return redirect()->route('social.index')->with('status', 'Threads terhubung.');
            }

            $pages = $this->meta->pages($this->meta->facebookUserToken($code, $redirect));
            if ($pages === []) {
                return redirect()->route('social.index')->with('error', 'Akun Facebook ini tidak mengelola Page apa pun.');
            }
            if (count($pages) === 1) {
                return $this->savePage($pages[0], $request);
            }
            // Lebih dari satu Page → admin memilih. Token Page disimpan di sesi server sementara.
            $request->session()->put('meta_pages', encrypt($pages));

            return redirect()->route('social.index')->with('status', 'Pilih Facebook Page brand SKINKU.');
        } catch (Throwable $e) {
            return redirect()->route('social.index')->with('error', 'Gagal menghubungkan: '.MetaClient::sanitize($e->getMessage()));
        }
    }

    public function selectPage(Request $request): RedirectResponse
    {
        $id = $request->validate(['page_id' => ['required', 'string']])['page_id'];
        $page = collect($this->pendingPages($request))->firstWhere('id', $id);
        $request->session()->forget('meta_pages');
        abort_unless($page, 422, 'Page tidak ditemukan — ulangi hubungkan Meta.');

        return $this->savePage($page, $request);
    }

    public function destroy(string $platform): RedirectResponse
    {
        abort_unless(in_array($platform, self::PLATFORMS, true), 404);
        SocialConnection::where('platform', $platform)->delete();
        AuditService::log(action: 'social.disconnect', targetType: 'social_connection', after: ['platform' => $platform]);

        return redirect()->route('social.index')->with('status', ucfirst($platform).' diputus.');
    }

    /** Daftar Page hasil OAuth yang menunggu dipilih — terenkripsi di sesi (berisi token Page). */
    private function pendingPages(Request $request): array
    {
        $raw = $request->session()->get('meta_pages');

        return $raw ? decrypt($raw) : [];
    }

    private function savePage(array $page, Request $request): RedirectResponse
    {
        $this->store('facebook', $page['id'], $page['name'] ?? null, $page['access_token'], null, $request);

        $ig = $page['instagram_business_account'] ?? null;
        if ($ig) {
            $this->store('instagram', $ig['id'], isset($ig['username']) ? '@'.$ig['username'] : null, $page['access_token'], null, $request,
                ['page_id' => $page['id']]);
        }

        return redirect()->route('social.index')->with('status', 'Facebook Page "'.($page['name'] ?? $page['id']).'" terhubung'
            .($ig ? ' + Instagram.' : '. Instagram Business belum tertaut ke Page ini — tautkan di Meta Business Suite lalu hubungkan ulang.'));
    }

    private function store(string $platform, string $accountId, ?string $name, string $token, $expires, Request $request, array $meta = [], ?string $refresh = null): void
    {
        SocialConnection::updateOrCreate(['platform' => $platform], [
            'account_id' => $accountId, 'account_name' => $name, 'access_token' => $token, 'refresh_token' => $refresh,
            'access_expires_at' => $expires, 'status' => 'active', 'last_error' => null,
            'meta' => $meta ?: null, 'connected_by' => $request->user()->id,
        ]);
        AuditService::log(action: 'social.connect', targetType: 'social_connection', after: ['platform' => $platform, 'account' => $name ?? $accountId]);
    }
}
