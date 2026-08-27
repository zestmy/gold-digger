<?php

namespace App\Services\Telegram;

use App\Services\Ai\OpenRouter;

/**
 * Image Signal Reader
 *
 * Reads a signal out of a picture, when the provider posted one instead of text.
 *
 * ## Why this is worth having
 *
 * A large share of providers post a chart screenshot with the levels drawn or typed on it,
 * sometimes with no caption at all. Those arrive here as unparsed messages, and a copier
 * that silently ignores them looks exactly like a copier watching a quiet channel. The
 * parse rate on the channels page is what makes that visible; this is what fixes it.
 *
 * ## Why it refuses far more readily than the text parser
 *
 * Reading numbers off a picture has a failure mode the text parser does not: it can be
 * confidently wrong. A misread digit in "2650" produces "2050", which is a well-formed
 * price, passes every sanity check a parser could apply, and is a completely different
 * trade. Text either says 2650 or it does not.
 *
 * So the model is asked to transcribe rather than interpret, told to refuse anything it
 * cannot read cleanly, and its output is put through the same coherence checks the text
 * parser applies - stop on the correct side, direction unambiguous, levels ordered. A
 * picture that produces a plausible-but-unverifiable reading is refused, because the cost
 * of being wrong here is a real position at an invented price.
 *
 * ## What it does not do
 *
 * It does not read levels off the chart's own axis or candles. Only text that is legibly
 * written on the image counts - a signal implied by an arrow drawn on a chart is not a
 * signal anybody can transcribe, and inferring one would be inventing it.
 */
final class ImageSignalReader
{
    /** Beyond this the picture is almost certainly a chart to look at, not a signal card. */
    private const MAX_BYTES = 4_000_000;

    public function __construct(
        private readonly OpenRouter $router = new OpenRouter,
        private readonly SignalParser $parser = new SignalParser,
    ) {}

    public function configured(): bool
    {
        return $this->router->configured();
    }

    /**
     * @param  string  $binary  The raw image bytes as forwarded by the collector
     * @return array{ok: bool, error: string|null, text: string|null, parsed: array<string, mixed>|null}
     */
    public function read(string $binary, string $mime = 'image/jpeg', string $caption = ''): array
    {
        if (! $this->configured()) {
            return $this->refuse('No OPENROUTER_API_KEY is configured, so images cannot be read.');
        }

        if ($binary === '') {
            return $this->refuse('The image was empty.');
        }

        if (strlen($binary) > self::MAX_BYTES) {
            return $this->refuse('The image is larger than this reads; a signal card is not four megabytes.');
        }

        $result = $this->router->structured(
            model: (string) config('ai.vision_model'),
            system: $this->systemPrompt(),
            brief: $this->brief($caption),
            schemaName: 'image_signal',
            schema: $this->schema(),
            imageDataUri: 'data:'.$mime.';base64,'.base64_encode($binary),
            callSite: 'image_signal_reader',
        );

        if (! $result['ok']) {
            return $this->refuse('The image could not be read: '.($result['error'] ?? 'unknown error').'.');
        }

        if (($result['data']['readable'] ?? false) !== true) {
            return $this->refuse(
                trim((string) ($result['data']['reason'] ?? '')) ?: 'Nothing legible enough to trade was found in the image.'
            );
        }

        $transcribed = trim((string) ($result['data']['transcription'] ?? ''));

        if ($transcribed === '') {
            return $this->refuse('The image was reported readable but transcribed to nothing.');
        }

        // Through the ordinary text parser, deliberately. Everything it enforces - a stop
        // on the correct side, one unambiguous direction, a refusal when the stop cannot be
        // read - has to hold for a picture exactly as it does for a message, and a second
        // implementation of those rules would eventually disagree with the first.
        $parsed = $this->parser->parse($transcribed);

        if (! $parsed['ok']) {
            return [
                'ok' => false,
                'error' => 'Read from the image, but it does not hold together as a signal: '.$parsed['error'],
                'text' => $transcribed,
                'parsed' => null,
            ];
        }

        return ['ok' => true, 'error' => null, 'text' => $transcribed, 'parsed' => $parsed];
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
        You transcribe trading signals from images. You are a reader, not an analyst.

        Write out the text that is legibly printed or written on the image - instrument,
        direction, entry, stop loss, take profits - as plain lines. Copy what is there.

        Refuse, by setting readable to false, whenever:

        - Any digit of any price is unclear, cropped, blurred, or overlapping something.
          A misread digit turns 2650 into 2050, which is a well-formed price and a
          completely different trade. "Probably a 6" is a refusal.
        - The image is a chart with lines drawn on it but no written levels. Reading a
          price off an axis is estimating, not transcribing.
        - There is no stop loss written anywhere. A copied trade with an invented stop is
          the worst thing this system can produce.
        - The direction is absent, or both buy and sell appear without one clearly being
          the instruction.
        - It is a screenshot of results, a profit report, an advertisement, or a chart
          posted for discussion rather than an instruction to trade.

        Refusing costs nothing: the message is still recorded and a person can look at it.
        Being confidently wrong costs a real position at a price nobody published.

        Transcribe only. Do not compute a missing level, do not convert, do not tidy up an
        inconsistency, and do not infer an instrument from the chart's appearance.
        TXT;
    }

    private function brief(string $caption): string
    {
        $caption = trim($caption);

        return $caption === ''
            ? 'Transcribe the signal in this image, or refuse.'
            : "Transcribe the signal in this image, or refuse.\n\nThe message's own caption, for context only - do not transcribe from it:\n".mb_substr($caption, 0, 500);
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'readable' => [
                    'type' => 'boolean',
                    'description' => 'True only if every price is legible and a stop loss is written. Any doubt about any digit is false.',
                ],
                'transcription' => [
                    'type' => ['string', 'null'],
                    'description' => 'The signal as plain lines, copied from the image. Null when not readable.',
                ],
                'reason' => [
                    'type' => ['string', 'null'],
                    'description' => 'When not readable, one sentence on what was missing or unclear.',
                ],
            ],
            'required' => ['readable', 'transcription', 'reason'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array{ok: false, error: string, text: null, parsed: null}
     */
    private function refuse(string $reason): array
    {
        return ['ok' => false, 'error' => $reason, 'text' => null, 'parsed' => null];
    }
}
