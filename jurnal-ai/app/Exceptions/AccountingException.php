<?php

namespace App\Exceptions;

use RuntimeException;

/** Pelanggaran aturan akuntansi (tidak balance, baris kurang, akun salah klien). */
class AccountingException extends RuntimeException {}
