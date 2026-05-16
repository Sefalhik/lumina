<?php

namespace Tests\Unit\Services\Auth;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorServiceTest extends TestCase
{
    use RefreshDatabase;

    private TwoFactorService $service;

    private Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->google2fa = new Google2FA;
        $this->service = new TwoFactorService($this->google2fa);
    }

    public function test_generate_secret_returns_valid_base32_string(): void
    {
        $secret = $this->service->generateSecret();

        $this->assertNotEmpty($secret);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+=*$/', $secret);
    }

    public function test_generate_secret_produces_unique_values(): void
    {
        $this->assertNotSame(
            $this->service->generateSecret(),
            $this->service->generateSecret()
        );
    }

    public function test_generate_qr_svg_returns_svg_markup(): void
    {
        $svg = $this->service->generateQrSvg('test@example.com', $this->service->generateSecret());

        $this->assertStringContainsString('<svg', $svg);
    }

    public function test_verify_returns_true_for_current_otp(): void
    {
        $secret = $this->google2fa->generateSecretKey();

        $this->assertTrue($this->service->verify($secret, $this->google2fa->getCurrentOtp($secret)));
    }

    public function test_verify_returns_false_for_wrong_code(): void
    {
        $this->assertFalse($this->service->verify($this->service->generateSecret(), '000000'));
    }

    public function test_confirm_sets_secret_and_confirmed_at(): void
    {
        $user = User::factory()->create();
        $secret = $this->service->generateSecret();

        $this->service->confirm($user, $secret);

        $user->refresh();
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertSame($secret, $user->two_factor_secret);
    }

    public function test_confirm_persists_encrypted_secret_to_database(): void
    {
        $user = User::factory()->create();
        $secret = $this->service->generateSecret();

        $this->service->confirm($user, $secret);

        $this->assertSame($secret, User::findOrFail($user->id)->two_factor_secret);
    }
}
