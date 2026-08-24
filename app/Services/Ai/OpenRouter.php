<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpenRouter
 *
 * One place that knows how to ask a model for a JSON object matching a schema.
 *
 * Both callers - the analysis card and the strategy proposer - need exactly this and
 * nothing else: a system prompt, a brief, a schema, and a validated array back. Sharing
 * it means the retry behaviour, the timeout, the attribution headers and the "what if the
 * model returns prose instead of JSON" handling exist once.
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
    public function structured(string $model, string $system, string $brief, string $schemaName, array $schema): array
    {
        if (! $this->configured()) {
            return $this->failure('No OPENROUTER_API_KEY is configured.');
        }

        try {
            $response = Http::withToken((string) config('ai.key'))
                ->withHeaders([
                    // OpenRouter attributes usage to these, which is how this dashboard's
                    // spend stays distinguishable from anything else on the same key.
                    'HTTP-Referer' => (string) config('ai.referer'),
                    'X-Title' => (string) config('ai.title'),
                ])
                ->timeout((int) config('ai.timeout'))
                ->acceptJson()
                ->post(rtrim((string) config('ai.base_url'), '/').'/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $brief],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => $schemaName,
                            'strict' => true,
                            'schema' => $schema,
                        ],
                    ],
                ]);
        } catch (Throwable $e) {
            return $this->failure('Request to OpenRouter failed: '.$e->getMessage());
        }

        if ($response->status() === 401) {
            return $this->failure('OpenRouter rejected the API key.');
        }

        if ($response->status() === 402) {
            // Named separately because "out of credit" and "the model is broken" send you
            // to completely different places.
            return $this->failure('OpenRouter reports insufficient credit for this request.');
        }

        if (! $response->successful()) {
            $detail = (string) ($response->json('error.message') ?? $response->body());

            return $this->failure("OpenRouter returned HTTP {$response->status()}: ".mb_substr($detail, 0, 200));
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            return $this->failure('The model returned an empty response.');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            // Some models ignore the schema and answer in prose. Say that rather than
            // showing a JSON parse error, which reads like the dashboard is broken.
            return $this->failure('The model did not return JSON matching the requested shape.');
        }

        return [
            'ok' => true,
            'data' => $decoded,
            'error' => null,
            // The model that actually served it: OpenRouter may route elsewhere when the
            // requested one is unavailable, and a surprising answer is worth being able
            // to attribute.
            'model' => $response->json('model') ?? $model,
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
