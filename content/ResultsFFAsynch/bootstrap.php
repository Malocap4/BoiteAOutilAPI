<?php
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/Db.php';
require_once __DIR__ . '/src/HttpClient.php';
require_once __DIR__ . '/src/RaceResultClient.php';
require_once __DIR__ . '/src/FfaClient.php';
require_once __DIR__ . '/src/PalmaresFormatter.php';
require_once __DIR__ . '/src/SyncService.php';
$db = new Db($config['db_path']);
$rr = new RaceResultClient($config, $db);
$ffa = new FfaClient($config);
$sync = new SyncService($config, $db, $rr, $ffa);
