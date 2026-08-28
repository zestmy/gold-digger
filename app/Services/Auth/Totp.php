<?php

namespace App\Services\Auth;

use Illuminate\Support\Str;

/**
 * Time-based one-time passwords, RFC 6238.
 *
 * ## Why this is not a package
 *
 * TOTP is HMAC-SHA1 over a counter, a dynamic truncation and a modulo. PHP ships
 * `hash_hmac`, and the RFC publishes test vectors, so the whole algorithm is forty lines
 * that can be proved correct against numbers somebody else published. This codebase already
 * declined Sanctum for a smaller reason than that.
 *
 * The part that would justify a dependency is QR rendering, which needs an image encoder.
 * `provisioningUri()` returns the standard `otpauth://` string instead; every authenticator
 * accepts a pasted secret, and a QR is a convenience rather than a capability.
 *
 * ## What the tests hold this to
 *
 * The RFC's own vectors, not a recording of this implementation's output. A refactor that
 * broke the truncation would fail against published numbers rather than agree with itself.
 *
 * ## Clock skew, and why the window is small
 *
 * Phones drift. A window of one step either side tolerates about ninety seconds of skew,
 * which covers real drift without meaningfully widening the guess space: a six-digit code
 * across three windows is three chances in a million per attempt, and login throttling does
 * the rest. Widening it further trades security for the convenience of not fixing a clock.
 */
final class Totp
{
    /** Seconds per code. Thirty is the universal default; changing it breaks every app. */
    private const STEP = 30;

    private const DIGITS = 6;

    /** Steps either side of now that are accepted, for clock drift. */
    private const SKEW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A new shared secret, base32 as every authenticator expects.
     *
     * 160 bits, which is the RFC's recommendation for HMAC-SHA1 and what the algorithm's
     * security actually rests on.
     */
    public function secret(): string
    {
        $bytes = random_bytes(20);
        $secret = '';

        // Base32 of raw bytes: five bits at a time, so twenty bytes becomes thirty-two
        // characters with no padding needed.
        $buffer = 0;
        $bits = 0;

        foreach (str_split($bytes) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $secret .= self::ALPHABET[($buffer >> $bits) & 31];
            }
        }

        return $secret;
    }

    /**
     * The string an authenticator scans or accepts pasted.
     *
     * The issuer appears twice by convention - in the label and as a parameter - because
     * older apps read one and newer ones the other, and an entry labelled only with an
     * email address is unidentifiable once somebody has three of them.
     */
    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::STEP,
        );
    }

    /**
     * Is this code valid for this secret right now?
     *
     * Returns the timestep it matched, or null. The timestep rather than a boolean because
     * the caller has to record it: a code stays valid for thirty seconds, so without
     * remembering which one was used, an intercepted code can be replayed inside its own
     * window.
     */
    public function verify(string $secret, string $code, ?int $at = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $now = intdiv($at ?? time(), self::STEP);

        for ($offset = -self::SKEW; $offset <= self::SKEW; $offset++) {
            $step = $now + $offset;

            // Constant-time comparison. A timing side channel on a six-digit code is a
            // small hole, but it is a free one to close.
            if (hash_equals($this->at($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The code for one timestep.
     */
    public function at(string $secret, int $step): string
    {
        $key = $this->decode($secret);

        // The counter is eight bytes, big-endian. `J` is machine-dependent, so it is
        // packed as two 32-bit halves to stay correct on any platform.
        $counter = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);

        $hash = hash_hmac('sha1', $counter, $key, true);

        // Dynamic truncation: the low nibble of the last byte chooses where to read four
        // bytes from, and the top bit is masked off so the result is always positive.
        $offset = ord($hash[19]) & 0x0F;

        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Recovery codes, for the phone that was lost or wiped.
     *
     * Without these, enabling two-factor on an account that can move money is a way to be
     * permanently locked out of it - which is why the flow refuses to enable without
     * showing them.
     *
     * @return array<int, string>
     */
    public function recoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->all();
    }

    /**
     * Base32 to raw bytes.
     *
     * Case and padding are forgiven because people retype these from a screen, and spaces
     * are stripped for the same reason - authenticators display the secret in groups of
     * four.
     */
    private function decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '');

        $buffer = 0;
        $bits = 0;
        $out = '';

        foreach (str_split($secret) as $char) {
            $index = strpos(self::ALPHABET, $char);

            if ($index === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $index;
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        return $out;
    }
}
