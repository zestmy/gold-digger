<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Trading Alert
 *
 * The fallback route for an incident, for tenants who have not connected Telegram.
 *
 * Email is a poor channel for this and that is understood: a stop that has not moved for
 * six hours wants a push notification, not an inbox. It exists because the alternative for
 * somebody who signed up an hour ago is silence, and a monitoring system whose default
 * state is silence is worse than none - it teaches people the absence of a message means
 * everything is fine.
 *
 * Deliberately not queued. `bot:monitor` runs every minute under `withoutOverlapping`, and
 * a deployment with no queue worker - which the scheduler notes is a real configuration
 * here - would otherwise collect alerts in a table nobody drains.
 */
class TradingAlert extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $subject,
        private readonly string $body,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->line($this->body)
            ->action('Open the dashboard', route('dashboard'))
            ->line('Connect Telegram in Settings to get these immediately instead of by email.');
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return ['subject' => $this->subject, 'body' => $this->body];
    }
}
