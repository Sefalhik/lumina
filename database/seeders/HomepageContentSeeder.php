<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\HomepageContent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Seeds the single homepage row from database/data/homepage-content.php.
 *
 * The content lives in that file rather than here so that the twenty-four
 * locales it carries can be reviewed in a diff, and so that a deployment never
 * has to call the translation API to produce its own content. See
 * `cms:export-seed` for how the file is regenerated.
 */
class HomepageContentSeeder extends Seeder
{
    public const DATA_FILE = 'data/homepage-content.php';

    public function run(): void
    {
        $content = HomepageContent::firstOrNew([]);
        $existed = $content->exists;

        // Existing content is never overwritten without a human saying so.
        // In a non-interactive run — a deployment — confirm() returns its
        // default, so this seeder only ever populates an empty row. Editing
        // published content is the admin form's job, not a redeploy's.
        if ($content->exists) {
            $confirmed = $this->command?->confirm(
                'HomepageContent already has data. Overwrite it from '.self::DATA_FILE.'?',
                false,
            );

            if (! $confirmed) {
                $this->command?->info('HomepageContentSeeder: skipped (existing data preserved).');

                // Console output is the only other trace, and a deployment log
                // is usually discarded. Whether production shipped its content
                // or quietly kept what was already there is worth being able to
                // answer afterwards.
                Log::info('Homepage content seeding skipped', [
                    'service' => self::class,
                    'method' => __FUNCTION__,
                    'step' => 'skipped_existing',
                ]);

                return;
            }
        }

        $data = self::data();

        foreach ($data as $field => $translations) {
            $content->setTranslations($field, $translations);
        }

        $content->save();

        Log::info('Homepage content seeded', [
            'service' => self::class,
            'method' => __FUNCTION__,
            'step' => 'seeded',
            'fields' => count($data),
            'locales' => count(reset($data) ?: []),
            'overwritten' => $existed,
        ]);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function data(): array
    {
        $path = database_path(self::DATA_FILE);

        if (! is_file($path)) {
            throw new \RuntimeException("Homepage seed data missing: {$path}");
        }

        $data = require $path;

        if (! is_array($data) || $data === []) {
            throw new \RuntimeException("Homepage seed data is not a non-empty array: {$path}");
        }

        return $data;
    }
}
