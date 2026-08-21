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
        'process_downline_po' => 'Proses Pesanan Downline',
        'process_return' => 'Proses Retur',
        'delete_po' => 'Hapus PO',
        'manage_products' => 'Kelola Produk',
        'manage_users' => 'Kelola User / Anggota',
        'delete_users' => 'Hapus User',
        'manage_hq_stock' => 'Kelola Stok Pusat & Stock Movement',
        'receive_stock' => 'Terima Stok Masuk & HPP',
        'manage_production' => 'Bahan Baku & Produksi (HPP)',
        'view_reports' => 'Lihat Laporan Penjualan',
        'view_commission_report' => 'Lihat Laporan Komisi',
        'view_accounting' => 'Akuntansi & Laporan Keuangan',
        'delete_accounting' => 'Hapus Jurnal / Data Akuntansi (BAHAYA)',
        'process_withdrawal' => 'Proses Penarikan Komisi',
        'view_audit_log' => 'Lihat Audit Log',
        'system_settings' => 'Pengaturan Sistem',
        'manage_announcements' => 'Kelola Pengumuman & Komunitas',
        'manage_permissions' => 'Manajemen Hak Akses',
        'view_learning' => 'Akses SKINKU Academy',
        'manage_learning' => 'Kelola Materi SKINKU Academy',
        'manage_tiktok' => 'Integrasi TikTok Shop',
        'kol.view' => 'Lihat Database KOL & Hasil Kurasi',
        'kol.screening.manage' => 'Input/Edit Screening KOL',
        'kol.deal.manage' => 'Kelola Deal KOL (buat/edit/hapus/urus)',
        'kol.deal.approve' => 'Setujui/Tolak Deal KOL (penyetuju — bukan pengaju)',
        'kol.deal.finance' => 'Finansial Deal KOL (biaya, status bayar, rekening)',
        'kol.report.view' => 'Laporan KOL (fase berikutnya)',
        'kanban.view' => 'Papan Kanban (tugas tim ala Trello)',
        'mindmap.view' => 'Mindmaps (kanvas ide & diagram)',
        'okr.view' => 'Lihat OKR & progres tim',
        'okr.manage' => 'Susun dan setujui OKR dengan AI',
        'use_ai_assistant' => 'Asisten AI',
        'manage_join_packages' => 'Kelola Paket Join',
    ];

    /** Default roles that hold each permission (super_admin is implicit/locked). */
    public const DEFAULTS = [
        'create_po' => [User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER, User::ROLE_GRAND_DISTRIBUTOR, User::ROLE_RESELLER_BRONZE, User::ROLE_RESELLER_GOLD],
        'update_po_status' => [User::ROLE_ADMIN, User::ROLE_GUDANG],
        // Model A: penjual PO inter-partner = stockist (GD/Distri). Reseller beli
        // offline (tak pernah jadi seller) → tak perlu. Guard seller_id===me tetap.
        'process_downline_po' => [User::ROLE_GRAND_DISTRIBUTOR, User::ROLE_DISTRIBUTOR],
        // Retur: HQ (admin/gudang) yang proses/acc. Mitra ajukan via kepemilikan PO.
        'process_return' => [User::ROLE_ADMIN, User::ROLE_GUDANG],
        'delete_po' => [User::ROLE_ADMIN],
        'manage_products' => [User::ROLE_ADMIN],
        'manage_users' => [User::ROLE_ADMIN],
        'delete_users' => [],
        'manage_hq_stock' => [User::ROLE_ADMIN, User::ROLE_GUDANG],
        'receive_stock' => [User::ROLE_ADMIN, User::ROLE_GUDANG],
        'manage_production' => [User::ROLE_ADMIN, User::ROLE_GUDANG],
        'view_reports' => [User::ROLE_ADMIN, User::ROLE_GUDANG, User::ROLE_DISTRIBUTOR, User::ROLE_GRAND_DISTRIBUTOR],
        // Data payout mitra = sensitif → admin-only (super_admin selalu implisit).
        'view_commission_report' => [User::ROLE_ADMIN],
        'view_accounting' => [User::ROLE_ADMIN],
        // Sengaja kosong: hapus jurnal itu permanen. Hanya super_admin (selalu punya
        // semua izin) yang bisa — admin biasa tidak boleh menghapus pembukuan.
        'delete_accounting' => [],
        // Samakan risiko dengan view_accounting: uang keluar sungguhan (mencairkan
        // penarikan mitra), jadi admin-only lewat DEFAULTS (super_admin selalu ikut).
        'process_withdrawal' => [User::ROLE_ADMIN],
        'view_audit_log' => [],
        'system_settings' => [],
        'manage_announcements' => [],
        'manage_permissions' => [],
        'view_learning' => [User::ROLE_ADMIN, User::ROLE_GUDANG, User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER, User::ROLE_GRAND_DISTRIBUTOR, User::ROLE_RESELLER_BRONZE, User::ROLE_RESELLER_GOLD],
        'manage_learning' => [User::ROLE_ADMIN],
        'manage_tiktok' => [User::ROLE_ADMIN],
        // kol_specialist = role dinamis (di-seed migrasi 000045), jadi string
        // literal, bukan konstanta User. Finansial & laporan SENGAJA kosong:
        // kol.deal.finance diberikan per-orang lewat matriks hak akses, dan
        // kol.report.view baru berarti di fase 3.
        'kol.view' => ['kol_specialist'],
        'kol.screening.manage' => ['kol_specialist'],
        // Pengaju/pengurus deal. Admin ikut (perlu akses halaman deal untuk
        // menyetujui); kol_specialist = pengaju yang TAK bisa Acc/Tolak.
        'kol.deal.manage' => ['kol_specialist', User::ROLE_ADMIN],
        // Penyetuju Acc/Tolak — TERPISAH dari pengaju. Admin (+ super_admin
        // selalu); kol_specialist sengaja TIDAK dapat (pengaju ≠ penyetuju).
        'kol.deal.approve' => [User::ROLE_ADMIN],
        'kol.deal.finance' => [],
        'kol.report.view' => [],
        // Dipakai SEMUA tim internal (klarifikasi Freddie) — admin, gudang,
        // kol_specialist dapat default; role dinamis lain via matriks. Mitra
        // diblokir KERAS oleh middleware 'internal' apa pun kata matriks.
        'kanban.view' => [User::ROLE_ADMIN, User::ROLE_GUDANG, 'kol_specialist'],
        // Mindmaps kanvas ide/diagram internal; mitra diblokir KERAS oleh
        // middleware 'internal'. Akses per-papan diatur keanggotaan papan.
        'mindmap.view' => [User::ROLE_ADMIN, User::ROLE_GUDANG],
        // OKR transparan untuk tim internal; penyusunan/persetujuan awal hanya
        // super_admin sampai haknya sengaja dibuka lewat matriks.
        'okr.view' => [User::ROLE_ADMIN, User::ROLE_GUDANG, 'kol_specialist'],
        'okr.manage' => [],
        // Asisten AI dibuka ke semua role. Yang bisa dibaca disaring per-alat
        // (ToolRegistry pakai izin): staf lihat data perusahaan sesuai izinnya,
        // mitra (distributor/reseller) HANYA data akunnya sendiri (ter-scope
        // user_id di dalam alat). Edit Pengetahuan AI tetap internal saja.
        'use_ai_assistant' => [User::ROLE_ADMIN, User::ROLE_GUDANG, User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER, User::ROLE_GRAND_DISTRIBUTOR, User::ROLE_RESELLER_BRONZE, User::ROLE_RESELLER_GOLD],
        // Katalog Paket Join (Onboarding) = admin-only (super_admin selalu ikut).
        'manage_join_packages' => [User::ROLE_ADMIN],
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
