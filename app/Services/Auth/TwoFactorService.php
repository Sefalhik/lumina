<?php

namespace App\Services\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PragmaRX\Google2FA\Exceptions\Google2FAException;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

class TwoFactorService
{
    public function __construct(private readonly Google2FA $google2fa) {}

    /** Generate a new random Base32 TOTP secret key. */
    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * Build the otpauth:// URL for the given account and render it as an SVG QR code.
     *
     * The QR code is what authenticator apps (Google Authenticator, Aegis…) scan
     * during enrolment. Size is fixed at 200 px — sufficient for on-screen scanning.
     */
    public function generateQrSvg(string $email, string $secret): string
    {
        $url = $this->google2fa->getQRCodeUrl(config('app.name'), $email, $secret);
        $renderer = new ImageRenderer(new RendererStyle(200), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($url);
    }

    /**
     * Stands for a null two_factor_last_used_timestep — no code accepted yet. verifyKeyNewer()
     * needs an integer here: given null, it answers `true` instead of the time step it accepted,
     * and there would be nothing to record.
     */
    private const NO_CODE_ACCEPTED_YET = 0;

    /**
     * The time step of a six-digit code newer than the last one this user had accepted, or null
     * when the code is wrong or already used (LUMN-91). Computation only: nothing is written —
     * the caller records the step, through claim() or confirm().
     *
     * Google2FA applies a ±1 window by default (covers minor clock drift between server and
     * authenticator device), so a code stays valid for about 90 seconds: without the last
     * accepted step, it could be typed again for as long.
     *
     * Every refusal fails closed, the improbable ones included: a last step in the future, or a
     * secret Google2FA cannot read, refuses the code and logs an error — never a 500, never a pass.
     */
    public function verify(User $user, string $secret, string $code): ?int
    {
        $lastUsed = $user->two_factor_last_used_timestep ?? self::NO_CODE_ACCEPTED_YET;
        $now = $this->currentTimestep();

        // No acceptance can record a step beyond the window: one there means the clock went back,
        // or the row came from elsewhere. Codes stay refused until time catches up with it.
        if ($lastUsed > $now + $this->google2fa->getWindow()) {
            Log::error('Two-factor last used step is in the future — code refused', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'timestep_in_future',
                'user_id' => $user->getKey(),
                'last_used_timestep' => $lastUsed,
                'current_timestep' => $now,
            ]);

            return null;
        }

        try {
            $timestep = $this->google2fa->verifyKeyNewer($secret, $code, $lastUsed, null, $now);
            if (is_int($timestep)) {
                return $timestep;
            }

            // A code the window accepts but the last step rules out is a replay, not a typo.
            if ($this->google2fa->verifyKey($secret, $code, null, $now)) {
                $this->logReplay(__FUNCTION__, $user);
            }
        } catch (Google2FAException $e) {
            $this->logUnreadableSecret(__FUNCTION__, $user, $e);
        }

        return null;
    }

    /**
     * verify() against the secret stored for this user — the challenge's case. A secret that
     * cannot be decrypted (APP_KEY rotated without re-encrypting) or is missing beside a confirmed
     * 2FA refuses the code like any other unreadable secret.
     */
    public function verifyStored(User $user, string $code): ?int
    {
        // getAttribute() rather than the property: it is the call in which the 'encrypted' cast
        // decrypts, and so the one that throws — which static analysis can see.
        try {
            $secret = $user->getAttribute('two_factor_secret');
        } catch (DecryptException $e) {
            $this->logUnreadableSecret(__FUNCTION__, $user, $e);

            return null;
        }

        if (! is_string($secret)) {
            $this->logUnreadableSecret(__FUNCTION__, $user, null);

            return null;
        }

        return $this->verify($user, $secret, $code);
    }

    /**
     * Record $timestep as the last code this user had accepted — only if it is newer than the one
     * on record. The database decides, in one UPDATE by primary key and no read: two requests
     * carrying the same code cannot both pass, whatever each had loaded.
     */
    public function claim(User $user, int $timestep): bool
    {
        // toBase(): the Eloquent builder would also touch updated_at, a write nobody reads here.
        $claimed = User::query()
            ->whereKey($user->getKey())
            ->where(fn ($query) => $query
                ->whereNull('two_factor_last_used_timestep')
                ->orWhere('two_factor_last_used_timestep', '<', $timestep))
            ->toBase()
            ->update(['two_factor_last_used_timestep' => $timestep]) === 1;

        if (! $claimed) {
            $this->logReplay(__FUNCTION__, $user);

            return false;
        }

        // Keep the model in step without marking it dirty: a later save() must not write it again.
        $user->two_factor_last_used_timestep = $timestep;
        $user->syncOriginalAttribute('two_factor_last_used_timestep');

        return true;
    }

    /**
     * Persist the confirmed 2FA secret for the given user, with the step of the code that
     * confirmed it — otherwise that code would still be accepted at the first challenge.
     *
     * One save() for the three columns. The User model casts two_factor_secret as 'encrypted',
     * so the raw value is never stored in the database.
     */
    public function confirm(User $user, string $secret, int $timestep): void
    {
        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = now();
        $user->two_factor_last_used_timestep = $timestep;
        $user->save();

        Log::info('Two-factor authentication confirmed and persisted', [
            'service' => self::class,
            'method' => __FUNCTION__,
            'step' => 'confirmed',
        ]);
    }

    /**
     * The current 30-second step, read from Carbon rather than from Google2FA's own microtime():
     * the application keeps one clock, and tests can freeze it.
     */
    private function currentTimestep(): int
    {
        return intdiv(Carbon::now()->getTimestamp(), (int) $this->google2fa->getKeyRegeneration());
    }

    /** @param  Throwable|null  $e  null when the secret is missing rather than unreadable */
    private function logUnreadableSecret(string $method, User $user, ?Throwable $e): void
    {
        $context = [
            'service' => self::class,
            'method' => $method,
            'step' => 'secret_unreadable',
            'user_id' => $user->getKey(),
        ];

        Log::error('Two-factor secret unreadable — code refused', $e === null ? $context : $context + ['exception' => $e]);
    }

    private function logReplay(string $method, User $user): void
    {
        Log::warning('Two-factor code refused — already used', [
            'service' => self::class,
            'method' => $method,
            'step' => 'code_replayed',
            'user_id' => $user->getKey(),
        ]);
    }
}
