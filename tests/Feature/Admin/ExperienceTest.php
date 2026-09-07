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
}
