# FFA → RaceResult14 Sync

Déposer le dossier sur un serveur PHP avec l’extension cURL activée. Le dossier `data/` doit être inscriptible par PHP.

## Fonctionnement
1. Saisir la clé API RaceResult, l’Event ID et les 3 champs UDF.
2. Charger les participants RaceResult.
3. Lancer la synchronisation.

Le matching FFA utilise `Firstname`, `Lastname`, `DateOfBirth` côté RaceResult. La recherche FFA est faite par paquets de 3 participants. Les non-matchs sont stockés dans `data/retry_queue.json` et abandonnés après 3 tentatives.

## Note importante RaceResult
Le champ `rr_api_base_template` est volontairement configurable. Par défaut :
`https://api.raceresult.com/event/{eventId}/`

Si ton compte RR14 utilise une URL Web API différente, modifie ce champ dans l’interface. L’écriture utilise `part/savefields` avec un body JSON.
