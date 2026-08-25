<?php

namespace App\Services\Telegram;

use App\Models\TelegramChannel;
use App\Models\TelegramSignal;
use App\Models\User;
use App\Services\Monitoring\AlertNotifier;
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
                    // Edits included: a provider correcting an entry is not a new message
                    // and arrives only on these update types.
                    'allowed_updates' => json_encode([
                        'message', 'channel_post', 'edited_message', 'edited_channel_post',
                    ]),
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

            $message = $update['message']
                ?? $update['channel_post']
                ?? $update['edited_message']
                ?? $update['edited_channel_post']
                ?? null;
            $text = $message['text'] ?? $message['caption'] ?? null;

            if (! is_array($message) || ! is_string($text) || trim($text) === '') {
                continue;
            }

            $result = $this->record([
                'source' => TelegramChannel::SOURCE_BOT,
                // Keyed on the message rather than the update, because an edit arrives as
                // a new update id for a message we already hold. Keying on the update
                // would turn every correction into a second signal.
                'external_id' => isset($message['message_id'])
                    ? "bot:{$message['chat']['id']}:{$message['message_id']}"
                    : "bot:{$updateId}",
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

        // Already seen? Then this is either a retry or an edit, and they need different
        // answers - see editOf().
        $existing = TelegramSignal::where('external_id', $message['external_id'])->first();

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

        // Reading a screenshot costs a model call and can be confidently wrong, so a
        // channel that posts charts as commentary can have it switched off.
        if ($channel !== null && $channel->read_images === false) {
            unset($message['image']);
        }

        if ($existing !== null) {
            return $this->editOf($existing, $message, $channel);
        }

        $replyTo = $message['reply_to_message_id'] ?? null;
        $parent = $this->parentFor($message['source'], $message['chat_id'], $replyTo);

        $attributes = [
            'source' => $message['source'],
            'chat_id' => $message['chat_id'],
            'telegram_channel_id' => $channel?->id,
            'chat_title' => $message['chat_title'] ?? null,
            'raw_text' => mb_substr($message['text'], 0, 4000),
            'posted_at' => $message['posted_at'] ?? null,
            'reply_to_message_id' => $replyTo,
            'parent_signal_id' => $parent?->id,
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

        // A reply to a signal we captured is an instruction about that position, not a
        // new trade. Sending it to the signal parser produces what it produced before this
        // existed - an unparsed row - while the provider has told everyone to take half
        // off. Interpretation happens later, with the parent's numbers in hand.
        if ($parent !== null) {
            $signal = TelegramSignal::updateOrCreate(
                ['external_id' => $message['external_id']],
                $attributes + [
                    'user_id' => $user->id,
                    'kind' => TelegramSignal::KIND_FOLLOW_UP,
                    // It parsed in the sense that matters: we know what it is and what it
                    // is about. Whether it says anything actionable is the interpreter's
                    // question, not the parser's.
                    'parse_status' => TelegramSignal::PARSE_OK,
                    'review_status' => TelegramSignal::REVIEW_SKIPPED,
                ],
            );

            return ['ignored' => false, 'parsed' => true, 'signal' => $signal];
        }

        $reading = $this->readFor($message);

        $parsed = $reading['parsed'];

        $signal = TelegramSignal::updateOrCreate(
            ['external_id' => $message['external_id']],
            $attributes + [
                'user_id' => $user->id,
                'from_image' => $reading['from_image'],
                'transcribed_text' => $reading['transcription'],
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
     * The captured signal a reply is about, if there is one.
     *
     * Message ids are only unique within a chat, so the parent is looked up by the same
     * composite identity the message itself carries. A reply to something posted before
     * the collector was running finds nothing, and is recorded as a follow-up with no
     * parent - visible, and unactionable, which is the honest outcome.
     */
    private function parentFor(string $source, string $chatId, ?string $replyToId): ?TelegramSignal
    {
        if ($replyToId === null || $replyToId === '' || $chatId === '') {
            return null;
        }

        return TelegramSignal::where('source', $source)
            ->where('chat_id', $chatId)
            ->where('external_id', "tg:{$chatId}:{$replyToId}")
            ->first();
    }

    /**
     * Parse the message, falling back to the picture it carried.
     *
     * Text first, always. A caption that parses is a signal the provider wrote out, and
     * reading digits off an image when somebody has already typed them is strictly worse:
     * transcription can be confidently wrong in a way text cannot.
     *
     * The image is only consulted when the text does not yield a signal - which is the case
     * that was previously recorded as unparsed and left there.
     *
     * @param  array<string, mixed>  $message
     * @return array{parsed: array<string, mixed>, from_image: bool, transcription: string|null}
     */
    private function readFor(array $message): array
    {
        $parsed = $this->parser->parse($message['text']);

        $image = $message['image'] ?? null;

        if ($parsed['ok'] || ! is_string($image) || $image === '') {
            return ['parsed' => $parsed, 'from_image' => false, 'transcription' => null];
        }

        $read = app(ImageSignalReader::class)->read(
            $image,
            (string) ($message['image_mime'] ?? 'image/jpeg'),
            $message['text'],
        );

        if (! $read['ok']) {
            // The refusal replaces the text parser's complaint, because "no instrument
            // found" is a misleading thing to say about a message that was a picture.
            return [
                'parsed' => ['ok' => false, 'error' => $read['error']] + $parsed,
                'from_image' => true,
                'transcription' => $read['text'],
            ];
        }

        return ['parsed' => $read['parsed'], 'from_image' => true, 'transcription' => $read['text']];
    }

    /**
     * A message we already hold, arriving again.
     *
     * Identical content is a retry and changes nothing. Different content is the provider
     * editing themselves, and what to do about that depends entirely on whether the signal
     * has been acted on:
     *
     * Untouched - the corrected message is simply the message. Re-parsed and sent back for
     * review as if it had arrived this way, because it effectively did.
     *
     * Already queued or filled - the levels that matter are the ones the order carries and
     * they cannot be un-sent. The original text is preserved so the record still shows what
     * was acted on, the parsed columns are left alone so the analytics keep grading the
     * trade against the levels it was actually taken at, and the new text is stored beside
     * them. Silently rewriting those columns would not have caused a bad trade; it would
     * have caused a bad measurement, which is harder to notice and lasts longer.
     *
     * @param  array<string, mixed>  $message
     * @return array{ignored: bool, parsed: bool, signal: TelegramSignal, edited?: bool}
     */
    private function editOf(TelegramSignal $signal, array $message, ?TelegramChannel $channel): array
    {
        $text = mb_substr($message['text'], 0, 4000);

        if ($text === $signal->raw_text) {
            // A retry. The whole point of external_id being unique.
            return ['ignored' => false, 'parsed' => $signal->parse_status === TelegramSignal::PARSE_OK, 'signal' => $signal];
        }

        $acted = $signal->execution_status !== TelegramSignal::EXEC_NONE;

        $attributes = [
            'raw_text' => $text,
            'original_text' => $signal->original_text ?? $signal->raw_text,
            'edited_at' => now(),
            'edit_count' => $signal->edit_count + 1,
        ];

        if ($acted || $signal->isFollowUp()) {
            $signal->update($attributes);

            if ($acted) {
                // Loud on purpose. A provider correcting a signal you are already in is a
                // thing to look at, and it is the one case here that nothing downstream
                // can act on by itself: the order has gone.
                app(AlertNotifier::class)->announce(
                    sprintf('Provider edited a signal already acted on (%s)', $signal->symbol ?? 'unknown'),
                    implode('
', [
                        'Was: '.mb_substr((string) $signal->original_text, 0, 300),
                        '',
                        'Now: '.mb_substr($text, 0, 300),
                        '',
                        'The order carries the original levels and they cannot be un-sent. Check the position.',
                    ]),
                    '🟠',
                    ['telegram_signal_id' => $signal->id, 'edit_count' => $signal->edit_count + 1],
                );
            }

            return ['ignored' => false, 'parsed' => true, 'signal' => $signal, 'edited' => true];
        }

        $parsed = $this->parser->parse($text);

        $signal->update($attributes + [
            'parse_status' => $parsed['ok'] ? TelegramSignal::PARSE_OK : TelegramSignal::PARSE_FAILED,
            'parse_error' => $parsed['error'],
            'symbol' => $parsed['symbol'],
            'direction' => $parsed['direction'],
            'entry_price' => $parsed['entry_price'],
            'entry_zone_high' => $parsed['entry_zone_high'],
            'sl_price' => $parsed['sl_price'],
            'tp_prices' => $parsed['tp_prices'] ?: null,
            // Back to the start of the pipeline. An approval was of the old text.
            'review_status' => $parsed['ok'] ? TelegramSignal::REVIEW_PENDING : TelegramSignal::REVIEW_SKIPPED,
            'review_reasoning' => null,
            'review_confidence' => null,
            'reviewed_at' => null,
        ]);

        return ['ignored' => false, 'parsed' => $parsed['ok'], 'signal' => $signal, 'edited' => true];
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
