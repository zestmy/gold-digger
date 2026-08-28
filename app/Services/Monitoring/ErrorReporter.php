<?php

namespace App\Services\Monitoring;

use App\Models\BotLog;
use App\Support\Tenancy\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Error Reporter
 *
 * The application's own faults, treated the way it already treats the executor's.
 *
 * ## The gap this closes
 *
 * This system watches the trading bot carefully - heartbeats, stalled queues, stale
 * calendars, drifting stops - and watched itself not at all. A 500 on a customer's settings
 * page was invisible until they emailed about it, and the only way to find one was to read
 * `storage/logs/laravel.log` over SSH. For one operator that is merely inconvenient. For a
 * product it means the first person to know a page is broken is the person it broke for.
 *
 * ## Why not a third-party reporter
 *
 * Because the pieces already exist and agree with each other. Incidents belong on `/logs`,
 * where every other incident is; notification belongs to `AlertNotifier`, which already
 * knows the difference between a tenant's channel and the platform's. Adding a second
 * system would mean two places to look and two ways to be misconfigured, and would need an
 * account and a key before it did anything at all.
 *
 * If a deployment wants stack traces, aggregation and release tracking, that is a genuine
 * reason to add one - this is the floor, not a replacement.
 *
 * ## It notifies the operator, never the tenant
 *
 * An exception is a fault in this software. The customer whose request hit it does not want
 * a stack trace, and cannot act on one. So the announcement carries no owner, which routes
 * it to the platform's own address - exactly what that address was separated out for.
 *
 * The `bot_logs` row *is* stamped with the tenant when one is current, so the fault is
 * findable beside their other activity when somebody investigates.
 *
 * ## Repetition is counted, not repeated
 *
 * A page that throws on every request would otherwise write a row and send a message per
 * request. The first occurrence in each window reports; the rest increment a counter that
 * travels with the next report. A flood becomes "and 431 more since", which is more useful
 * than 431 messages and does not take the channel down with the page.
 */
final class ErrorReporter
{
    /** Minutes before the same fault is worth reporting again. */
    private const WINDOW = 15;

    /**
     * Exceptions that describe a request rather than a fault.
     *
     * A 404, a failed login, a rejected form and a throttled client are all this
     * application working correctly. Reporting them would bury the faults among them, which
     * is the failure mode of every error reporter that reports everything.
     *
     * @var array<int, class-string>
     */
    private const EXPECTED = [
        AuthenticationException::class,
        AuthorizationException::class,
        ModelNotFoundException::class,
        ThrottleRequestsException::class,
        TokenMismatchException::class,
        ValidationException::class,
    ];

    /**
     * Record a fault, and tell the operator if it is new.
     *
     * Never throws. An exception raised while reporting an exception is how a small fault
     * becomes an outage, and there is nowhere useful for it to go.
     */
    public function report(Throwable $e): void
    {
        try {
            if (! $this->worthReporting($e)) {
                return;
            }

            $signature = $this->signature($e);
            $key = 'app-error:'.$signature;

            $seen = (int) Cache::get($key, 0);
            Cache::put($key, $seen + 1, now()->addMinutes(self::WINDOW));

            // Inside the window: counted and nothing else. The count travels with the next
            // report rather than being lost.
            if ($seen > 0) {
                return;
            }

            $since = (int) Cache::pull('app-error-pending:'.$signature, 0);

            $this->record($e, $signature, $since);
        } catch (Throwable) {
            // Deliberately silent. Laravel's own log handler still has the original.
        }
    }

    /**
     * Is this a fault, or the application refusing something correctly?
     */
    public function worthReporting(Throwable $e): bool
    {
        foreach (self::EXPECTED as $expected) {
            if ($e instanceof $expected) {
                return false;
            }
        }

        // Anything carrying a 4xx is a statement about the request. 5xx is ours.
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode() >= 500;
        }

        return true;
    }

    /**
     * What makes two occurrences the same fault.
     *
     * Class, file and line - deliberately not the message. Messages carry ids, symbols and
     * balances, so the same broken line would produce a new signature per request and the
     * deduplication would do nothing at all.
     */
    public function signature(Throwable $e): string
    {
        return substr(sha1(sprintf('%s|%s|%d', $e::class, $e->getFile(), $e->getLine())), 0, 12);
    }

    private function record(Throwable $e, string $signature, int $since): void
    {
        $where = sprintf('%s:%d', $this->relative($e->getFile()), $e->getLine());
        $title = class_basename($e).' in '.$where;

        BotLog::create([
            // Whose request hit it, when that is known. Console and queue work has no
            // tenant, and null there is correct rather than missing.
            'user_id' => Tenant::current(),
            'level' => 'critical',
            'source' => 'app',
            'message' => mb_substr($e->getMessage() ?: $title, 0, 2000),
            'context' => [
                'exception' => $e::class,
                'where' => $where,
                'signature' => $signature,
                'since_last_report' => $since,
                // One frame, not the whole trace. Enough to know which call site, short
                // enough that a log page stays readable.
                'caller' => $this->caller($e),
            ],
        ]);

        // `notifyPlatform` rather than `announce`: the row above is already the record, and
        // announce() would file the same fault a second time as an info-level copier event,
        // without the level, the exception class or the signature that make it findable.
        app(AlertNotifier::class)->notifyPlatform(
            $title,
            implode('
', array_filter([
                mb_substr($e->getMessage() ?: '(no message)', 0, 300),
                'At '.$where,
                $this->caller($e),
                $since > 0 ? "{$since} further occurrences since the last report." : null,
                'Repeats within '.self::WINDOW.' minutes are counted rather than sent.',
            ])),
        );
    }

    /**
     * The first frame inside this application, which is usually the useful one.
     */
    private function caller(Throwable $e): ?string
    {
        foreach ($e->getTrace() as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || ! str_contains($file, DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            return sprintf('%s:%d', $this->relative($file), $frame['line'] ?? 0);
        }

        return null;
    }

    private function relative(string $path): string
    {
        return str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);
    }
}
