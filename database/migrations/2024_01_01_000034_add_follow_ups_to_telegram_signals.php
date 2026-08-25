<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Management instructions posted after a signal.
 *
 * ## Why a reply is a different kind of message
 *
 * "Secure half" is not a signal. It has no instrument, no direction and no stop, and
 * feeding it to the signal parser produces exactly what it produced before this existed:
 * an unparsed row and a copier that holds a full position while the provider has told
 * everyone to take half off.
 *
 * What makes it interpretable is the message it replies to. Telegram carries that link,
 * so the copier can too - and once a follow-up knows its parent, it knows the position,
 * the entry, the stop and how much is still open. Without that link the same words are
 * unactionable, which is why an orphaned reply is recorded and does nothing.
 *
 * ## The instruction is stored as fields, not as text
 *
 * `follow_up_action` and its two operands are what execution reads. The model's job ends
 * at turning a sentence into one of a closed set of actions; nothing downstream ever
 * interprets prose, so a provider writing something novel produces an unrecognised action
 * rather than a creative trade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_signals', function (Blueprint $table) {
            // 'signal' or 'follow_up'. Kept explicit rather than inferred from
            // parent_signal_id being set, because a follow-up whose parent was never
            // captured is still a follow-up - it is simply one that cannot be acted on,
            // and counting it as a signal that failed to parse would libel the channel's
            // parse rate.
            $table->string('kind', 16)->default('signal')->after('source')->index();

            // The provider's own message id for the parent. Stored even when the parent is
            // unknown, so a later backfill can join what was orphaned.
            $table->string('reply_to_message_id', 64)->nullable()->after('external_id');

            $table->foreignId('parent_signal_id')->nullable()->after('reply_to_message_id')
                ->constrained('telegram_signals')->nullOnDelete();

            // none / secure_partial / breakeven / close / move_stop / add_entry
            $table->string('follow_up_action', 32)->nullable()->after('tp_prices');

            // The fraction to take off, for a partial close. 0.5 is "secure half".
            $table->decimal('follow_up_fraction', 5, 4)->nullable()->after('follow_up_action');

            // An explicit level, for a stop move that names one.
            $table->decimal('follow_up_price', 16, 6)->nullable()->after('follow_up_fraction');

            $table->index(['parent_signal_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('telegram_signals', function (Blueprint $table) {
            $table->dropForeign(['parent_signal_id']);
            $table->dropColumn([
                'kind', 'reply_to_message_id', 'parent_signal_id',
                'follow_up_action', 'follow_up_fraction', 'follow_up_price',
            ]);
        });
    }
};
