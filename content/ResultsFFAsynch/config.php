<?php
return [
    // Sécurité simple de l'interface. Changez cette valeur.
    'admin_password' => 'change-me',

    // SQLite local
    'db_path' => __DIR__ . '/data/sync.sqlite',

    // RaceResult
    'rr_login_url' => 'https://events.raceresult.com/api/public/login',
    'rr_base_url' => 'https://events.raceresult.com',
    'rr_lang' => 'en-fr',

    // FFA / athle.fr
    'ffa_base_url' => 'https://www.athle.fr',
    'ffa_user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0 Safari/537.36',

    // Rythme de scraping FFA, en microsecondes.
    // 500000 = 0,5 seconde entre deux coureurs non présents en cache.
    'ffa_delay_us' => 500000,

    // Nombre maximum de nouveaux coureurs interrogés sur athle.fr par exécution.
    // 2 = micro-lots de deux participants pour éviter les timeouts / 502.
    'ffa_fetch_batch_size' => 2,

    // Durée max d'une exécution web/cron avant de reporter au prochain passage.
    'max_run_seconds' => 25,

    // Nombre de participants envoyés par requête RaceResult savefields.
    // 2 = envoi deux participants par deux participants.
    'rr_save_batch_size' => 2,

    // Nombre max de résultats FFA à stocker dans le palmarès.
    'max_palmares_results' => 8,

    // Plage événement par défaut dans l'UI
    'default_days_before' => 15,
    'default_days_after' => 30,
];
