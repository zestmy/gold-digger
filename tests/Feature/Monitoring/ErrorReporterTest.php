<?php

namespace Tests\Feature\Monitoring;

use App\Models\BotLog;
use App\Models\User;
use App\Services\Monitoring\ErrorReporter;
use App\Support\Tenancy\Tenant;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

/**
 * The application's own faults.
 *
 * This system watched the trading bot closely - heartbeats, stalled queues, drifting stops -
 * and watched itself not at all. A 500 on a customer's page was invisible until they
 * emailed about it.
 *
 * The tests that matter most are about restraint rather than coverage. An error reporter
 * that reports everything buries the faults among the 404s, and one that reports every
 * occurrence takes the notification channel down alongside the page that is failing.
 */
class ErrorReporterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'alerts.telegram.token' => 'platform-token',
            'alerts.telegram.chat_id' => '999-operator',
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    // =====================================================================
    // WHAT IT REFUSES TO REPORT
    // =====================================================================

    /**
     * A 404, a failed login, a rejected form. All of these are the application working
     * correctly, and reporting them would bury the faults among them.
     */
    public function test_the_application_refusing_something_correctly_is_not_a_fault(): void
    {
        $reporter = app(ErrorReporter::class);

        foreach ([
            new NotFoundHttpException('no such page'),
            new AuthenticationException,
            new ModelNotFoundException,
            ValidationException::withMessages(['email' => 'required']),
        ] as $expected) {
            $reporter->report($expected);
        }

        $this->assertSame(0, BotLog::acrossTenants()->count());
        Http::assertNothingSent();
    }

    public function test_a_five_hundred_is_ours_and_is_reported(): void
    {
        app(ErrorReporter::class)->report(new ServiceUnavailableHttpException);

        $this->assertSame(1, BotLog::acrossTenants()->count());
    }

    public function test_an_ordinary_exception_is_reported(): void
    {
        app(ErrorReporter::class)->report(new RuntimeException('the copier fell over'));

        $log = BotLog::acrossTenants()->sole();

        $this->assertSame('critical', $log->level);
        $this->assertSame('app', $log->source);
        $this->assertStringContainsString('the copier fell over', $log->message);
        $this->assertSame(RuntimeException::class, $log->context['exception']);
    }

    // =====================================================================
    // REPETITION IS COUNTED, NOT REPEATED
    // =====================================================================

    /**
     * A page throwing on every request would otherwise write a row and send a message per
     * request - taking the channel down alongside the page.
     */
    public function test_the_same_fault_is_reported_once_per_window(): void
    {
        $reporter = app(ErrorReporter::class);

        for ($i = 0; $i < 50; $i++) {
            $reporter->report($this->sameFault());
        }

        $this->assertSame(1, BotLog::acrossTenants()->count());
        Http::assertSentCount(1);
    }

    /**
     * Two different faults are two incidents. Deduplication that collapsed unrelated
     * failures would hide the second one.
     */
    public function test_different_faults_are_reported_separately(): void
    {
        $reporter = app(ErrorReporter::class);

        $reporter->report(new RuntimeException('one'));
        $reporter->report(new \LogicException('two'));

        $this->assertSame(2, BotLog::acrossTenants()->count());
    }

    /**
     * Messages carry ids, symbols and balances, so signing on the message would give the
     * same broken line a new signature per request and deduplicate nothing at all.
     */
    public function test_the_signature_ignores_the_message(): void
    {
        $reporter = app(ErrorReporter::class);

        // Both raised from the same line of the same helper - one broken line, hit twice,
        // with a different id in the message each time. That is the case deduplication has
        // to survive, and signing on the message would defeat it entirely.
        $this->assertSame(
            $reporter->signature($this->faultAbout('trade 4182')),
            $reporter->signature($this->faultAbout('trade 9903')),
        );
    }

    /**
     * A fault whose message varies but whose origin does not.
     */
    private function faultAbout(string $subject): RuntimeException
    {
        return new RuntimeException("{$subject} failed");
    }

    // =====================================================================
    // WHO HEARS ABOUT IT
    // =====================================================================

    /**
     * An exception is a fault in this software. The customer whose request hit it does not
     * want a stack trace and cannot act on one, so it goes to the platform's address.
     */
    public function test_the_operator_is_told_and_the_tenant_is_not(): void
    {
        $tenant = User::factory()->create(['telegram_chat_id' => '111-tenant']);

        Tenant::for($tenant, fn () => app(ErrorReporter::class)->report(new RuntimeException('boom')));

        Http::assertSent(function ($request) {
            $this->assertSame('999-operator', $request->data()['chat_id']);

            return true;
        });
    }

    /**
     * The log row still carries the tenant, so the fault is findable beside their other
     * activity when somebody investigates.
     */
    public function test_the_log_row_still_names_whose_request_hit_it(): void
    {
        $tenant = User::factory()->create();

        Tenant::for($tenant, fn () => app(ErrorReporter::class)->report(new RuntimeException('boom')));

        $this->assertSame($tenant->id, BotLog::acrossTenants()->sole()->user_id);
    }

    public function test_a_fault_with_no_tenant_belongs_to_nobody(): void
    {
        app(ErrorReporter::class)->report(new RuntimeException('console work'));

        $this->assertNull(BotLog::acrossTenants()->sole()->user_id);
    }

    // =====================================================================
    // IT MUST NOT BECOME THE OUTAGE
    // =====================================================================

    /**
     * An exception raised while reporting an exception is how a small fault becomes an
     * outage, and there is nowhere useful for it to go.
     */
    public function test_reporting_never_throws_even_when_notification_fails(): void
    {
        Http::fake(['api.telegram.org/*' => fn () => throw new RuntimeException('telegram is down')]);

        app(ErrorReporter::class)->report(new RuntimeException('the original fault'));

        // The record still landed; only the message failed.
        $this->assertSame(1, BotLog::acrossTenants()->count());
    }

    public function test_the_record_says_where_in_this_application_it_happened(): void
    {
        app(ErrorReporter::class)->report(new RuntimeException('boom'));

        $context = BotLog::acrossTenants()->sole()->context;

        $this->assertArrayHasKey('where', $context);
        $this->assertArrayHasKey('signature', $context);
        // Relative to the project, not an absolute path off somebody's disk.
        $this->assertStringNotContainsString(base_path(), $context['where']);
    }

    private function sameFault(): RuntimeException
    {
        return new RuntimeException('a repeating fault');
    }
}
