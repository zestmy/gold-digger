<?php

namespace App\Console\Commands;

use App\Services\Telegram\SignalIngest;
use Illuminate\Console\Command;

/**
 * Pull new messages off the Telegram bot.
 *
 * Scheduled every minute. `getUpdates` confirms as it reads - once called with an offset,
 * everything before it is gone server-side - so a long gap between polls does not lose
 * messages, but a lost offset does. See SignalIngest for how that is persisted.
 */
class PollTelegramSignals extends Command
{
    protected $signature = 'telegram:poll';

    protected $description = 'Capture signals posted to the Telegram bot';

    public function handle(SignalIngest $ingest): int
    {
        if (! $ingest->configured()) {
            $this->error('No TELEGRAM_BOT_TOKEN is configured.');

            return self::FAILURE;
        }

        $result = $ingest->poll();

        if (! $result['ok']) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d message(s): %d parsed, %d from chats that are not allow-listed.',
            $result['stored'],
            $result['parsed'],
            $result['ignored'],
        ));

        return self::SUCCESS;
    }
}
