<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman bisnis inti (PO, retur, stok, penjualan mitra, komisi) hanya untuk
 * staff (super_admin/admin/gudang) dan mitra. Controller-nya hanya menyaring
 * "milik sendiri" untuk mitra — role kustom lain (kol_specialist, affiliator,
 * content_creator) dulu jatuh ke jalur staff dan melihat data SEMUA mitra.
 *
 * Usage: ->middleware('business').
 */
class BusinessOnlyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || (! $user->isStaff() && ! $user->isPartner())) {
            abort(403, 'Halaman ini khusus staff & mitra.');
        }

        return $next($request);
    }
}
