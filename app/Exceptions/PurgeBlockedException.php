<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Purge PO dibatalkan karena akan membuat saldo stok negatif.
 *
 * Dilempar (bukan sekadar dikembalikan sebagai array) supaya transaksi ikut
 * rollback: purge menyentuh banyak baris, dan separuh koreksi yang tersimpan
 * jauh lebih berbahaya daripada tidak dikoreksi sama sekali.
 */
class PurgeBlockedException extends RuntimeException
{
    /**
     * @param  array<int, string>  $blockers  alasan penolakan, sudah siap dibaca manusia
     * @param  array<int, string>  $actions  koreksi yang TADINYA akan dilakukan
     */
    public function __construct(
        public readonly array $blockers,
        public readonly array $actions = [],
        public readonly int $movements = 0,
    ) {
        parent::__construct('Purge dibatalkan: '.implode(' | ', $blockers));
    }
}
