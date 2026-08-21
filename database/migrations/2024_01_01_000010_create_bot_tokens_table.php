<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bot Tokens Migration
 *
 * API credentials for the execution side (the MQL5 EA, or any future executor).
 *
 * WHY a table instead of a single BOT_API_KEY in .env?
 * - The API has to know WHICH user a request belongs to. Every table in this schema
 *   is scoped by user_id; a shared config secret carries no identity.
 * - Per-device tokens can be revoked individually. If the VPS is compromised you
 *   revoke one row rather than rotating a secret shared with everything else.
 * - last_used_at doubles as a cheap "is that terminal still alive?" signal.
 *
 * SECURITY: only the SHA-256 hash is stored. The plaintext token is shown once at
 * creation and is unrecoverable afterwards - same model as a GitHub PAT. Lookup is
 * by hash, so a database leak does not yield working credentials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Human label so you can tell devices apart: "Windows VPS - Octa Demo"
            $table->string('name');

            // SHA-256 of the plaintext token. Indexed because every authenticated
            // request looks the token up by this column.
            $table->string('token_hash', 64)->unique();

            // Optional scoping: bind a token to one broker account so a compromised
            // demo terminal cannot act on the live account.
            $table->foreignId('broker_account_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_tokens');
    }
};
