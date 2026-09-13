<?php

return [
    'title' => 'Accueil',
    'cta_projects' => 'Voir mes projets',
    'cta_cv' => 'Mon CV',

    'about_heading' => 'À propos',
    'skills_heading' => 'Stack & expertise',

    // Shown only while the homepage row is missing entirely — before the very
    // first db:seed, or on a database that was reset. Deliberately neutral:
    // duplicating the real copy here would give it a second home, and the two
    // would drift. The content itself lives in
    // database/data/homepage-content.php.
    'fallback_tagline' => 'Initialisation en cours',
    'fallback_subtitle' => 'Contenu en cours de chargement',
    'fallback_bio' => 'Le contenu de cette page n’est pas encore initialisé.',

    // Fallback skills grid — displayed before skills are configured in the CMS
    'fallback_skills' => [
        ['icon' => '⬡', 'tech' => 'PHP / Laravel',     'category' => 'Backend'],
        ['icon' => '◈', 'tech' => 'Vue 3 / TypeScript', 'category' => 'Frontend'],
        ['icon' => '⬡', 'tech' => 'PostgreSQL',         'category' => 'Data'],
        ['icon' => '◈', 'tech' => 'Architecture',       'category' => 'Design'],
    ],
];
