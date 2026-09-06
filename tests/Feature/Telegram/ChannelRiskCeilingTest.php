<?php

namespace Tests\Feature\Telegram;

use App\Livewire\Pages\SignalChannels;
use App\Models\BotSettings;
use App\Models\TelegramChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * How much one channel may be allowed to risk.
 *
 * A channel override changes the share of the fund a trade takes, not the fund. But a
 * share is only a share if all of them together fit: with three positions allowed open
 * at once, a channel at fifty percent and another at fifty percent could commit the
 * whole fund before either resolved. The ceiling on an override is therefore one share
 * of the fund - 100% over the open-trade limit - and the page says so.
 */
class ChannelRiskCeilingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TelegramChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        BotSettings::where('user_id', $this->user->id)->update(['ai_max_concurrent_trades' => 3]);

        $this->channel = TelegramChannel::create([
            'user_id' => $this->user->id,
            'source' => TelegramChannel::SOURCE_ACCOUNT,
            'chat_id' => '5001',
            'title' => 'FTC 2026',
            'is_enabled' => true,
        ]);
    }

    public function test_a_channel_may_not_risk_more_than_one_share_of_the_fund(): void
    {
        Livewire::actingAs($this->user)->test(SignalChannels::class)
            ->call('edit', $this->channel->id)
            ->set('form.risk_percentage', '50')
            ->call('savePolicy')
            ->assertHasErrors(['form.risk_percentage']);

        $this->assertNull($this->channel->fresh()->risk_percentage);
    }

    public function test_the_refusal_names_the_ceiling_and_where_it_comes_from(): void
    {
        $component = Livewire::actingAs($this->user)->test(SignalChannels::class)
            ->call('edit', $this->channel->id)
            ->set('form.risk_percentage', '50')
            ->call('savePolicy');

        $message = implode(' ', $component->errors()->get('form.risk_percentage'));

        $this->assertStringContainsString('33.33%', $message);
        $this->assertStringContainsString('3 open AI trades', $message);
    }

    public function test_one_share_exactly_is_allowed(): void
    {
        Livewire::actingAs($this->user)->test(SignalChannels::class)
            ->call('edit', $this->channel->id)
            ->set('form.risk_percentage', '33.33')
            ->call('savePolicy')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(33.33, (float) $this->channel->fresh()->risk_percentage, 1e-9);
    }

    /**
     * With one position allowed at a time, one share is the whole fund - the old ceiling.
     */
    public function test_a_single_open_trade_may_take_the_whole_fund(): void
    {
        BotSettings::where('user_id', $this->user->id)->update(['ai_max_concurrent_trades' => 1]);

        Livewire::actingAs($this->user)->test(SignalChannels::class)
            ->call('edit', $this->channel->id)
            ->set('form.risk_percentage', '100')
            ->call('savePolicy')
            ->assertHasNoErrors();
    }
}
