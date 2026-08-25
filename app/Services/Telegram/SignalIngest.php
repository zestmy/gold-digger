<?php

namespace App\Services\Telegram;

use App\Models\TelegramSignal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Signal Ingest
 *
 * Pulls messages off the Telegram bot and stores them.
 *
 * ## The allow-list is the security boundary
 *
 * A Telegram bot is publicly reachable. Anyone who finds `@YourBot` can message it, and a
 * copier that ingested and traded whatever arrived would be a remote trade-execution
 * endpoint on a live account, reachable by strangers, authenticated by nothing.
 *
 * So a chat is either allow-listed or it is not. Messages from anywhere else are stored -
 * silently dropping them would hide the fact that someone is talking to the bot - but they
 * are marked as ignored and can never be parsed, reviewed or executed.
 *
 * The operator's own alert chat is allow-listed by default, because that is the one chat
 * we already know belongs to them. Anything else is opt-in via config.
 *
 * ## The offset must survive a restart
 *
 * `getUpdates` confirms as it reads: once called with an offset, everything before it is
 * discarded server-side and cannot be fetched again. A lost offset therefore means lost
 * messages, not repeated ones - so it is persisted rather than held in memory.
 *
 * ## What this deliberately does not do
 *
 * The Bot API can only see chats the bot has been added to. Reading a third-party channel
 * needs an MTProto client authenticated as a user account, which is a different program
 * with a different login. Everything downstream of this class is source-agnostic so such
 * a feeder can write into the same table without changing anything else.
 */
final class SignalIngest
{
    private const OFFSET_KEY = 'telegram.ingest.offset';

    private const TIMEOUT_SECONDS = 20;

    public function __construct(private readonly SignalParser $parser = new SignalParser) {}

    public function configured(): bool
    {
        $token = config('alerts.telegram.token');

        return is_string($token) && $token !== '';
    }

    /**
     * @return array{ok: bool, stored: int, ignored: int, parsed: int, error: string|null}
     */
    public function poll(): array
    {
        if (! $this->configured()) {
            return $this->failure('No TELEGRAM_BOT_TOKEN is configured.');
        }

        $offset = (int) Cache::get(self::OFFSET_KEY, 0);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->get($this->url('getUpdates'), array_filter([
                    'offset' => $offset > 0 ? $offset : null,
                    'limit' => 100,
                    // Only what could carry a signal. Ignoring the rest server-side keeps
                    // the confirmation semantics simple.
                    'allowed_updates' => json_encode(['message', 'channel_post']),
                ]));
        } catch (Throwable $e) {
            return $this->failure('Telegram fetch failed: '.$e->getMessage());
        }

        if (! $response->successful()) {
            return $this->failure("Telegram returned HTTP {$response->status()}.");
        }

        $updates = $response->json('result');

        if (! is_array($updates)) {
            return $this->failure('Telegram returned an unexpected payload.');
        }

        $stored = 0;
        $ignored = 0;
        $parsed = 0;
        $highest = $offset > 0 ? $offset - 1 : 0;

        foreach ($updates as $update) {
            $updateId = (int) ($update['update_id'] ?? 0);
            $highest = max($highest, $updateId);

            $message = $update['message'] ?? $update['channel_post'] ?? null;
            $text = $message['text'] ?? $message['caption'] ?? null;

            if (! is_array($message) || ! is_string($text) || trim($text) === '') {
                continue;
            }

            $result = $this->store($updateId, $message, $text);

            $stored++;
            $ignored += $result['ignored'] ? 1 : 0;
            $parsed += $result['parsed'] ? 1 : 0;
        }

        // Advance only after the batch is stored. Confirming first and failing during the
        // write would discard messages server-side that were never recorded here.
        if ($highest > 0) {
            Cache::forever(self::OFFSET_KEY, $highest + 1);
        }

        return ['ok' => true, 'stored' => $stored, 'ignored' => $ignored, 'parsed' => $parsed, 'error' => null];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{ignored: bool, parsed: bool}
     */
    private function store(int $updateId, array $message, string $text): array
    {
        $chatId = (string) ($message['chat']['id'] ?? '');
        $user = $this->userForChat($chatId);

        $attributes = [
            'source' => 'bot_api',
            'chat_id' => $chatId,
            'chat_title' => $message['chat']['title']
                ?? trim(($message['chat']['first_name'] ?? '').' '.($message['chat']['last_name'] ?? ''))
                ?: null,
            'raw_text' => mb_substr($text, 0, 4000),
            'posted_at' => isset($message['date']) ? Carbon::createFromTimestamp((int) $message['date']) : null,
        ];

        // Not allow-listed. Recorded so somebody talking to the bot is visible, but it can
        // never be parsed, reviewed or executed - the parse stage is where trading starts,
        // and this never reaches it.
        if ($user === null) {
            TelegramSignal::updateOrCreate(
                ['external_id' => "bot:{$updateId}"],
                $attributes + [
                    // The table requires a user; attribute unknown chats to nobody tradeable
                    // by pointing at the operator while refusing to parse.
                    'user_id' => User::orderBy('id')->value('id'),
                    'parse_status' => TelegramSignal::PARSE_FAILED,
                    'parse_error' => 'Chat is not allow-listed as a signal source.',
                    'review_status' => TelegramSignal::REVIEW_SKIPPED,
                ],
            );

            return ['ignored' => true, 'parsed' => false];
        }

        $parsed = $this->parser->parse($text);

        TelegramSignal::updateOrCreate(
            ['external_id' => "bot:{$updateId}"],
            $attributes + [
                'user_id' => $user->id,
                'parse_status' => $parsed['ok'] ? TelegramSignal::PARSE_OK : TelegramSignal::PARSE_FAILED,
                'parse_error' => $parsed['error'],
                'symbol' => $parsed['symbol'],
                'direction' => $parsed['direction'],
                'entry_price' => $parsed['entry_price'],
                'entry_zone_high' => $parsed['entry_zone_high'],
                'sl_price' => $parsed['sl_price'],
                'tp_prices' => $parsed['tp_prices'] ?: null,
                // A message that never parsed is never reviewed: there is nothing to review.
                'review_status' => $parsed['ok']
                    ? TelegramSignal::REVIEW_PENDING
                    : TelegramSignal::REVIEW_SKIPPED,
            ],
        );

        return ['ignored' => false, 'parsed' => $parsed['ok']];
    }

    /**
     * The account a chat may trade for, or null if it may not trade at all.
     */
    private function userForChat(string $chatId): ?User
    {
        if ($chatId === '') {
            return null;
        }

        // Explicit config first: chat id => user email.
        $configured = (array) config('telegram.sources', []);

        if (isset($configured[$chatId])) {
            return User::where('email', $configured[$chatId])->first();
        }

        // Otherwise the operator's own alert chat, which is the one chat already known to
        // belong to them. Anything else has to be opted in deliberately.
        if ((string) config('alerts.telegram.chat_id') === $chatId) {
            return User::orderBy('id')->first();
        }

        return null;
    }

    private function url(string $method): string
    {
        return 'https://api.telegram.org/bot'.config('alerts.telegram.token').'/'.$method;
    }

    /**
     * @return array{ok: false, stored: int, ignored: int, parsed: int, error: string}
     */
    private function failure(string $message): array
    {
        Log::info("[telegram-ingest] {$message}");

        return ['ok' => false, 'stored' => 0, 'ignored' => 0, 'parsed' => 0, 'error' => $message];
    }
}
