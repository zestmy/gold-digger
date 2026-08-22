<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grant Admin
 *
 * The only way to reach the Filament panel.
 *
 * Deliberately a console command rather than a screen: the panel is not scoped by user, so an
 * admin sees every account's trading data. That is acceptable for the person who owns the
 * server and unacceptable as something grantable from a web form by anyone who already has an
 * account.
 */
class GrantAdmin extends Command
{
    protected $signature = 'user:admin {email : The account to grant or revoke}
                            {--revoke : Remove admin access instead of granting it}';

    protected $description = 'Grant or revoke access to the /admin panel';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No account with the email {$email}.");

            // Listing the alternatives beats making someone guess at their own database.
            $known = User::orderBy('id')->pluck('email');

            if ($known->isNotEmpty()) {
                $this->line('Known accounts: '.$known->implode(', '));
            }

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');

        if ((bool) $user->is_admin === $grant) {
            $this->info($grant
                ? "{$email} already has admin access."
                : "{$email} already has no admin access.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_admin' => $grant])->save();

        $this->info($grant
            ? "{$email} can now reach /admin."
            : "{$email} can no longer reach /admin.");

        if (! $grant && User::where('is_admin', true)->count() === 0) {
            $this->warn('No account now has admin access. Grant one before you need it: '
                .'php artisan user:admin '.$email);
        }

        return self::SUCCESS;
    }
}
