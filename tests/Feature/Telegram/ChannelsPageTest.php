<?php

namespace Tests\Feature\Telegram;

use App\Livewire\Pages\SignalChannels;
use App\Models\TelegramAccount;
use App\Models\TelegramChannel;
use App\Models\TelegramSignal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Providers page as a screen: the accounts strip above the channels, the chips that
 * split followed channels from recorded ones, and the words on the switch.
 *
 * The numbers under each channel are ChannelPerformanceTest's business. This file is
 * about what a reader with no channels, or a hundred, sees first.
 */
class ChannelsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    // =====================================================================
    // THE ACCOUNTS STRIP
    // =====================================================================

    /**
     * A provider that has gone quiet is more often an account that stopped being read
     * than a channel that stopped posting, so the accounts come first.
     */
    public function test_each_account_is_listed_with_its_state(): void
    {
        TelegramAccount::create([
            'user_id' => $this->user->id, 'label' => 'Running',
            'login_state' => TelegramAccount::ACTIVE, 'last_seen_at' => now()->subMinute(),
        ]);
        TelegramAccount::create([
            'user_id' => $this->user->id, 'label' => 'Halfway',
            'login_state' => TelegramAccount::CODE_SENT, 'login_phone' => '+60123456789',
            'login_updated_at' => now(),
        ]);
        TelegramAccount::create([
            'user_id' => $this->user->id, 'label' => 'Broken',
            'login_state' => TelegramAccount::FAILED,
        ]);
        TelegramAccount::create(['user_id' => $this->user->id, 'label' => 'Fresh']);

        Livewire::test(SignalChannels::class)
            ->assertSeeInOrder(['Running', 'CONNECTED'])
            ->assertSeeInOrder(['Halfway', 'SIGNING IN'])
            ->assertSeeInOrder(['Broken', 'FAILED'])
            ->assertSeeInOrder(['Fresh', 'IDLE'])
            ->assertSee('last heard')
            ->assertSee('Manage accounts');
    }

    /**
     * Enough of the number to tell two apart, not enough to dial.
     */
    public function test_a_phone_number_is_masked(): void
    {
        TelegramAccount::create([
            'user_id' => $this->user->id, 'label' => 'Halfway',
            'login_state' => TelegramAccount::CODE_SENT, 'login_phone' => '+60123456789',
            'login_updated_at' => now(),
        ]);

        Livewire::test(SignalChannels::class)
            ->assertSee('+60******789')
            ->assertDontSee('+60123456789');
    }

    /**
     * Signed in and not being read is not idle: the sign-in is good, and sending somebody
     * to redo it would be the wrong fix.
     */
    public function test_a_signed_in_account_nobody_is_reading_is_not_called_idle(): void
    {
        TelegramAccount::create([
            'user_id' => $this->user->id, 'label' => 'Stopped',
            'login_state' => TelegramAccount::ACTIVE, 'last_seen_at' => now()->subHours(2),
        ]);

        Livewire::test(SignalChannels::class)
            ->assertSee('SIGNED IN, NOT READING')
            ->assertDontSee('IDLE');
    }

    public function test_with_no_account_the_strip_says_where_to_start(): void
    {
        Livewire::test(SignalChannels::class)
            ->assertSee('Connect a Telegram account to follow providers')
            ->assertSee(route('signals.accounts'));
    }

    public function test_another_users_accounts_are_not_shown(): void
    {
        $other = User::factory()->create();
        TelegramAccount::create(['user_id' => $other->id, 'label' => 'Not mine']);

        Livewire::test(SignalChannels::class)->assertDontSee('Not mine');
    }

    // =====================================================================
    // FOLLOWING, RECORDING ONLY, ALL SEEN
    // =====================================================================

    public function test_the_chips_count_followed_and_recorded_channels(): void
    {
        $this->channel('Fira', enabled: true);
        $this->channel('Gold Ninja', enabled: false);
        $this->channel('Quiet', enabled: false);

        Livewire::test(SignalChannels::class)
            ->assertSee('Following (1)')
            ->assertSee('Recording only (2)')
            ->assertSee('All seen (3)');
    }

    public function test_the_chips_narrow_the_list(): void
    {
        $followed = $this->channel('Fira', enabled: true);
        $recorded = $this->channel('Gold Ninja', enabled: false);
        $this->capture($followed);
        $this->capture($recorded);

        $page = Livewire::test(SignalChannels::class);

        $page->set('show', 'following')
            ->assertSee('Fira')
            ->assertDontSee('Gold Ninja');

        $page->set('show', 'recording')
            ->assertSee('Gold Ninja')
            ->assertDontSee('Fira');

        $page->set('show', 'all')
            ->assertSee('Fira')
            ->assertSee('Gold Ninja');
    }

    /**
     * Channels that have never posted sit in their own list, and the chips have to reach
     * them too - a channel you armed weeks ago that has said nothing is exactly the one
     * the Following chip exists to find.
     */
    public function test_the_chips_also_narrow_the_silent_channels(): void
    {
        $this->channel('Armed but silent', enabled: true);
        $this->channel('Merely joined', enabled: false);

        Livewire::test(SignalChannels::class)
            ->set('show', 'following')
            ->assertSee('Armed but silent')
            ->assertDontSee('Merely joined');
    }

    // =====================================================================
    // THE WORDS ON THE SWITCH
    // =====================================================================

    public function test_the_switch_is_called_auto_trade_and_shows_its_state(): void
    {
        $channel = $this->channel('Fira', enabled: false);
        $this->capture($channel);

        $page = Livewire::test(SignalChannels::class)
            ->assertSee('Auto-trade')
            ->assertSee('RECORDING ONLY')
            ->assertDontSee('FOLLOWING');

        $page->call('toggle', $channel->id)
            ->assertSee('FOLLOWING');

        $this->assertTrue($channel->fresh()->is_enabled);
    }

    public function test_the_explainer_says_what_the_switch_does_and_does_not_do(): void
    {
        Livewire::test(SignalChannels::class)
            ->assertSee('A channel switched on is the only thing that arms it.')
            ->assertSee('kept, scored, and never traded');
    }

    public function test_the_route_renders_under_the_providers_header(): void
    {
        $this->get(route('signals.channels'))
            ->assertOk()
            ->assertSee('Providers')
            ->assertSee('Connected accounts');
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function channel(string $title, bool $enabled): TelegramChannel
    {
        return TelegramChannel::create([
            'user_id' => $this->user->id,
            'source' => TelegramChannel::SOURCE_ACCOUNT,
            'chat_id' => (string) random_int(1000, 999999),
            'title' => $title,
            'is_enabled' => $enabled,
        ]);
    }

    /**
     * One captured message, which is what moves a channel from the silent list into the
     * one with numbers under it.
     */
    private function capture(TelegramChannel $channel): TelegramSignal
    {
        return TelegramSignal::create([
            'user_id' => $this->user->id,
            'source' => TelegramChannel::SOURCE_ACCOUNT,
            'external_id' => 'tg:'.$channel->chat_id.':'.random_int(1, 999999999),
            'chat_id' => $channel->chat_id,
            'telegram_channel_id' => $channel->id,
            'chat_title' => $channel->title,
            'raw_text' => 'XAUUSD BUY',
            'parse_status' => TelegramSignal::PARSE_OK,
            'symbol' => 'XAUUSD',
            'direction' => 'buy',
            'review_status' => TelegramSignal::REVIEW_PENDING,
        ]);
    }
}
