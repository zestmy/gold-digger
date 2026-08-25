<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signals that arrived as a picture.
 *
 * A large share of providers post a chart screenshot with the levels written on it, often
 * with no caption at all. Those were recorded as unparsed messages, which on the channels
 * page is indistinguishable from a provider who has gone quiet.
 *
 * The transcription is stored beside the caption rather than replacing it. What the
 * provider typed and what a model read off an image are different kinds of evidence, and
 * when a trade goes wrong the question "did we read that right" needs both to be
 * answerable - the caption as written, and the transcription as machine-read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_signals', function (Blueprint $table) {
            $table->boolean('from_image')->default(false)->after('kind');

            // What the model read off the picture. Parsed from this, never from the
            // caption, so the two can be compared afterwards.
            $table->text('transcribed_text')->nullable()->after('original_text');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_signals', function (Blueprint $table) {
            $table->dropColumn(['from_image', 'transcribed_text']);
        });
    }
};
