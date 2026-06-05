# FFA → RaceResult Sync PHP

Synchronisation PHP/SQLite pour :

1. lire les inscrits RaceResult ;
2. rechercher le RunnerID sur athle.fr à partir de nom/prénom/sexe/date de naissance ;
3. lire licence + résultats ;
4. formater le palmarès ;
5. repousser RunnerID, RunnerLicence et palmarès dans les champs RR mappés.

## Installation

Pré-requis PHP :

- PHP 8.1+
- extensions `curl`, `sqlite3`, `pdo_sqlite`, `dom`, `mbstring`, `iconv`

Déposer le dossier sur le serveur ou PC local avec PHP.

```bash
cd ffa-rr-sync
php -S 127.0.0.1:8080
```

Puis ouvrir :

```text
http://127.0.0.1:8080/index.php
```

## Configuration

Modifier `config.php` :

```php
'admin_password' => 'change-me',
'ffa_delay_us' => 500000,
'rr_save_batch_size' => 2,
```

## Cron

Exemple toutes les 5 minutes :

```cron
*/5 * * * * /usr/bin/php /chemin/ffa-rr-sync/cron_sync.php >> /chemin/ffa-rr-sync/data/cron.log 2>&1
```

Il n'y a plus de limite de coureurs par exécution : tous les participants de l'événement sélectionné sont parcourus. Les envois vers RaceResult sont groupés par lots configurables, par défaut deux participants par deux participants :

```php
'rr_save_batch_size' => 2,
```

## Point important FFA

Le parsing FFA ne s'appuie pas sur des positions fixes de colonnes.
Il détecte les champs par appellations et alias :

- date, jour
- épreuve, compétition, event, meeting
- distance
- type, discipline, nature
- place, classement, clt, rang
- temps, chrono, performance, perf
- catégorie, cat
- sexe

Cela permet de supporter des décalages de colonnes ou des présentations différentes.

## Cache

SQLite stocke les coureurs déjà requêtés dans `data/sync.sqlite`.
La clé cache est :

```text
NOM|PRENOM|SEXE|DATE_NAISSANCE
```

Le bouton **RAZ cache FFA** vide ce cache et force une nouvelle interrogation athle.fr.

## Limites connues

- athle.fr est parsé en HTML : si la structure change fortement, il faudra ajuster les alias ou le parser.
- Le login RaceResult peut varier selon le type d'API key. Le code tente de récupérer le token depuis JSON ou texte brut.
- Il est conseillé de garder une cadence raisonnable côté FFA.
