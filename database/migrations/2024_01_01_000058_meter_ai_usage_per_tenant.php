<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nine model call sites, one platform API key, and no record of who spent what.
 *
 * That is the correct arrangement for one operator paying their own OpenRouter bill. As a
 * product it is a business with unbounded, unattributed cost of goods: a tenant who never
 * places a trade can still run chart analyses, market scans and the strategy improver
 * without limit, and nothing anywhere records that they did.
 *
 * `AiFund` already bounds what the AI may *lose*. This bounds what it may *spend*, which
 * is a different quantity with a different payer - the fund protects the tenant's account,
 * and this protects the platform's.
 *
 * ## Attempts are recorded, not successes
 *
 * OpenRouter bills per request that reaches a model. A 5xx that arrived after generation
 * finished is a charge, and `OpenRouter` already declines to retry status codes for
 * exactly that reason. A usage table that only counted successful calls would therefore
 * under-report real spend, and would do so worst on the days something was broken - which
 * is precisely when somebody goes looking.
 *
 * ## Cost is nullable on purpose
 *
 * Token counts come back on every response. A currency figure depends on the gateway
 * returning one, and no row should be lost because it did not. A null cost with real token
 * counts is still an answer to "who is using this"; a missing row is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();

            // Nullable: a call made with no tenant in scope belongs to the platform, and
            // recording it against nobody is more honest than attributing it to whoever
            // happened to be first in the table.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            // Which of the nine. The whole point of metering is to find out which surface
            // is expensive, and "openrouter" as a single line item cannot answer that.
            $table->string('call_site', 40);

            $table->string('model_requested', 64);

            // What actually served it. OpenRouter routes elsewhere when the requested
            // model is unavailable, and a bill that disagrees with the request is worth
            // being able to see rather than deduce.
            $table->string('model_served', 64)->nullable();

            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();

            // Six decimal places because a single cheap call is fractions of a cent, and
            // rounding it to two would record most of this table as zero.
            $table->decimal('cost_usd', 12, 6)->nullable();

            $table->boolean('ok')->default(false);

            // Short, because it is for grouping failures rather than for reading one.
            $table->string('failure', 120)->nullable();

            $table->timestamps();

            // The allowance question: how much has this tenant spent since midnight.
            $table->index(['user_id', 'created_at']);

            // The product question: which surface costs the most.
            $table->index(['call_site', 'created_at']);
        });

        Schema::table('bot_settings', function (Blueprint $table) {
            // Null means "use the platform default from config". An explicit number is
            // this tenant's own ceiling, which is where a paid plan will eventually write
            // its entitlement. Zero is a real value meaning no AI at all.
            $table->unsignedInteger('ai_daily_call_limit')->nullable()->after('ai_capital_cap');
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn('ai_daily_call_limit');
        });

        Schema::dropIfExists('ai_usage');
    }
};
