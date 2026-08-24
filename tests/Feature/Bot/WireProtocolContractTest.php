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

    /**
     * A command with no payload is nothing but empty trailing columns.
     *
     * This is the shape that broke commissioning: `close_all` serialises as an id, a
     * type, and ten empty columns. Both sides agreed the format had twelve columns, so
     * the constant check above passed while every such command was rejected.
     */
    public function test_a_command_with_no_payload_still_serialises_every_column(): void
    {
        $command = new TradeCommand(['type' => 'close_all', 'payload' => []]);
        $command->id = 5;

        $line = $command->toWireLine();

        $this->assertCount(
            count(TradeCommand::WIRE_COLUMNS),
            explode("\t", $line),
            'A payloadless command must still occupy every column.',
        );

        // The hazard, stated as an assertion so the test below is not vacuous: the line
        // ends in TABs, and anything that treats a TAB as trailing whitespace destroys it.
        $this->assertStringEndsWith("\t", $line);
        $this->assertNotCount(
            count(TradeCommand::WIRE_COLUMNS),
            explode("\t", rtrim($line, " \t\r\n")),
        );
    }

    public function test_the_ea_does_not_trim_tabs_off_a_command_line(): void
    {
        // The parse loop, from where it takes the line to where it splits on TAB.
        $found = preg_match(
            '/string\s+line\s*=\s*lines\[i\];(.*?)StringSplit\(\s*line\s*,/s',
            $this->eaSource(),
            $m,
        );

        $this->assertSame(1, $found, 'Could not find the command-line parse loop in the EA.');

        // Comments in that span explain this very hazard and name the functions, so
        // strip them: what matters is whether the code calls one, not whether the
        // prose mentions one.
        $code = preg_replace('#//[^\n]*#', '', $m[1]);

        foreach (['StringTrimRight', 'StringTrimLeft'] as $trim) {
            $this->assertStringNotContainsString(
                $trim.'(',
                $code,
                "The EA must not call {$trim}() on a command line before splitting it. MQL5 "
                .'counts TAB as whitespace, so trimming deletes the empty trailing columns '
                .'of any payloadless command: close_all and stop arrive as 2 columns instead '
                .'of '.count(TradeCommand::WIRE_COLUMNS).' and are refused as malformed. '
                .'Strip the line terminator explicitly instead.',
            );
        }
    }
}
