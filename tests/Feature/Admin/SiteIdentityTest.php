<?php

namespace Tests\Feature\Admin;

use App\Models\SiteIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SiteIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create([
            'password' => Hash::make('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->admin->assignRole('admin');

        $this->withSession(['auth.two_factor_verified' => true]);
    }

    /**
     * The job title must stay a string that appears nowhere else in the
     * rendered page. Every `home.php` lang file sets fallback_subtitle to
     * "Lead Developer // Ingénieur Logiciel", which the home page shows
     * whenever HomepageContent is empty — as it is under RefreshDatabase. A
     * footer assertion on "Lead Developer" therefore passed with the footer
     * job title switched off entirely.
     *
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Laurent Bernard-Cardascia',
            'job_title' => 'Principal Engineer',
            'contact_email' => 'contact@cardascia-it.org',
            'github_url' => 'https://github.com/Sefalhik',
            'linkedin_url' => 'https://www.linkedin.com/in/laurent-bernard-cardascia-290bb48b/',
            'mastodon_url' => 'https://mastodon.social/@Sefalhik',
        ], $overrides);
    }

    // ── Access control ────────────────────────────────────────────────────────

    public function test_unauthenticated_user_is_redirected_from_edit(): void
    {
        $this->get('/fr/admin/identity')->assertRedirect();
    }

    public function test_non_admin_user_cannot_access_edit(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);

        $this->actingAs($user)
            ->withSession(['auth.two_factor_verified' => true])
            ->get('/fr/admin/identity')
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_update(): void
    {
        $this->put('/fr/admin/identity', $this->validPayload())->assertRedirect();

        $this->assertSame(0, SiteIdentity::count());
    }

    public function test_non_admin_user_cannot_update(): void
    {
        // Write access deserves its own test, not just read access: a routing
        // refactor could move the PUT out of the protected group unnoticed.
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);

        $this->actingAs($user)
            ->withSession(['auth.two_factor_verified' => true])
            ->put('/fr/admin/identity', $this->validPayload())
            ->assertForbidden();

        $this->assertSame(0, SiteIdentity::count());
    }

    public function test_admin_without_verified_two_factor_cannot_update(): void
    {
        $this->actingAs($this->admin)
            ->withSession(['auth.two_factor_verified' => false])
            ->put('/fr/admin/identity', $this->validPayload())
            ->assertRedirect();

        $this->assertSame(0, SiteIdentity::count());
    }

    public function test_admin_without_verified_two_factor_is_redirected(): void
    {
        $this->actingAs($this->admin)
            ->withSession(['auth.two_factor_verified' => false])
            ->get('/fr/admin/identity')
            ->assertRedirect();
    }

    public function test_admin_can_view_edit_form(): void
    {
        $this->actingAs($this->admin)
            ->get('/fr/admin/identity')
            ->assertOk()
            ->assertSee('name="github_url"', false);
    }

    public function test_edit_form_shows_the_saved_job_title(): void
    {
        // The E2E suite checks the field is visible and accepts input, which a
        // permanently blank field would also pass. Only this asserts the form
        // hands back what is stored.
        $identity = SiteIdentity::firstOrNew([]);
        $identity->fill(['job_title' => 'Tech Lead']);
        $identity->save();

        $this->actingAs($this->admin)
            ->get('/fr/admin/identity')
            ->assertOk()
            ->assertSee('value="Tech Lead"', false);
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    public function test_admin_can_save_identity(): void
    {
        $this->actingAs($this->admin)
            ->put('/fr/admin/identity', $this->validPayload())
            ->assertRedirect();

        $identity = SiteIdentity::first();

        $this->assertNotNull($identity);
        $this->assertSame('Laurent Bernard-Cardascia', $identity->full_name);
        $this->assertSame('contact@cardascia-it.org', $identity->contact_email);
        $this->assertSame('https://github.com/Sefalhik', $identity->github_url);
        $this->assertSame('Principal Engineer', $identity->job_title);
    }

    public function test_saving_twice_updates_the_same_row(): void
    {
        $this->actingAs($this->admin)->put('/fr/admin/identity', $this->validPayload());
        $this->actingAs($this->admin)->put('/fr/admin/identity', $this->validPayload([
            'full_name' => 'Someone Else',
        ]));

        $identity = SiteIdentity::first();

        $this->assertSame(1, SiteIdentity::count());
        $this->assertNotNull($identity);
        $this->assertSame('Someone Else', $identity->full_name);
    }

    public function test_all_fields_are_optional(): void
    {
        $this->actingAs($this->admin)
            ->put('/fr/admin/identity', [])
            ->assertSessionHasNoErrors();
    }


    public function test_invalid_email_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->put('/fr/admin/identity', $this->validPayload(['contact_email' => 'not-an-email']))
            ->assertSessionHasErrors('contact_email');
    }

    public function test_url_pointing_at_the_wrong_network_is_rejected(): void
    {
        // The mistake the domain constraint exists for.
        $this->actingAs($this->admin)
            ->put('/fr/admin/identity', $this->validPayload([
                'github_url' => 'https://www.linkedin.com/in/someone/',
            ]))
            ->assertSessionHasErrors('github_url');
    }

    public function test_mastodon_url_on_another_instance_is_accepted(): void
    {
        $this->actingAs($this->admin)
            ->put('/fr/admin/identity', $this->validPayload([
                'mastodon_url' => 'https://piaille.fr/@someone',
            ]))
            ->assertSessionHasNoErrors();
    }

    public function test_malformed_mastodon_url_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->put('/fr/admin/identity', $this->validPayload([
                'mastodon_url' => 'https://mastodon.social/Sefalhik',
            ]))
            ->assertSessionHasErrors('mastodon_url');
    }

    public function test_plain_http_url_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->put('/fr/admin/identity', $this->validPayload([
                'github_url' => 'http://github.com/Sefalhik',
            ]))
            ->assertSessionHasErrors('github_url');
    }

    public function test_network_home_page_is_not_accepted_as_a_profile(): void
    {
        foreach ([
            'github_url' => 'https://github.com/',
            'linkedin_url' => 'https://www.linkedin.com/feed/',
        ] as $field => $url) {
            $this->actingAs($this->admin)
                ->put('/fr/admin/identity', $this->validPayload([$field => $url]))
                ->assertSessionHasErrors($field);
        }
    }

    public function test_repository_url_is_not_accepted_as_a_profile(): void
    {
        $this->actingAs($this->admin)
            ->put('/fr/admin/identity', $this->validPayload([
                'github_url' => 'https://github.com/Sefalhik/lumina',
            ]))
            ->assertSessionHasErrors('github_url');
    }

    public function test_tracking_parameters_are_stripped_before_saving(): void
    {
        // LinkedIn appends ?trk=... when you copy a profile URL from its UI.
        $this->actingAs($this->admin)
            ->put('/fr/admin/identity', $this->validPayload([
                'linkedin_url' => 'https://www.linkedin.com/in/someone/?trk=public_profile&originalSubdomain=fr',
            ]))
            ->assertSessionHasNoErrors();

        $identity = SiteIdentity::first();

        $this->assertNotNull($identity);
        $this->assertSame('https://www.linkedin.com/in/someone/', $identity->linkedin_url);
    }

    public function test_surrounding_whitespace_is_trimmed_before_saving(): void
    {
        $this->actingAs($this->admin)
            ->put('/fr/admin/identity', $this->validPayload([
                'full_name' => '  Laurent Bernard-Cardascia  ',
                'github_url' => '  https://github.com/Sefalhik  ',
            ]))
            ->assertSessionHasNoErrors();

        $identity = SiteIdentity::first();

        $this->assertNotNull($identity);
        $this->assertSame('Laurent Bernard-Cardascia', $identity->full_name);
        $this->assertSame('https://github.com/Sefalhik', $identity->github_url);
    }

    // ── Length limits ─────────────────────────────────────────────────────────

    public function test_full_name_must_not_exceed_120_characters(): void
    {
        $this->actingAs($this->admin)
            ->put('/fr/admin/identity', $this->validPayload(['full_name' => str_repeat('a', 121)]))
            ->assertSessionHasErrors('full_name');
    }

    public function test_job_title_must_not_exceed_120_characters(): void
    {
        $this->actingAs($this->admin)
            ->put('/fr/admin/identity', $this->validPayload([
                'job_title' => str_repeat('a', 121),
            ]))
            ->assertSessionHasErrors('job_title');
    }

    public function test_job_title_posted_in_the_old_translated_shape_is_rejected(): void
    {
        // The form used to post job_title[fr] — an array. A browser holding the
        // previous page in cache would still submit that shape. The `string`
        // rule has to turn it into a validation error rather than let the
        // controller write an array into a varchar column.
        $this->actingAs($this->admin)
            ->put('/fr/admin/identity', $this->validPayload([
                'job_title' => ['fr' => 'Tech Lead'],
            ]))
            ->assertSessionHasErrors('job_title');

        $this->assertNull(SiteIdentity::first());
    }

    public function test_contact_email_must_not_exceed_180_characters(): void
    {
        $long = str_repeat('a', 170).'@example.test';

        $this->actingAs($this->admin)
            ->put('/fr/admin/identity', $this->validPayload(['contact_email' => $long]))
            ->assertSessionHasErrors('contact_email');
    }

    public function test_profile_url_must_not_exceed_255_characters(): void
    {
        // Valid host, valid scheme — only the length is wrong.
        $long = 'https://github.com/'.str_repeat('a', 250);

        $this->actingAs($this->admin)
            ->put('/fr/admin/identity', $this->validPayload(['github_url' => $long]))
            ->assertSessionHasErrors('github_url');
    }

    // ── Clearing a field ──────────────────────────────────────────────────────

    public function test_clearing_a_filled_field_removes_it_everywhere(): void
    {
        // A real workflow: fill a profile, change your mind, empty the field.
        $this->actingAs($this->admin)->put('/fr/admin/identity', $this->validPayload());

        $this->actingAs($this->admin)
            ->put('/fr/admin/identity', $this->validPayload(['mastodon_url' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull(SiteIdentity::first()?->mastodon_url);

        $this->get('/fr/')->assertOk()->assertDontSee('mastodon.social', false);
    }

    public function test_clearing_the_job_title_removes_it_from_the_footer(): void
    {
        $this->actingAs($this->admin)->put('/fr/admin/identity', $this->validPayload());
        $this->get('/fr/')->assertOk()->assertSee('Principal Engineer', false);

        $this->actingAs($this->admin)
            ->put('/fr/admin/identity', $this->validPayload(['job_title' => '']))
            ->assertSessionHasNoErrors();

        // job_title is absent from prepareForValidation, unlike full_name and
        // the profile URLs, so nothing in this codebase normalises an emptied
        // field. It still lands as null rather than '': Laravel's
        // ConvertEmptyStringsToNull middleware runs before validation. Asserted
        // because the column relies on framework behaviour, not on our own.
        $this->assertNull(SiteIdentity::first()?->job_title);

        $this->get('/fr/')->assertOk()->assertDontSee('Principal Engineer', false);
    }

    public function test_successful_update_flashes_a_confirmation(): void
    {
        $this->actingAs($this->admin)
            ->put('/fr/admin/identity', $this->validPayload())
            ->assertSessionHas('success');
    }

    // ── Footer rendering ──────────────────────────────────────────────────────

    public function test_footer_shows_filled_links(): void
    {
        $identity = SiteIdentity::firstOrNew([]);
        $identity->fill([
            'full_name' => 'Laurent Bernard-Cardascia',
            'job_title' => 'Principal Engineer',
            'contact_email' => 'contact@cardascia-it.org',
            'github_url' => 'https://github.com/Sefalhik',
            'mastodon_url' => 'https://mastodon.social/@Sefalhik',
        ]);
        $identity->save();

        $response = $this->get('/fr/')->assertOk();

        $response->assertSee('https://github.com/Sefalhik', false);
        $response->assertSee('https://mastodon.social/@Sefalhik', false);
        $response->assertSee('mailto:contact@cardascia-it.org', false);
        $response->assertSee('Laurent Bernard-Cardascia', false);
        $response->assertSee('Principal Engineer', false);

        // rel="me" is what makes Mastodon's verification work.
        $response->assertSee('rel="me noopener noreferrer"', false);
    }

    public function test_footer_omits_links_that_are_not_set(): void
    {
        $identity = SiteIdentity::firstOrNew([]);
        $identity->fill(['github_url' => 'https://github.com/Sefalhik']);
        $identity->save();

        $response = $this->get('/fr/')->assertOk();

        $response->assertSee('https://github.com/Sefalhik', false);
        $response->assertDontSee('linkedin.com', false);
        $response->assertDontSee('mailto:', false);
    }

    public function test_footer_renders_with_no_identity_at_all(): void
    {
        // The state of a fresh install, right after migrating.
        $this->assertSame(0, SiteIdentity::count());

        $this->get('/fr/')
            ->assertOk()
            ->assertDontSee('rel="me', false)
            ->assertDontSee('mailto:', false)
            ->assertSee('cardascia-it.org', false);
    }

    public function test_footer_appears_on_every_public_page(): void
    {
        $identity = SiteIdentity::firstOrNew([]);
        $identity->fill(['github_url' => 'https://github.com/Sefalhik']);
        $identity->save();

        foreach (['/fr/cv', '/en/projects', '/de/blog'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('https://github.com/Sefalhik', false);
        }
    }

    public function test_admin_supplied_name_is_escaped_in_the_footer(): void
    {
        // full_name is admin-editable and rendered on every page of the site.
        // Blade escapes by default; this locks that in, so switching to {!! !!}
        // can never silently open an injection sitewide.
        $identity = SiteIdentity::firstOrNew([]);
        $identity->fill(['full_name' => '<script>alert(1)</script>']);
        $identity->save();

        $response = $this->get('/fr/')->assertOk();

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;', false);
    }

    // ── Translation wiring ────────────────────────────────────────────────────

    public function test_site_identity_is_not_registered_for_cms_translation(): void
    {
        // job_title is the value a sameAs statement cross-references with the
        // GitHub, LinkedIn and Mastodon profiles, which each carry one
        // hand-typed title. Translating it would make the cross-reference hold
        // in `fr` only. Registering the model here again would silently undo
        // that on the next `cms:translate` run.
        $this->assertNotContains(SiteIdentity::class, config('i18n.cms_models'));
    }

    public function test_job_title_is_stored_as_a_plain_string(): void
    {
        // The other half of the same guard: re-adding HasTranslations would
        // turn the column back into a translations object without the config
        // entry above ever changing.
        $this->actingAs($this->admin)->put('/fr/admin/identity', $this->validPayload([
            'job_title' => 'Tech Lead',
        ]));

        $this->assertSame(
            'Tech Lead',
            DB::table('site_identities')->value('job_title'),
        );
    }
}
