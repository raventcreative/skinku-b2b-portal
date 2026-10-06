<?php

namespace App\Services\Ai\Tools;

use App\Models\Kol;
use App\Models\KolUsernameAlias;

/** Cari KOL dari username/nama yang diketik user: persis (atau alias) dulu, lalu sebagian. */
trait CariKol
{
    /** @return array{0:?Kol,1:array} [kol, payload error utk AI bila tak ketemu/ambigu] */
    protected function cariKol(string $q): array
    {
        $norm = KolUsernameAlias::norm($q);
        if ($norm === '') {
            return [null, ['error' => 'Username kreator kosong.']];
        }
        if (($id = KolUsernameAlias::kolId($norm)) && ($kol = Kol::find($id))) {
            return [$kol, []];
        }
        $mirip = Kol::where('tiktok_username', 'like', "%{$norm}%")->orWhere('name', 'like', "%{$norm}%")
            ->orderByDesc('followers')->limit(5)->get();
        if ($mirip->count() === 1) {
            return [$mirip->first(), []];
        }

        return [null, $mirip->isEmpty()
            ? ['error' => "Kreator \"{$q}\" tidak ada di Database KOL."]
            : ['error' => "Ada beberapa kreator yang mirip \"{$q}\" — tanyakan ke user yang mana.",
                'kandidat' => $mirip->map(fn (Kol $k) => '@'.$k->handle().($k->name ? " ({$k->name})" : ''))->all()]];
    }
}
