<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signals captured from Telegram, before anything is done with them.
 *
 * ## Every message is stored, parsed or not
 *
 * A copier that only records what it understood cannot answer the question that actually
 * matters: what did it miss? A provider changing their format is silent otherwise - the
 * messages keep arriving, nothing trades, and the dashboard looks like a quiet week.
 * Unparsed messages are kept with the reason, so the gap is visible and the parser can be
 * fixed against real examples rather than imagined ones.
 *
 * ## The pipeline is recorded, not just the outcome
 *
 * raw text -> parsed fields -> AI review -> execution. Each stage's result is a column,
 * because "why did this one not trade" has four possible answers and they need different
 * responses: it did not parse, the AI declined it, a gate blocked it, or the broker
 * refused it.
 *
 * ## Source-agnostic on purpose
 *
 * `source` and `external_id` describe where a message came from without assuming how it
 * arrived. The Bot API can only see chats the bot is in; reading a third-party channel
 * needs an MTProto client running as a user account. Both can write here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_signals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 'bot_api' for now; an MTProto feeder would use its own.
            $table->string('source', 32)->default('bot_api');

            // Identity on the source, so re-polling cannot double-execute a signal. This
            // is the column standing between a retry and two positions.
            $table->string('external_id', 128)->unique();

            $table->string('chat_title')->nullable();
            $table->string('chat_id', 64)->nullable()->index();
            $table->text('raw_text');
            $table->timestamp('posted_at')->nullable();

            // ---- parsing ----
            // pending / parsed / unparsed
            $table->string('parse_status', 16)->default('pending')->index();
            $table->string('parse_error')->nullable();

            $table->string('symbol', 32)->nullable()->index();
            $table->string('direction', 8)->nullable();
            $table->decimal('entry_price', 16, 6)->nullable();
            $table->decimal('entry_zone_high', 16, 6)->nullable();

            // Nullable in the column, required by the code. A copied trade with an invented
            // stop is the worst thing this feature could produce, so a signal whose stop
            // could not be read is refused rather than completed with a guess.
            $table->decimal('sl_price', 16, 6)->nullable();

            $table->json('tp_prices')->nullable();

            // ---- review ----
            // pending / approved / declined / skipped
            $table->string('review_status', 16)->default('pending')->index();
            $table->text('review_reasoning')->nullable();
            $table->unsignedTinyInteger('review_confidence')->nullable();
            $table->string('review_model')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            // ---- execution ----
            // not_attempted / queued / executed / blocked / failed
            $table->string('execution_status', 16)->default('not_attempted')->index();
            $table->string('execution_note')->nullable();
            $table->foreignId('trade_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_signals');
    }
};
