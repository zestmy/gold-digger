<?php

namespace App\Console\Commands;

use App\Models\TradeCommand;
use App\Support\Mql5\Toolchain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Build the Expert Advisor and commit the binary beside its source.
 *
 * ## Why a binary is in the repository
 *
 * The download used to ship source and ask people to press F7. Compiling is the step a
 * first-time user gets wrong most often - the wrong folder, an Include tree that was not
 * merged, a terminal whose MetaEditor is not installed - and every one of those failures
 * looks, from the dashboard, like a terminal that never connected. An `.ex5` is portable
 * between MT5 terminals; MetaQuotes ships nothing else through its Market. Shipping one
 * turns setup into "extract, whitelist, drag onto a chart".
 *
 * ## Why the binary carries a fingerprint
 *
 * A committed binary drifts from its source the first time someone edits the MQL5 and
 * forgets to rebuild, and a compiler is not available in CI to notice. So the build
 * writes a manifest naming the sources it was made from, and a test compares that to the
 * sources as they are. A change to the EA without a rebuild fails the suite, on any
 * machine, with a message saying to run this.
 *
 * The compile itself happens in a scratch tree - see Toolchain - so the live terminal's
 * folder is never touched.
 */
class BuildExpertAdvisor extends Command
{
    public const BINARY = 'mql5/Experts/FXSignalPro/FXSignalPro.ex5';

    public const MANIFEST = 'mql5/Experts/FXSignalPro/FXSignalPro.build.json';

    protected $signature = 'ea:build {--check : Only verify that the committed binary matches the sources}';

    protected $description = 'Compile the Expert Advisor with MetaEditor and record what it was built from';

    public function handle(Toolchain $toolchain): int
    {
        if ($this->option('check')) {
            return $this->check($toolchain);
        }

        if (! $toolchain->available()) {
            $this->error('MetaEditor and an MT5 data folder are needed to compile the EA. Set GD_METAEDITOR and GD_MQL5_ROOT if discovery fails.');

            return self::FAILURE;
        }

        $this->line('Compiling '.array_key_first(Toolchain::SOURCES).' with '.$toolchain->metaEditor());

        $result = $toolchain->compile();

        try {
            if ($result['errors'] === null) {
                $this->error("No result line in the compile log:\n".$result['log']);

                return self::FAILURE;
            }

            $this->line(sprintf('Result: %d errors, %d warnings', $result['errors'], $result['warnings']));

            if ($result['errors'] > 0 || $result['warnings'] > 0 || $result['ex5'] === null) {
                $this->error($result['log']);

                return self::FAILURE;
            }

            File::copy($result['ex5'], base_path(self::BINARY));

            $manifest = [
                'wire_version' => TradeCommand::WIRE_VERSION,
                'ea_version' => $this->eaVersion(),
                'source_fingerprint' => $toolchain->sourceFingerprint(),
                'built_at' => now()->toIso8601String(),
                'compiler' => basename((string) $toolchain->metaEditor()),
                'size' => filesize(base_path(self::BINARY)),
            ];

            File::put(base_path(self::MANIFEST), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

            $this->info(sprintf('Built %s (%d bytes), EA %s, wire %s.', self::BINARY, $manifest['size'], $manifest['ea_version'], $manifest['wire_version']));
            $this->line('Commit the binary and the manifest together.');

            return self::SUCCESS;
        } finally {
            File::deleteDirectory($result['scratch']);
        }
    }

    private function check(Toolchain $toolchain): int
    {
        $manifest = self::manifest();

        if ($manifest === null) {
            $this->error('No build manifest. Run ea:build on a machine with MetaEditor.');

            return self::FAILURE;
        }

        if (! is_file(base_path(self::BINARY))) {
            $this->error('The manifest exists but the binary does not. Run ea:build.');

            return self::FAILURE;
        }

        if ($manifest['source_fingerprint'] !== $toolchain->sourceFingerprint()) {
            $this->error('The EA sources have changed since the binary was built. Run ea:build and commit both.');

            return self::FAILURE;
        }

        if ($manifest['wire_version'] !== TradeCommand::WIRE_VERSION) {
            $this->error("The binary speaks {$manifest['wire_version']}; the dashboard speaks ".TradeCommand::WIRE_VERSION.'. Run ea:build.');

            return self::FAILURE;
        }

        $this->info(sprintf('The committed EA matches its sources (%s, built %s).', $manifest['ea_version'], $manifest['built_at']));

        return self::SUCCESS;
    }

    /**
     * @return array{wire_version: string, ea_version: string, source_fingerprint: string, built_at: string, compiler: string, size: int}|null
     */
    public static function manifest(): ?array
    {
        $path = base_path(self::MANIFEST);

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function eaVersion(): string
    {
        $source = (string) file_get_contents(base_path(array_key_first(Toolchain::SOURCES)));

        preg_match('/#property\s+version\s+"([^"]+)"/', $source, $m);

        return $m[1] ?? 'unknown';
    }
}
