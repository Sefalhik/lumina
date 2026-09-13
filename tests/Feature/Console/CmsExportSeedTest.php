<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\HomepageContent;
use Database\Seeders\HomepageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The command writes executable PHP that a deployment then requires. That makes
 * two things worth pinning harder than usual: the file must always parse, and
 * nothing may reach it unescaped.
 */
class CmsExportSeedTest extends TestCase
{
    use RefreshDatabase;

    private string $target;

    private ?string $original = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The command writes to the real seed file. It is snapshotted here and
        // restored in tearDown so a test run never leaves the repository dirty.
        $this->target = database_path(HomepageContentSeeder::DATA_FILE);
        $this->original = File::exists($this->target) ? File::get($this->target) : null;
    }

    protected function tearDown(): void
    {
        if ($this->original !== null) {
            File::put($this->target, $this->original);
        } elseif (File::exists($this->target)) {
            File::delete($this->target);
        }

        parent::tearDown();
    }

    /**
     * Every translated column is NOT NULL, so a row always carries all five.
     * Fields the caller does not name get a placeholder rather than an empty
     * set: an empty field is a failure case, and a test that wants one has to
     * ask for it explicitly.
     *
     * @param  array<string, array<string, string>>  $fields
     */
    private function row(array $fields, bool $fillTheRest = true): HomepageContent
    {
        $content = new HomepageContent;

        foreach ($content->translatable as $field) {
            $content->setTranslations(
                $field,
                $fields[$field] ?? ($fillTheRest ? ['fr' => "Valeur de {$field}"] : []),
            );
        }

        $content->save();

        return $content;
    }

    /** @return array<string, array<string, string>> */
    private function exported(): array
    {
        $data = include $this->target;

        $this->assertIsArray($data);

        return $data;
    }

    public function test_it_fails_when_there_is_no_row_to_export(): void
    {
        $this->assertSame(0, HomepageContent::count());

        $this->artisan('cms:export-seed')->assertFailed();
    }

    public function test_it_writes_every_locale_of_every_translated_field(): void
    {
        $this->row([
            'tagline' => ['fr' => 'Accroche', 'en' => 'Tagline'],
            'bio' => ['fr' => 'Biographie', 'en' => 'Biography'],
        ]);

        $this->artisan('cms:export-seed')->assertSuccessful();

        $data = $this->exported();

        // Locale order is sorted by design, hence en before fr.
        $this->assertSame(['en' => 'Tagline', 'fr' => 'Accroche'], $data['tagline']);
        $this->assertSame(['en' => 'Biography', 'fr' => 'Biographie'], $data['bio']);
    }

    public function test_a_field_without_translations_makes_the_export_fail(): void
    {
        // Measured, not assumed: every translated column is NOT NULL, so a file
        // missing one key fails when the seeder runs it — at deploy time, with
        // a constraint violation naming a column and nothing else. The command
        // refuses instead.
        $this->row(['tagline' => ['fr' => 'Accroche']], fillTheRest: false);

        $before = File::exists($this->target) ? File::get($this->target) : null;

        $this->artisan('cms:export-seed')->assertFailed();

        $after = File::exists($this->target) ? File::get($this->target) : null;
        $this->assertSame($before, $after);
    }

    public function test_it_fails_when_every_field_is_empty(): void
    {
        $this->row([], fillTheRest: false);

        $this->artisan('cms:export-seed')->assertFailed();
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->row(['tagline' => ['fr' => 'NOUVELLE VALEUR']]);

        $before = File::exists($this->target) ? File::get($this->target) : null;

        $this->artisan('cms:export-seed', ['--dry-run' => true])->assertSuccessful();

        $after = File::exists($this->target) ? File::get($this->target) : null;

        $this->assertSame($before, $after);
    }

    public function test_hostile_values_survive_the_round_trip(): void
    {
        // Single-quoted PHP needs two characters escaped and no others. The
        // combined case matters: a backslash immediately before a quote must
        // not let the quote out.
        $hostile = "L'apostrophe, un antislash \\, la paire \\' et\nun saut de ligne";

        $this->row(['bio' => ['fr' => $hostile]]);

        $this->artisan('cms:export-seed')->assertSuccessful();

        $this->assertSame($hostile, $this->exported()['bio']['fr']);
    }

    public function test_an_unsupported_locale_is_refused_and_nothing_is_written(): void
    {
        // spatie accepts any key. This command writes executable PHP, so a key
        // it did not expect is refused outright rather than escaped and shipped
        // — the file must mirror a row the application can actually serve.
        $content = $this->row(['tagline' => ['fr' => 'Accroche']]);
        $content->setTranslation('tagline', "fr'] , 'INJECTE", 'charge utile');
        $content->save();

        $before = File::exists($this->target) ? File::get($this->target) : null;

        $this->artisan('cms:export-seed')->assertFailed();

        $after = File::exists($this->target) ? File::get($this->target) : null;

        $this->assertSame($before, $after, 'A refused export must leave the file untouched.');
    }

    public function test_the_written_file_is_valid_php(): void
    {
        $this->row(['bio' => ['fr' => "Une valeur avec ' une apostrophe et \\ un antislash"]]);

        $this->artisan('cms:export-seed')->assertSuccessful();

        exec('php -l '.escapeshellarg($this->target).' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
    }

    public function test_locales_are_sorted_so_two_exports_produce_the_same_file(): void
    {
        $this->row(['tagline' => ['sv' => 'Sv', 'fr' => 'Fr', 'de' => 'De']]);

        $this->artisan('cms:export-seed')->assertSuccessful();
        $first = File::get($this->target);

        $this->assertSame(['de', 'fr', 'sv'], array_keys($this->exported()['tagline']));

        $this->artisan('cms:export-seed')->assertSuccessful();

        $this->assertSame($first, File::get($this->target), 'Two exports of one row must be byte-identical.');
    }

    public function test_the_seeder_can_read_back_what_the_command_wrote(): void
    {
        // The pair is the point: an export the seeder cannot consume is worse
        // than no export, because it fails at deploy time rather than here.
        $this->row([
            'tagline' => ['fr' => 'Accroche', 'en' => 'Tagline'],
            'bio' => ['fr' => "Prose avec ' apostrophe", 'en' => 'Prose'],
        ]);

        $this->artisan('cms:export-seed')->assertSuccessful();

        HomepageContent::query()->delete();
        $this->seed(HomepageContentSeeder::class);

        $reseeded = HomepageContent::first();

        $this->assertNotNull($reseeded);
        $this->assertSame("Prose avec ' apostrophe", $reseeded->getTranslation('bio', 'fr', false));
        $this->assertSame('Tagline', $reseeded->getTranslation('tagline', 'en', false));
    }
}
