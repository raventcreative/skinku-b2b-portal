<?php

namespace App\Exceptions;

use RuntimeException;

/** Domain error for the accounting/GL engine (unbalanced journal, bad lines, dll). */
class AccountingException extends RuntimeException {}
