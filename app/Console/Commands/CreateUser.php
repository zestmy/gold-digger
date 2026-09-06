<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Create User
 *
 * How the first account comes to exist.
 *
 * Registration is off by default - see routes/auth.php for why - and `user:admin` can only
 * promote an account that is already there. Between those two, a fresh install had no way
 * to get a user short of the seeder, and the seeder ran with model events off, so the
 * account it made had no settings row and opened the dashboard to a crash. This is the
 * path the README can actually describe.
 *
 * ## Why the password is asked for, not passed
 *
 * `--password=` would land in shell history and in the process list for as long as the
 * command ran. `secret()` reads it with echo off and it goes nowhere else.
 *
 * ## Why it goes through the model
 *
 * `User::create()` fires `UserObserver`, which is what gives the account its `BotSettings`
 * row and starter strategy. A raw insert would look like a user and behave like the
 * seeder's.
 */
class CreateUser extends Command
{
    protected $signature = 'user:create {email : The address the account signs in with}
                            {--name= : Display name; defaults to the part of the address before the @}
                            {--admin : Also grant access to the /admin console}';

    protected $description = 'Create an account, prompting for its password';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        $validator = Validator::make(['email' => $email], [
            'email' => ['required', 'email', 'unique:users,email'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first('email'));

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password');

        $validator = Validator::make(['password' => $password], [
            'password' => ['required', Password::defaults()],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first('password'));

            return self::FAILURE;
        }

        if ($this->secret('Confirm password') !== $password) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        $name = trim((string) $this->option('name')) ?: strstr($email, '@', true);

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $this->info("Created {$email}.");

        // is_admin is not fillable, on purpose: no form that accepts user input can set
        // it. forceFill is the same deliberate step GrantAdmin takes.
        if ($this->option('admin')) {
            $user->forceFill(['is_admin' => true])->save();

            $this->info("{$email} can reach /admin.");
        }

        return self::SUCCESS;
    }
}
