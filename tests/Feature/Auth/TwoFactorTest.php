<?php

namespace Tests\Feature\Auth;

use App\Livewire\Profile\AccountSecurity;
use App\Models\User;
use App\Services\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * A second factor, on an account that can move money.
 *
 * A session on this dashboard can enable autonomous trading, raise the AI capital cap and
 * queue orders. It was protected by a password and nothing else.
 *
 * The algorithm is held to RFC 6238's published vectors rather than to a recording of this
 * implementation's own output - a refactor that broke the truncation should fail against
 * numbers somebody else published, not agree with itself.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * RFC 6238 Appendix B. The seed is ASCII "12345678901234567890", which is this in base32.
     */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    // =====================================================================
    // THE ALGORITHM
    // =====================================================================

    public function test_it_matches_the_published_rfc_vectors(): void
    {
        $totp = new Totp;

        foreach ([
            59 => '287082',
            1111111109 => '081804',
            1111111111 => '050471',
            1234567890 => '005924',
            2000000000 => '279037',
            // Past 2^31, which is where a 32-bit counter would quietly wrap.
            20000000000 => '353130',
        ] as $time => $expected) {
            $this->assertSame($expected, $totp->at(self::RFC_SECRET, intdiv($time, 30)), "at t={$time}");
        }
    }

    /**
     * Phones drift. One step either side is about ninety seconds of tolerance.
     */
    public function test_a_code_from_the_previous_or_next_window_is_accepted(): void
    {
        $totp = new Totp;
        $now = 1111111111;
        $step = intdiv($now, 30);

        $this->assertNotNull($totp->verify(self::RFC_SECRET, $totp->at(self::RFC_SECRET, $step - 1), $now));
        $this->assertNotNull($totp->verify(self::RFC_SECRET, $totp->at(self::RFC_SECRET, $step + 1), $now));
    }

    public function test_a_code_from_far_outside_the_window_is_refused(): void
    {
        $totp = new Totp;
        $now = 1111111111;

        $this->assertNull($totp->verify(self::RFC_SECRET, $totp->at(self::RFC_SECRET, intdiv($now, 30) + 5), $now));
        $this->assertNull($totp->verify(self::RFC_SECRET, '000000', $now));
        $this->assertNull($totp->verify(self::RFC_SECRET, 'not a code', $now));
    }

    public function test_a_generated_secret_is_usable_base32(): void
    {
        $totp = new Totp;
        $secret = $totp->secret();

        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertNotNull($totp->verify($secret, $totp->at($secret, intdiv(time(), 30))));
    }

    // =====================================================================
    // SIGNING IN
    // =====================================================================

    public function test_an_account_without_two_factor_signs_in_on_the_password_alone(): void
    {
        $user = User::factory()->create();

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login');

        $this->assertAuthenticated();
    }

    /**
     * The important one. The password alone must not establish a session on an enrolled
     * account - not even briefly.
     */
    public function test_the_password_alone_does_not_sign_in_an_enrolled_account(): void
    {
        $this->enrolled();

        Volt::test('pages.auth.login')
            ->set('form.email', 'trader@example.com')
            ->set('form.password', 'password')
            ->call('login')
            ->assertHasErrors('form.code')
            ->assertSet('form.awaitingCode', true);

        $this->assertGuest();
    }

    public function test_the_right_code_completes_the_sign_in(): void
    {
        $user = $this->enrolled();

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->set('form.code', (new Totp)->at(self::RFC_SECRET, intdiv(time(), 30)))
            ->call('login');

        $this->assertAuthenticated();
    }

    public function test_a_wrong_code_does_not(): void
    {
        $user = $this->enrolled();

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->set('form.code', '123456')
            ->call('login')
            ->assertHasErrors('form.code');

        $this->assertGuest();
    }

    /**
     * A code is valid for its whole window, so without spending it an intercepted one could
     * be replayed inside its own thirty seconds.
     */
    public function test_a_code_cannot_be_used_twice(): void
    {
        $user = $this->enrolled();
        $code = (new Totp)->at(self::RFC_SECRET, intdiv(time(), 30));

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->set('form.code', $code)
            ->call('login');

        $this->assertAuthenticated();
        auth()->logout();

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->set('form.code', $code)
            ->call('login')
            ->assertHasErrors('form.code');

        $this->assertGuest();
    }

    // =====================================================================
    // RECOVERY
    // =====================================================================

    public function test_a_recovery_code_works_in_place_of_a_code_and_is_spent(): void
    {
        $user = $this->enrolled(['aaaaa-bbbbb', 'ccccc-ddddd']);

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->set('form.code', 'aaaaa-bbbbb')
            ->call('login');

        $this->assertAuthenticated();

        // Single use: one gone, one left.
        $this->assertSame(1, $user->fresh()->recoveryCodesRemaining());
    }

    public function test_a_spent_recovery_code_does_not_work_again(): void
    {
        $user = $this->enrolled(['aaaaa-bbbbb']);

        $this->assertTrue($user->useRecoveryCode('aaaaa-bbbbb'));
        $this->assertFalse($user->fresh()->useRecoveryCode('aaaaa-bbbbb'));
    }

    // =====================================================================
    // ENROLMENT
    // =====================================================================

    /**
     * A secret nobody has proved they hold would lock somebody out of an account that can
     * move money, with no way back in.
     */
    public function test_issuing_a_secret_does_not_by_itself_enforce_anything(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(AccountSecurity::class)
            ->call('begin')
            ->assertSet('pendingSecret', fn ($s) => is_string($s) && strlen($s) === 32);

        $this->assertFalse($user->fresh()->hasTwoFactor());
    }

    public function test_a_proved_code_turns_it_on_and_issues_recovery_codes(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(AccountSecurity::class)->call('begin');
        $secret = $component->get('pendingSecret');

        $component->set('code', (new Totp)->at($secret, intdiv(time(), 30)))->call('confirm');

        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->assertCount(8, $component->get('freshRecoveryCodes'));
    }

    public function test_a_wrong_code_leaves_it_off(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(AccountSecurity::class)
            ->call('begin')
            ->set('code', '000000')
            ->call('confirm')
            ->assertHasErrors('code');

        $this->assertFalse($user->fresh()->hasTwoFactor());
    }

    /**
     * Removing a second factor is what somebody holding a stolen session would do first.
     */
    public function test_turning_it_off_requires_the_password(): void
    {
        $user = $this->enrolled();

        Livewire::actingAs($user)
            ->test(AccountSecurity::class)
            ->set('password', 'not-the-password')
            ->call('disable')
            ->assertHasErrors('password');

        $this->assertTrue($user->fresh()->hasTwoFactor());

        Livewire::actingAs($user)
            ->test(AccountSecurity::class)
            ->set('password', 'password')
            ->call('disable');

        $this->assertFalse($user->fresh()->hasTwoFactor());
    }

    // =====================================================================
    // WHAT IS STORED
    // =====================================================================

    /**
     * Anyone holding the secret can generate valid codes for ever, so it gets the treatment
     * broker account numbers get.
     */
    public function test_the_secret_is_encrypted_at_rest_and_never_serialised(): void
    {
        $user = $this->enrolled();

        $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

        $this->assertNotSame(self::RFC_SECRET, $raw, 'the secret must not sit in the column in plaintext');
        $this->assertSame(self::RFC_SECRET, $user->fresh()->two_factor_secret);

        $serialised = json_encode($user->fresh()->toArray());
        $this->assertStringNotContainsString(self::RFC_SECRET, (string) $serialised);
        $this->assertStringNotContainsString('two_factor_recovery_codes', (string) $serialised);
    }

    /**
     * Recovery codes are single-use passwords, so they get what passwords get. The server
     * only ever checks one; it never needs to read one back.
     */
    public function test_recovery_codes_are_hashed_not_recoverable(): void
    {
        $user = $this->enrolled(['aaaaa-bbbbb']);

        $stored = json_encode($user->fresh()->two_factor_recovery_codes);

        $this->assertStringNotContainsString('aaaaa-bbbbb', (string) $stored);
    }

    /**
     * @param  array<int, string>  $recovery
     */
    private function enrolled(array $recovery = ['aaaaa-bbbbb']): User
    {
        $user = User::factory()->create(['email' => 'trader@example.com']);

        $user->forceFill([
            'two_factor_secret' => self::RFC_SECRET,
            'two_factor_recovery_codes' => array_map(fn (string $c) => Hash::make($c), $recovery),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }
}
