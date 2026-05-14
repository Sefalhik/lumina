<?php

namespace App\Services\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Log;
use PragmaRX\Google2FA\Google2FA;

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
     * Verify a six-digit TOTP code against the given secret.
     *
     * Google2FA applies a ±1 window by default (covers minor clock drift between
     * server and authenticator device).
     */
    public function verify(string $secret, string $code): bool
    {
        return (bool) $this->google2fa->verifyKey($secret, $code);
    }

    /**
     * Persist the confirmed 2FA secret for the given user.
     *
     * The User model casts two_factor_secret as 'encrypted', so the raw value is
     * never stored in the database.
     */
    public function confirm(User $user, string $secret): void
    {
        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = now();
        $user->save();

        Log::info('Two-factor authentication confirmed and persisted', [
            'service' => self::class,
            'method' => __FUNCTION__,
            'step' => 'confirmed',
        ]);
    }
}
