<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Counts failed authentication attempts and says when to refuse the next one (LUMN-36).
 *
 * Only failures are counted: a correct password or code never consumes an attempt, and clears
 * the counters it belongs to. Callers ask for a lockout *before* checking credentials, so a
 * locked-out caller never gets to test a password or a code.
 *
 * Login is keyed on email + IP, because the caller is not identified yet. On this host the IP is
 * a real barrier — mod_remoteip sets REMOTE_ADDR upstream and no proxy is trusted, see
 * bootstrap/app.php. A second, wider counter on the email alone catches guesses spread across
 * many addresses; it stays generous and expires on its own, so that nobody can lock the only
 * administrator out for good by submitting their address in a loop.
 *
 * The two-factor counter is keyed on the user, who is already authenticated at that stage: the
 * key depends on no header and no host. Setup and challenge share it, so alternating between
 * the two forms buys no extra attempt.
 */
class AuthThrottleService
{
    // Thresholds decided on 2026-10-22, recorded in LUMN-36.
    public const LOGIN_MAX_ATTEMPTS = 5;

    public const LOGIN_DECAY_SECONDS = 60;

    public const LOGIN_EMAIL_MAX_ATTEMPTS = 20;

    public const LOGIN_EMAIL_DECAY_SECONDS = 3600;

    public const TWO_FACTOR_MAX_ATTEMPTS = 5;

    public const TWO_FACTOR_DECAY_SECONDS = 900;

    public function __construct(private readonly RateLimiter $limiter) {}

    /** Seconds to wait before the next login attempt, or null when one is allowed. */
    public function loginLockout(string $email, string $ip): ?int
    {
        $wait = $this->secondsUntilAvailable([
            $this->loginKey($email, $ip) => self::LOGIN_MAX_ATTEMPTS,
            $this->loginEmailKey($email) => self::LOGIN_EMAIL_MAX_ATTEMPTS,
        ]);

        if ($wait !== null) {
            Log::warning('Login attempt refused — too many failures', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'login_throttled',
                'ip' => $ip,
                'retry_after' => $wait,
            ]);
        }

        return $wait;
    }

    public function recordLoginFailure(string $email, string $ip): void
    {
        $this->limiter->hit($this->loginKey($email, $ip), self::LOGIN_DECAY_SECONDS);
        $this->limiter->hit($this->loginEmailKey($email), self::LOGIN_EMAIL_DECAY_SECONDS);

        if ($this->limiter->tooManyAttempts($this->loginKey($email, $ip), self::LOGIN_MAX_ATTEMPTS)
            || $this->limiter->tooManyAttempts($this->loginEmailKey($email), self::LOGIN_EMAIL_MAX_ATTEMPTS)) {
            // This failure has just reached a threshold — callers refuse locked-out attempts
            // before getting here, so it fires once per lockout. Brute force is the likeliest
            // reading, hence alert rather than warning (docs/logging-conventions.md).
            Log::alert('Login locked out after repeated failures', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'login_lockout',
                'ip' => $ip,
            ]);
        }
    }

    public function clearLogin(string $email, string $ip): void
    {
        $this->limiter->clear($this->loginKey($email, $ip));
        $this->limiter->clear($this->loginEmailKey($email));
    }

    /** Seconds to wait before the next two-factor code, or null when one is allowed. */
    public function twoFactorLockout(User $user): ?int
    {
        $wait = $this->secondsUntilAvailable([
            $this->twoFactorKey($user) => self::TWO_FACTOR_MAX_ATTEMPTS,
        ]);

        if ($wait !== null) {
            Log::warning('Two-factor code refused — too many failures', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'two_factor_throttled',
                'user_id' => $user->getKey(),
                'retry_after' => $wait,
            ]);
        }

        return $wait;
    }

    public function recordTwoFactorFailure(User $user): void
    {
        $this->limiter->hit($this->twoFactorKey($user), self::TWO_FACTOR_DECAY_SECONDS);

        if ($this->limiter->tooManyAttempts($this->twoFactorKey($user), self::TWO_FACTOR_MAX_ATTEMPTS)) {
            // Whoever is guessing already holds the password: this is the alert that matters.
            Log::alert('Two-factor locked out after repeated failures', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'two_factor_lockout',
                'user_id' => $user->getKey(),
            ]);
        }
    }

    public function clearTwoFactor(User $user): void
    {
        $this->limiter->clear($this->twoFactorKey($user));
    }

    /**
     * The longest wait among the counters that have reached their limit, or null when none has.
     *
     * @param  array<string, int>  $limits  counter key => maximum attempts
     */
    private function secondsUntilAvailable(array $limits): ?int
    {
        $wait = null;

        foreach ($limits as $key => $maxAttempts) {
            if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
                $wait = max($wait ?? 0, $this->limiter->availableIn($key));
            }
        }

        return $wait;
    }

    private function loginKey(string $email, string $ip): string
    {
        return 'login:'.$this->emailDigest($email).'|'.$ip;
    }

    private function loginEmailKey(string $email): string
    {
        return 'login-email:'.$this->emailDigest($email);
    }

    private function twoFactorKey(User $user): string
    {
        return 'two-factor:'.$user->getKey();
    }

    /**
     * The email is hashed rather than written into the key: the cache lives in the database, and
     * an address is personal data. Case and accents are folded first, so that "Admin@…" and
     * "admin@…" share one counter instead of doubling the attempts.
     */
    private function emailDigest(string $email): string
    {
        return hash('sha256', Str::transliterate(Str::lower(trim($email))));
    }
}
