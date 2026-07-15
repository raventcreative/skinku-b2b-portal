<?php

namespace App\Support;

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;

/**
 * Central registry of configurable capabilities and the source of truth for
 * "can this role do X?".
 *
 * Rules:
 *  - super_admin ALWAYS has every permission (locked — cannot be revoked).
 *  - For other roles, a saved row in `role_permissions` wins; if none exists,
 *    the built-in DEFAULTS apply. This means the matrix works out-of-the-box
 *    without seeding, and admins only persist what they change.
 */
class Permissions
{
    /** key => human label (also defines the rows shown in the matrix, in order). */
    public const DEFINITIONS = [
        'create_po' => 'Buat Purchase Order',
        'update_po_status' => 'Update Status PO',
        'delete_po' => 'Hapus PO',
        'manage_products' => 'Kelola Produk',
        'manage_users' => 'Kelola User / Anggota',
        'delete_users' => 'Hapus User',
        'manage_hq_stock' => 'Kelola Stok Pusat & Stock Movement',
        'receive_stock' => 'Terima Stok Masuk & HPP',
        'manage_production' => 'Bahan Baku & Produksi (HPP)',
        'view_reports' => 'Lihat Laporan Penjualan',
        'view_accounting' => 'Akuntansi & Laporan Keuangan',
        'delete_accounting' => 'Hapus Jurnal / Data Akuntansi (BAHAYA)',
        'view_audit_log' => 'Lihat Audit Log',
        'system_settings' => 'Pengaturan Sistem',
        'manage_permissions' => 'Manajemen Hak Akses',
        'view_learning' => 'Akses Pembelajaran',
        'manage_learning' => 'Kelola Materi Pembelajaran',
        'manage_tiktok' => 'Integrasi TikTok Shop',
    ];

    /** Default roles that hold each permission (super_admin is implicit/locked). */
    public const DEFAULTS = [
        'create_po' => [User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER],
        'update_po_status' => [User::ROLE_ADMIN, User::ROLE_GUDANG],
        'delete_po' => [User::ROLE_ADMIN],
        'manage_products' => [User::ROLE_ADMIN],
        'manage_users' => [User::ROLE_ADMIN],
        'delete_users' => [],
        'manage_hq_stock' => [User::ROLE_ADMIN, User::ROLE_GUDANG],
        'receive_stock' => [User::ROLE_ADMIN, User::ROLE_GUDANG],
        'manage_production' => [User::ROLE_ADMIN, User::ROLE_GUDANG],
        'view_reports' => [User::ROLE_ADMIN, User::ROLE_GUDANG, User::ROLE_DISTRIBUTOR],
        'view_accounting' => [User::ROLE_ADMIN],
        // Sengaja kosong: hapus jurnal itu permanen. Hanya super_admin (selalu punya
        // semua izin) yang bisa — admin biasa tidak boleh menghapus pembukuan.
        'delete_accounting' => [],
        'view_audit_log' => [],
        'system_settings' => [],
        'manage_permissions' => [],
        'view_learning' => [User::ROLE_ADMIN, User::ROLE_GUDANG, User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER],
        'manage_learning' => [User::ROLE_ADMIN],
        'manage_tiktok' => [User::ROLE_ADMIN],
    ];

    /** Fallback role list if the roles table is empty (pre-seed). */
    private const FALLBACK_ROLES = [
        User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_GUDANG,
        User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER,
    ];

    private static ?array $overrides = null;

    private static ?array $roleNames = null;

    /** All role names (system + custom), system first. Cached per request. */
    public static function roleNames(): array
    {
        if (self::$roleNames === null) {
            try {
                self::$roleNames = Role::ordered()->pluck('name')->all();
            } catch (\Throwable $e) {
                self::$roleNames = [];
            }
            if (empty(self::$roleNames)) {
                self::$roleNames = self::FALLBACK_ROLES;
            }
        }

        return self::$roleNames;
    }

    private static function overrides(): array
    {
        if (self::$overrides === null) {
            self::$overrides = [];
            foreach (RolePermission::all() as $row) {
                self::$overrides[$row->role][$row->permission_key] = (bool) $row->allowed;
            }
        }

        return self::$overrides;
    }

    public static function flushCache(): void
    {
        self::$overrides = null;
        self::$roleNames = null;
    }

    /** Does a given role hold a permission key right now? */
    public static function roleHas(string $role, string $key): bool
    {
        if ($role === User::ROLE_SUPER_ADMIN) {
            return true; // locked: full access always
        }

        if (! array_key_exists($key, self::DEFINITIONS)) {
            return false;
        }

        $overrides = self::overrides();
        if (isset($overrides[$role]) && array_key_exists($key, $overrides[$role])) {
            return $overrides[$role][$key];
        }

        return in_array($role, self::DEFAULTS[$key] ?? [], true);
    }

    /** Effective matrix for rendering: [key][role] => bool. */
    public static function matrix(): array
    {
        $matrix = [];
        foreach (array_keys(self::DEFINITIONS) as $key) {
            foreach (self::roleNames() as $role) {
                $matrix[$key][$role] = self::roleHas($role, $key);
            }
        }

        return $matrix;
    }

    /**
     * Persist submitted matrix. $input shape: [role][key] => "on" (checked).
     * super_admin is skipped (locked).
     */
    public static function save(array $input): void
    {
        foreach (self::roleNames() as $role) {
            if ($role === User::ROLE_SUPER_ADMIN) {
                continue;
            }
            foreach (array_keys(self::DEFINITIONS) as $key) {
                RolePermission::updateOrCreate(
                    ['role' => $role, 'permission_key' => $key],
                    ['allowed' => isset($input[$role][$key])],
                );
            }
        }

        self::flushCache();
    }
}
