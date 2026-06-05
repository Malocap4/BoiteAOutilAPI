# FFA → RaceResult14 Sync

Déposer le dossier sur un serveur PHP avec l’extension cURL activée. Le dossier `data/` doit être inscriptible par PHP.

## Flux RaceResult utilisé

V5 recolle au flux de l’application exemple : host `https://events.raceresult.com`, login `/api/public/login`, eventlist `/api/public/eventlist`, puis endpoints évènement `/_EventID/api/...`. Un fichier `data/rr_eventlist_debug.json` est créé à chaque chargement pour contrôler le nombre brut renvoyé par année avant filtrage par dates.


1. La clé API RaceResult est saisie une première fois dans l’interface.
2. Le soft génère un Bearer token via :
   `POST https://events.raceresult.com/api/public/login`
3. Le token est conservé en cache dans `data/settings.json` et renouvelé automatiquement en cas d’expiration ou d’erreur d’autorisation.
4. Les évènements sont chargés avec :
   `GET https://events.raceresult.com/api/public/eventlist?year=YYYY&filter=&addsettings=EventName,EventDate,EventDate2,EventLocation,EventCountry`
5. L’interface filtre ensuite les évènements sur la plage de dates saisie : par défaut J-15 à J+1 mois.
6. Après sélection de l’évènement, les champs déclarés/UDF sont chargés avec :
   `POST https://events.raceresult.com/_{EventID}/api/multirequest?lang=en-fr`
   body : `["Fields"]`

## Utilisation

1. Saisir la clé API RaceResult.
2. Régler au besoin la plage de dates.
3. Cliquer sur **Charger les évènements du compte**.
4. Sélectionner l’évènement RaceResult : affichage `EventName (EventDate)`.
5. Cliquer sur **Charger les UDF de l’évènement**.
6. Sélectionner les 3 champs de destination : licence FFA, ID participant FFA, résultats FFA.
7. Charger les participants RaceResult.
8. Lancer la synchronisation.

## Matching et retry

Le matching FFA utilise `Firstname`, `Lastname`, `DateOfBirth` côté RaceResult. La recherche FFA est faite par paquets de 3 participants. Les non-matchs sont stockés dans `data/retry_queue.json` et abandonnés après 3 tentatives.

## Format écrit dans l’UDF résultats

```text
{Evènement} ({distance} - {Type})
{Date}
Place : {classement} / Temps : {Temps}
```


## V6
- Lecture participants RaceResult compatible JSON ou XML (`<list><record>...</record></list>`).
- Les champs techniques `API public RR` et `Base événements RR` ne sont plus affichés sur le front.
- Sélection multi-saisons FFA.
- Les listes UDF affichent uniquement le nom du champ RaceResult.
