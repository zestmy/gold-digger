<?php

namespace App\Support\Mql5;

use Illuminate\Support\Facades\File;

/**
 * Where MetaEditor and the terminal's standard library are, on a machine that has them.
 *
 * Only a Windows machine with a terminal installed can compile MQL5. This finds the
 * compiler and the `Include` tree the executor's `#include <Trade/Trade.mqh>` needs,
 * and compiles the repository's copy of the EA in a scratch tree so the live terminal's
 * own folder is never written to. `GD_METAEDITOR` and `GD_MQL5_ROOT` override the
 * discovery for a machine with several terminals.
 *
 * Shared by the build command and the compile test, so they cannot find different
 * compilers.
 */
final class Toolchain
{
    public const SOURCES = [
        'mql5/Experts/FXSignalPro/FXSignalPro.mq5' => 'Experts/FXSignalPro/FXSignalPro.mq5',
        'mql5/Include/FXSignalPro/Executor.mqh' => 'Include/FXSignalPro/Executor.mqh',
    ];

    public function metaEditor(): ?string
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
     * The terminal's `MQL5/Include` folder: the standard library lives there, and only
     * the terminal ships it.
     */
    public function standardLibrary(): ?string
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

    public function available(): bool
    {
        return $this->metaEditor() !== null && $this->standardLibrary() !== null;
    }

    /**
     * Compile the repository's EA in a scratch tree.
     *
     * MetaEditor's exit code says nothing about the outcome; the log does. The result
     * carries the parsed error and warning counts, the log text, and the path of the
     * `.ex5` when one was produced. The caller deletes the scratch tree.
     *
     * @return array{errors: int|null, warnings: int|null, log: string, ex5: string|null, scratch: string}
     */
    public function compile(): array
    {
        $editor = $this->metaEditor();
        $library = $this->standardLibrary();

        if ($editor === null || $library === null) {
            throw new \RuntimeException('MetaEditor and an MT5 data folder are needed to compile the EA.');
        }

        $scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gd-ea-'.uniqid();

        File::copyDirectory($library, "{$scratch}/Include");
        File::deleteDirectory("{$scratch}/Include/FXSignalPro");

        foreach (self::SOURCES as $repo => $tree) {
            File::ensureDirectoryExists(dirname("{$scratch}/{$tree}"));
            File::copy(base_path($repo), "{$scratch}/{$tree}");
        }

        $source = "{$scratch}/Experts/FXSignalPro/FXSignalPro.mq5";
        $log = "{$scratch}/compile.log";

        exec(sprintf(
            '"%s" /compile:"%s" /inc:"%s" /log:"%s"',
            $editor,
            str_replace('/', '\\', $source),
            str_replace('/', '\\', $scratch),
            str_replace('/', '\\', $log),
        ));

        $text = is_file($log) ? mb_convert_encoding((string) file_get_contents($log), 'UTF-8', 'UTF-16') : '';

        preg_match('/Result: (\d+) errors, (\d+) warnings/', $text, $m);

        $ex5 = "{$scratch}/Experts/FXSignalPro/FXSignalPro.ex5";

        return [
            'errors' => isset($m[1]) ? (int) $m[1] : null,
            'warnings' => isset($m[2]) ? (int) $m[2] : null,
            'log' => $text,
            'ex5' => is_file($ex5) ? $ex5 : null,
            'scratch' => $scratch,
        ];
    }

    /**
     * A fingerprint of the sources a build was made from. The committed binary carries
     * this beside it, so a source change without a rebuild is detectable without a
     * compiler.
     */
    public function sourceFingerprint(): string
    {
        $parts = [];

        foreach (array_keys(self::SOURCES) as $repo) {
            $parts[] = hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents(base_path($repo))));
        }

        return hash('sha256', implode("\n", $parts));
    }
}
