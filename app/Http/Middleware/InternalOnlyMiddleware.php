<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blokir KERAS mitra (distributor/reseller) — lapisan di ATAS permission.
 *
 * Permission bisa berubah dari matriks Manajemen Hak Akses; kalau suatu saat
 * checkbox untuk role mitra tercentang (sengaja atau keliru), fitur internal
 * ikut terbuka ke pihak eksternal. Middleware ini memastikan itu mustahil:
 * papan tugas tim bisa memuat strategi, harga deal, rencana promo — bukan
 * konsumsi mitra, apa pun kata matriks.
 *
 * Usage: ->middleware('internal').
 */
class InternalOnlyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isPartner()) {
            abort(403, 'Fitur ini khusus tim internal.');
        }

        return $next($request);
    }
}
