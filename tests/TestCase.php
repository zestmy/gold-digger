<?php

namespace Tests;

use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Clear any nominated tenant between cases.
     *
     * `Tenant` holds a static, which is right for a request and wrong for a process that
     * runs hundreds of them in a row. Without this, a test that authenticates a bot token
     * leaves that tenant current, and the next test silently reads its data through
     * somebody else's filter - which fails in a way that looks like a bug in the code
     * under test rather than in the harness.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Tenant::forget();
    }

    protected function tearDown(): void
    {
        Tenant::forget();

        parent::tearDown();
    }
}
