<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gate the Admin Panel
 *
 * `User::canAccessPanel()` returned true unconditionally, and no Filament resource scopes its
 * query by user. Combined with an open registration route, that meant anyone who signed up
 * could open /admin and read - or edit - every user's trades, broker accounts, bot settings
 * and strategies. The sidebar linked straight to it.
 *
 * ## Why this fails closed
 *
 * The obvious convenience would be to grant admin to every existing user so nobody is locked
 * out by the upgrade. That is precisely the hole being closed: on a database with stray
 * registrations it would promote exactly the accounts that should not have been there.
 *
 * So the only automatic grant is the unambiguous case - a database holding exactly one user,
 * which can only be the person who set the thing up. Anything else stays false and is granted
 * deliberately:
 *
 *     php artisan user:admin you@example.com
 *
 * A deployment that loses its own admin access is recoverable in one command. A deployment
 * that silently hands admin to a stranger is not recoverable at all.
 *
 * ## What this does not do
 *
 * Resource-level tenancy. An admin still sees every user's records, which is correct while
 * this is a single-operator tool and wrong the moment it is not. If this becomes multi-user,
 * every Filament resource needs `getEloquentQuery()` scoped by user - see the audit, F1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email_verified_at');
        });

        if (DB::table('users')->count() === 1) {
            DB::table('users')->update(['is_admin' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
