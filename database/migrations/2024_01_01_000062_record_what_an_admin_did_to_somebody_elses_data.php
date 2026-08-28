<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an administrator changed on an account that is not theirs.
 *
 * The Filament panel is the one place in this application where cross-tenant access happens
 * by design - it is a support console, and a support console that could only see its own
 * operator's data would be useless. Every resource in it carries an `EditAction` and a
 * `DeleteBulkAction`, so an administrator can change a customer's stop price, raise their AI
 * capital cap, or bulk-delete their broker accounts.
 *
 * None of that was recorded anywhere. For a product holding broker account numbers and trade
 * history, "who changed this, and when" is a question that has to have an answer - and the
 * one time somebody needs it is the one time nobody will be able to reconstruct it.
 *
 * ## It records nothing until there is somebody to record it against
 *
 * The observer only writes when an administrator acts on a row belonging to a *different*
 * user. On a single-operator deployment - which is what this is today - that is never, so
 * this table stays empty and costs nothing. It starts recording the moment there is a second
 * tenant, which is exactly when it starts mattering.
 *
 * ## The changes column is redacted
 *
 * `broker_accounts.account_number` and `telegram_accounts.session` are encrypted at rest,
 * and a session can read every chat on somebody's Telegram account. An audit log that
 * captured the plaintext of the secrets it was auditing would be a worse leak than the one
 * it exists to detect, so hidden and known-sensitive attributes are stored as `[redacted]` -
 * the fact that they changed is the auditable part, not the value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_actions', function (Blueprint $table) {
            $table->id();

            // Who did it. Kept even if the account is later removed, because an audit trail
            // that erases itself when somebody leaves is not one.
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Whose data it was. The reason this table exists at all.
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action', 16);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id')->nullable();

            // Before and after, redacted. Null for a delete, where the interesting fact is
            // that the row is gone rather than what its columns were.
            $table->json('changes')->nullable();

            // Where from. A support action taken from an unexpected address is the shape of
            // a compromised administrator account.
            $table->string('ip', 45)->nullable();

            $table->timestamps();

            // "What has been done to this customer" - the question a complaint arrives as.
            $table->index(['subject_user_id', 'created_at']);

            // "What has this administrator been doing" - the question an incident arrives as.
            $table->index(['admin_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_actions');
    }
};
