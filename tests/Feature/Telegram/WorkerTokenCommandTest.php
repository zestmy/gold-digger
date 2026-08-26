<?php

namespace Tests\Feature\Telegram;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The worker's shared secret is invented rather than issued, which is exactly the kind of
 * credential that ends up being "worker1" because somebody had to make one up.
 */
class WorkerTokenCommandTest extends TestCase
{
    public function test_it_mints_a_token_long_enough_to_be_worth_having(): void
    {
        $this->artisan('telegram:worker-token')
            ->expectsOutputToContain('gdw_')
            ->assertSuccessful();
    }

    public function test_every_run_produces_a_different_token(): void
    {
        $this->assertNotSame($this->mint(), $this->mint());
    }

    private function mint(): string
    {
        // Artisan::call, not the pending-command helper: only the former populates
        // Artisan::output(), and the token is the whole thing under test here.
        Artisan::call('telegram:worker-token');

        preg_match('/gdw_[A-Za-z0-9]{64}/', Artisan::output(), $m);

        $this->assertNotEmpty($m, 'The command did not print a token of the expected shape.');

        return $m[0];
    }
}
