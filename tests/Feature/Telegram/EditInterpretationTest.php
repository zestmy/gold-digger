<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramSignal;
use App\Models\User;
use App\Services\Telegram\EditInterpreter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reading a provider's edit to a signal that has already been traded.
 *
 * The alert this feeds used to say only that something had changed - the same sentence
 * for a stop tightened by ten points and one removed altogether. Untriageable without
 * opening Telegram and comparing two messages by eye, which is not a thing anyone does at
 * three in the morning.
 */
class EditInterpretationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        config(['ai.key' => 'test-key', 'ai.model' => 'test-model']);
    }

    public function test_it_names_which_way_the_edit_moved_risk(): void
    {
        $this->model(['risk' => 'reduced', 'action' => 'move_stop', 'confidence' => 90,
            'reasoning' => 'Stop moved 2390 -> 2380.']);

        $reading = app(EditInterpreter::class)->interpret($this->edited());

        $this->assertSame('reduced', $reading['risk']);
        $this->assertSame('move_stop', $reading['action']);
        $this->assertSame(90, $reading['confidence']);
    }

    public function test_an_action_this_copier_does_not_implement_becomes_none(): void
    {
        // A value the executor does not understand is indistinguishable from an invention.
        $this->model(['risk' => 'reduced', 'action' => 'reverse_direction', 'confidence' => 95,
            'reasoning' => 'Flipped to sell.']);

        $this->assertSame(
            TelegramSignal::FOLLOW_NONE,
            app(EditInterpreter::class)->interpret($this->edited())['action'],
        );
    }

    public function test_an_unknown_risk_reading_becomes_unclear(): void
    {
        $this->model(['risk' => 'catastrophic', 'action' => 'none', 'confidence' => 50, 'reasoning' => '?']);

        $this->assertSame(
            EditInterpreter::RISK_UNCLEAR,
            app(EditInterpreter::class)->interpret($this->edited())['risk'],
        );
    }

    public function test_no_api_key_reads_nothing_rather_than_guessing(): void
    {
        config(['ai.key' => null]);

        $reading = app(EditInterpreter::class)->interpret($this->edited());

        $this->assertSame(TelegramSignal::FOLLOW_NONE, $reading['action']);
        $this->assertSame(EditInterpreter::RISK_UNCLEAR, $reading['risk']);
        $this->assertSame(0, $reading['confidence']);
    }

    public function test_a_signal_with_no_earlier_version_is_not_sent_to_a_model(): void
    {
        Http::fake();

        $signal = $this->edited();
        $signal->update(['original_text' => null]);

        $reading = app(EditInterpreter::class)->interpret($signal->fresh());

        $this->assertSame(EditInterpreter::RISK_UNCLEAR, $reading['risk']);
        Http::assertNothingSent();
    }

    public function test_confidence_is_clamped_to_a_percentage(): void
    {
        $this->model(['risk' => 'unchanged', 'action' => 'none', 'confidence' => 4200, 'reasoning' => 'Typo.']);

        $this->assertSame(100, app(EditInterpreter::class)->interpret($this->edited())['confidence']);
    }

    // =====================================================================

    private function model(array $payload): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'model' => 'test-model',
                'choices' => [['message' => ['content' => json_encode($payload)]]],
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);
    }

    private function edited(): TelegramSignal
    {
        return TelegramSignal::create([
            'user_id' => $this->user->id,
            'source' => 'mtproto',
            'external_id' => 'mtproto:100:7',
            'chat_id' => '100',
            'raw_text' => 'XAUUSD BUY @ 2400 SL 2380 TP 2420',
            'original_text' => 'XAUUSD BUY @ 2400 SL 2390 TP 2420',
            'edit_count' => 1,
            'edited_at' => now(),
            'parse_status' => TelegramSignal::PARSE_OK,
        ]);
    }
}
