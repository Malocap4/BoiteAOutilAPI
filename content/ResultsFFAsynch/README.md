# FFA → RaceResult Sync

Application PHP + SQLite pour enrichir les participants RaceResult avec les informations trouvées sur athle.fr : RunnerID, licence et palmarès.

## Installation

1. Copier le dossier `ffa-rr-sync` sur le serveur PHP.
2. Vérifier que PHP a les extensions `curl`, `pdo_sqlite`, `mbstring` et `dom`.
3. Donner les droits d'écriture au dossier `data/`.
4. Modifier `config.php`, au minimum :
   - `admin_password`
   - éventuellement `ffa_fetch_batch_size`, `rr_save_batch_size`, `max_run_seconds`.

## Utilisation UI

1. Ouvrir `index.php`.
2. Sauvegarder l'API Key RaceResult.
3. Charger la plage d'événements.
4. Sélectionner l'événement.
5. Mapper les champs RaceResult :
   - `runnerId`
   - `RunnerLicence`
   - `palmarès`
6. Lancer une synchro manuelle ou configurer le cron.

## Cron conseillé

```bash
* * * * * /usr/bin/php /chemin/ffa-rr-sync/cron_sync.php >> /chemin/ffa-rr-sync/data/cron.log 2>&1
```

## Stratégie anti-timeout / anti-502

Cette version ne reparcourt plus toute la liste RaceResult à chaque exécution.

Elle utilise un curseur local SQLite :

- exécution 1 : traite les prochains participants depuis le curseur ;
- exécution 2 : reprend là où la précédente s'est arrêtée ;
- quand la fin de liste est atteinte, le curseur revient au début.

Les appels FFA sont limités par :

```php
'ffa_fetch_batch_size' => 2,
```

Donc seulement 2 nouveaux coureurs non présents en cache sont requêtés sur athle.fr par exécution.

Les coureurs introuvables côté FFA sont aussi stockés en cache avec un RunnerID vide. Cela évite de refaire la même recherche à chaque cron.

## Push RaceResult

Dès qu'une donnée est prête, elle est poussée vers RaceResult via `part/savefields`, par lots configurables :

```php
'rr_save_batch_size' => 2,
```

Un participant déjà poussé n'est pas renvoyé à chaque cron, sauf après RAZ cache.

## RAZ cache

Le bouton **RAZ cache FFA** vide :

- le cache des coureurs ;
- le curseur de synchro.

La synchro repart donc de zéro.

## Lecture du JSON de résultat

Exemple :

```json
{
  "processed_this_run": 2,
  "total_rr": 3559,
  "cursor_start": 0,
  "cursor_end": 2,
  "cache_hits": 0,
  "ffa_fetched_this_run": 2,
  "runnerid_found": 1,
  "runnerid_not_found": 1,
  "cache_inserted": 2,
  "pushed": 1
}
```

Signification :

- `cursor_start` / `cursor_end` : progression dans la liste RR ;
- `ffa_fetched_this_run` : nombre de nouvelles recherches athle.fr tentées ;
- `runnerid_found` : nombre de RunnerID trouvés ;
- `runnerid_not_found` : nombre de participants sans correspondance FFA ;
- `cache_not_found` : coureurs déjà connus comme introuvables FFA ;
- `pushed` : participants envoyés à RaceResult.

## Point d'attention FFA

Le parsing FFA est volontairement fait par recherche de libellés / appellations et non par position fixe, car les colonnes et blocs HTML peuvent varier selon les pages athle.fr.
