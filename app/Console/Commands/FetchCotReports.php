<?php

namespace App\Console\Commands;

use App\Services\News\CotFeed;
use Illuminate\Console\Command;

/**
 * Pull the week's Commitments of Traders.
 *
 * Weekly by nature: positions are counted on a Tuesday and published the following Friday
 * afternoon. Running this hourly would fetch the same rows all week.
 */
class FetchCotReports extends Command
{
    protected $signature = 'cot:fetch';

    protected $description = 'Fetch CFTC Commitments of Traders positioning';

    public function handle(CotFeed $feed): int
    {
        $result = $feed->fetch();

        if (! $result['ok']) {
            // Not a failure exit: a missing COT reading costs a paragraph of context, and
            // a scheduler treating that as an error would be noisier than the data is
            // useful.
            $this->warn($result['error']);

            return self::SUCCESS;
        }

        $this->info("{$result['stored']} weekly reading(s) stored.");

        return self::SUCCESS;
    }
}
