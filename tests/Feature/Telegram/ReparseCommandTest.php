<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramSignal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * telegram:reparse
 *
 * How a change to the parser is judged: run it again over the misses and count what now
 * reads. What it must never do is overwrite a reading a person made, or credit the
 * parser with one it did not make.
 */
class ReparseCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function miss(string $text, array $overrides = []): TelegramSignal
    {
        return TelegramSignal::create($overrides + [
            'user_id' => $this->user->id,
            'source' => 'bot_api',
            'external_id' => 'bot:'.random_int(1, 999999999),
            'chat_id' => '316745398',
            'raw_text' => $text,
            'posted_at' => now()->subHours(2),
            'parse_status' => TelegramSignal::PARSE_FAILED,
            'parse_error' => 'No stop loss found.',
            'review_status' => TelegramSignal::REVIEW_SKIPPED,
        ]);
    }

    /**
     * A miss recorded under an older parser, whose text today's parser reads. That is
     * exactly the row an improved pattern is meant to recover.
     */
    public function test_a_miss_the_parser_now_reads_joins_the_pipeline_at_review(): void
    {
        $recovered = $this->miss('XAUUSD BUY 2650 SL 2640 TP 2680');
        $still = $this->miss('GOLD BUY NOW!!');

        $this->artisan('telegram:reparse')
            ->expectsOutputToContain('2 unparsed, 1 now parse.')
            ->expectsOutputToContain('Still unparsed, by complaint:')
            ->assertSuccessful();

        $recovered->refresh();
        $this->assertSame(TelegramSignal::PARSE_OK, $recovered->parse_status);
        $this->assertSame(TelegramSignal::PARSED_BY_PARSER, $recovered->parsed_by);
        $this->assertSame('XAUUSD', $recovered->symbol);
        $this->assertSame(2640.0, (float) $recovered->sl_price);
        $this->assertSame(TelegramSignal::REVIEW_PENDING, $recovered->review_status);

        $still->refresh();
        $this->assertSame(TelegramSignal::PARSE_FAILED, $still->parse_status);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $miss = $this->miss('XAUUSD BUY 2650 SL 2640 TP 2680');

        $this->artisan('telegram:reparse --dry-run')
            ->expectsOutputToContain('1 now parse (dry run, nothing changed)')
            ->assertSuccessful();

        $this->assertSame(TelegramSignal::PARSE_FAILED, $miss->fresh()->parse_status);
    }

    /**
     * A person's reading outranks the parser's. A reparse over a corrected row would
     * either discard what they typed or, worse, replace it with a different trade.
     */
    public function test_it_never_touches_a_signal_a_person_corrected(): void
    {
        $corrected = $this->miss('XAUUSD BUY 2650 SL 2640 TP 2680', [
            'parse_status' => TelegramSignal::PARSE_FAILED,
            'corrected_at' => now()->subMinutes(5),
        ]);

        $this->artisan('telegram:reparse')
            ->expectsOutputToContain('No unparsed text messages in the window.')
            ->assertSuccessful();

        $this->assertNull($corrected->fresh()->symbol);
    }

    public function test_it_leaves_image_messages_and_refused_chats_alone(): void
    {
        $image = $this->miss('XAUUSD BUY 2650 SL 2640 TP 2680', ['from_image' => true, 'parse_error' => 'Could not read the image cleanly.']);
        $refused = $this->miss('XAUUSD BUY 2650 SL 2640 TP 2680', ['parse_error' => 'Channel is not enabled as a signal source.']);

        $this->artisan('telegram:reparse')->assertSuccessful();

        $this->assertSame(TelegramSignal::PARSE_FAILED, $image->fresh()->parse_status);
        $this->assertSame(TelegramSignal::PARSE_FAILED, $refused->fresh()->parse_status);
    }
}
