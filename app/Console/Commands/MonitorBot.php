<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Services\Monitoring\AlertNotifier;
use App\Services\Monitoring\HealthMonitor;
use Illuminate\Console\Command;

/**
 * Monitor Bot
 *
 * Runs the health checks and sends what needs sending. Scheduled every minute.
 *
 * Ordering matters here: conditions are evaluated and persisted first, then notifications go
 * out. A channel that is down must not prevent the sweep from recording an incident or
 * resolving one that cleared - the record is what makes this discoverable in the morning
 * whether or not anybody was woken up.
 */
class MonitorBot extends Command
{
    protected $signature = 'bot:monitor
                            {--quiet-channel : Evaluate and record, but send nothing}';

    protected $description = 'Check executor health and notify on anything worth interrupting for';

    public function handle(HealthMonitor $monitor, AlertNotifier $notifier): int
    {
        $result = $monitor->sweep();

        $this->line(sprintf(
            '%d opened, %d resolved.',
            count($result['opened']),
            count($result['resolved']),
        ));

        if ($this->option('quiet-channel')) {
            return self::SUCCESS;
        }

        if (! $notifier->configured()) {
            // Not a failure. Incidents are still recorded and visible on /logs; they simply
            // do not reach anyone. Said once per run so it is discoverable without nagging.
            $this->warn('No alert channel configured; incidents recorded but not sent. See config/alerts.php.');
        }

        $repeatAfter = (int) config('alerts.repeat_after_minutes', 60);
        $sent = 0;

        // Everything still firing that is due a message - which includes incidents opened on
        // an earlier run whose delivery failed, since a failed send leaves notified_at null.
        foreach (Alert::query()->firing()->get() as $alert) {
            if ($alert->needsNotifying($repeatAfter) && $notifier->send($alert)) {
                $sent++;
            }
        }

        $cleared = 0;

        foreach (Alert::query()->whereNotNull('resolved_at')->where('resolution_notified', false)->get() as $alert) {
            if ($notifier->sendResolution($alert)) {
                $cleared++;
            }
        }

        $this->line("{$sent} alert(s) sent, {$cleared} resolution(s) sent.");

        return self::SUCCESS;
    }
}
