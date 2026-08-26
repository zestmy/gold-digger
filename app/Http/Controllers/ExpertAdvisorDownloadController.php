<?php

namespace App\Http\Controllers;

use App\Models\TradeCommand;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Ships the Expert Advisor, configured for this dashboard.
 *
 * ## The URL is substituted, not hardcoded
 *
 * `ApiBaseUrl` in the committed source names the canonical dashboard, https://fxsignal.pro.
 * Pinning any one deployment's hostname there would be wrong for anyone else running it,
 * and telling people to edit MQL5 by hand before their first compile is a step that gets
 * skipped.
 *
 * So the archive is built per request with `config('app.url')` written into the default.
 * The EA arrives already pointing at the dashboard that served it, and remains overridable
 * on the chart like every other input.
 *
 * ## The token is not included
 *
 * Deliberately. A downloaded file carrying a live credential is a credential that travels
 * - into a shared folder, a support thread, a backup. The token is shown once on the setup
 * page with a copy button, which costs one paste and keeps the secret out of a file that
 * exists to be moved around.
 *
 * ## Source, not the compiled binary
 *
 * `.ex5` is machine-specific enough that shipping one built here is a worse answer than
 * shipping the source and having MetaEditor build it - and compiling is the step that
 * proves the terminal can, which is exactly what a first-time setup needs to establish.
 */
class ExpertAdvisorDownloadController extends Controller
{
    /** Files that make up the EA, and where they belong in the terminal's data folder. */
    private const FILES = [
        'mql5/Experts/FXSignalPro/FXSignalPro.mq5' => 'MQL5/Experts/FXSignalPro/FXSignalPro.mq5',
        'mql5/Include/FXSignalPro/Executor.mqh' => 'MQL5/Include/FXSignalPro/Executor.mqh',
    ];

    public function __invoke(Request $request): StreamedResponse
    {
        $url = rtrim((string) config('app.url'), '/');
        $version = TradeCommand::WIRE_VERSION;

        $archive = tempnam(sys_get_temp_dir(), 'gd-ea-');
        $zip = new ZipArchive;
        $zip->open($archive, ZipArchive::OVERWRITE | ZipArchive::CREATE);

        foreach (self::FILES as $source => $destination) {
            $contents = (string) file_get_contents(base_path($source));

            if (str_ends_with($source, '.mq5')) {
                $contents = $this->configure($contents, $url);
            }

            $zip->addFromString($destination, $contents);
        }

        $zip->addFromString('README.txt', $this->readme($url, $version));
        $zip->close();

        return response()->streamDownload(function () use ($archive) {
            readfile($archive);
            @unlink($archive);
        }, "fxsignalpro-ea-{$version}.zip", [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * Write this dashboard's URL into the EA's default input.
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

    private function readme(string $url, string $version): string
    {
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
        4. In MetaEditor, open Experts/FXSignalPro/FXSignalPro.mq5 and press F7.
           Expect 0 errors.
        5. Drag FXSignalPro onto any chart of a DEMO account.
             - Common tab: tick "Allow Algo Trading". This is separate from the toolbar
               button, and both must be on.
             - Inputs tab: paste your ApiToken. Everything else has a working default.
        6. The toolbar Algo Trading button must also be on. MetaTrader switches it off by
           itself whenever the account changes.

        The dashboard's Bot Status card should read ONLINE within a few seconds. If it
        says BLOCKED, one of the two Algo Trading switches is off. If nothing happens at
        all, check the Logs page - the EA reports there before anything else works, so
        silence means it is not reaching the API.

        This EA speaks wire protocol {$version}. If the dashboard is upgraded and this
        copy is not, it will refuse every command and say so in the log rather than
        misreading one - download it again and recompile.
        TXT;
    }
}
