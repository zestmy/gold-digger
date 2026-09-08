<?php

namespace Tests\Feature\Bot;

use App\Console\Commands\BuildExpertAdvisor;
use App\Models\TradeCommand;
use App\Support\Mql5\Toolchain;
use Tests\TestCase;

/**
 * The committed EA binary is the one its sources describe.
 *
 * A compiler is not available in CI, so the only guard against a source edit that
 * forgot to rebuild is the manifest `ea:build` writes beside the binary. This test runs
 * everywhere, and when it fails the fix is one command on a machine with MetaEditor.
 */
class ExpertAdvisorBuildTest extends TestCase
{
    public function test_a_built_binary_and_its_manifest_are_committed(): void
    {
        $this->assertFileExists(base_path(BuildExpertAdvisor::BINARY), 'No compiled EA. Run `php artisan ea:build` on a machine with MetaEditor and commit the result.');
        $this->assertFileExists(base_path(BuildExpertAdvisor::MANIFEST));
    }

    public function test_the_binary_was_built_from_the_sources_as_they_are(): void
    {
        $manifest = BuildExpertAdvisor::manifest();

        $this->assertNotNull($manifest);
        $this->assertSame(
            (new Toolchain)->sourceFingerprint(),
            $manifest['source_fingerprint'],
            'The EA sources have changed since the binary was built. Run `php artisan ea:build` and commit the binary and manifest together.',
        );
    }

    public function test_the_binary_speaks_the_wire_version_the_dashboard_speaks(): void
    {
        $this->assertSame(TradeCommand::WIRE_VERSION, BuildExpertAdvisor::manifest()['wire_version'] ?? null);
    }

    public function test_the_check_command_agrees(): void
    {
        $this->artisan('ea:build --check')
            ->expectsOutputToContain('matches its sources')
            ->assertSuccessful();
    }
}
