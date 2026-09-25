<?php

namespace Tests;

use App\Support\Permissions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Aset Vite di-commit (public/build); test tidak butuh manifest.
        $this->withoutVite();

        // Permission/role lookups are cached per-request in production; reset between
        // tests so DB changes in one test don't leak into the next (single process).
        Permissions::flushCache();
    }
}
