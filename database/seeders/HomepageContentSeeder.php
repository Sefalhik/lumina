<?php

namespace Database\Seeders;

use App\Models\HomepageContent;
use Illuminate\Database\Seeder;

class HomepageContentSeeder extends Seeder
{
    public function run(): void
    {
        $content = HomepageContent::firstOrNew([]);

        if ($content->exists) {
            $confirmed = $this->command?->confirm(
                'HomepageContent already has data. Overwrite tagline, subtitle, bio and skills?',
                false,
            );

            if (! $confirmed) {
                $this->command?->info('HomepageContentSeeder: skipped (existing data preserved).');

                return;
            }
        }

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

        $content->setTranslation('skills', 'fr', json_encode([
            [
                'icon' => '⬡',
                'name' => 'Backend',
                'techs' => [
                    'PHP 8.5', 'Laravel 13', 'Symfony', 'API Platform',
                    'FrankenPHP / Octane', 'REST API', 'PSR standards', 'Composer',
                ],
            ],
            [
                'icon' => '◈',
                'name' => 'Frontend',
                'techs' => [
                    'Vue 3.5', 'React / Next.js', 'TypeScript',
                    'Vite', 'Tailwind CSS', 'Playwright', 'jQuery',
                ],
            ],
            [
                'icon' => '⊙',
                'name' => 'Data & Persistance',
                'techs' => [
                    'PostgreSQL', 'MySQL / MariaDB', 'Redis',
                    'Elasticsearch', 'ELK Stack', 'Query optimization', 'Schema design',
                ],
            ],
            [
                'icon' => '△',
                'name' => 'Architecture & Qualité',
                'techs' => [
                    'Clean Architecture', 'SOLID', 'DDD', 'PHPStan lvl 8',
                    'PHPUnit / Vitest', 'TDD', 'CI quality gates', 'Code review',
                ],
            ],
            [
                'icon' => '⚙',
                'name' => 'DevOps & Infrastructure',
                'techs' => [
                    'Docker', 'GitHub Actions', 'Jenkins',
                    'Nginx', 'Caddy', 'Sentry', 'Telescope', 'Xdebug',
                ],
            ],
            [
                'icon' => '⊗',
                'name' => 'Sécurité',
                'techs' => [
                    'JWT', 'OAuth 2.0', 'OpenID Connect', 'TOTP / 2FA',
                    'Sanctum', 'OWASP Top 10', 'Rate limiting', 'CORS',
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $content->save();
    }
}
