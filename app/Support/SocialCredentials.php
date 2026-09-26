<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Kredensial app Meta/Threads/TikTok yang bisa diisi dari halaman Akun Sosial Media
 * (selain .env). Disimpan terenkripsi di app_settings; nilai dari portal menimpa .env.
 * Diterapkan lazy oleh MetaClient & TikTokContentClient — tak ada query di request lain.
 */
class SocialCredentials
{
    /** field form => [kunci config, label, rahasia?] */
    public const FIELDS = [
        'meta_app_id' => ['services.meta.app_id', 'Meta App ID', false],
        'meta_app_secret' => ['services.meta.app_secret', 'Meta App Secret', true],
        'threads_app_id' => ['services.threads.app_id', 'Threads App ID', false],
        'threads_app_secret' => ['services.threads.app_secret', 'Threads App Secret', true],
        'tiktok_client_key' => ['services.tiktok_content.client_key', 'TikTok Client Key', false],
        'tiktok_client_secret' => ['services.tiktok_content.client_secret', 'TikTok Client Secret', true],
    ];

    private const PREFIX = 'social_cred.';

    private const APPLIED = 'services.social_credentials_applied';

    /** Timpa config() dengan nilai tersimpan (sekali per proses). */
    public static function apply(): void
    {
        if (config(self::APPLIED)) {
            return;
        }
        config([self::APPLIED => true]);

        try {
            $rows = AppSetting::where('key', 'like', self::PREFIX.'%')->pluck('value', 'key');
        } catch (Throwable) {
            return; // tabel belum ada (instalasi baru / migrasi) → pakai .env saja
        }
        foreach (self::FIELDS as $field => [$configKey]) {
            $raw = $rows[self::PREFIX.$field] ?? null;
            if (filled($raw)) {
                try {
                    config([$configKey => Crypt::decryptString($raw)]);
                } catch (Throwable) {
                    // APP_KEY berganti → nilai lama tak terbaca; abaikan, .env tetap berlaku
                }
            }
        }
    }

    /** Simpan isian form. Field kosong = pertahankan nilai lama. @return list<string> label yang diubah */
    public static function save(array $input): array
    {
        $changed = [];
        foreach (self::FIELDS as $field => [, $label]) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            AppSetting::put(self::PREFIX.$field, Crypt::encryptString($value));
            $changed[] = $label;
        }
        config([self::APPLIED => false]); // terapkan ulang sekarang juga (proses ini, mis. worker/test)
        self::apply();

        return $changed;
    }

    /** Status per field untuk tampilan (tanpa membuka rahasia). @return array<string, array{label:string, secret:bool, source:?string, value:?string}> */
    public static function status(): array
    {
        $saved = AppSetting::where('key', 'like', self::PREFIX.'%')->pluck('value', 'key');
        $out = [];
        foreach (self::FIELDS as $field => [$configKey, $label, $secret]) {
            $inPortal = filled($saved[self::PREFIX.$field] ?? null);
            $value = config($configKey);
            $out[$field] = [
                'label' => $label,
                'secret' => $secret,
                'source' => $inPortal ? 'portal' : (filled($value) ? '.env' : null),
                'value' => $secret ? null : $value,   // ID/key publik boleh tampil, secret tidak pernah
            ];
        }

        return $out;
    }
}
