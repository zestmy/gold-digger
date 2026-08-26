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
 * Holding that on the web server means a dashboard compromise is a Telegram account
 * takeover. That is the reason the collector is a separate program, running wherever the
 * operator chooses, holding the session there and posting messages in over the same
 * bearer-token API the terminal uses.
 *
 * ## And why a hosted mode exists anyway
 *
 * Because the above asks a new customer for Python, a file on disk and an application
 * registered at my.telegram.org before the product has done anything for them, and a
 * signup funnel does not survive that. So a tenant may instead let the platform sign them
 * in and keep the session - see `config/telegram.php` and `tools/telegram-worker/`.
 *
 * The risk is not argued away by being chosen: on a hosted account a dashboard compromise
 * IS a Telegram account takeover, and the sessions are encrypted at rest and kept off
 * every response a browser can reach because that is the only mitigation available. Both
 * modes feed this same controller, so the ingest path stays single.
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

        // Scoped to the caller. Unscoped, `known` below was every channel in the
        // database, so one tenant's collector could read another's channel titles - a
        // leak that was invisible while there was one tenant and silent once there were
        // two.
        $channels = TelegramChannel::where('source', TelegramChannel::SOURCE_ACCOUNT)
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'watch' => $channels->where('is_enabled', true)
                ->pluck('chat_id')
                ->values(),
            'known' => $channels->reject(fn (TelegramChannel $c) => $c->resolve_state !== null)
                ->map(fn (TelegramChannel $c) => [
                    'chat_id' => $c->chat_id,
                    'title' => $c->title,
                    'enabled' => $c->is_enabled,
                ])->values(),

            // Private chats this tenant has named but nothing has looked up yet. Only a
            // signed-in client can turn @someone into a chat id, so the dashboard asks
            // rather than guessing.
            'resolve' => $channels->where('resolve_state', TelegramChannel::RESOLVE_PENDING)
                ->pluck('username')
                ->values(),
        ]);
    }

    /**
     * A collector reporting what `@someone` turned out to be.
     *
     * Resolution is the only way a private chat gets a real id, and it is deliberately
     * one-way: this fills in what was missing and never enables anything. Naming a chat
     * and trading it stay two separate acts, exactly as for a channel.
     */
    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'chat_id' => ['nullable', 'string', 'max:64'],
            'title' => ['nullable', 'string', 'max:255'],
            'error' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $this->user($request);

        $row = TelegramChannel::where('user_id', $user->id)
            ->where('username', ltrim($data['username'], '@'))
            ->awaitingResolution()
            ->first();

        if ($row === null) {
            return response()->json(['message' => 'No pending request for that username.'], 404);
        }

        if (($data['chat_id'] ?? '') === '') {
            $row->update([
                'resolve_state' => TelegramChannel::RESOLVE_FAILED,
                'resolve_error' => $data['error'] ?? 'Telegram did not recognise that username.',
            ]);

            return response()->json(['ok' => true, 'resolved' => false]);
        }

        // Another row for the same chat can already exist - the same account might also be
        // reachable as a bot this tenant announced. Keep the one that is already real and
        // drop the request rather than colliding on the key.
        $existing = TelegramChannel::where('user_id', $user->id)
            ->where('source', TelegramChannel::SOURCE_ACCOUNT)
            ->where('chat_id', $data['chat_id'])
            ->whereKeyNot($row->getKey())
            ->first();

        if ($existing !== null) {
            $row->delete();

            return response()->json(['ok' => true, 'resolved' => true, 'merged' => true]);
        }

        $row->update([
            'chat_id' => $data['chat_id'],
            'title' => $data['title'] ?: $row->title,
            'resolve_state' => null,
            'resolve_error' => null,
        ]);

        return response()->json(['ok' => true, 'resolved' => true]);
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
            // Absent from an older collector, which is why it is nullable rather than
            // required: a deploy should not stop a running one from announcing.
            'channels.*.kind' => ['nullable', 'string', 'in:channel,group,bot'],
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
                $channel['kind'] ?? null,
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

        // Whose messages these are, from the credential rather than the body. The same
        // identity the watch list is scoped by, so what a collector may write and what it
        // may read agree.
        $user = $this->user($request);

        $stored = 0;
        $parsed = 0;
        $ignored = 0;

        foreach ($data['messages'] as $message) {
            $result = $ingest->record(ownerId: $user->id, message: [
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
        return $this->account($request)?->user
            ?? $request->attributes->get('bot_user');
    }

    /**
     * Which account is speaking.
     *
     * Two ways in, and in both of them the identity comes from the credential rather than
     * from anything in the body. A self-hosted collector is named by the token it
     * authenticated with; a hosted account is named by the route, which only the worker
     * token can reach and which `BindWorkerAccount` has already checked is hosted.
     *
     * What matters either way is that a collector cannot claim to be an account it was
     * not issued a token for, which is what keeps two of them from writing over each
     * other's channel list.
     */
    private function account(Request $request): ?TelegramAccount
    {
        $bound = $request->attributes->get('telegram_account');

        if ($bound instanceof TelegramAccount) {
            return $bound;
        }

        $token = $request->attributes->get('bot_token');

        return $token === null
            ? null
            : TelegramAccount::where('bot_token_id', $token->id)->first();
    }
}
