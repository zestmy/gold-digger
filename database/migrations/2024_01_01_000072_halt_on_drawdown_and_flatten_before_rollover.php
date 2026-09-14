<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Halt on Drawdown, and Flatten Before Rollover
 *
 * Two limits the system had no way to express, both off unless configured.
 *
 * ## The drawdown halt
 *
 * `max_daily_loss_percentage` measures one day's *realised* loss against that day's opening
 * balance. That is the right shape for a bad afternoon and the wrong shape for a bad month:
 * an account can lose a quarter of itself over three weeks without any single day breaching
 * a 3% limit, and nothing in the system would have said a word.
 *
 * `max_drawdown_percentage` is the other measurement - peak-to-trough on equity, the figure
 * a track record is actually judged by - and it needs a peak to measure against, which is
 * what `broker_accounts.peak_equity` is. Equity rather than balance because a position
 * still open is money still at risk; the heartbeat reports both.
 *
 * The peak is stored rather than derived because `bot_heartbeats` is pruned. A peak
 * reconstructed from surviving rows would be lower than the real one, which makes the
 * drawdown *look smaller* - the one direction a risk limit must never be wrong in.
 *
 * A deposit raises the peak, and a withdrawal looks exactly like a loss. There is no deal
 * history here to tell them apart, so the peak is shown on the risk page with the date it
 * was set and can be reset to the account's current equity. A limit nobody can unstick is
 * one somebody will switch off for good.
 *
 * ## Flat before rollover
 *
 * Brokers close gold for a daily rollover - Elev8 at 21:00 UTC - and the minutes either
 * side are the worst spread of the day. A position carried through it also pays swap, which
 * `docs/BACKTESTING.md` admits is not modelled, so every overnight hold has been measured
 * better than it traded.
 *
 * `rollover_at` is the broker's own time and has no default, for the same reason `pip_size`
 * has none: the dashboard cannot derive it, brokers disagree about it, and a guess here
 * closes positions at the wrong hour. `flat_before_rollover_minutes` is how long before it
 * to stop opening and start closing - policy rather than truth, and zero means the whole
 * behaviour is off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            // Peak-to-trough equity loss that stops new entries. Null is off, which is what
            // every existing account gets: a limit nobody chose must not start halting
            // trading on the strength of a migration.
            $table->decimal('max_drawdown_percentage', 5, 2)
                ->nullable()
                ->after('max_daily_loss_percentage');

            // The broker's daily rollover, as HH:MM in UTC. Null is off.
            $table->string('rollover_at', 5)
                ->nullable()
                ->after('max_drawdown_percentage');

            // Minutes before that time to stop opening and flatten what is open. Zero is
            // off even when a rollover time is set, so the hour can be recorded before the
            // policy is adopted.
            $table->unsignedSmallInteger('flat_before_rollover_minutes')
                ->default(0)
                ->after('rollover_at');
        });

        Schema::table('broker_accounts', function (Blueprint $table) {
            // The highest equity this account has ever reported, and when it did.
            $table->decimal('peak_equity', 12, 2)->nullable()->after('last_equity');
            $table->timestamp('peak_equity_at')->nullable()->after('peak_equity');
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn([
                'max_drawdown_percentage',
                'rollover_at',
                'flat_before_rollover_minutes',
            ]);
        });

        Schema::table('broker_accounts', function (Blueprint $table) {
            $table->dropColumn(['peak_equity', 'peak_equity_at']);
        });
    }
};
