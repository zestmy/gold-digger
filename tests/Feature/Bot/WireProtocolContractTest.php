<?php

namespace Tests\Feature\Bot;

use App\Models\TradeCommand;
use Tests\TestCase;

/**
 * Cross-language contract between Laravel and the MQL5 EA.
 *
 * The EA cannot be compiled or run in CI - it needs MetaEditor and a Windows
 * terminal. What CAN be checked here is that the constants both sides agree on have
 * not drifted apart, which is the realistic way this integration breaks: someone adds
 * a column to the wire format, the EA keeps splitting on the old count, and every
 * command is silently rejected as malformed at 3am.
 *
 * These read the EA source as text on purpose. It is a weaker check than compiling,
 * and it is the strongest one available on Linux.
 */
class WireProtocolContractTest extends TestCase
{
    private function eaSource(): string
    {
        return $this->readSource('mql5/Experts/GoldDigger/GoldDiggerBridge.mq5');
    }

    private function executorSource(): string
    {
        return $this->readSource('mql5/Include/GoldDigger/GDExecutor.mqh');
    }

    private function readSource(string $relative): string
    {
        $path = base_path($relative);

        $this->assertFileExists($path, "{$relative} is part of the contract and must be committed.");

        return file_get_contents($path);
    }

    public function test_the_ea_expects_the_wire_version_laravel_sends(): void
    {
        $this->assertMatchesRegularExpression(
            '/#define\s+GD_WIRE_VERSION\s+"'.preg_quote(TradeCommand::WIRE_VERSION, '/').'"/',
            $this->eaSource(),
            'GD_WIRE_VERSION in the EA must match TradeCommand::WIRE_VERSION.',
        );
    }

    public function test_the_ea_expects_the_column_count_laravel_sends(): void
    {
        preg_match('/#define\s+GD_WIRE_COLUMNS\s+(\d+)/', $this->eaSource(), $m);

        $this->assertNotEmpty($m, 'GD_WIRE_COLUMNS is not defined in the EA.');
        $this->assertSame(
            count(TradeCommand::WIRE_COLUMNS),
            (int) $m[1],
            'The EA splits each line into a fixed number of columns. Adding a field to '
            .'WIRE_COLUMNS without bumping GD_WIRE_COLUMNS makes the EA reject every command.',
        );
    }

    public function test_the_ea_calls_every_endpoint_the_api_exposes(): void
    {
        $source = $this->eaSource();

        foreach (['/api/v1/bot/commands', '/api/v1/bot/fills', '/api/v1/bot/heartbeat', '/api/v1/bot/logs'] as $path) {
            $this->assertStringContainsString(
                $path,
                $source,
                "The EA no longer references {$path}; the route and the executor have drifted.",
            );
        }
    }

    public function test_a_rendered_batch_matches_what_the_ea_parses(): void
    {
        // The EA validates the header before reading any command, so even an empty
        // batch has to carry it.
        $this->assertSame(
            TradeCommand::WIRE_VERSION,
            trim(explode("\n", TradeCommand::toWireBatch([]))[0]),
            'An empty batch must still carry the version header so the EA can validate it.',
        );
    }

    public function test_the_ea_and_the_python_executor_map_the_same_critical_retcodes(): void
    {
        $mql = $this->executorSource();
        $python = file_get_contents(base_path('bot/mt5_executor.py'));

        // The three that actually cause "connects but never trades". Both executors
        // must be able to explain them, or a rejection reads as silence.
        foreach ([10016, 10027, 10030] as $retcode) {
            $this->assertStringContainsString((string) $retcode, $mql, "The MQL5 executor does not explain retcode {$retcode}.");
            $this->assertStringContainsString((string) $retcode, $python, "Python executor does not explain retcode {$retcode}.");
        }
    }
}
