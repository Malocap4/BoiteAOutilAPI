<?php
require __DIR__ . '/bootstrap.php';
session_start();
$msg = '';
$err = '';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function post($k, $d='') { return $_POST[$k] ?? $d; }

if (($config['admin_password'] ?? '') !== '' && ($config['admin_password'] ?? '') !== 'change-me') {
    if (isset($_POST['login_password'])) {
        $_SESSION['ok'] = hash_equals($config['admin_password'], (string)$_POST['login_password']);
    }
    if (empty($_SESSION['ok'])) {
        echo '<!doctype html><meta charset="utf-8"><title>FFA RR Sync</title><form method="post" style="max-width:360px;margin:80px auto;font-family:Arial"><h2>FFA RR Sync</h2><input type="password" name="login_password" placeholder="Mot de passe" style="width:100%;padding:10px"><button style="margin-top:10px;padding:10px 16px">Entrer</button></form>';
        exit;
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
        if ($action === 'save_api_key') {
            $rr->setApiKey(post('rr_api_key'));
            $rr->login();
            $msg = 'API Key RR sauvegardée et token généré.';
        } elseif ($action === 'load_events') {
            $db->setSetting('date_from', post('date_from'));
            $db->setSetting('date_to', post('date_to'));
            $msg = 'Liste évènements rafraîchie.';
        } elseif ($action === 'select_event') {
            $oldEventId = $db->getSetting('selected_event_id', '');
            $newEventId = post('event_id');
            $db->setSetting('selected_event_id', $newEventId);
            $db->setSetting('selected_event_label', post('event_label'));
            if ($newEventId !== $oldEventId) {
                $db->resetCursor($newEventId);
            }
            $msg = 'Évènement sélectionné.';
        } elseif ($action === 'save_mapping') {
            $db->setSetting('map_runner_id', post('map_runner_id'));
            $db->setSetting('map_runner_licence', post('map_runner_licence'));
            $db->setSetting('map_palmares', post('map_palmares'));
            $msg = 'Mapping sauvegardé.';
        } elseif ($action === 'reset_cache') {
            $db->clearCache();
            $msg = 'Cache FFA vidé.';
        } elseif ($action === 'run_once') {
            $res = $sync->run();
            $msg = 'Synchro exécutée : ' . json_encode($res, JSON_UNESCAPED_UNICODE);
        }
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

$dateFrom = $db->getSetting('date_from', date('Y-m-d', strtotime('-' . $config['default_days_before'] . ' days')));
$dateTo = $db->getSetting('date_to', date('Y-m-d', strtotime('+' . $config['default_days_after'] . ' days')));
$events = [];
$fields = [];
$selectedEventId = $db->getSetting('selected_event_id', '');
try {
    if ($db->getSetting('rr_api_key')) {
        $events = $rr->eventListRange($dateFrom, $dateTo);
        if ($selectedEventId) {
            $fields = $rr->fields($selectedEventId);
        }
    }
} catch (Throwable $e) {
    $err = $err ?: $e->getMessage();
}
$logs = $db->pdo()->query('SELECT * FROM sync_log ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
$cacheCount = (int)$db->pdo()->query('SELECT COUNT(*) FROM runner_cache')->fetchColumn();
$cacheFoundCount = (int)$db->pdo()->query("SELECT COUNT(*) FROM runner_cache WHERE COALESCE(runner_id,'') <> ''")->fetchColumn();
$cacheNotFoundCount = (int)$db->pdo()->query("SELECT COUNT(*) FROM runner_cache WHERE COALESCE(runner_id,'') = ''")->fetchColumn();
$cursorInfo = '';
if ($selectedEventId) {
    $cursorInfo = (string)$db->getCursor($selectedEventId);
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>FFA → RaceResult Sync</title>
<style>
body{font-family:Arial, sans-serif;margin:24px;background:#f7f7f8;color:#202124}.wrap{max-width:1180px;margin:auto}.card{background:#fff;border:1px solid #ddd;border-radius:10px;padding:18px;margin-bottom:16px;box-shadow:0 1px 2px #0001}label{display:block;font-weight:700;margin:8px 0 4px}input,select,button{padding:9px;border:1px solid #bbb;border-radius:6px}input[type=text],input[type=date],input[type=password],select{min-width:260px}button{background:#154c9f;color:#fff;border-color:#154c9f;cursor:pointer}.danger{background:#a51616;border-color:#a51616}.ok{background:#e8f5e9;color:#165c1d;border:1px solid #a5d6a7;padding:10px;border-radius:6px}.err{background:#ffebee;color:#8a1111;border:1px solid #ef9a9a;padding:10px;border-radius:6px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.row{display:flex;gap:10px;align-items:end;flex-wrap:wrap}table{border-collapse:collapse;width:100%;font-size:13px}th,td{border-bottom:1px solid #eee;padding:7px;text-align:left}code{background:#eee;padding:2px 4px;border-radius:3px}.small{color:#666;font-size:13px}</style>
</head>
<body><div class="wrap">
<h1>FFA → RaceResult Sync</h1>
<?php if ($msg): ?><div class="ok"><?=h($msg)?></div><?php endif; ?>
<?php if ($err): ?><div class="err"><?=h($err)?></div><?php endif; ?>

<div class="grid">
<div class="card">
<h2>1. RaceResult</h2>
<form method="post">
<input type="hidden" name="action" value="save_api_key">
<label>API Key RaceResult</label>
<input type="password" name="rr_api_key" value="<?=h($db->getSetting('rr_api_key',''))?>" style="width:80%">
<button>Sauver / Login</button>
</form>
<p class="small">Le token est renouvelé automatiquement si RR renvoie 440/401.</p>
</div>

<div class="card">
<h2>2. Plage événements</h2>
<form method="post" class="row">
<input type="hidden" name="action" value="load_events">
<div><label>Début</label><input type="date" name="date_from" value="<?=h($dateFrom)?>"></div>
<div><label>Fin</label><input type="date" name="date_to" value="<?=h($dateTo)?>"></div>
<button>Recharger</button>
</form>
</div>
</div>

<div class="card">
<h2>3. Événement RR</h2>
<form method="post" class="row">
<input type="hidden" name="action" value="select_event">
<select name="event_id" onchange="this.form.event_label.value=this.options[this.selectedIndex].text">
<option value="">-- choisir --</option>
<?php foreach ($events as $e): $label = ($e['EventName'] ?? '') . ' (' . ($e['EventDate'] ?? '') . ')'; ?>
<option value="<?=h($e['ID'])?>" <?=($selectedEventId==(string)$e['ID']?'selected':'')?>><?=h($label)?></option>
<?php endforeach; ?>
</select>
<input type="hidden" name="event_label" value="">
<button>Sélectionner</button>
</form>
<p class="small">Sélection actuelle : <b><?=h($db->getSetting('selected_event_label', $selectedEventId))?></b></p>
</div>

<div class="card">
<h2>4. Mapping UDF</h2>
<form method="post">
<input type="hidden" name="action" value="save_mapping">
<div class="grid">
<div><label>runnerId → Field RR</label><select name="map_runner_id"><option value="">-- choisir --</option><?php foreach($fields as $f): ?><option <?=($db->getSetting('map_runner_id')===$f?'selected':'')?>><?=h($f)?></option><?php endforeach; ?></select></div>
<div><label>RunnerLicence → Field RR</label><select name="map_runner_licence"><option value="">-- choisir --</option><?php foreach($fields as $f): ?><option <?=($db->getSetting('map_runner_licence')===$f?'selected':'')?>><?=h($f)?></option><?php endforeach; ?></select></div>
<div><label>palmarès → Field RR</label><select name="map_palmares"><option value="">-- choisir --</option><?php foreach($fields as $f): ?><option <?=($db->getSetting('map_palmares')===$f?'selected':'')?>><?=h($f)?></option><?php endforeach; ?></select></div>
</div><br>
<button>Sauvegarder mapping</button>
<button type="submit" formaction="" name="action" value="select_event">Reload UDF</button>
</form>
</div>

<div class="card">
<h2>5. Exécution</h2>
<form method="post" class="row">
<input type="hidden" name="action" value="run_once">
<button>Lancer une synchro maintenant</button>
</form>
<form method="post" style="margin-top:10px"><input type="hidden" name="action" value="reset_cache"><button class="danger" onclick="return confirm('Vider tout le cache FFA ?')">RAZ cache FFA</button></form>
<p class="small">Cache actuel : <b><?=$cacheCount?></b> coureur(s), dont <b><?=$cacheFoundCount?></b> trouvé(s) et <b><?=$cacheNotFoundCount?></b> introuvable(s) FFA. Curseur événement : <b><?=h($cursorInfo)?></b>. FFA : <b><?=h($config['ffa_fetch_batch_size'] ?? 2)?></b> nouveau(x) coureur(s) max par exécution. Envoi RR par lots de <b><?=h($config['rr_save_batch_size'] ?? 2)?></b> participant(s). Cron conseillé : <code>php <?=h(__DIR__)?>/cron_sync.php</code></p>
</div>

<div class="card">
<h2>Derniers logs</h2>
<table><tr><th>Date</th><th>Bib</th><th>Status</th><th>Message</th></tr>
<?php foreach ($logs as $l): ?><tr><td><?=h($l['created_at'])?></td><td><?=h($l['bib'])?></td><td><?=h($l['status'])?></td><td><?=h($l['message'])?></td></tr><?php endforeach; ?>
</table>
</div>
</div></body></html>
