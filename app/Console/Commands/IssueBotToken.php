<?php

namespace App\Console\Commands;

use App\Models\BotToken;
use App\Models\BrokerAccount;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Issue a bot API token for an executor.
 *
 * The plaintext is printed once and cannot be recovered - only its hash is stored.
 * Bind it to a broker account so a compromised demo terminal cannot touch the live one.
 *
 *   php artisan bot:token you@example.com --name="Windows VPS" --account=2
 */
class IssueBotToken extends Command
{
    protected $signature = 'bot:token
        {email : Email of the user the token acts on behalf of}
        {--name= : Label so you can tell devices apart}
        {--account= : Broker account ID to bind the token to}';

    protected $description = 'Issue an API token for the MQL5 EA or another executor';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error("No user with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $account = null;

        if ($accountId = $this->option('account')) {
            $account = BrokerAccount::where('user_id', $user->id)->find($accountId);

            if ($account === null) {
                $this->error("Broker account {$accountId} does not belong to {$user->email}.");

                return self::FAILURE;
            }
        } else {
            $this->warn('No --account given: this token may act on any of the user\'s accounts.');
        }

        [$plaintext, $token] = BotToken::generate(
            $user,
            $this->option('name') ?: 'executor',
            $account,
        );

        $this->newLine();
        $this->info('Token issued. Copy it now - it is not stored and cannot be shown again.');
        $this->newLine();
        $this->line("  {$plaintext}");
        $this->newLine();
        $this->line("  id:      {$token->id}");
        $this->line('  user:    '.$user->email);
        $this->line('  account: '.($account?->label ?? 'any'));
        $this->newLine();
        $this->comment('Paste it into the EA\'s ApiToken input in MetaTrader.');

        return self::SUCCESS;
    }
}
