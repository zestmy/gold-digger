<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpenRouter
 *
 * One place that knows how to ask a model for a JSON object matching a schema.
 *
 * Every caller - the analysis cards, the copier's reviewer, the interpreters, the strategy
 * proposer - needs exactly this and nothing else: a system prompt, a brief, a schema, and a
 * validated array back. Sharing it means the retry behaviour, the timeout, the attribution
 * headers and the "what if the model returns prose instead of JSON" handling exist once.
 *
 * ## What is retried, and what deliberately is not
 *
 * Connection failures only - a refused socket, a DNS blip, a read that times out before
 * anything came back. One retry, after two seconds.
 *
 * HTTP status codes are not retried, and that is the deliberate half. This endpoint bills
 * per request that reaches it, so a 500 that arrived after the model had already generated
 * is a charge; retrying it buys a second charge for the same answer. A 402 means the credit
 * is gone and will still be gone in two seconds. A 429 wants a longer wait than anything
 * worth blocking a request on. All three are reported instead - the interactive surfaces
 * have a refresh button, and the scheduled ones run again on their own timer.
 *
 * ## On schemas
 *
 * `response_format: json_schema` with `strict` is the whole reason this is usable. Without
 * it the model returns a paragraph and the caller parses English, which fails in ways that
 * look like the model being unhelpful rather than like a bug. With it, either the response
 * matches the shape or it is an error - and an error is something the UI can say plainly.
 */
final class OpenRouter
{
    public function configured(): bool
    {
        $key = config('ai.key');

        return is_string($key) && $key !== '';
    }

    /**
     * Ask for a JSON object matching $schema.
     *
     * @param  array<string, mixed>  $schema  A JSON Schema object
     * @return array{ok: bool, data: array<string, mixed>|null, error: string|null, model: string|null}
     */
    /**
     * @param  string|null  $imageDataUri  A data: URI, when the brief is about a picture
     */
    public function structured(string $model, string $system, string $brief, string $schemaName, array $schema, ?string $imageDataUri = null, string $callSite = 'unattributed'): array
    {
        if (! $this->configured()) {
            return $this->failure('No OPENROUTER_API_KEY is configured.');
        }

        // Every call in the application funnels through here, which is the only reason
        // metering can be one piece of code rather than nine. Resolved before the request
        // rather than passed in, so a caller cannot forget to declare whose spend it is.
        $spend = app(AiSpend::class);
        $tenant = $spend->currentTenant();

        if (! $spend->permits($tenant)) {
            $allowance = $spend->allowance($tenant);

            // Not recorded as usage: nothing was sent, so nothing was charged. Counting
            // refusals would make the allowance shrink by being enforced.
            return $this->failure(
                "Daily AI allowance used up ({$allowance['used']} of {$allowance['limit']} calls). It resets at midnight UTC."
            );
        }

        // Sent inline as a data URI rather than as a link. A URL would have to be publicly
        // reachable for the model to fetch it, which means publishing somebody's chart to
        // the open internet to read it - a needless disclosure for a picture that only has
        // to travel one hop.
        $userContent = $imageDataUri === null ? $brief : [
            ['type' => 'text', 'text' => $brief],
            ['type' => 'image_url', 'image_url' => ['url' => $imageDataUri]],
        ];

        try {
            $response = Http::withToken((string) config('ai.key'))
                ->withHeaders([
                    // OpenRouter attributes usage to these, which is how this dashboard's
                    // spend stays distinguishable from anything else on the same key.
                    'HTTP-Referer' => (string) config('ai.referer'),
                    'X-Title' => (string) config('ai.title'),
                ])
                ->timeout((int) config('ai.timeout'))
                // Connection failures only - see the note above on why a status code is
                // reported rather than retried. `throw: false` keeps the 401/402/5xx
                // handling below reachable instead of routing it through the catch.
                ->retry(2, 2000, when: fn (Throwable $e) => $e instanceof ConnectionException, throw: false)
                ->acceptJson()
                ->post(rtrim((string) config('ai.base_url'), '/').'/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $userContent],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => $schemaName,
                            'strict' => true,
                            'schema' => $schema,
                        ],
                    ],
                    // Ask the gateway to price the call in its own response. Gateways that
                    // do not understand this field ignore it, and the cost column stays
                    // null while the token counts still land - which is why nothing here
                    // depends on it being honoured.
                    'usage' => ['include' => true],
                ]);
        } catch (Throwable $e) {
            // A connection that never completed was never billed, so this is a failure to
            // report rather than usage to record.
            return $this->failure('Request to OpenRouter failed: '.$e->getMessage());
        }

        // Neither of these two reached a model, so neither was billed. Recording them
        // would make a wrong API key look like consumption and eat an allowance nothing
        // was spent from.
        if ($response->status() === 401) {
            return $this->failure('OpenRouter rejected the API key.');
        }

        if ($response->status() === 402) {
            // Named separately because "out of credit" and "the model is broken" send you
            // to completely different places.
            return $this->failure('OpenRouter reports insufficient credit for this request.');
        }

        // Everything from here down may have been generated, and therefore charged - see
        // the note at the top of this class on why status codes are not retried.
        $usage = (array) ($response->json('usage') ?? []);
        $served = $response->json('model') ?? $model;

        $meter = function (bool $ok, ?string $failure) use ($spend, $tenant, $callSite, $model, $served, $usage): void {
            $spend->record($tenant, $callSite, $model, $served, $ok, $failure, $usage);
        };

        if (! $response->successful()) {
            $detail = (string) ($response->json('error.message') ?? $response->body());
            $message = "OpenRouter returned HTTP {$response->status()}: ".mb_substr($detail, 0, 200);

            $meter(false, $message);

            return $this->failure($message);
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            $meter(false, 'empty response');

            return $this->failure('The model returned an empty response.');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            $meter(false, 'response was not JSON');

            // Some models ignore the schema and answer in prose. Say that rather than
            // showing a JSON parse error, which reads like the dashboard is broken.
            return $this->failure('The model did not return JSON matching the requested shape.');
        }

        $meter(true, null);

        return [
            'ok' => true,
            'data' => $decoded,
            'error' => null,
            // The model that actually served it: OpenRouter may route elsewhere when the
            // requested one is unavailable, and a surprising answer is worth being able
            // to attribute.
            'model' => $served,
        ];
    }

    /**
     * @return array{ok: false, data: null, error: string, model: null}
     */
    private function failure(string $message): array
    {
        Log::info("[openrouter] {$message}");

        return ['ok' => false, 'data' => null, 'error' => $message, 'model' => null];
    }
}
