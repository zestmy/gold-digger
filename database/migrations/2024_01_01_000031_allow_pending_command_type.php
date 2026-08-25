<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `open_pending` as a command type.
 *
 * A resting order at a level the market has not reached. Separate from `open` rather than
 * a flag on it, because the EA does genuinely different work: it chooses limit or stop
 * from where the entry sits relative to the tick, carries absolute levels instead of pip
 * distances, and sets an expiry.
 *
 * ## Why the column stops being an enum
 *
 * It was an enum of six values, and adding a seventh means rewriting the column on every
 * deployment that ever adds a command type. `trades.origin` went the same way for the same
 * reason when 'ai' was added. The constraint was doing little: an unrecognised type is
 * already refused by the EA, which reports "Unknown command type" against the command, and
 * that is a better error than a database write failing halfway through enqueueing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_commands', function (Blueprint $table) {
            $table->string('type', 24)->change();
        });
    }

    public function down(): void
    {
        Schema::table('trade_commands', function (Blueprint $table) {
            $table->enum('type', ['open', 'close', 'modify', 'close_all', 'start', 'stop'])->change();
        });
    }
};
