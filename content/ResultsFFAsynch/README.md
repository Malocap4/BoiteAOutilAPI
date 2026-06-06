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


## Synchro automatique depuis l'interface

Le bouton **Lancer la synchro automatique** ne lance pas une seule grosse requête PHP.
Il enchaîne des petites exécutions AJAX vers `api_sync.php` :

- 2 nouveaux participants maximum interrogés côté FFA par passage ;
- push RaceResult régulier dès que des infos sont disponibles ;
- arrêt automatique quand toute la liste RaceResult a été parcourue et que tout est en cache / poussé ;
- bouton **Arrêter** disponible à tout moment.

Le délai entre deux passages se règle dans `config.php` :

```php
'auto_sync_delay_ms' => 1500,
```

Pour une exécution totalement autonome même navigateur fermé, utiliser plutôt un cron serveur toutes les 1 ou 2 minutes :

```bash
* * * * * php /chemin/ffa-rr-sync/cron_sync.php >> /chemin/ffa-rr-sync/data/cron.log 2>&1
```

## v7 - Correction format palmarès FFA

Le parsing FFA des résultats lit désormais les colonnes par leurs libellés (`Epreuve`, `Résultat`, `Date`, `Ville`, `Infos`) et ignore les lignes mobiles `detail-row` qui provoquaient les faux blocs `Tour`, `Niveau`, `Lieu`, etc.

Format généré :

```text
{Epreuve} ({Ville})
{Date}
{icone} Place : {classement général} ({classement sexe}{sexe} - {classement catégorie}{catégorie}) / Temps : {temps}
```

Les parenthèses de classement sexe/catégorie ne sont ajoutées que si ces classements existent réellement dans les données FFA. Les participants déjà en cache doivent être recalculés avec le bouton **RAZ cache FFA** si leur palmarès a été généré par une ancienne version.
