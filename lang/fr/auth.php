<?php

return [
    // Laravel built-ins
    'failed' => 'Identifiants incorrects.',
    'password' => 'Mot de passe incorrect.',
    'throttle' => 'Trop de tentatives. Réessayez dans :seconds secondes.',

    // 2FA validation messages (used in controllers)
    'two_factor_invalid_code' => 'Code invalide. Vérifiez votre application et réessayez.',
    'two_factor_setup_expired' => 'Session de configuration expirée. Rechargez la page.',

    // Login view
    'login_page_title' => 'Accès sécurisé',
    'login_subtitle' => 'Terminal d\'accès sécurisé',
    'login_heading' => 'Authentification requise',
    'login_label_email' => '› Identifiant',
    'login_label_password' => '› Mot de passe',
    'login_remember' => 'Mémoriser ce terminal',
    'login_submit' => '› Connexion',

    // 2FA challenge view
    'challenge_page_title' => 'Vérification en deux étapes',
    'challenge_subtitle' => 'Vérification en deux étapes',
    'challenge_heading' => 'Code de vérification',
    'challenge_hint' => 'Ouvrez votre application d\'authentification et entrez le code à 6 chiffres.',
    'challenge_label_code' => '› Code à usage unique',
    'challenge_submit' => '› Vérifier',

    // 2FA setup view
    'setup_page_title' => 'Configuration de la double authentification',
    'setup_subtitle' => 'Configuration de la sécurité',
    'setup_heading' => 'Configurer la double authentification',
    'setup_hint' => 'Scannez ce QR code avec votre application d\'authentification (Google Authenticator, Aegis, Authy…).',
    'setup_manual_key' => 'Clé manuelle',
    'setup_label_code' => '› Confirmez avec un code de votre application',
    'setup_submit' => '› Activer la double authentification',

    // Shared across challenge and setup views
    'abort_logout' => 'Annuler — Déconnexion',
];
