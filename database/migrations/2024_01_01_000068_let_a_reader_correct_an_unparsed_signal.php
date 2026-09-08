<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who read the signal out of the message.
 *
 * Until now an unparsed message was a dead end: stored, shown with the parser's
 * complaint, and never traded. The parser refuses more than it guesses - see
 * SignalParser - which is right, but it leaves a class of message that a person reading
 * it would understand at once. `parsed_by` records whether the fields came from the text
 * parser, the image reader, or a reader who typed them in; `corrected_at` is when.
 *
 * The distinction matters for the numbers. A channel's parse rate is about the parser;
 * a signal a person corrected must not flatter it. And a reparse - re-running an
 * improved parser over the misses - must never overwrite what a person wrote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_signals', function (Blueprint $table) {
            $table->string('parsed_by', 16)->nullable()->after('parse_error');
            $table->timestamp('corrected_at')->nullable()->after('parsed_by');
        });

        // History: everything that parsed so far was read by the parser or the image
        // reader, and `from_image` already says which.
        DB::table('telegram_signals')->where('parse_status', 'parsed')->where('from_image', true)->update(['parsed_by' => 'image']);
        DB::table('telegram_signals')->where('parse_status', 'parsed')->whereNull('parsed_by')->update(['parsed_by' => 'parser']);
    }

    public function down(): void
    {
        Schema::table('telegram_signals', function (Blueprint $table) {
            $table->dropColumn(['parsed_by', 'corrected_at']);
        });
    }
};
