# FFA → RaceResult14 Sync

Déposer le dossier sur un serveur PHP avec l’extension cURL activée. Le dossier `data/` doit être inscriptible par PHP.

## Fonctionnement
1. Saisir la clé API RaceResult puis cliquer sur **Charger les évènements du compte**.
2. Sélectionner l’évènement RaceResult concerné puis cliquer sur **Charger les UDF de l’évènement**.
3. Sélectionner les 3 UDF à utiliser : licence FFA, ID participant FFA, résultats FFA.
4. Charger les participants RaceResult.
5. Lancer la synchronisation.

Le matching FFA utilise `Firstname`, `Lastname`, `DateOfBirth` côté RaceResult. La recherche FFA est faite par paquets de 3 participants. Les non-matchs sont stockés dans `data/retry_queue.json` et abandonnés après 3 tentatives.

## Endpoints RaceResult
Le soft tente plusieurs endpoints probables pour rester compatible avec les variantes RR14 :

- évènements : `events`, `event/list`, `events/list`, `event/getevents`, `account/events`
- UDF : `userdefinedfields`, `userdefinedfields/list`, `userdefinedfields/get`, `udf/list`, `customfields`, `customfields/list`
- écriture : `part/savefields`

Si ton accès RaceResult utilise une URL différente, ajuste `rr_api_base_template` dans l’interface. Par défaut :

`https://api.raceresult.com/event/{eventId}/`
