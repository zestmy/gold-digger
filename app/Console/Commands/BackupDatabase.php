<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Backup Database
 *
 * Taken immediately before `migrate --force` on every deploy.
 *
 * The deploy has always run migrations against production with nothing to roll back to. Most
 * of the migrations in this repo are additive and their `down()` works, but a migration that
 * fails halfway leaves the schema in a state no `down()` describes - and the ones that alter
 * existing columns cannot restore data they widened or dropped.
 *
 * ## Why not read .env from the deploy script
 *
 * Because doing it in shell means either parsing .env (fragile: quoting, comments, spaces) or
 * echoing credentials into a CI log. Reading Laravel's own config here avoids both, and the
 * password reaches mysqldump through a private defaults file rather than a command line every
 * process on the box can read in `ps`.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup
                            {--keep=7 : How many previous backups to retain}';

    protected $description = 'Dump the database to storage/backups before a risky operation';

    public function handle(): int
    {
        $connection = config('database.default');

        if ($connection !== 'mysql') {
            // sqlite in tests, and anything else is not something this knows how to dump.
            $this->warn("Connection [{$connection}] is not MySQL; nothing to back up.");

            return self::SUCCESS;
        }

        $config = config("database.connections.{$connection}");
        $directory = storage_path('backups');

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error("Could not create {$directory}.");

            return self::FAILURE;
        }

        $target = $directory.'/'.$config['database'].'-'.now()->format('Y-m-d-His').'.sql.gz';

        // A defaults file rather than --password=, so the credential never appears in the
        // process list. 0600 before anything is written to it.
        $credentials = tempnam(sys_get_temp_dir(), 'gdmy');
        chmod($credentials, 0600);

        file_put_contents($credentials, implode("\n", [
            '[client]',
            'user='.$config['username'],
            'password='.$config['password'],
            'host='.$config['host'],
            'port='.($config['port'] ?: 3306),
            '',
        ]));

        $raw = $target.'.tmp.sql';

        try {
            // No shell, and no pipe into gzip. A pipeline reports the *last* command's exit
            // status, so `mysqldump | gzip` succeeds whenever gzip does - which it always
            // does, even when it compressed nothing because mysqldump failed. The first run
            // of this produced a valid, empty archive and reported success.
            $process = new Process([
                'mysqldump',
                '--defaults-extra-file='.$credentials,
                '--single-transaction',
                '--quick',
                '--routines',
                '--no-tablespaces',
                '--result-file='.$raw,
                $config['database'],
            ]);

            $process->setTimeout(600);
            $process->run();

            if (! $process->isSuccessful()) {
                @unlink($raw);
                $this->error('mysqldump failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));

                return self::FAILURE;
            }
        } finally {
            @unlink($credentials);
        }

        if (! $this->compress($raw, $target)) {
            @unlink($raw);
            $this->error('Could not compress the dump.');

            return self::FAILURE;
        }

        @unlink($raw);

        $size = is_file($target) ? filesize($target) : 0;

        if ($size === 0) {
            // An empty dump is worse than none: it looks like a backup and restores nothing.
            @unlink($target);
            $this->error('The dump was empty; refusing to keep it.');

            return self::FAILURE;
        }

        $this->info('Backed up to '.$target.' ('.number_format($size / 1024, 1).' KB)');

        $this->prune($directory, max(1, (int) $this->option('keep')));

        return self::SUCCESS;
    }

    /**
     * Gzip the dump in PHP rather than shelling out.
     *
     * One less external tool to depend on, and a return value that actually reflects whether
     * the compression worked.
     */
    private function compress(string $from, string $to): bool
    {
        $in = fopen($from, 'rb');

        if ($in === false) {
            return false;
        }

        $out = gzopen($to, 'wb9');

        if ($out === false) {
            fclose($in);

            return false;
        }

        while (! feof($in)) {
            $chunk = fread($in, 262144);

            if ($chunk === false || gzwrite($out, $chunk) === false) {
                fclose($in);
                gzclose($out);

                return false;
            }
        }

        fclose($in);

        return gzclose($out);
    }

    /**
     * Keep the most recent backups and delete the rest.
     *
     * A deploy that silently fills the disk is its own outage.
     */
    private function prune(string $directory, int $keep): void
    {
        $files = glob($directory.'/*.sql.gz') ?: [];

        if (count($files) <= $keep) {
            return;
        }

        // Filenames carry a sortable timestamp, so lexical order is chronological.
        sort($files);
        $stale = array_slice($files, 0, count($files) - $keep);

        foreach ($stale as $file) {
            @unlink($file);
        }

        $this->line('Pruned '.count($stale).' older backup(s), keeping '.$keep.'.');
    }
}
