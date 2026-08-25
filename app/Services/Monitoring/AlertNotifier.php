<?php

namespace App\Services\Monitoring;

use App\Models\Alert;
use App\Models\BotLog;
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
    public function configured(): bool
    {
        return filled(config('alerts.telegram.token')) && filled(config('alerts.telegram.chat_id'));
    }

    /**
     * Send an alert, marking it notified if the send succeeded.
     */
    public function send(Alert $alert): bool
    {
        $this->record($alert, resolution: false);

        if (! $this->configured()) {
            return false;
        }

        $icon = $alert->level === 'critical' ? '🔴' : '🟠';

        $sent = $this->deliver(
            $icon.' *'.$this->escape($alert->title).'*'."\n\n"
            .$this->escape($alert->body)
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

        if ($alert->notify_count === 0 || ! $this->configured()) {
            // Still mark it, so a later sweep does not keep reconsidering it.
            $alert->update(['resolution_notified' => true]);

            return false;
        }

        $lasted = $alert->first_seen_at?->diffForHumans($alert->resolved_at ?? now(), syntax: Carbon::DIFF_ABSOLUTE) ?? 'a while';

        $sent = $this->deliver(
            '🟢 *'.$this->escape('Resolved: '.$alert->title).'*'."\n\n"
            .$this->escape("Cleared after {$lasted}.")
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
    public function announce(string $title, string $body, string $icon = 'ℹ️', array $context = []): bool
    {
        // Logged first and unconditionally: the record is the point, and it has to survive
        // Telegram being unreachable exactly like an incident does.
        BotLog::create([
            'level' => 'info',
            'source' => 'copier',
            'message' => $title,
            'context' => $context,
        ]);

        if (! $this->configured()) {
            return false;
        }

        return $this->deliver(
            $icon.' *'.$this->escape($title).'*'.'

'.$this->escape($body)
        );
    }

    /**
     * POST to Telegram. Returns whether it was accepted.
     */
    private function deliver(string $markdown): bool
    {
        $token = (string) config('alerts.telegram.token');

        try {
            $response = Http::timeout((int) config('alerts.timeout', 8))
                ->asJson()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => (string) config('alerts.telegram.chat_id'),
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
