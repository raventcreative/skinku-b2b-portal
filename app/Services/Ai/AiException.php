<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Error khusus lapisan AI (key kosong, API nolak/limit, model ngaco). Pesannya
 * sudah ramah-user (Bahasa Indonesia) dan aman ditampilkan di chat — bukan 500.
 */
class AiException extends RuntimeException {}
