<?php

namespace Tests\Feature\Bot;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The Expert Advisor compiles.
 *
 * The wire-contract tests read the EA as text because a compiler is not available on
 * Linux. On the Windows machine that runs the terminal, one is - MetaEditor compiles
 * headlessly - and a change to the MQL5 that does not go through it is a change nobody
 * has checked. This test finds the compiler and the terminal's standard library on its
 * own, builds the repository's copy of the EA in a scratch tree so the live terminal's
 * folder is never written to, and reads the result line out of the log.
 *
 * Skipped, not failed, where there is no terminal: CI is not the place this runs.
 *
 * `GD_METAEDITOR` and `GD_MQL5_ROOT` override the discovery, for a machine with several
 * terminals installed.
 */
class ExpertAdvisorCompilesTest extends TestCase
{
    public function test_the_expert_advisor_compiles_with_no_errors_or_warnings(): void
    {
        $editor = $this->metaEditor();
        $library = $this->standardLibrary();

        if ($editor === null || $library === null) {
            $this->markTestSkipped('MetaEditor and an MT5 data folder are needed to compile the EA.');
        }

        $scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gd-ea-'.uniqid();
        File::ensureDirectoryExists("{$scratch}/Experts/FXSignalPro");
        File::copyDirectory($library, "{$scratch}/Include");
        File::deleteDirectory("{$scratch}/Include/FXSignalPro");
        File::ensureDirectoryExists("{$scratch}/Include/FXSignalPro");
        File::copy(base_path('mql5/Include/FXSignalPro/Executor.mqh'), "{$scratch}/Include/FXSignalPro/Executor.mqh");
        File::copy(base_path('mql5/Experts/FXSignalPro/FXSignalPro.mq5'), "{$scratch}/Experts/FXSignalPro/FXSignalPro.mq5");

        $log = "{$scratch}/compile.log";

        try {
            $command = sprintf(
                '"%s" /compile:"%s" /inc:"%s" /log:"%s"',
                $editor,
                str_replace('/', '\\', "{$scratch}/Experts/FXSignalPro/FXSignalPro.mq5"),
                str_replace('/', '\\', $scratch),
                str_replace('/', '\\', $log),
            );

            // MetaEditor's exit code says nothing about the outcome; the log does.
            exec($command);

            $this->assertFileExists($log, 'MetaEditor wrote no log; the compile never ran.');

            $text = mb_convert_encoding(file_get_contents($log), 'UTF-8', 'UTF-16');

            preg_match('/Result: (\d+) errors, (\d+) warnings/', $text, $m);

            $this->assertNotEmpty($m, "No result line in the compile log:\n{$text}");
            $this->assertSame('0', $m[1], "The EA does not compile:\n{$text}");
            $this->assertSame('0', $m[2], "The EA compiles with warnings, and it did not before:\n{$text}");
        } finally {
            File::deleteDirectory($scratch);
        }
    }

    private function metaEditor(): ?string
    {
        $configured = env('GD_METAEDITOR');

        if ($configured && is_file($configured)) {
            return $configured;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            return null;
        }

        foreach (glob('C:/Program Files/*/MetaEditor64.exe') ?: [] as $candidate) {
            return str_replace('/', '\\', $candidate);
        }

        return null;
    }

    /**
     * The terminal's MQL5 folder: the standard library the executor includes lives under
     * its Include directory, and only the terminal ships it.
     */
    private function standardLibrary(): ?string
    {
        $configured = env('GD_MQL5_ROOT');

        if ($configured && is_file("{$configured}/Include/Trade/Trade.mqh")) {
            return "{$configured}/Include";
        }

        $appData = getenv('APPDATA');

        if (! $appData) {
            return null;
        }

        foreach (glob(str_replace('\\', '/', $appData).'/MetaQuotes/Terminal/*/MQL5/Include/Trade/Trade.mqh') ?: [] as $found) {
            return dirname($found, 2);
        }

        return null;
    }
}
