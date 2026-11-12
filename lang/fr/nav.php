<?php

return [
    'home' => 'Accueil',
    // cv, projects and blog are not rendered while their pages are unfinished (LUMN-104), and
    // kept on purpose in every locale: they come back with their links. UnfinishedPagesTest
    // fails if one disappears.
    'cv' => 'CV',
    'projects' => 'Projets',
    'blog' => 'Blog',

    // Accessible names of the header navigation and of the mobile menu button
    'main_label' => 'Navigation principale',
    'menu' => 'Menu',

    // Authenticated user menu
    'admin_panel' => 'Panneau d\'administration',
    'logout' => 'Déconnexion',
];
