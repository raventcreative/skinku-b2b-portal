<?php

namespace Tests;

use App\Support\Permissions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Permission/role lookups are cached per-request in production; reset between
        // tests so DB changes in one test don't leak into the next (single process).
        Permissions::flushCache();
    }
}
