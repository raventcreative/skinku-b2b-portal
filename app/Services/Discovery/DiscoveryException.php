<?php

namespace App\Services\Discovery;

use RuntimeException;

/** Pencarian web gagal — key kosong, API menolak, atau respons tak terbaca. */
class DiscoveryException extends RuntimeException {}
