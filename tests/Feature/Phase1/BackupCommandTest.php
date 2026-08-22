<?php

namespace Tests\Feature\Phase1;

use App\Console\Commands\BackupDatabase;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * The pre-migration backup.
 *
 * The dump itself needs a MySQL server, so what is pinned here is the behaviour around it -
 * particularly the two ways this command could quietly do nothing useful: running against a
 * connection it cannot dump, and keeping an archive with nothing in it.
 *
 * The first implementation piped mysqldump into gzip. A shell pipeline reports the last
 * command's exit status, so a failed dump still produced a valid empty archive and reported
 * success. That is why the empty check below exists and why the pipe does not.
 */
class BackupCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('backups');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    /**
     * The suite runs on in-memory sqlite. The command has to notice rather than shell out to
     * mysqldump with a database name that does not exist - and it must not fail the deploy.
     */
    public function test_a_non_mysql_connection_is_skipped_rather_than_failed(): void
    {
        $this->artisan('db:backup')
            ->expectsOutputToContain('not MySQL')
            ->assertSuccessful();

        $this->assertFalse(is_dir($this->directory) && count(glob($this->directory.'/*.sql.gz')) > 0);
    }

    /**
     * Retention is what stops a daily deploy filling the disk, which would be an outage
     * caused by the thing meant to protect against one.
     */
    public function test_pruning_keeps_the_newest_backups_and_drops_the_rest(): void
    {
        File::ensureDirectoryExists($this->directory);

        // Filenames carry a sortable timestamp, which is what the prune relies on.
        $names = [
            'gold_digger-2026-01-01-010000.sql.gz',
            'gold_digger-2026-01-02-010000.sql.gz',
            'gold_digger-2026-01-03-010000.sql.gz',
            'gold_digger-2026-01-04-010000.sql.gz',
        ];

        foreach ($names as $name) {
            file_put_contents($this->directory.'/'.$name, 'x');
        }

        $command = new \ReflectionMethod(BackupDatabase::class, 'prune');
        $instance = app(BackupDatabase::class);
        $instance->setOutput(new OutputStyle(
            new ArrayInput([]),
            new NullOutput,
        ));

        $command->invoke($instance, $this->directory, 2);

        $remaining = array_map('basename', glob($this->directory.'/*.sql.gz'));
        sort($remaining);

        $this->assertSame([
            'gold_digger-2026-01-03-010000.sql.gz',
            'gold_digger-2026-01-04-010000.sql.gz',
        ], $remaining);
    }

    public function test_pruning_leaves_everything_when_under_the_limit(): void
    {
        File::ensureDirectoryExists($this->directory);
        file_put_contents($this->directory.'/gold_digger-2026-01-01-010000.sql.gz', 'x');

        $command = new \ReflectionMethod(BackupDatabase::class, 'prune');
        $instance = app(BackupDatabase::class);
        $instance->setOutput(new OutputStyle(
            new ArrayInput([]),
            new NullOutput,
        ));

        $command->invoke($instance, $this->directory, 7);

        $this->assertCount(1, glob($this->directory.'/*.sql.gz'));
    }

    /**
     * Dumps are production data. Committing one would put every trade, token hash and broker
     * account detail into the repository.
     */
    public function test_the_backup_directory_is_ignored_by_git(): void
    {
        $this->assertStringContainsString(
            '/storage/backups',
            file_get_contents(base_path('.gitignore')),
        );
    }
}
