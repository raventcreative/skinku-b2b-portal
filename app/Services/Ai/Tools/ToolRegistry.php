<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Support\Permissions;

/**
 * Kumpulan alat yang tersedia buat asisten. Menyaring per izin user (agent
 * jalan SEBAGAI user itu) dan menyusun skema buat provider.
 */
class ToolRegistry
{
    /** @param  array<int,AiTool>  $tools */
    public function __construct(private array $tools = []) {}

    /**
     * Alat yang boleh dipakai user ini.
     *
     * @return array<int,AiTool>
     */
    public function forUser(User $user): array
    {
        return array_values(array_filter($this->tools, fn (AiTool $t) => $this->allowed($t, $user)));
    }

    /** Skema alat (name/description/parameters) buat provider. */
    public function schemasFor(User $user): array
    {
        return array_map(fn (AiTool $t) => [
            'name' => $t->name(),
            'description' => $t->description(),
            'parameters' => $t->parameters(),
        ], $this->forUser($user));
    }

    /** Cari alat by name — hanya di antara yang boleh dipakai user. */
    public function find(string $name, User $user): ?AiTool
    {
        foreach ($this->forUser($user) as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    private function allowed(AiTool $tool, User $user): bool
    {
        $perm = $tool->permission();

        return $perm === null || Permissions::roleHas($user->role, $perm);
    }
}
