<?php

namespace App\Http\Controllers\Api\Telegram;

use App\Http\Controllers\Controller;
use App\Models\TelegramAccount;
use App\Models\TelegramChannel;
use App\Models\User;
use App\Services\Telegram\SignalIngest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The contract with an account-authenticated Telegram collector.
 *
 * ## Why the collector is not part of this application
 *
 * Reading somebody else's channel requires MTProto, logged in as a real Telegram account.
 * That login produces a session file which is a full account credential - it can read
 * every chat the account has and post as them - and it is created by an interactive flow
 * (phone number, code, second factor) that no web request can perform.
 *
 * Holding that on the web server would mean a dashboard compromise is a Telegram account
 * takeover, which is a far worse outcome than the one this feature is worth. So the
 * collector is a separate program, running wherever the operator chooses, holding the
 * session there and posting messages in over the same bearer-token API the terminal uses.
 * The dashboard never sees the account credential and cannot leak it.
 *
 * This mirrors how the Expert Advisor already works, deliberately: an outside process
 * that observes something this application cannot reach, authenticated by a revocable
 * token, feeding a source-agnostic pipeline.
 *
 * ## Registering a channel grants it nothing
 *
 * A collector reports every dialog the account can see so the operator can choose from a
 * real list rather than hunting for numeric ids. None of them arrive enabled. Joining a
 * channel to read it must not be the same gesture as trading it, so the switch lives in
 * the dashboard and is never flipped by anything arriving over this endpoint.
 */
class CollectorController extends Controller
{
    /** One post carries a batch; this bounds what a single request can cost. */
    private const MAX_MESSAGES = 200;

    /**
     * What to watch.
     *
     * The collector reads far more than it forwards - the account is in chats that have
     * nothing to do with trading - so it asks what is enabled and filters at its end. That
     * keeps unrelated private conversations from being posted to a web server at all,
     * which is a meaningfully better default than storing them and marking them ignored.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        // Polled every minute, which makes this the reliable liveness signal - announce
        // runs once at startup and a busy channel may post nothing for hours.
        $this->account($request)?->update(['last_seen_at' => now()]);

        $channels = TelegramChannel::where('source', TelegramChannel::SOURCE_ACCOUNT)
            ->orderBy('id')
            ->get();

        return response()->json([
            'watch' => $channels->where('is_enabled', true)
                ->where('user_id', $user->id)
                ->pluck('chat_id')
                ->values(),
            'known' => $channels->map(fn (TelegramChannel $c) => [
                'chat_id' => $c->chat_id,
                'title' => $c->title,
                'enabled' => $c->is_enabled,
            ])->values(),
        ]);
    }

    /**
     * Report the dialogs this account can see, so they can be chosen from a list.
     */
    public function announce(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channels' => ['required', 'array', 'max:500'],
            'channels.*.chat_id' => ['required', 'string', 'max:64'],
            'channels.*.title' => ['nullable', 'string', 'max:255'],
            'channels.*.username' => ['nullable', 'string', 'max:64'],
            'me' => ['nullable', 'array'],
            'me.username' => ['nullable', 'string', 'max:64'],
            'me.name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $this->user($request);
        $account = $this->account($request);

        // Whatever the collector knows about itself. Filled in here rather than typed by
        // hand, so the list can be checked against the accounts a person actually has.
        $account?->update(array_filter([
            'telegram_username' => $request->input('me.username'),
            'display_name' => $request->input('me.name'),
        ]) + ['last_seen_at' => now()]);

        $registered = 0;

        foreach ($data['channels'] as $channel) {
            // register() never widens permission: an existing row keeps its switch and its
            // owner, and only the descriptive fields are refreshed.
            $row = TelegramChannel::register(
                TelegramChannel::SOURCE_ACCOUNT,
                $channel['chat_id'],
                $channel['title'] ?? null,
                $channel['username'] ?? null,
                $user->id,
            );

            // Which account can see it. Only claimed when unset or already ours, so two
            // accounts that are both in a channel do not fight over the row.
            if ($account !== null && ($row->telegram_account_id === null || $row->telegram_account_id === $account->id)) {
                $row->forceFill(['telegram_account_id' => $account->id])->save();
            }

            $registered++;
        }

        return response()->json(['registered' => $registered]);
    }

    /**
     * Take a batch of messages.
     *
     * Idempotent on `external_id`: the collector may resend anything it is unsure landed,
     * and a resent message updates its row rather than becoming a second signal. That is
     * what lets the collector's own checkpoint be advanced only after a successful post
     * without risking a duplicate trade on a retry.
     */
    public function store(Request $request, SignalIngest $ingest): JsonResponse
    {
        $data = $request->validate([
            'messages' => ['required', 'array', 'max:'.self::MAX_MESSAGES],
            'messages.*.chat_id' => ['required', 'string', 'max:64'],
            'messages.*.message_id' => ['required', 'integer'],
            // A screenshot with no caption is the common case, so the text may be empty -
            // but one of text or image has to be present or there is nothing to record.
            // Nullable as well as present: Laravel's ConvertEmptyStringsToNull middleware
            // turns an absent caption into null before validation sees it, and a screenshot
            // with no caption is the common case rather than the odd one.
            'messages.*.text' => ['present', 'nullable', 'string'],
            'messages.*.chat_title' => ['nullable', 'string', 'max:255'],
            'messages.*.username' => ['nullable', 'string', 'max:64'],
            'messages.*.date' => ['nullable', 'integer'],
            'messages.*.reply_to_message_id' => ['nullable', 'integer'],
            // Base64, inline. A picture that only has to travel one hop should not be
            // published to a URL to be read.
            'messages.*.image' => ['nullable', 'string', 'max:8000000'],
            'messages.*.image_mime' => ['nullable', 'string', 'max:40'],
        ]);

        $stored = 0;
        $parsed = 0;
        $ignored = 0;

        foreach ($data['messages'] as $message) {
            $result = $ingest->record([
                'source' => TelegramChannel::SOURCE_ACCOUNT,
                // Chat plus message id, because message ids are only unique within a chat.
                'external_id' => "tg:{$message['chat_id']}:{$message['message_id']}",
                'chat_id' => (string) $message['chat_id'],
                'chat_title' => $message['chat_title'] ?? null,
                'username' => $message['username'] ?? null,
                'text' => (string) ($message['text'] ?? ''),
                'posted_at' => isset($message['date'])
                    ? Carbon::createFromTimestamp((int) $message['date'])
                    : null,
                // What makes a management instruction attributable to a position.
                'reply_to_message_id' => isset($message['reply_to_message_id'])
                    ? (string) $message['reply_to_message_id']
                    : null,
                'image' => isset($message['image'])
                    ? (base64_decode((string) $message['image'], true) ?: null)
                    : null,
                'image_mime' => $message['image_mime'] ?? 'image/jpeg',
            ]);

            $stored++;
            $parsed += $result['parsed'] ? 1 : 0;
            $ignored += $result['ignored'] ? 1 : 0;
        }

        return response()->json([
            'stored' => $stored,
            'parsed' => $parsed,
            'ignored' => $ignored,
        ]);
    }

    private function user(Request $request): User
    {
        return $request->attributes->get('bot_user');
    }

    /**
     * Which account this collector is, from the token it authenticated with.
     *
     * The token is the identity. A collector cannot claim to be an account it was not
     * issued a token for, which is what keeps two of them on one machine from writing over
     * each other's channel list.
     */
    private function account(Request $request): ?TelegramAccount
    {
        $token = $request->attributes->get('bot_token');

        return $token === null
            ? null
            : TelegramAccount::where('bot_token_id', $token->id)->first();
    }
}
