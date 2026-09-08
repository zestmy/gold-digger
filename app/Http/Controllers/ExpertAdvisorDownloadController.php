<?php

namespace App\Http\Controllers;

use App\Console\Commands\BuildExpertAdvisor;
use App\Models\TradeCommand;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Ships the Expert Advisor, built and configured for this dashboard.
 *
 * ## A binary, ready to attach
 *
 * The archive carries the compiled `FXSignalPro.ex5` that `ea:build` produced from the
 * committed source, so setup is extract, whitelist, drag onto a chart. Compiling was the
 * step first-time users got wrong most often, and every way of getting it wrong looked
 * from the dashboard like a terminal that never connected. The source travels too, for
 * anyone who wants to read it or build it themselves.
 *
 * ## The URL is a preset, not a hardcoded default
 *
 * `ApiBaseUrl` in the committed source names the canonical dashboard, and the binary
 * was compiled with that default. Pinning any one deployment's hostname into a binary
 * would be wrong for anyone else running it, so this dashboard's URL is written into a
 * preset file - `MQL5/Presets/FXSignalPro.set`, which the Inputs tab loads with one
 * click - and, for anyone who compiles the source instead, substituted into the source's
 * default as well. Either way the EA arrives pointing at the dashboard that served it,
 * and remains overridable on the chart like every other input.
 *
 * ## The token is not included
 *
 * Deliberately. A downloaded file carrying a live credential is a credential that travels
 * - into a shared folder, a support thread, a backup. The token is shown once on the setup
 * page with a copy button, which costs one paste and keeps the secret out of a file that
 * exists to be moved around.
 */
class ExpertAdvisorDownloadController extends Controller
{
    /** Source files, and where they belong in the terminal's data folder. */
    private const SOURCES = [
        'mql5/Experts/FXSignalPro/FXSignalPro.mq5' => 'MQL5/Experts/FXSignalPro/FXSignalPro.mq5',
        'mql5/Include/FXSignalPro/Executor.mqh' => 'MQL5/Include/FXSignalPro/Executor.mqh',
    ];

    public const BINARY_IN_ARCHIVE = 'MQL5/Experts/FXSignalPro/FXSignalPro.ex5';

    public const PRESET_IN_ARCHIVE = 'MQL5/Presets/FXSignalPro.set';

    public function __invoke(Request $request): StreamedResponse
    {
        $url = rtrim((string) config('app.url'), '/');
        $version = TradeCommand::WIRE_VERSION;
        $binary = base_path(BuildExpertAdvisor::BINARY);
        $built = is_file($binary);

        $archive = tempnam(sys_get_temp_dir(), 'gd-ea-');
        $zip = new ZipArchive;
        $zip->open($archive, ZipArchive::OVERWRITE | ZipArchive::CREATE);

        if ($built) {
            $zip->addFile($binary, self::BINARY_IN_ARCHIVE);
        }

        $zip->addFromString(self::PRESET_IN_ARCHIVE, $this->preset($url));

        foreach (self::SOURCES as $source => $destination) {
            $contents = (string) file_get_contents(base_path($source));

            if (str_ends_with($source, '.mq5')) {
                $contents = $this->configure($contents, $url);
            }

            $zip->addFromString($destination, $contents);
        }

        $zip->addFromString('README.txt', $this->readme($url, $version, $built));
        $zip->close();

        return response()->streamDownload(function () use ($archive) {
            readfile($archive);
            @unlink($archive);
        }, "fxsignalpro-ea-{$version}.zip", [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * The preset the Inputs tab loads: this dashboard's URL, and nothing else, so every
     * other input keeps the default compiled into the binary.
     *
     * MT5 writes its own presets as UTF-16LE with a byte-order mark and reads nothing
     * else reliably, so that is what this writes.
     */
    private function preset(string $url): string
    {
        $lines = [
            '; FXSignalPro - preset for '.$url,
            '; Load from the Inputs tab when attaching the EA, then paste the token from the dashboard.',
            'ApiBaseUrl='.$url,
        ];

        return "\xFF\xFE".mb_convert_encoding(implode("\r\n", $lines)."\r\n", 'UTF-16LE', 'UTF-8');
    }

    /**
     * Write this dashboard's URL into the EA's default input, for anyone who compiles
     * the source rather than attaching the binary.
     *
     * Matched on the input declaration rather than the URL itself, so the
     * substitution keeps working if that default is ever changed.
     */
    private function configure(string $source, string $url): string
    {
        return preg_replace(
            '/^(input\s+string\s+ApiBaseUrl\s*=\s*)"[^"]*"/m',
            '$1"'.$url.'"',
            $source,
            1,
        ) ?? $source;
    }

    private function readme(string $url, string $version, bool $built): string
    {
        $attach = $built
            ? <<<'TXT'
            4. Restart MetaTrader, or right-click the Navigator's Expert Advisors and Refresh.
               FXSignalPro appears under Expert Advisors. No compiling is needed: the
               archive carries the built FXSignalPro.ex5.
            TXT
            : <<<'TXT'
            4. In MetaEditor, open Experts/FXSignalPro/FXSignalPro.mq5 and press F7.
               Expect 0 errors.
            TXT;

        return <<<TXT
        FXSignalPro Expert Advisor ({$version})
        =========================================

        This copy is already pointed at {$url}.
        Your API token is NOT in this archive - copy it from the dashboard's Terminal
        Setup page. A credential inside a file travels with the file.

        1. In MetaTrader: File -> Open Data Folder.
        2. Extract this archive over that folder, so the MQL5 directory merges with the
           one already there.
        3. Tools -> Options -> Expert Advisors -> tick "Allow WebRequest for listed URL"
           and add exactly:

               {$url}

           Scheme and host only. A trailing path is the usual cause of error 4014.
        {$attach}
        5. Drag FXSignalPro onto any chart of a DEMO account.
             - Common tab: tick "Allow Algo Trading". This is separate from the toolbar
               button, and both must be on.
             - Inputs tab: press Load and choose FXSignalPro.set - it points the EA at
               {$url}. Then paste your ApiToken. Everything else has a working default.
        6. The toolbar Algo Trading button must also be on. MetaTrader switches it off by
           itself whenever the account changes.

        The dashboard's Bot Status card should read ONLINE within a few seconds. If it
        says BLOCKED, one of the two Algo Trading switches is off. If nothing happens at
        all, check the Logs page - the EA reports there before anything else works, so
        silence means it is not reaching the API.

        This EA speaks wire protocol {$version}. If the dashboard is upgraded and this
        copy is not, it will refuse every command and say so in the log rather than
        misreading one - download it again and reattach.

        The source is included under MQL5/Experts and MQL5/Include for anyone who wants
        to read it or build it themselves.
        TXT;
    }
}
