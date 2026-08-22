<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * OctaFX is now Elev8
 *
 * A rebrand, not a change of broker: the same accounts on the same servers, renamed.
 *
 * This exists because `broker_name` is not free text in practice - the Broker Accounts page
 * offers a fixed list, and a stored value that is not one of its keys renders as an empty
 * selection. An account created before the rename would look like it had no broker at all the
 * next time somebody opened it for editing.
 *
 * Only the label moves. Nothing in this system keys behaviour on the broker's name: symbol
 * resolution scans the server's own symbol list rather than mapping a brand to a suffix, which
 * is exactly why a rebrand costs one migration instead of a release.
 *
 * `server` is deliberately left alone. Those are real MetaTrader server identifiers - the
 * strings a terminal connects with - and this has no way to know which of them the broker
 * actually renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('broker_accounts')
            ->whereIn('broker_name', ['Octa', 'OctaFX'])
            ->update(['broker_name' => 'Elev8']);
    }

    public function down(): void
    {
        // Reversible in shape only. Any account genuinely created as Elev8 after the rename
        // would be renamed back to a broker that no longer exists, which is why this is not
        // a migration to run casually.
        DB::table('broker_accounts')
            ->where('broker_name', 'Elev8')
            ->update(['broker_name' => 'Octa']);
    }
};
