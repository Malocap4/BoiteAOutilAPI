<?php
require __DIR__ . '/lib/bootstrap.php';
$settings = Settings::load();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$result = null;
try {
    if ($action === 'save_settings') {
        $settings['rr_api_key'] = trim($_POST['rr_api_key'] ?? '');
        $settings['rr_event_id'] = trim($_POST['rr_event_id'] ?? '');
        $settings['rr_api_base_template'] = trim($_POST['rr_api_base_template'] ?? $settings['rr_api_base_template']);
        $settings['ffa_season'] = (int)($_POST['ffa_season'] ?? date('Y'));
        $settings['udf_license'] = trim($_POST['udf_license'] ?? '');
        $settings['udf_ffa_id'] = trim($_POST['udf_ffa_id'] ?? '');
        $settings['udf_results'] = trim($_POST['udf_results'] ?? '');
        Settings::save($settings);
        $result = ['type'=>'ok','message'=>'Paramètres sauvegardés.'];
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
$participants = Store::read('participants.json', []);
$queue = Store::read('retry_queue.json', []);
$logs = array_slice(array_reverse(Store::readLines('sync.log')), 0, 80);
function h($s){return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');}
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>FFA → RaceResult14</title><link rel="stylesheet" href="assets/style.css"></head><body>
<header><div><h1>FFA → RaceResult14</h1><p>Licence, ID participant FFA et résultats vers UDF RaceResult.</p></div></header>
<main>
<?php if ($result): ?><div class="alert <?= $result['type']==='ok'?'ok':'err' ?>"><b><?= h($result['message']) ?></b><?php if (!empty($result['details'])): ?><pre><?= h(json_encode($result['details'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre><?php endif; ?></div><?php endif; ?>
<section class="card"><h2>1. Paramètres</h2>
<form method="post" class="grid"><input type="hidden" name="action" value="save_settings">
<label>Clé API RaceResult <input type="password" name="rr_api_key" value="<?= h($settings['rr_api_key']) ?>" autocomplete="off"></label>
<label>Event ID RaceResult <input name="rr_event_id" value="<?= h($settings['rr_event_id']) ?>" placeholder="ex: 123456"></label>
<label>Saison FFA <input type="number" name="ffa_season" value="<?= h($settings['ffa_season']) ?>"></label>
<label>Base API RR avancée <input name="rr_api_base_template" value="<?= h($settings['rr_api_base_template']) ?>"></label>
<label>UDF licence FFA <input name="udf_license" value="<?= h($settings['udf_license']) ?>" placeholder="ex: ATF1 / UDF.LicenceFFA"></label>
<label>UDF ID participant FFA <input name="udf_ffa_id" value="<?= h($settings['udf_ffa_id']) ?>" placeholder="ex: ATF2 / UDF.FFAID"></label>
<label>UDF résultats FFA <input name="udf_results" value="<?= h($settings['udf_results']) ?>" placeholder="ex: ATF3 / UDF.ResultatsFFA"></label>
<div class="actions"><button>Sauvegarder</button></div></form></section>
<section class="card"><h2>2. Participants RaceResult</h2><p>Le chargement lit les champs <code>ID, Firstname, Lastname, DateOfBirth</code>. Le match FFA utilise ces trois valeurs.</p>
<form method="post" class="inline"><input type="hidden" name="action" value="load_participants"><button>Charger les participants RR</button></form>
<div class="stats"><span><?= count($participants) ?> chargé(s)</span><span><?= count($queue) ?> en attente retry</span></div></section>
<section class="card"><h2>3. Synchronisation</h2><p>La recherche FFA est traitée par paquets de 3 participants. Un non-match est mis de côté puis abandonné après 3 tentatives.</p>
<form method="post" class="inline"><input type="hidden" name="action" value="sync"><button class="primary">Lancer la synchronisation</button></form>
<form method="post" class="inline"><input type="hidden" name="action" value="reset_queue"><button class="ghost">Vider la file retry</button></form></section>
<section class="card"><h2>État retry</h2><table><thead><tr><th>RR ID</th><th>Nom</th><th>Tentatives</th><th>Statut</th></tr></thead><tbody><?php foreach($queue as $q): ?><tr><td><?=h($q['rr_id']??'')?></td><td><?=h(($q['firstname']??'').' '.($q['lastname']??''))?></td><td><?=h($q['tries']??0)?></td><td><?=h($q['status']??'')?></td></tr><?php endforeach; ?></tbody></table></section>
<section class="card"><h2>Logs</h2><pre class="logs"><?= h(implode("\n", $logs)) ?></pre></section>
</main></body></html>
