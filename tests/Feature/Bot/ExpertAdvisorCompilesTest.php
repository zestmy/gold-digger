<?php

namespace Tests\Feature\Bot;

use App\Support\Mql5\Toolchain;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The Expert Advisor compiles.
 *
 * The wire-contract tests read the EA as text because a compiler is not available on
 * Linux. On the Windows machine that runs the terminal, one is - MetaEditor compiles
 * headlessly - and a change to the MQL5 that does not go through it is a change nobody
 * has checked. Toolchain finds the compiler and the terminal's standard library, builds
 * the repository's copy of the EA in a scratch tree so the live terminal's folder is
 * never written to, and reads the result line out of the log.
 *
 * Skipped, not failed, where there is no terminal: CI is not the place this runs. The
 * committed binary's freshness is what ExpertAdvisorBuildTest checks everywhere.
 */
class ExpertAdvisorCompilesTest extends TestCase
{
    public function test_the_expert_advisor_compiles_with_no_errors_or_warnings(): void
    {
        $toolchain = new Toolchain;

        if (! $toolchain->available()) {
            $this->markTestSkipped('MetaEditor and an MT5 data folder are needed to compile the EA.');
        }

        $result = $toolchain->compile();

        try {
            $this->assertNotNull($result['errors'], "No result line in the compile log:\n{$result['log']}");
            $this->assertSame(0, $result['errors'], "The EA does not compile:\n{$result['log']}");
            $this->assertSame(0, $result['warnings'], "The EA compiles with warnings, and it did not before:\n{$result['log']}");
            $this->assertNotNull($result['ex5'], 'The compile reported success but produced no binary.');
        } finally {
            File::deleteDirectory($result['scratch']);
        }
    }
}
