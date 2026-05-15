<?php

namespace Database\Seeders;

use App\Models\HomepageContent;
use Illuminate\Database\Seeder;

class HomepageContentSeeder extends Seeder
{
    public function run(): void
    {
        // Single-record table — upsert so it is safe to re-run.
        $content = HomepageContent::firstOrNew([]);

        $content->setTranslations('tagline', [
            'fr' => 'Neuromatrix online — biocortex actif',
            'en' => 'Neuromatrix online — biocortex active',
        ]);

        $content->setTranslations('subtitle', [
            'fr' => 'Lead Developer // Ingénieur Logiciel',
            'en' => 'Lead Developer // Software Engineer',
        ]);

        $content->setTranslations('bio', [
            'fr' => 'Architecte de systèmes, artisan du code propre. Je construis des applications robustes et des équipes qui durent — quelque part entre la console et les étoiles.',
            'en' => 'Systems architect, craftsman of clean code. I build robust applications and lasting teams — somewhere between the console and the stars.',
        ]);

        $content->save();
    }
}
