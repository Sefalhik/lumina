<?php

namespace Tests\Unit\Services\Auth;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Exceptions\IncompatibleWithGoogleAuthenticatorException;
use PragmaRX\Google2FA\Exceptions\InvalidCharactersException;
use PragmaRX\Google2FA\Exceptions\SecretKeyTooShortException;
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

        // An absolute moment, 10 s into a 30-second step: the service reads Carbon, so every code
        // below is decided by this date and never by the moment the suite happens to run.
        $this->travelTo(Carbon::parse('2026-10-23 12:00:10', 'UTC'));
    }

    /** The current 30-second step, offset by $offset steps. */
    private function step(int $offset = 0): int
    {
        return intdiv(Carbon::now()->getTimestamp(), 30) + $offset;
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

    // --- verify(): which code is accepted, computed only (LUMN-91) ---

    private function code(string $secret, int $offset): string
    {
        return $this->google2fa->oathTotp($secret, $this->step($offset));
    }

    private function userWithLastStep(?int $step): User
    {
        return User::factory()->create(['two_factor_last_used_timestep' => $step]);
    }

    public function test_a_first_code_is_accepted_and_its_step_returned(): void
    {
        $secret = $this->service->generateSecret();

        $this->assertSame($this->step(), $this->service->verify($this->userWithLastStep(null), $secret, $this->code($secret, 0)));
    }

    /** @return array<string, array{int, bool}> */
    public static function windowEdges(): array
    {
        return [
            'two steps early' => [-2, false],
            'one step early' => [-1, true],
            'current step' => [0, true],
            'one step late' => [1, true],
            'two steps late' => [2, false],
        ];
    }

    #[DataProvider('windowEdges')]
    public function test_the_window_accepts_one_step_either_side_and_no_more(int $offset, bool $accepted): void
    {
        $secret = $this->service->generateSecret();

        $this->assertSame(
            $accepted ? $this->step($offset) : null,
            $this->service->verify($this->userWithLastStep(null), $secret, $this->code($secret, $offset)),
        );
    }

    public function test_the_step_comes_from_the_application_clock(): void
    {
        // Far from the real time: a service reading Google2FA's own microtime() would refuse it.
        $this->travelTo(Carbon::parse('2020-01-01 00:00:10', 'UTC'));
        $secret = $this->service->generateSecret();

        $this->assertSame($this->step(), $this->service->verify($this->userWithLastStep(null), $secret, $this->code($secret, 0)));
    }

    public function test_a_wrong_code_is_refused_and_not_logged(): void
    {
        $log = Log::spy();

        $this->assertNull($this->service->verify($this->userWithLastStep(null), $this->service->generateSecret(), '000000'));

        $log->shouldNotHaveReceived('warning');
        $log->shouldNotHaveReceived('error');
    }

    public function test_a_code_already_accepted_is_refused_within_its_own_window(): void
    {
        $secret = $this->service->generateSecret();
        $log = Log::spy();

        $this->assertNull($this->service->verify($this->userWithLastStep($this->step()), $secret, $this->code($secret, 0)));

        $log->shouldHaveReceived('warning')->with(
            'Two-factor code refused — already used',
            Mockery::on(fn (array $context): bool => $context['service'] === TwoFactorService::class
                && $context['method'] === 'verify'
                && $context['step'] === 'code_replayed'
                && is_int($context['user_id'])),
        )->once();
    }

    public function test_a_code_older_than_the_last_accepted_is_refused(): void
    {
        $secret = $this->service->generateSecret();

        $this->assertNull($this->service->verify($this->userWithLastStep($this->step()), $secret, $this->code($secret, -1)));
    }

    public function test_a_code_newer_than_the_last_accepted_is_accepted(): void
    {
        $secret = $this->service->generateSecret();

        $this->assertSame($this->step(), $this->service->verify($this->userWithLastStep($this->step(-1)), $secret, $this->code($secret, 0)));
    }

    public function test_a_code_outside_the_window_is_refused_as_wrong_not_as_replayed(): void
    {
        $secret = $this->service->generateSecret();
        $log = Log::spy();

        $this->assertNull($this->service->verify($this->userWithLastStep(null), $secret, $this->code($secret, -5)));

        $log->shouldNotHaveReceived('warning');
    }

    public function test_verifying_writes_nothing_and_reads_nothing(): void
    {
        $secret = $this->service->generateSecret();
        $user = $this->userWithLastStep(null);
        DB::enableQueryLog();

        $this->service->verify($user, $secret, $this->code($secret, 0));

        $this->assertSame([], DB::getQueryLog());
    }

    // --- The improbable: a last step in the future, a secret nobody can read ---

    public function test_a_last_step_at_the_edge_of_the_window_is_not_an_anomaly(): void
    {
        // One step ahead is the furthest a legitimate acceptance can record (clock drift). Its code
        // typed again is a replay — a warning — not a step "in the future".
        $secret = $this->service->generateSecret();
        $log = Log::spy();

        $this->assertNull($this->service->verify($this->userWithLastStep($this->step(1)), $secret, $this->code($secret, 1)));

        $log->shouldNotHaveReceived('error');
        $log->shouldHaveReceived('warning')->with('Two-factor code refused — already used', Mockery::any())->once();
    }

    public function test_a_last_step_beyond_the_window_refuses_every_code_and_logs_an_error(): void
    {
        $secret = $this->service->generateSecret();
        $user = $this->userWithLastStep($this->step(2));
        $log = Log::spy();

        foreach ([-1, 0, 1] as $offset) {
            $this->assertNull($this->service->verify($user, $secret, $this->code($secret, $offset)));
        }

        // An error, and only that: the codes are not replays, whatever their step.
        $log->shouldNotHaveReceived('warning');
        $log->shouldHaveReceived('error')->with(
            'Two-factor last used step is in the future — code refused',
            Mockery::on(fn (array $context): bool => $context['service'] === TwoFactorService::class
                && $context['method'] === 'verify'
                && $context['step'] === 'timestep_in_future'
                && $context['user_id'] === $user->getKey()
                && $context['last_used_timestep'] === $this->step(2)
                && $context['current_timestep'] === $this->step()),
        )->times(3);
    }

    public function test_codes_pass_again_once_time_has_caught_up_with_a_future_step(): void
    {
        $secret = $this->service->generateSecret();
        $user = $this->userWithLastStep($this->step(2));

        $this->travel(90)->seconds();

        // The old "future" step is now the previous one: the current code is newer, and passes.
        $this->assertSame($this->step(), $this->service->verify($user, $secret, $this->code($secret, 0)));
    }

    /** @return array<string, array{string, class-string<\Throwable>}> */
    public static function unreadableSecrets(): array
    {
        return [
            'characters outside base32' => ['not base32!', InvalidCharactersException::class],
            'too short' => ['ABCDEFG', SecretKeyTooShortException::class],
            'empty' => ['', SecretKeyTooShortException::class],
            'a length no authenticator takes' => ['ABCDEFGHIJKLMNO', IncompatibleWithGoogleAuthenticatorException::class],
        ];
    }

    /** @param  class-string<\Throwable>  $exception */
    #[DataProvider('unreadableSecrets')]
    public function test_a_secret_google2fa_cannot_read_refuses_the_code_without_throwing(string $secret, string $exception): void
    {
        $user = $this->userWithLastStep(null);
        $log = Log::spy();

        $this->assertNull($this->service->verify($user, $secret, '123456'));

        $log->shouldHaveReceived('error')->with(
            'Two-factor secret unreadable — code refused',
            Mockery::on(fn (array $context): bool => $context['service'] === TwoFactorService::class
                && $context['method'] === 'verify'
                && $context['step'] === 'secret_unreadable'
                && $context['user_id'] === $user->getKey()
                && $context['exception'] instanceof $exception),
        )->once();
    }

    public function test_the_stored_secret_is_the_one_verified(): void
    {
        $secret = $this->service->generateSecret();
        $user = User::factory()->create(['two_factor_secret' => $secret]);

        $this->assertSame($this->step(), $this->service->verifyStored($user, $this->code($secret, 0)));
        $this->assertNull($this->service->verifyStored($user, $this->code($this->service->generateSecret(), 0)));
    }

    public function test_a_stored_secret_that_cannot_be_decrypted_refuses_the_code_without_throwing(): void
    {
        // As after APP_KEY was rotated without re-encrypting the column.
        $user = User::factory()->create();
        $user->setRawAttributes(['id' => $user->id, 'two_factor_secret' => 'not a ciphertext']);
        $log = Log::spy();

        $this->assertNull($this->service->verifyStored($user, '123456'));

        $log->shouldHaveReceived('error')->with(
            'Two-factor secret unreadable — code refused',
            Mockery::on(fn (array $context): bool => $context['method'] === 'verifyStored'
                && $context['step'] === 'secret_unreadable'
                && $context['exception'] instanceof DecryptException),
        )->once();
    }

    public function test_a_missing_stored_secret_refuses_the_code_and_logs_no_exception(): void
    {
        $user = User::factory()->create(['two_factor_secret' => null]);
        $log = Log::spy();

        $this->assertNull($this->service->verifyStored($user, '123456'));

        $log->shouldHaveReceived('error')->with(
            'Two-factor secret unreadable — code refused',
            Mockery::on(fn (array $context): bool => $context['method'] === 'verifyStored'
                && $context['step'] === 'secret_unreadable'
                && ! array_key_exists('exception', $context)),
        )->once();
    }

    // --- claim(): the database decides, in one statement ---

    public function test_a_first_claim_records_the_step(): void
    {
        $user = $this->userWithLastStep(null);

        $this->assertTrue($this->service->claim($user, 1000));

        $this->assertSame(1000, User::findOrFail($user->id)->two_factor_last_used_timestep);
    }

    public function test_a_newer_step_is_claimed_over_an_older_one(): void
    {
        $user = $this->userWithLastStep(999);

        $this->assertTrue($this->service->claim($user, 1000));

        $this->assertSame(1000, User::findOrFail($user->id)->two_factor_last_used_timestep);
    }

    public function test_the_same_step_cannot_be_claimed_twice(): void
    {
        $user = $this->userWithLastStep(1000);
        $log = Log::spy();

        $this->assertFalse($this->service->claim($user, 1000));

        $this->assertSame(1000, User::findOrFail($user->id)->two_factor_last_used_timestep);
        $log->shouldHaveReceived('warning')->once()->with(
            'Two-factor code refused — already used',
            Mockery::on(fn (array $context): bool => $context['method'] === 'claim' && $context['step'] === 'code_replayed'),
        );
    }

    public function test_an_older_step_cannot_be_claimed_over_a_newer_one(): void
    {
        $user = $this->userWithLastStep(1000);

        $this->assertFalse($this->service->claim($user, 999));

        $this->assertSame(1000, User::findOrFail($user->id)->two_factor_last_used_timestep);
    }

    public function test_two_requests_holding_the_same_code_cannot_both_claim_it(): void
    {
        // Both requests loaded the user before either claimed: each model still says "no code".
        $id = $this->userWithLastStep(null)->id;
        $first = User::findOrFail($id);
        $second = User::findOrFail($id);

        $this->assertTrue($this->service->claim($first, 1000));
        $this->assertFalse($this->service->claim($second, 1000));
    }

    public function test_a_claim_is_one_statement_that_leaves_updated_at_alone(): void
    {
        $user = $this->userWithLastStep(null);
        $updatedAt = User::findOrFail($user->id)->updated_at;
        $this->travel(1)->hours();
        DB::enableQueryLog();

        $this->service->claim($user, 1000);

        $queries = DB::getQueryLog();
        $this->assertCount(1, $queries);
        $this->assertStringStartsWith('update "users"', $queries[0]['query']);
        $this->assertEquals($updatedAt, User::findOrFail($user->id)->updated_at);
    }

    public function test_a_claim_keeps_the_model_in_step_without_making_it_dirty(): void
    {
        $user = $this->userWithLastStep(null);

        $this->service->claim($user, 1000);

        $this->assertSame(1000, $user->two_factor_last_used_timestep);
        $this->assertFalse($user->isDirty('two_factor_last_used_timestep'));
    }

    public function test_a_claim_touches_no_other_user(): void
    {
        $user = $this->userWithLastStep(null);
        $other = $this->userWithLastStep(null);

        $this->service->claim($user, 1000);

        $this->assertNull(User::findOrFail($other->id)->two_factor_last_used_timestep);
    }

    // --- confirm() ---

    public function test_confirm_sets_secret_confirmed_at_and_the_step_of_the_confirming_code(): void
    {
        $user = User::factory()->create();
        $secret = $this->service->generateSecret();

        $this->service->confirm($user, $secret, 1000);

        $user->refresh();
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertSame($secret, $user->two_factor_secret);
        $this->assertSame(1000, $user->two_factor_last_used_timestep);
    }

    public function test_confirm_is_logged(): void
    {
        $user = User::factory()->create();
        $log = Log::spy();

        $this->service->confirm($user, $this->service->generateSecret(), 1000);

        $log->shouldHaveReceived('info')->with(
            'Two-factor authentication confirmed and persisted',
            Mockery::on(fn (array $context): bool => $context === [
                'service' => TwoFactorService::class,
                'method' => 'confirm',
                'step' => 'confirmed',
            ]),
        )->once();
    }

    public function test_confirm_writes_its_three_columns_in_one_statement(): void
    {
        $user = User::factory()->create();
        DB::enableQueryLog();

        $this->service->confirm($user, $this->service->generateSecret(), 1000);

        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_confirm_stores_the_secret_encrypted(): void
    {
        $user = User::factory()->create();
        $secret = $this->service->generateSecret();

        $this->service->confirm($user, $secret, 1000);

        $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertSame($secret, User::findOrFail($user->id)->two_factor_secret);
    }
}
