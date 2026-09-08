<?php

namespace Tests\Feature\Telegram;

use App\Livewire\Pages\Settings;
use App\Models\BotSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Choosing whether a copied signal is judged or trusted.
 *
 * The default is the model, because trusting a stranger's trades is a choice to make and
 * not one to inherit. The setting is on the risk page, saved like every other.
 */
class CopierReviewSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_model_reviews_copied_signals_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertSame(BotSettings::COPIER_REVIEW_MODEL, BotSettings::where('user_id', $user->id)->value('copier_review'));
    }

    public function test_trusting_the_provider_is_saved_from_the_risk_page(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('copier_review', 'gates')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(BotSettings::COPIER_REVIEW_GATES, BotSettings::where('user_id', $user->id)->value('copier_review'));
    }

    public function test_only_the_two_known_modes_are_accepted(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('copier_review', 'yolo')
            ->call('save')
            ->assertHasErrors(['copier_review']);
    }
}
