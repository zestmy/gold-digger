<?php

namespace App\Services\Monitoring;

use App\Models\Alert;
use App\Models\BotLog;
use App\Models\User;
use App\Notifications\TradingAlert;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Alert Notifier
 *
 * Delivers alerts to Telegram, and records every one on `/logs` whether or not a channel is
 * configured.
 *
 * ## Delivery failure is never allowed to fail the monitor
 *
 * The monitor's job is to notice things. If Telegram is down, or the token is wrong, that must
 * not stop the sweep from recording the incident or from resolving the ones that cleared -
 * otherwise a notification outage becomes a monitoring outage, and the second is much worse
 * than the first. Every send is wrapped, and a failure leaves `notified_at` null so the next
 * sweep tries again.
 *
 * ## Why an incident is logged even when nothing is configured
 *
 * Because the record is the point. A bot that went offline at 3am and came back at 6am should
 * be discoverable in the morning whether or not anyone was woken up.
 */
final class AlertNotifier
{
    /**
     * Is there any channel at all through which an alert could leave this server?
     *
     * The token is platform-wide; the destination is not. So this answers "is Telegram
     * usable", and `destinationFor()` answers "usable by whom" - which is the distinction
     * that was missing when every tenant's incidents arrived in one operator's chat.
     */
    public function configured(): bool
    {
        return filled(config('alerts.telegram.token'));
    }

    /**
     * Send an alert, marking it notified if the send succeeded.
     */
    public function send(Alert $alert): bool
    {
        $this->record($alert, resolution: false);

        $icon = $alert->level === 'critical' ? '🔴' : '🟠';

        $sent = $this->dispatch(
            $alert->user,
            $icon.' *'.$this->escape($alert->title).'*'."\n\n".$this->escape($alert->body),
            $alert->title,
            $alert->body,
        );

        if ($sent) {
            $alert->update([
                'notified_at' => now(),
                'notify_count' => $alert->notify_count + 1,
            ]);
        }

        return $sent;
    }

    /**
     * Tell whoever was alerted that the condition has cleared.
     *
     * Only for incidents that were actually announced. An alert nobody heard about does not
     * need an all-clear, and sending one is how a channel becomes noise.
     */
    public function sendResolution(Alert $alert): bool
    {
        $this->record($alert, resolution: true);

        if ($alert->notify_count === 0) {
            // Still mark it, so a later sweep does not keep reconsidering it.
            $alert->update(['resolution_notified' => true]);

            return false;
        }

        $lasted = $alert->first_seen_at?->diffForHumans($alert->resolved_at ?? now(), syntax: Carbon::DIFF_ABSOLUTE) ?? 'a while';

        $sent = $this->dispatch(
            $alert->user,
            '🟢 *'.$this->escape('Resolved: '.$alert->title).'*'."\n\n".$this->escape("Cleared after {$lasted}."),
            'Resolved: '.$alert->title,
            "Cleared after {$lasted}.",
        );

        if ($sent) {
            $alert->update(['resolution_notified' => true]);
        }

        return $sent;
    }

    /**
     * Announce something that happened, as opposed to something that is wrong.
     *
     * The rest of this class carries incidents: they fire, they repeat while still true,
     * and they resolve. An order placed while nobody was watching is none of those - it
     * is a fact, it never clears, and modelling it as an incident would leave a row
     * firing for ever or teach the channel that green messages can be ignored.
     *
     * So this delivers and logs, and keeps no state. It exists because an autonomous
     * copier that trades silently is indistinguishable from one that is broken, and the
     * difference should not require opening the dashboard to discover.
     *
     * @param  array<string, mixed>  $context
     */
    public function announce(string $title, string $body, string $icon = 'ℹ️', array $context = [], int|User|null $owner = null): bool
    {
        $user = $owner instanceof User ? $owner : ($owner === null ? null : User::find($owner));

        // Logged first and unconditionally: the record is the point, and it has to survive
        // Telegram being unreachable exactly like an incident does.
        BotLog::create([
            'user_id' => $user?->id,
            'level' => 'info',
            'source' => 'copier',
            'message' => $title,
            'context' => $context,
        ]);

        return $this->dispatch(
            $user,
            $icon.' *'.$this->escape($title).'*'."\n\n".$this->escape($body),
            $title,
            $body,
        );
    }

    /**
     * Get a message to whoever it concerns, by whatever route reaches them.
     *
     * Telegram when the tenant has connected it, because it is immediate and it is what
     * somebody watching a live account actually reads. Email when they have not, because
     * a customer who signed up an hour ago still needs to be told their bot stopped, and
     * silence is the failure this whole subsystem exists to prevent.
     *
     * ## Email is the fallback for "no channel", not for "channel failed"
     *
     * A configured chat that refuses the message is a delivery failure, and delivery
     * failures already have an answer here: `notified_at` stays null and the next sweep
     * tries again. Emailing instead would convert a transient Telegram outage into a
     * permanent switch to a worse channel, and would mark an incident notified on the
     * strength of a route the tenant did not ask for. The incident is on `/logs` either
     * way, which is what makes waiting for the retry safe.
     *
     * A null user means the incident belongs to the platform rather than to a customer,
     * and only then does the operator's own configured chat come into it.
     */
    private function dispatch(?User $user, string $markdown, string $subject, string $body): bool
    {
        if ($user !== null && ! $user->alerts_enabled) {
            return false;
        }

        $chat = $this->destinationFor($user);

        if ($chat !== null) {
            return $this->configured() && $this->deliver($chat, $markdown);
        }

        // Only a real tenant has an inbox. A platform incident with nowhere to go is
        // reported as undelivered rather than mailed into a void.
        if ($user === null) {
            return false;
        }

        try {
            $user->notify(new TradingAlert($subject, $body));

            return true;
        } catch (Throwable $e) {
            Log::warning('Alert email failed.', ['user' => $user->id, 'exception' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * The Telegram chat this message belongs in, or null if there isn't one.
     *
     * `TELEGRAM_CHAT_ID` is the platform's own address, and it is deliberately never used
     * for a tenant's incident. Falling back to it would put one customer's trading in
     * front of whoever reads the operator's channel - which is the bug this closes.
     */
    private function destinationFor(?User $user): ?string
    {
        if ($user === null) {
            $platform = (string) (config('alerts.telegram.chat_id') ?? '');

            return $platform === '' ? null : $platform;
        }

        return filled($user->telegram_chat_id) ? (string) $user->telegram_chat_id : null;
    }

    /**
     * POST to Telegram. Returns whether it was accepted.
     */
    private function deliver(string $chatId, string $markdown): bool
    {
        $token = (string) config('alerts.telegram.token');

        try {
            $response = Http::timeout((int) config('alerts.timeout', 8))
                ->asJson()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $markdown,
                    'parse_mode' => 'MarkdownV2',
                    'disable_web_page_preview' => true,
                ]);

            if ($response->successful()) {
                return true;
            }

            // The body carries Telegram's own description, which is far more useful than a
            // status code - a bad chat id and a revoked token both return 400.
            Log::warning('Alert delivery refused by Telegram.', [
                'status' => $response->status(),
                'body' => $response->json('description') ?? $response->body(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Alert delivery failed.', ['exception' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * Put the incident on /logs, where it is visible without a channel.
     */
    private function record(Alert $alert, bool $resolution): void
    {
        BotLog::create([
            // The incident already knows whose it is; the log row has to say so too, or
            // /logs shows one tenant the diagnosis of another tenant's outage.
            'user_id' => $alert->user_id,
            'level' => $resolution ? 'info' : ($alert->level === 'critical' ? 'critical' : 'warning'),
            'source' => 'monitor',
            'message' => ($resolution ? 'Resolved: ' : '').$alert->title,
            'context' => array_merge($alert->context ?? [], [
                'alert_key' => $alert->key,
                'alert_id' => $alert->id,
            ]),
        ]);
    }

    /**
     * Escape for Telegram's MarkdownV2, which rejects the whole message on an unescaped
     * reserved character - and alert bodies are full of them: percentages, hyphens, dots in
     * decimals, and parentheses.
     */
    private function escape(string $text): string
    {
        return str_replace(
            ['\\', '_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'],
            ['\\\\', '\\_', '\\*', '\\[', '\\]', '\\(', '\\)', '\\~', '\\`', '\\>', '\\#', '\\+', '\\-', '\\=', '\\|', '\\{', '\\}', '\\.', '\\!'],
            $text,
        );
    }
}
