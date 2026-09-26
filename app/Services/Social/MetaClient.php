<?php

namespace App\Services\Social;

use App\Support\SocialCredentials;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP client Graph API Meta (Facebook Page + Instagram) & Threads API — pakai
 * Http facade bawaan, tanpa SDK. Token dikirim lewat header Authorization
 * (bukan query string) supaya tak pernah bocor ke pesan error/log.
 */
class MetaClient
{
    public const THREADS_BASE = 'https://graph.threads.net/v1.0';

    public const FB_SCOPES = [
        'pages_show_list', 'pages_read_engagement', 'pages_manage_posts',
        'instagram_basic', 'instagram_content_publish', 'business_management',
    ];

    public const THREADS_SCOPES = ['threads_basic', 'threads_content_publish'];

    public function __construct()
    {
        SocialCredentials::apply(); // kredensial yang diisi di portal menimpa .env
    }

    public function graphBase(): string
    {
        return 'https://graph.facebook.com/'.config('services.meta.graph_version');
    }

    public function metaConfigured(): bool
    {
        return filled(config('services.meta.app_id')) && filled(config('services.meta.app_secret'));
    }

    public function threadsConfigured(): bool
    {
        return filled(config('services.threads.app_id')) && filled(config('services.threads.app_secret'));
    }

    public function get(string $base, string $path, string $token, array $query = []): array
    {
        return $this->decode(Http::withToken($token)->timeout(60)->get($base.$path, $query));
    }

    public function post(string $base, string $path, string $token, array $data = []): array
    {
        return $this->decode(Http::withToken($token)->timeout(120)->asForm()->post($base.$path, $data));
    }

    /* ---------------- OAuth Facebook Login (FB Page + IG Business) ---------------- */

    public function facebookAuthorizeUrl(string $redirect, string $state): string
    {
        return 'https://www.facebook.com/'.config('services.meta.graph_version').'/dialog/oauth?'.http_build_query([
            'client_id' => config('services.meta.app_id'),
            'redirect_uri' => $redirect,
            'state' => $state,
            'scope' => implode(',', self::FB_SCOPES),
            'response_type' => 'code',
        ]);
    }

    /** code → token user long-lived (±60 hari). Page token turunannya tak kedaluwarsa. */
    public function facebookUserToken(string $code, string $redirect): string
    {
        $short = $this->decode(Http::get($this->graphBase().'/oauth/access_token', [
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'redirect_uri' => $redirect,
            'code' => $code,
        ]))['access_token'];

        return $this->decode(Http::get($this->graphBase().'/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'fb_exchange_token' => $short,
        ]))['access_token'];
    }

    /** @return array<int,array{id:string,name:string,access_token:string,instagram_business_account?:array}> */
    public function pages(string $userToken): array
    {
        return $this->get($this->graphBase(), '/me/accounts', $userToken, [
            'fields' => 'id,name,access_token,instagram_business_account{id,username}',
        ])['data'] ?? [];
    }

    /* ---------------- OAuth Threads ---------------- */

    public function threadsAuthorizeUrl(string $redirect, string $state): string
    {
        return 'https://threads.net/oauth/authorize?'.http_build_query([
            'client_id' => config('services.threads.app_id'),
            'redirect_uri' => $redirect,
            'state' => $state,
            'scope' => implode(',', self::THREADS_SCOPES),
            'response_type' => 'code',
        ]);
    }

    /** code → token long-lived (±60 hari) + profil. @return array{token:string,expires_in:int,user_id:string,username:?string} */
    public function threadsToken(string $code, string $redirect): array
    {
        $short = $this->decode(Http::asForm()->post(self::THREADS_BASE.'/oauth/access_token', [
            'client_id' => config('services.threads.app_id'),
            'client_secret' => config('services.threads.app_secret'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirect,
            'code' => $code,
        ]));

        $long = $this->decode(Http::get('https://graph.threads.net/access_token', [
            'grant_type' => 'th_exchange_token',
            'client_secret' => config('services.threads.app_secret'),
            'access_token' => $short['access_token'],
        ]));

        $me = $this->get(self::THREADS_BASE, '/me', $long['access_token'], ['fields' => 'id,username']);

        return ['token' => $long['access_token'], 'expires_in' => (int) ($long['expires_in'] ?? 0),
            'user_id' => (string) ($me['id'] ?? $short['user_id']), 'username' => $me['username'] ?? null];
    }

    /** Perpanjang token Threads long-lived. @return array{token:string,expires_in:int} */
    public function refreshThreadsToken(string $token): array
    {
        $res = $this->decode(Http::get('https://graph.threads.net/refresh_access_token', [
            'grant_type' => 'th_refresh_token',
            'access_token' => $token,
        ]));

        return ['token' => $res['access_token'], 'expires_in' => (int) ($res['expires_in'] ?? 0)];
    }

    /** Buang rahasia dari pesan error (exception koneksi Guzzle menyertakan URL lengkap). */
    public static function sanitize(string $message): string
    {
        return mb_substr(preg_replace('/(access_token|refresh_token|client_secret|client_key|fb_exchange_token|code)=[^&\s"]+/', '$1=***', $message), 0, 1000);
    }

    private function decode($response): array
    {
        $json = $response->json() ?? [];
        if ($response->failed() || isset($json['error'])) {
            $msg = $json['error']['error_user_msg'] ?? $json['error']['message'] ?? ('HTTP '.$response->status());
            throw new RuntimeException('Meta API: '.$msg);
        }

        return $json;
    }
}
