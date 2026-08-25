<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The channels the copier listens to, as data rather than configuration.
 *
 * ## Why this stops being a config array
 *
 * `config/telegram.php` held the allow-list because there was one source of messages: a
 * bot, in chats somebody had deliberately added it to. Reading real provider channels
 * with a user account changes the shape of the problem - channels get discovered rather
 * than configured, there are many of them, and the question "is this one worth keeping"
 * is answered by its results over weeks. None of that belongs in a file you have to
 * deploy to edit.
 *
 * The config array still works and still wins, so an existing deployment keeps behaving
 * exactly as it did.
 *
 * ## Disabled by default, always
 *
 * `is_enabled` defaults to false and there is no path that flips it automatically. A
 * collector logged in as a real Telegram account can see every channel that account has
 * ever joined; if discovery implied consent, joining a channel to read it would arm it.
 * So a discovered channel arrives as a row to look at, and trades nothing until somebody
 * turns it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_channels', function (Blueprint $table) {
            $table->id();

            // The account whose money this channel's signals would risk.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 'bot_api' or 'mtproto'. The same chat can legitimately be reachable both
            // ways, and the two arrive with different ids, so the pair is the identity.
            $table->string('source', 32)->default('mtproto');
            $table->string('chat_id', 64);

            $table->string('title')->nullable();
            $table->string('username', 64)->nullable();

            // The switch. Off means: record everything, trade nothing.
            $table->boolean('is_enabled')->default(false);

            $table->timestamp('last_message_at')->nullable();
            $table->string('notes')->nullable();

            $table->timestamps();

            $table->unique(['source', 'chat_id']);
            $table->index(['user_id', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_channels');
    }
};
