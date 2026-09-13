<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Experience;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->get('/fr/admin/experiences')->assertRedirect('/fr/login');
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
            ->assertRedirect('/fr/two-factor/challenge');
    }

    public function test_unauthenticated_user_cannot_create(): void
    {
        $this->post('/fr/admin/experiences', $this->validPayload())->assertRedirect('/fr/login');

        $this->assertSame(0, Experience::count());
    }

    public function test_unauthenticated_user_cannot_delete(): void
    {
        $experience = $this->makeExperience();

        $this->delete('/fr/admin/experiences/'.$experience->id)->assertRedirect('/fr/login');

        $this->assertSame(1, Experience::count());
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    public function test_admin_can_create_an_experience(): void
    {
        $this->actingAs($this->admin)
            ->post('/fr/admin/experiences', $this->validPayload())
            ->assertRedirect('/fr/admin/experiences')
            ->assertSessionHas('success');

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
            ->assertRedirect('/fr/admin/experiences')
            ->assertSessionHas('success');

        $this->assertSame('Staff Engineer', Experience::firstOrFail()->job_title);
        $this->assertSame(1, Experience::count());
    }

    public function test_admin_can_delete_an_experience(): void
    {
        $experience = $this->makeExperience();

        $this->actingAs($this->admin)
            ->delete('/fr/admin/experiences/'.$experience->id)
            ->assertRedirect('/fr/admin/experiences')
            ->assertSessionHas('success');

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

    // ── Query behaviour ───────────────────────────────────────────────────────

    /** Counts the queries a single request to the CV page issues. */
    private function queriesForCvPage(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get('/fr/cv')->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_cv_page_query_count_does_not_grow_with_the_rows(): void
    {
        // Deliberately an invariance check rather than a fixed number: LUMN-19
        // adds a second section to this page and will legitimately add a query.
        // Pinning an exact count would break then and teach nothing. What must
        // never change is that the count is independent of how many records
        // exist — which is the definition of the N+1 this guards against.
        $this->makeExperience(['employer' => 'Unique']);
        $withOne = $this->queriesForCvPage();

        for ($i = 0; $i < 20; $i++) {
            $this->makeExperience([
                'employer' => 'Employeur '.$i,
                'started_at' => sprintf('%04d-01-01', 2000 + $i),
            ]);
        }

        $this->assertSame(21, Experience::count());
        $this->assertSame(
            $withOne,
            $this->queriesForCvPage(),
            'Rendering twenty-one positions must cost the same number of queries as one.',
        );
    }

    // ── Routing edges ─────────────────────────────────────────────────────────

    public function test_an_unknown_experience_yields_a_not_found(): void
    {
        $this->actingAs($this->admin)
            ->get('/fr/admin/experiences/999999/edit')
            ->assertNotFound();
    }

    public function test_deleting_an_unknown_experience_yields_a_not_found(): void
    {
        $this->actingAs($this->admin)
            ->delete('/fr/admin/experiences/999999')
            ->assertNotFound();
    }

    public function test_the_redirect_keeps_the_submitting_locale(): void
    {
        // The controller builds its redirect from app()->getLocale(). Submitting
        // from /en must come back to /en, or an admin working in one language is
        // thrown into another on every save.
        $this->actingAs($this->admin)
            ->post('/en/admin/experiences', $this->validPayload())
            ->assertRedirect('/en/admin/experiences');
    }

    // ── Payload shape ─────────────────────────────────────────────────────────

    public function test_an_array_is_rejected_where_a_string_is_expected(): void
    {
        // The `string` rule is the only thing between a crafted array and a
        // varchar column. It looks decorative until someone changes the form.
        $this->actingAs($this->admin)
            ->post('/fr/admin/experiences', $this->validPayload(['employer' => ['fr' => 'X']]))
            ->assertSessionHasErrors('employer');

        $this->assertSame(0, Experience::count());
    }

    public function test_an_array_is_rejected_for_the_prose_fields(): void
    {
        // Prose is translated, so `description[fr]` is a plausible thing for a
        // stale page or a hand-written request to send. The form posts a plain
        // string and the controller owns the locale.
        $this->actingAs($this->admin)
            ->post('/fr/admin/experiences', $this->validPayload(['description' => ['fr' => 'X']]))
            ->assertSessionHasErrors('description');
    }

    public function test_relative_date_strings_are_rejected(): void
    {
        foreach (['now', '+1 day', 'next tuesday'] as $value) {
            $this->actingAs($this->admin)
                ->post('/fr/admin/experiences', $this->validPayload(['started_at' => $value]))
                ->assertSessionHasErrors('started_at');
        }

        $this->assertSame(0, Experience::count());
    }

    // ── Partial payloads ──────────────────────────────────────────────────────

    public function test_clearing_the_prose_from_the_form_empties_it(): void
    {
        $experience = $this->makeExperience();
        $experience->setTranslation('description', 'fr', 'Texte à effacer.');
        $experience->save();

        $this->actingAs($this->admin)->put('/fr/admin/experiences/'.$experience->id, $this->validPayload([
            'description' => '',
        ]));

        $this->assertSame('', Experience::firstOrFail()->getTranslation('description', 'fr', false));
    }

    public function test_a_payload_omitting_the_prose_leaves_it_untouched(): void
    {
        // An emptied textarea and an absent field are different intentions.
        // Conflating them would let a partial request wipe the French prose
        // while the machine translations of every other locale survive — a
        // state no screen can produce, and none can repair.
        $experience = $this->makeExperience();
        $experience->setTranslation('description', 'fr', 'Texte à conserver.');
        $experience->setTranslation('description', 'de', 'Deutscher Text.');
        $experience->save();

        $payload = $this->validPayload();
        unset($payload['description'], $payload['achievements']);

        $this->actingAs($this->admin)
            ->put('/fr/admin/experiences/'.$experience->id, $payload)
            ->assertSessionHasNoErrors();

        $fresh = Experience::firstOrFail();

        $this->assertSame('Texte à conserver.', $fresh->getTranslation('description', 'fr', false));
        $this->assertSame('Deutscher Text.', $fresh->getTranslation('description', 'de', false));
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
            ->assertRedirect('/fr/two-factor/challenge');

        $this->assertSame(0, Experience::count());
    }

    public function test_admin_without_verified_two_factor_cannot_update(): void
    {
        $experience = $this->makeExperience();
        $this->flushSession();

        $this->actingAs($this->admin)
            ->put('/fr/admin/experiences/'.$experience->id, $this->validPayload(['job_title' => 'Pirate']))
            ->assertRedirect('/fr/two-factor/challenge');

        $this->assertSame('Principal Engineer', Experience::firstOrFail()->job_title);
    }

    public function test_admin_without_verified_two_factor_cannot_delete(): void
    {
        $experience = $this->makeExperience();
        $this->flushSession();

        $this->actingAs($this->admin)
            ->delete('/fr/admin/experiences/'.$experience->id)
            ->assertRedirect('/fr/two-factor/challenge');

        $this->assertSame(1, Experience::count());
    }

    public function test_unauthenticated_user_cannot_update(): void
    {
        $experience = $this->makeExperience();

        $this->put('/fr/admin/experiences/'.$experience->id, $this->validPayload(['job_title' => 'Pirate']))
            ->assertRedirect('/fr/login');

        $this->assertSame('Principal Engineer', Experience::firstOrFail()->job_title);
    }

    public function test_unauthenticated_user_cannot_reach_the_create_form(): void
    {
        $this->get('/fr/admin/experiences/create')->assertRedirect('/fr/login');
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

    public function test_the_admin_list_shows_the_same_period_as_the_public_page(): void
    {
        // The index used to build its own "start — end" string in Blade, next
        // to CvService doing the same thing for the public page. Both are read
        // here so the two cannot drift apart again without a failure: a format
        // change on one side has to be a format change on both.
        $this->makeExperience([
            'job_title' => 'Principal Engineer',
            'started_at' => '2024-04-01',
            'ended_at' => '2026-09-01',
        ]);

        $expected = '04/2024 — 09/2026';

        $this->actingAs($this->admin)
            ->get('/fr/admin/experiences')
            ->assertOk()
            ->assertSee($expected);

        $this->get('/fr/cv')->assertOk()->assertSee($expected);
    }

    public function test_the_admin_list_names_a_position_still_held(): void
    {
        $this->makeExperience(['started_at' => '2024-04-01', 'ended_at' => null]);

        $this->actingAs($this->admin)
            ->get('/fr/admin/experiences')
            ->assertOk()
            ->assertSee('04/2024 — '.__('cv.period_present'));
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

    // ── Normalisation ─────────────────────────────────────────────────────────

    public function test_surrounding_whitespace_is_trimmed(): void
    {
        $this->actingAs($this->admin)->post('/fr/admin/experiences', $this->validPayload([
            'employer' => '   Groupe Vantarel   ',
            'job_title' => "\t Principal Engineer \n",
            'description' => '  Direction technique.  ',
        ]));

        $experience = Experience::firstOrFail();

        $this->assertSame('Groupe Vantarel', $experience->employer);
        $this->assertSame('Principal Engineer', $experience->job_title);
        $this->assertSame('Direction technique.', $experience->getTranslation('description', 'fr'));
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
