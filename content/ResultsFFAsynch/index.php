<?php
require __DIR__ . '/lib/bootstrap.php';
$settings = Settings::load();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$result = null;
try {
    if ($action === 'save_settings') {
        $settings['rr_api_key'] = trim($_POST['rr_api_key'] ?? '');
        $settings['rr_event_id'] = trim($_POST['rr_event_id'] ?? '');
        $settings['event_date_from'] = trim($_POST['event_date_from'] ?? $settings['event_date_from']);
        $settings['event_date_to'] = trim($_POST['event_date_to'] ?? $settings['event_date_to']);
        $settings['ffa_seasons'] = array_values(array_unique(array_map('intval', $_POST['ffa_seasons'] ?? [$settings['ffa_season'] ?? date('Y')])));
        $settings['ffa_season'] = (int)($settings['ffa_seasons'][0] ?? date('Y'));
        $settings['udf_license'] = trim($_POST['udf_license'] ?? '');
        $settings['udf_ffa_id'] = trim($_POST['udf_ffa_id'] ?? '');
        $settings['udf_results'] = trim($_POST['udf_results'] ?? '');
        Settings::save($settings);
        $result = ['type'=>'ok','message'=>'Paramètres sauvegardés.'];
    } elseif ($action === 'load_events') {
        $tmp = $settings;
        $tmp['rr_api_key'] = trim($_POST['rr_api_key'] ?? $settings['rr_api_key']);
        $tmp['event_date_from'] = trim($_POST['event_date_from'] ?? $settings['event_date_from']);
        $tmp['event_date_to'] = trim($_POST['event_date_to'] ?? $settings['event_date_to']);
        $rr = new RaceResultClient($tmp, false);
        $events = $rr->loadEvents($tmp['event_date_from'], $tmp['event_date_to']);
        Store::write('events.json', $events);
        $settings['rr_api_key'] = $tmp['rr_api_key'];
        $settings['event_date_from'] = $tmp['event_date_from'];
        $settings['event_date_to'] = $tmp['event_date_to'];
        $settings['rr_token'] = $tmp['rr_token'] ?? ($settings['rr_token'] ?? '');
        $settings['rr_token_expires_at'] = $tmp['rr_token_expires_at'] ?? ($settings['rr_token_expires_at'] ?? 0);
        Settings::save($settings);
        $result = ['type'=>'ok','message'=>count($events).' évènement(s) chargé(s) depuis RaceResult.'];
    } elseif ($action === 'load_udfs') {
        $tmp = $settings;
        foreach(['rr_api_key','rr_event_id'] as $k) $tmp[$k] = trim($_POST[$k] ?? $settings[$k]);
        $tmp['ffa_seasons'] = array_values(array_unique(array_map('intval', $_POST['ffa_seasons'] ?? ($settings['ffa_seasons'] ?? [$settings['ffa_season'] ?? date('Y')]))));
        $tmp['ffa_season'] = (int)($tmp['ffa_seasons'][0] ?? date('Y'));
        $rr = new RaceResultClient($tmp, true);
        $udfs = $rr->loadUdfs();
        Store::write('udfs_'.$tmp['rr_event_id'].'.json', $udfs);
        $settings['rr_api_key']=$tmp['rr_api_key']; $settings['rr_event_id']=$tmp['rr_event_id']; $settings['ffa_seasons']=$tmp['ffa_seasons']; $settings['ffa_season']=$tmp['ffa_season'];
        Settings::save($settings);
        $result = ['type'=>'ok','message'=>count($udfs).' UDF chargé(s) depuis RaceResult.'];
    } elseif ($action === 'load_participants') {
        $rr = new RaceResultClient($settings);
        $participants = $rr->loadParticipants();
        Store::write('participants.json', $participants);
        $result = ['type'=>'ok','message'=>count($participants).' participant(s) chargés depuis RaceResult.'];
    } elseif ($action === 'sync') {
        $sync = new SyncService($settings);
        $result = ['type'=>'ok','message'=>'Synchronisation terminée.','details'=>$sync->run()];
    } elseif ($action === 'reset_queue') {
        Store::write('retry_queue.json', []);
        $result = ['type'=>'ok','message'=>'File retry vidée.'];
    }
} catch (Throwable $e) {
    $result = ['type'=>'err','message'=>$e->getMessage()];
}
$events = Store::read('events.json', []);
$udfs = $settings['rr_event_id'] ? Store::read('udfs_'.$settings['rr_event_id'].'.json', []) : [];
$participants = Store::read('participants.json', []);
$queue = Store::read('retry_queue.json', []);
$logs = array_slice(array_reverse(Store::readLines('sync.log')), 0, 80);
function h($s){return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');}
function udfOptions(array $udfs, string $selected): string { $html='<option value="">— choisir —</option>'; foreach($udfs as $u){ $v=$u['field']??''; $l=$u['label']??$v; $html.='<option value="'.h($v).'"'.($v===$selected?' selected':'').'>'.h($l).'</option>'; } return $html; }
function seasonOptions(array $selected): string { $html=''; $current=(int)date('Y')+1; $min=$current-12; foreach(range($current,$min) as $y){ $html.='<option value="'.h($y).'"'.(in_array($y,$selected,true)?' selected':'').'>'.h($y).'</option>'; } return $html; }
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>FFA → RaceResult14</title><link rel="stylesheet" href="assets/style.css"></head><body>
<header><div><h1>FFA → RaceResult14</h1><p>Licence, ID participant FFA et résultats vers UDF RaceResult.</p></div></header>
<main>
<?php if ($result): ?><div class="alert <?= $result['type']==='ok'?'ok':'err' ?>"><b><?= h($result['message']) ?></b><?php if (!empty($result['details'])): ?><pre><?= h(json_encode($result['details'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre><?php endif; ?></div><?php endif; ?>
<section class="card"><h2>1. Connexion RaceResult</h2>
<form method="post" class="grid"><input type="hidden" name="action" value="load_events">
<label>Clé API RaceResult <input type="password" name="rr_api_key" value="<?= h($settings['rr_api_key']) ?>" autocomplete="off"></label>
<label>Date début <input type="date" name="event_date_from" value="<?= h($settings['event_date_from']) ?>"></label>
<label>Date fin <input type="date" name="event_date_to" value="<?= h($settings['event_date_to']) ?>"></label>
<div class="actions"><button>Charger les évènements du compte</button></div></form>
<p class="small"><?= count($events) ?> évènement(s) en cache.</p></section>
<section class="card"><h2>2. Évènement et UDF</h2>
<form method="post" class="grid"><input type="hidden" name="action" value="load_udfs">
<input type="hidden" name="rr_api_key" value="<?= h($settings['rr_api_key']) ?>">
<label>Évènement RaceResult <select name="rr_event_id"><option value="">— choisir —</option><?php foreach($events as $ev): $id=$ev['id']??''; ?><option value="<?=h($id)?>" <?=$id===$settings['rr_event_id']?'selected':''?>><?=h(($ev['name']??'').' ('.($ev['date']??'').')')?></option><?php endforeach; ?></select></label>
<label>Saisons FFA <select name="ffa_seasons[]" multiple size="6"><?= seasonOptions($settings['ffa_seasons']) ?></select><span class="hint">Ctrl/Cmd + clic pour sélectionner plusieurs saisons.</span></label>
<div class="actions"><button>Charger les UDF de l’évènement</button></div></form>
<form method="post" class="grid topgap"><input type="hidden" name="action" value="save_settings">
<input type="hidden" name="rr_api_key" value="<?= h($settings['rr_api_key']) ?>">
<label>Évènement RaceResult <select name="rr_event_id"><option value="<?=h($settings['rr_event_id'])?>"><?=h($settings['rr_event_id'] ?: '— aucun —')?></option><?php foreach($events as $ev): $id=$ev['id']??''; ?><option value="<?=h($id)?>" <?=$id===$settings['rr_event_id']?'selected':''?>><?=h(($ev['name']??'').' ('.($ev['date']??'').')')?></option><?php endforeach; ?></select></label>
<label>Saisons FFA <select name="ffa_seasons[]" multiple size="6"><?= seasonOptions($settings['ffa_seasons']) ?></select><span class="hint">Ctrl/Cmd + clic pour sélectionner plusieurs saisons.</span></label>
<label>UDF licence FFA <select name="udf_license"><?= udfOptions($udfs, $settings['udf_license']) ?></select></label>
<label>UDF ID participant FFA <select name="udf_ffa_id"><?= udfOptions($udfs, $settings['udf_ffa_id']) ?></select></label>
<label>UDF résultats FFA <select name="udf_results"><?= udfOptions($udfs, $settings['udf_results']) ?></select></label>
<div class="actions"><button>Sauvegarder la sélection</button></div></form>
<p class="small"><?= count($udfs) ?> UDF en cache pour cet évènement.</p></section>
<section class="card"><h2>3. Participants RaceResult</h2><p>Le chargement lit les champs <code>ID, Firstname, Lastname, DateOfBirth</code>. Si RaceResult renvoie du XML, il est maintenant pris en charge automatiquement.</p>
<form method="post" class="inline"><input type="hidden" name="action" value="load_participants"><button>Charger les participants RR</button></form>
<div class="stats"><span><?= count($participants) ?> chargé(s)</span><span><?= count($queue) ?> en attente retry</span></div></section>
<section class="card"><h2>4. Synchronisation</h2><p>La recherche FFA est traitée par paquets de 3 participants. Un non-match est mis de côté puis abandonné après 3 tentatives.</p>
<form method="post" class="inline"><input type="hidden" name="action" value="sync"><button class="primary">Lancer la synchronisation</button></form>
<form method="post" class="inline"><input type="hidden" name="action" value="reset_queue"><button class="ghost">Vider la file retry</button></form></section>
<section class="card"><h2>État retry</h2><table><thead><tr><th>RR ID</th><th>Nom</th><th>Tentatives</th><th>Statut</th></tr></thead><tbody><?php foreach($queue as $q): ?><tr><td><?=h($q['rr_id']??'')?></td><td><?=h(($q['firstname']??'').' '.($q['lastname']??''))?></td><td><?=h($q['tries']??0)?></td><td><?=h($q['status']??'')?></td></tr><?php endforeach; ?></tbody></table></section>
<section class="card"><h2>Logs</h2><pre class="logs"><?= h(implode("\n", $logs)) ?></pre></section>
</main></body></html>
