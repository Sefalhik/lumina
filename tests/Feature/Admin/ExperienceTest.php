<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Experience;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExperienceTest extends TestCase
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
     * Employer and job title must be strings that appear nowhere else in the
     * rendered page. A realistic value is comfortable to read and dangerous to
     * assert on — see the fallback_subtitle collision documented in
     * SiteIdentityTest.
     *
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'employer' => 'Groupe Vantarel',
            'job_title' => 'Principal Engineer',
            'location' => 'Lille',
            'started_at' => '2024-04-01',
            'ended_at' => null,
            'description' => 'Direction technique de la plateforme.',
            'achievements' => 'Migration du socle applicatif.',
        ], $overrides);
    }

    /** @param array<string, mixed> $attributes */
    private function makeExperience(array $attributes = []): Experience
    {
        $experience = new Experience(array_merge([
            'employer' => 'Groupe Vantarel',
            'job_title' => 'Principal Engineer',
            'started_at' => '2024-04-01',
        ], $attributes));

        $experience->save();

        return $experience;
    }

    // ── Access control ────────────────────────────────────────────────────────

    public function test_unauthenticated_user_is_redirected_from_the_list(): void
    {
        $this->get('/fr/admin/experiences')->assertRedirect();
    }

    public function test_non_admin_user_cannot_reach_the_list(): void
    {
        $intruder = User::factory()->create(['password' => Hash::make('secret')]);

        $this->actingAs($intruder)->get('/fr/admin/experiences')->assertForbidden();
    }

    public function test_admin_without_verified_two_factor_is_redirected(): void
    {
        $this->flushSession();

        $this->actingAs($this->admin)
            ->get('/fr/admin/experiences')
            ->assertRedirect();
    }

    public function test_unauthenticated_user_cannot_create(): void
    {
        $this->post('/fr/admin/experiences', $this->validPayload())->assertRedirect();

        $this->assertSame(0, Experience::count());
    }

    public function test_unauthenticated_user_cannot_delete(): void
    {
        $experience = $this->makeExperience();

        $this->delete('/fr/admin/experiences/'.$experience->id)->assertRedirect();

        $this->assertSame(1, Experience::count());
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    public function test_admin_can_create_an_experience(): void
    {
        $this->actingAs($this->admin)
            ->post('/fr/admin/experiences', $this->validPayload())
            ->assertRedirect();

        $experience = Experience::first();

        $this->assertNotNull($experience);
        $this->assertSame('Groupe Vantarel', $experience->employer);
        $this->assertSame('Principal Engineer', $experience->job_title);
        $this->assertSame('Lille', $experience->location);
        $this->assertSame('2024-04-01', $experience->started_at->format('Y-m-d'));
        $this->assertSame(
            'Direction technique de la plateforme.',
            $experience->getTranslation('description', 'fr'),
        );
    }

    public function test_admin_can_update_an_experience(): void
    {
        $experience = $this->makeExperience();

        $this->actingAs($this->admin)
            ->put('/fr/admin/experiences/'.$experience->id, $this->validPayload([
                'job_title' => 'Staff Engineer',
            ]))
            ->assertRedirect();

        $this->assertSame('Staff Engineer', Experience::firstOrFail()->job_title);
        $this->assertSame(1, Experience::count());
    }

    public function test_admin_can_delete_an_experience(): void
    {
        $experience = $this->makeExperience();

        $this->actingAs($this->admin)
            ->delete('/fr/admin/experiences/'.$experience->id)
            ->assertRedirect();

        $this->assertSame(0, Experience::count());
    }

    public function test_editing_the_french_prose_keeps_other_locales(): void
    {
        $experience = $this->makeExperience();
        $experience->setTranslation('description', 'fr', 'Version française.');
        $experience->setTranslation('description', 'de', 'Deutsche Fassung.');
        $experience->save();

        $this->actingAs($this->admin)->put('/fr/admin/experiences/'.$experience->id, $this->validPayload([
            'description' => 'Version française révisée.',
        ]));

        $fresh = Experience::firstOrFail();

        $this->assertSame('Version française révisée.', $fresh->getTranslation('description', 'fr'));
        $this->assertSame('Deutsche Fassung.', $fresh->getTranslation('description', 'de', false));
    }

    public function test_the_edit_form_shows_the_saved_values(): void
    {
        $experience = $this->makeExperience(['employer' => 'Groupe Vantarel']);
        $experience->setTranslation('description', 'fr', 'Direction technique.');
        $experience->save();

        $this->actingAs($this->admin)
            ->get('/fr/admin/experiences/'.$experience->id.'/edit')
            ->assertOk()
            ->assertSee('value="Groupe Vantarel"', false)
            ->assertSee('Direction technique.', false);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function test_employer_is_required(): void
    {
        $this->actingAs($this->admin)
            ->post('/fr/admin/experiences', $this->validPayload(['employer' => '']))
            ->assertSessionHasErrors('employer');

        $this->assertSame(0, Experience::count());
    }

    public function test_job_title_is_required(): void
    {
        $this->actingAs($this->admin)
            ->post('/fr/admin/experiences', $this->validPayload(['job_title' => '']))
            ->assertSessionHasErrors('job_title');
    }

    public function test_start_date_is_required(): void
    {
        $this->actingAs($this->admin)
            ->post('/fr/admin/experiences', $this->validPayload(['started_at' => '']))
            ->assertSessionHasErrors('started_at');
    }

    public function test_end_date_cannot_precede_the_start_date(): void
    {
        $this->actingAs($this->admin)
            ->post('/fr/admin/experiences', $this->validPayload([
                'started_at' => '2024-04-01',
                'ended_at' => '2023-01-01',
            ]))
            ->assertSessionHasErrors('ended_at');
    }

    public function test_an_empty_end_date_is_accepted(): void
    {
        $this->actingAs($this->admin)
            ->post('/fr/admin/experiences', $this->validPayload(['ended_at' => '']))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Experience::firstOrFail()->isCurrent());
    }

    // ── "Still there" semantics ───────────────────────────────────────────────

    public function test_is_current_is_true_without_an_end_date(): void
    {
        $this->assertTrue($this->makeExperience(['ended_at' => null])->isCurrent());
    }

    public function test_is_current_is_false_with_an_end_date(): void
    {
        $this->assertFalse($this->makeExperience(['ended_at' => '2026-01-31'])->isCurrent());
    }

    public function test_current_scope_returns_only_open_ended_positions(): void
    {
        $this->makeExperience(['employer' => 'Terminé', 'ended_at' => '2024-03-31']);
        $this->makeExperience(['employer' => 'En cours', 'ended_at' => null]);

        $current = Experience::current()->get();

        $this->assertCount(1, $current);
        $this->assertSame('En cours', $current->first()?->employer);
    }

    // ── Public page ───────────────────────────────────────────────────────────

    public function test_cv_page_lists_the_experiences(): void
    {
        $experience = $this->makeExperience();
        $experience->setTranslation('description', 'fr', 'Direction technique de la plateforme.');
        $experience->save();

        $this->get('/fr/cv')
            ->assertOk()
            ->assertSee('Groupe Vantarel', false)
            ->assertSee('Principal Engineer', false)
            ->assertSee('Direction technique de la plateforme.', false);
    }

    public function test_cv_page_orders_experiences_most_recent_first(): void
    {
        $this->makeExperience(['employer' => 'Ancien Employeur', 'started_at' => '2015-01-01', 'ended_at' => '2019-01-01']);
        $this->makeExperience(['employer' => 'Groupe Vantarel', 'started_at' => '2024-04-01']);

        $body = $this->get('/fr/cv')->assertOk()->getContent();

        $this->assertIsString($body);
        $this->assertLessThan(
            strpos($body, 'Ancien Employeur'),
            strpos($body, 'Groupe Vantarel'),
            'The most recent position must be rendered first.',
        );
    }

    public function test_cv_page_marks_a_current_position(): void
    {
        $this->makeExperience(['ended_at' => null]);

        $this->get('/fr/cv')
            ->assertOk()
            ->assertSee(__('cv.current_badge'), false)
            ->assertSee(__('cv.period_present'), false);
    }

    public function test_cv_page_does_not_mark_a_finished_position(): void
    {
        $this->makeExperience(['ended_at' => '2024-03-31']);

        $this->get('/fr/cv')
            ->assertOk()
            ->assertDontSee(__('cv.current_badge'), false)
            ->assertDontSee(__('cv.period_present'), false);
    }

    public function test_cv_page_renders_with_no_experience_at_all(): void
    {
        $this->get('/fr/cv')
            ->assertOk()
            ->assertSee(__('cv.empty'), false);
    }

    public function test_cv_page_is_public(): void
    {
        // No acting-as: the whole point of the page is that it is reachable.
        $this->makeExperience();

        $this->get('/fr/cv')->assertOk();
    }

    // ── Other locales ─────────────────────────────────────────────────────────

    public function test_cv_page_is_served_in_a_non_french_locale(): void
    {
        $this->makeExperience();

        $this->get('/en/cv')
            ->assertOk()
            ->assertSee('Groupe Vantarel', false)
            ->assertSee('Principal Engineer', false);
    }

    public function test_untranslated_prose_falls_back_to_french(): void
    {
        // The page is served in 24 locales but cms:translate has only ever been
        // run on some of them. Falling back beats rendering an experience with
        // no description at all — and nothing else pins this behaviour down, so
        // publishing config/translatable.php with different settings would empty
        // the CV in 23 locales silently.
        $experience = $this->makeExperience();
        $experience->setTranslation('description', 'fr', 'Texte disponible en français seulement.');
        $experience->save();

        $this->get('/en/cv')
            ->assertOk()
            ->assertSee('Texte disponible en français seulement.', false);
    }

    // ── Ordering edge case ────────────────────────────────────────────────────

    public function test_experiences_starting_the_same_month_keep_a_stable_order(): void
    {
        // Dates alone do not define a total order. Without the id tie-breaker in
        // scopeMostRecentFirst() the two would come back in whatever order the
        // database felt like, and the page would shuffle between requests.
        $first = $this->makeExperience(['employer' => 'Premier Saisi', 'started_at' => '2024-04-01']);
        $second = $this->makeExperience(['employer' => 'Second Saisi', 'started_at' => '2024-04-01']);

        $this->assertLessThan($second->id, $first->id);

        $body = $this->get('/fr/cv')->assertOk()->getContent();

        $this->assertIsString($body);
        $this->assertLessThan(
            strpos($body, 'Premier Saisi'),
            strpos($body, 'Second Saisi'),
            'With equal start dates the most recently created must come first.',
        );
    }

    // ── Translation wiring ────────────────────────────────────────────────────

    public function test_job_title_is_not_translatable(): void
    {
        // Same reasoning as SiteIdentity: the job title is what cross-references
        // this page with the LinkedIn profile, which carries one hand-typed
        // title. Translating it would make the match hold in `fr` alone.
        $this->assertNotContains('job_title', (new Experience)->translatable);
    }

    public function test_employer_and_location_are_not_translatable(): void
    {
        $translatable = (new Experience)->translatable;

        $this->assertNotContains('employer', $translatable);
        $this->assertNotContains('location', $translatable);
    }

    public function test_prose_fields_are_translatable(): void
    {
        $translatable = (new Experience)->translatable;

        $this->assertContains('description', $translatable);
        $this->assertContains('achievements', $translatable);
    }

    // ── Access control on the mutating routes ─────────────────────────────────
    //
    // The list is a read. These are the routes that write and destroy, and they
    // are the ones worth proving: a middleware chain that protects the index but
    // not the delete would look identical from the outside.

    public function test_non_admin_user_cannot_create(): void
    {
        $intruder = User::factory()->create(['password' => Hash::make('secret')]);

        $this->actingAs($intruder)
            ->post('/fr/admin/experiences', $this->validPayload())
            ->assertForbidden();

        $this->assertSame(0, Experience::count());
    }

    public function test_non_admin_user_cannot_update(): void
    {
        $experience = $this->makeExperience();
        $intruder = User::factory()->create(['password' => Hash::make('secret')]);

        $this->actingAs($intruder)
            ->put('/fr/admin/experiences/'.$experience->id, $this->validPayload(['job_title' => 'Pirate']))
            ->assertForbidden();

        $this->assertSame('Principal Engineer', Experience::firstOrFail()->job_title);
    }

    public function test_non_admin_user_cannot_delete(): void
    {
        $experience = $this->makeExperience();
        $intruder = User::factory()->create(['password' => Hash::make('secret')]);

        $this->actingAs($intruder)
            ->delete('/fr/admin/experiences/'.$experience->id)
            ->assertForbidden();

        $this->assertSame(1, Experience::count());
    }

    public function test_admin_without_verified_two_factor_cannot_create(): void
    {
        $this->flushSession();

        $this->actingAs($this->admin)
            ->post('/fr/admin/experiences', $this->validPayload())
            ->assertRedirect();

        $this->assertSame(0, Experience::count());
    }

    public function test_admin_without_verified_two_factor_cannot_update(): void
    {
        $experience = $this->makeExperience();
        $this->flushSession();

        $this->actingAs($this->admin)
            ->put('/fr/admin/experiences/'.$experience->id, $this->validPayload(['job_title' => 'Pirate']))
            ->assertRedirect();

        $this->assertSame('Principal Engineer', Experience::firstOrFail()->job_title);
    }

    public function test_admin_without_verified_two_factor_cannot_delete(): void
    {
        $experience = $this->makeExperience();
        $this->flushSession();

        $this->actingAs($this->admin)
            ->delete('/fr/admin/experiences/'.$experience->id)
            ->assertRedirect();

        $this->assertSame(1, Experience::count());
    }

    public function test_unauthenticated_user_cannot_update(): void
    {
        $experience = $this->makeExperience();

        $this->put('/fr/admin/experiences/'.$experience->id, $this->validPayload(['job_title' => 'Pirate']))
            ->assertRedirect();

        $this->assertSame('Principal Engineer', Experience::firstOrFail()->job_title);
    }

    public function test_unauthenticated_user_cannot_reach_the_create_form(): void
    {
        $this->get('/fr/admin/experiences/create')->assertRedirect();
    }

    public function test_non_admin_user_cannot_reach_the_edit_form(): void
    {
        $experience = $this->makeExperience();
        $intruder = User::factory()->create(['password' => Hash::make('secret')]);

        $this->actingAs($intruder)
            ->get('/fr/admin/experiences/'.$experience->id.'/edit')
            ->assertForbidden();
    }

    // ── Admin screens ─────────────────────────────────────────────────────────

    public function test_the_list_shows_the_saved_experiences(): void
    {
        $this->makeExperience(['employer' => 'Groupe Vantarel']);

        $this->actingAs($this->admin)
            ->get('/fr/admin/experiences')
            ->assertOk()
            ->assertSee('Groupe Vantarel', false);
    }

    public function test_the_create_form_renders(): void
    {
        $this->actingAs($this->admin)
            ->get('/fr/admin/experiences/create')
            ->assertOk()
            ->assertSee('name="employer"', false)
            ->assertSee('name="started_at"', false);
    }

    // ── Escaping ──────────────────────────────────────────────────────────────

    public function test_admin_supplied_values_are_escaped_on_the_cv_page(): void
    {
        // Every field on this page is admin-supplied. Blade escapes by default,
        // but a future switch to {!! !!} for rich text would be silent without
        // this.
        $experience = $this->makeExperience(['employer' => '<script>alert(1)</script>']);
        $experience->setTranslation('description', 'fr', '<script>alert(2)</script>');
        $experience->save();

        $response = $this->get('/fr/cv')->assertOk();

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertDontSee('<script>alert(2)</script>', false);
        $response->assertSee('&lt;script&gt;', false);
    }

    public function test_admin_supplied_values_are_escaped_in_the_admin_list(): void
    {
        $this->makeExperience(['job_title' => '<script>alert(3)</script>']);

        $this->actingAs($this->admin)
            ->get('/fr/admin/experiences')
            ->assertOk()
            ->assertDontSee('<script>alert(3)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    // ── Length limits ─────────────────────────────────────────────────────────

    public function test_employer_must_not_exceed_120_characters(): void
    {
        $this->actingAs($this->admin)
            ->post('/fr/admin/experiences', $this->validPayload(['employer' => str_repeat('a', 121)]))
            ->assertSessionHasErrors('employer');
    }

    public function test_job_title_must_not_exceed_120_characters(): void
    {
        $this->actingAs($this->admin)
            ->post('/fr/admin/experiences', $this->validPayload(['job_title' => str_repeat('a', 121)]))
            ->assertSessionHasErrors('job_title');
    }

    public function test_description_must_not_exceed_2000_characters(): void
    {
        $this->actingAs($this->admin)
            ->post('/fr/admin/experiences', $this->validPayload(['description' => str_repeat('a', 2001)]))
            ->assertSessionHasErrors('description');
    }

    // ── Optional fields ───────────────────────────────────────────────────────

    public function test_achievements_are_saved_and_displayed(): void
    {
        $this->actingAs($this->admin)->post('/fr/admin/experiences', $this->validPayload([
            'achievements' => 'Refonte du socle applicatif.',
        ]));

        $this->assertSame(
            'Refonte du socle applicatif.',
            Experience::firstOrFail()->getTranslation('achievements', 'fr'),
        );

        $this->get('/fr/cv')->assertOk()->assertSee('Refonte du socle applicatif.', false);
    }

    public function test_location_is_optional(): void
    {
        $this->actingAs($this->admin)
            ->post('/fr/admin/experiences', $this->validPayload(['location' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull(Experience::firstOrFail()->location);
    }
}
