<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Model events are deliberately left on. `UserObserver` is what gives a new account its
     * `BotSettings` row and starter strategy, and a seeded user without those opens the
     * dashboard to a Settings page reading null. `WithoutModelEvents` used to be here,
     * copied from the skeleton, and produced exactly that.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
