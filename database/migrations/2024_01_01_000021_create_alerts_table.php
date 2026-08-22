<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alerts Migration
 *
 * Conditions worth interrupting somebody for, and whether they have been sent.
 *
 * The dashboard already shows when the executor is offline or blocked - but a dashboard only
 * helps somebody who is looking at it, and the whole point of an unattended bot is that
 * nobody is. A silently dead bot holding open positions is the risk the handoff named first
 * and the one nothing has addressed until now.
 *
 * ## Why a table rather than firing a message each time the check runs
 *
 * Because a check that runs every minute would send a message every minute. What makes
 * alerting usable rather than noise is that an alert is an *incident* with a lifetime: it
 * starts, it is notified once, it may be re-notified if it drags on, and it resolves - and
 * the resolution is worth sending too, because an alert you never hear the end of teaches
 * you to ignore the channel.
 *
 * ## The open row is the one with no resolved_at
 *
 * At most one unresolved row exists per (user, key); a new occurrence after a resolution
 * starts a new row, so the history is a list of incidents rather than one row that flaps.
 *
 * This is not enforced with a unique index: MySQL treats NULLs as distinct, so a unique on
 * (user_id, key, resolved_at) would not constrain the open rows at all, and one on
 * (user_id, key) would forbid the history. It is enforced in HealthMonitor instead, which is
 * safe because the only writer is a single scheduled command running withoutOverlapping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Stable identity of the condition, e.g. "executor_offline" or
            // "feed_stalled:M5". Two firings of the same key are the same incident.
            $table->string('key', 100);

            $table->enum('level', ['warning', 'critical']);

            $table->string('title');
            $table->text('body');

            // Readings the alert was raised from, so the message can be reconstructed
            // without re-deriving state that has since changed.
            $table->json('context')->nullable();

            // useCurrent on both, because MySQL gives only the *first* NOT NULL TIMESTAMP
            // in a table an implicit CURRENT_TIMESTAMP default and rejects the second
            // outright (error 1067). Both are set to now() on creation anyway, so the
            // default is also what they mean.
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();

            // Null means still firing. This is the field that defines the open row.
            $table->timestamp('resolved_at')->nullable();

            // When a message was last delivered, and how many have been sent for this
            // incident. Both null/zero when no channel is configured - the incident is
            // still recorded, it simply went nowhere.
            $table->timestamp('notified_at')->nullable();
            $table->unsignedInteger('notify_count')->default(0);

            // Whether the resolution message has gone out, so an incident that resolves
            // while the channel is down is not silently forgotten.
            $table->boolean('resolution_notified')->default(false);

            $table->timestamps();

            // The monitor's only lookup: this user's open incidents.
            $table->index(['user_id', 'resolved_at']);
            $table->index(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
