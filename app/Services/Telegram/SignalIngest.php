<?php

namespace App\Services\Telegram;

use App\Models\TelegramChannel;
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
 * Turns an inbound Telegram message into a stored, parsed signal - whichever collector
 * brought it.
 *
 * ## One recording path, two collectors
 *
 * There are two ways to see a Telegram message and they have almost nothing in common.
 * The Bot API shows chats a bot was added to, which is fine for your own alerts and
 * useless for somebody else's channel: providers do not add your bot, and no setting
 * changes that. Reading their channel means MTProto, logged in as a real user account.
 *
 * `record()` is the seam between the two. `poll()` above it drives the Bot API; the
 * collector endpoint drives the account side. Everything downstream - parsing, review,
 * execution, the P&L attributed back to the channel - sees one shape and cannot tell
 * which collector a message arrived through.
 *
 * That is also why MTProto is not implemented in this application. The session file it
 * produces is a full account credential - it can read every chat the account has and post
 * as them - so putting one on the web server would make a dashboard compromise into a
 * Telegram account takeover. The collector runs wherever the operator chooses and posts
 * in over the same bearer-token API the terminal uses; see tools/telegram-collector/.
 *
 * ## The allow-list is the security boundary
 *
 * Nothing trades because it arrived. A chat is either enabled or it is not, and messages
 * from anywhere else are stored - dropping them silently would hide the fact that
 * something is talking to us - but marked so they can never be parsed, reviewed or
 * executed.
 *
 * ## The offset must survive a restart
 *
 * `getUpdates` confirms as it reads: once called with an offset, everything before it is
 * discarded server-side and cannot be fetched again. A lost offset therefore means lost
 * messages, not repeated ones - so it is persisted rather than held in memory.
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

            $result = $this->record([
                'source' => TelegramChannel::SOURCE_BOT,
                'external_id' => "bot:{$updateId}",
                'chat_id' => (string) ($message['chat']['id'] ?? ''),
                'chat_title' => $message['chat']['title']
                    ?? (trim(($message['chat']['first_name'] ?? '').' '.($message['chat']['last_name'] ?? '')) ?: null),
                'username' => $message['chat']['username'] ?? null,
                'text' => $text,
                'posted_at' => isset($message['date'])
                    ? Carbon::createFromTimestamp((int) $message['date'])
                    : null,
            ]);

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
     * Store one message, and parse it if its chat is allowed to trade.
     *
     * Idempotent on `external_id`, which is what stands between a collector retry and two
     * positions. A re-delivered message updates its row rather than creating a second one,
     * and the execution columns are never written here - so replaying something that has
     * already traded cannot make it trade again.
     *
     * @param  array{source: string, external_id: string, chat_id: string, chat_title?: ?string, username?: ?string, text: string, posted_at?: ?Carbon}  $message
     * @return array{ignored: bool, parsed: bool, signal: TelegramSignal}
     */
    public function record(array $message): array
    {
        $operator = (int) User::orderBy('id')->value('id');

        $channel = $message['chat_id'] === ''
            ? null
            : TelegramChannel::register(
                $message['source'],
                $message['chat_id'],
                $message['chat_title'] ?? null,
                $message['username'] ?? null,
                $operator,
            );

        $user = $this->traderFor($channel, $message['chat_id']);

        $attributes = [
            'source' => $message['source'],
            'chat_id' => $message['chat_id'],
            'telegram_channel_id' => $channel?->id,
            'chat_title' => $message['chat_title'] ?? null,
            'raw_text' => mb_substr($message['text'], 0, 4000),
            'posted_at' => $message['posted_at'] ?? null,
        ];

        // Not enabled. Recorded so what is arriving stays visible, but it can never be
        // parsed, reviewed or executed - the parse stage is where trading starts, and this
        // never reaches it.
        if ($user === null) {
            $signal = TelegramSignal::updateOrCreate(
                ['external_id' => $message['external_id']],
                $attributes + [
                    // The table requires a user; attribute chats that may not trade to
                    // nobody tradeable by pointing at the operator while refusing to parse.
                    'user_id' => $operator,
                    'parse_status' => TelegramSignal::PARSE_FAILED,
                    'parse_error' => 'Channel is not enabled as a signal source.',
                    'review_status' => TelegramSignal::REVIEW_SKIPPED,
                ],
            );

            return ['ignored' => true, 'parsed' => false, 'signal' => $signal];
        }

        $parsed = $this->parser->parse($message['text']);

        $signal = TelegramSignal::updateOrCreate(
            ['external_id' => $message['external_id']],
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

        return ['ignored' => false, 'parsed' => $parsed['ok'], 'signal' => $signal];
    }

    /**
     * The account a chat may trade for, or null if it may not trade at all.
     *
     * Three ways to be allowed, checked in the order they were added to this project so a
     * deployment predating the channels table keeps behaving exactly as it did: the
     * channel row's own switch, the config allow-list, and the operator's own alert chat.
     *
     * The last two write the switch back onto the row, because a page showing a channel as
     * disabled while it trades would be worse than having no page at all.
     */
    private function traderFor(?TelegramChannel $channel, string $chatId): ?User
    {
        if ($channel !== null && $channel->is_enabled) {
            return $channel->user;
        }

        if ($chatId === '') {
            return null;
        }

        $configured = (array) config('telegram.sources', []);

        if (isset($configured[$chatId])) {
            $user = User::where('email', $configured[$chatId])->first();
        } elseif ((string) config('alerts.telegram.chat_id') === $chatId) {
            // The one chat already known to belong to the operator. Anything else has to
            // be opted into deliberately.
            $user = User::orderBy('id')->first();
        } else {
            return null;
        }

        if ($user !== null && $channel !== null) {
            $channel->forceFill(['is_enabled' => true, 'user_id' => $user->id])->save();
        }

        return $user;
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
