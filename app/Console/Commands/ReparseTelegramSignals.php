<?php

namespace App\Console\Commands;

use App\Models\TelegramSignal;
use App\Services\Telegram\SignalParser;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Run the parser again over the messages it could not read.
 *
 * This is the other half of the feedback loop. The copier page shows what the parser
 * missed and lets a person read it; this is how a change to the parser is judged. Improve
 * a pattern, run this, and the count of misses that now parse is the measure - with the
 * complaints that remain, most common first, as the list of what to fix next.
 *
 * It never touches a message a person corrected, a message read from an image, or a
 * message the ingest refused because its chat is not a source. Those are not misses of
 * the text parser, and rewriting them would either discard a reader's work or credit the
 * parser with a reading it did not make.
 */
class ReparseTelegramSignals extends Command
{
    protected $signature = 'telegram:reparse
        {--days=30 : How far back to look}
        {--dry-run : Report what would change without changing it}';

    protected $description = 'Re-run the text parser over unparsed Telegram messages and report what now reads';

    public function handle(SignalParser $parser): int
    {
        $misses = TelegramSignal::acrossTenants()
            ->where('kind', TelegramSignal::KIND_SIGNAL)
            ->where('parse_status', TelegramSignal::PARSE_FAILED)
            ->where('execution_status', TelegramSignal::EXEC_NONE)
            ->whereNull('corrected_at')
            ->where('from_image', false)
            ->where('parse_error', '!=', 'Channel is not enabled as a signal source.')
            ->where('created_at', '>=', now()->subDays((int) $this->option('days')))
            ->orderBy('id')
            ->get();

        if ($misses->isEmpty()) {
            $this->info('No unparsed text messages in the window.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $nowParse = 0;
        $remaining = [];

        foreach ($misses as $signal) {
            $parsed = $parser->parse($signal->raw_text);

            if (! $parsed['ok']) {
                $remaining[] = $parsed['error'];

                // The complaint may have changed even when the verdict has not.
                if (! $dry && $parsed['error'] !== $signal->parse_error) {
                    $signal->update(['parse_error' => $parsed['error']]);
                }

                continue;
            }

            $nowParse++;
            $this->line(sprintf(
                '  #%d %s %s  <fg=gray>%s</>',
                $signal->id,
                $parsed['symbol'],
                strtoupper($parsed['direction']),
                mb_substr(str_replace("\n", ' ', $signal->raw_text), 0, 60),
            ));

            if ($dry) {
                continue;
            }

            $signal->update([
                'parse_status' => TelegramSignal::PARSE_OK,
                'parse_error' => null,
                'parsed_by' => TelegramSignal::PARSED_BY_PARSER,
                'symbol' => $parsed['symbol'],
                'direction' => $parsed['direction'],
                'entry_price' => $parsed['entry_price'],
                'entry_zone_high' => $parsed['entry_zone_high'],
                'sl_price' => $parsed['sl_price'],
                'tp_prices' => $parsed['tp_prices'] ?: null,
                // Into the pipeline at review. The reviewer's gates decide whether a signal
                // this old is still worth anything; an old message is not traded on the
                // strength of having finally been read.
                'review_status' => TelegramSignal::REVIEW_PENDING,
            ]);
        }

        $this->newLine();
        $this->info(sprintf(
            '%d unparsed, %d now parse%s.',
            $misses->count(),
            $nowParse,
            $dry ? ' (dry run, nothing changed)' : '',
        ));

        $this->complaints(collect($remaining));

        return self::SUCCESS;
    }

    /**
     * What the parser still cannot read, most common first: the to-do list.
     *
     * @param  Collection<int, string>  $errors
     */
    private function complaints(Collection $errors): void
    {
        if ($errors->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('Still unparsed, by complaint:');

        foreach ($errors->countBy()->sortDesc() as $error => $count) {
            $this->line(sprintf('  %4d  %s', $count, $error));
        }
    }
}
