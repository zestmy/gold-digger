<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Broker Accounts Migration
 *
 * Stores MT5 broker account credentials and metadata.
 * Users can have multiple broker accounts (demo + live, multiple brokers).
 *
 * SECURITY NOTE: account_number is encrypted at rest via Laravel's encrypted cast.
 * Server credentials are stored in .env, not here, for additional security.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broker_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Human-readable label for this account
            // Example: "Octa Demo", "Octa Live", "IC Markets Demo"
            $table->string('label');

            // Broker name for display and potential future multi-broker support
            $table->string('broker_name')->default('Octa');

            // MT5 account number - encrypted at rest for security
            // Even if DB is compromised, account numbers are protected
            $table->string('account_number');

            // MT5 server name (e.g., "Octa-Demo", "Octa-Real")
            $table->string('server');

            // Demo vs live account flag
            // Important for: risk management, statistics separation
            $table->boolean('is_demo')->default(true);

            // Whether this account is currently active for trading
            $table->boolean('is_active')->default(true);

            // Account base currency (most gold accounts are USD)
            $table->string('account_currency', 10)->default('USD');

            // Account leverage setting (affects position sizing calculations)
            // Octa typically offers up to 1:500 leverage
            $table->integer('leverage')->default(500);

            // Cached balance/equity from last sync with MT5
            // Updated by Python bot, used for dashboard display without MT5 query
            $table->decimal('last_balance', 12, 2)->nullable();
            $table->decimal('last_equity', 12, 2)->nullable();

            // When the balance/equity was last synced from MT5
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broker_accounts');
    }
};
