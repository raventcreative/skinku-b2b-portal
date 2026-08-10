<?php

namespace App\Support;

use App\Models\User;

/**
 * Sumber kebenaran tier mitra MLM (spine distribusi). tier = role.
 * level: makin kecil makin tinggi (1 = paling atas / langsung HQ).
 */
class PartnerHierarchy
{
    /** role => [level, label, holds_stock] — urutan = urutan tampil. */
    public const TIERS = [
        User::ROLE_GRAND_DISTRIBUTOR => ['level' => 1, 'label' => 'Grand Distributor', 'holds_stock' => true],
        User::ROLE_DISTRIBUTOR => ['level' => 2, 'label' => 'Distributor', 'holds_stock' => true],
        User::ROLE_RESELLER_BRONZE => ['level' => 3, 'label' => 'Reseller Bronze', 'holds_stock' => false],
        User::ROLE_RESELLER_GOLD => ['level' => 3, 'label' => 'Reseller Gold', 'holds_stock' => false],
    ];

    public static function isTierRole(string $role): bool
    {
        return array_key_exists($role, self::TIERS);
    }

    public static function levelOf(string $role): ?int
    {
        return self::TIERS[$role]['level'] ?? null;
    }

    public static function holdsStock(string $role): bool
    {
        return self::TIERS[$role]['holds_stock'] ?? false;
    }

    public static function label(string $role): string
    {
        return self::TIERS[$role]['label'] ?? $role;
    }

    /** Role induk yang sah = tepat 1 level di atas. Top tier → []. */
    public static function allowedParentRoles(string $role): array
    {
        $level = self::levelOf($role);
        if ($level === null || $level === 1) {
            return [];
        }

        return array_values(array_keys(array_filter(self::TIERS, fn ($meta) => $meta['level'] === $level - 1)));
    }
}
