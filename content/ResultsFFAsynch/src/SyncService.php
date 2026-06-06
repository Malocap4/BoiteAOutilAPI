<?php
final class SyncService
{
    private array $config;
    private Db $db;
    private RaceResultClient $rr;
    private FfaClient $ffa;

    public function __construct(array $config, Db $db, RaceResultClient $rr, FfaClient $ffa)
    {
        $this->config = $config;
        $this->db = $db;
        $this->rr = $rr;
        $this->ffa = $ffa;
    }

    public static function cacheKey(array $p): string
    {
        $norm = fn($s) => strtoupper(trim(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$s) ?: (string)$s));
        return $norm($p['Lastname'] ?? '') . '|' . $norm($p['Firstname'] ?? '') . '|' . strtolower(trim((string)($p['Sex'] ?? ''))) . '|' . trim((string)($p['DateofBirth'] ?? ''));
    }

    /**
     * Exécution incrémentale à curseur :
     * - on ne reparcourt plus les 3500+ participants à chaque exécution ;
     * - on avance dans la liste RR à partir du dernier index traité ;
     * - on limite les nouvelles requêtes FFA à ffa_fetch_batch_size par passage ;
     * - un coureur FFA introuvable est aussi mis en cache pour ne pas le rechercher à chaque cron ;
     * - dès qu'une donnée est prête, elle est envoyée vers RaceResult par micro-lots.
     */
    public function run(): array
    {
        $eventId = $this->db->getSetting('selected_event_id');
        if (!$eventId) {
            throw new RuntimeException('Aucun événement RaceResult sélectionné.');
        }
        $mapRunnerId = $this->db->getSetting('map_runner_id');
        $mapLicence = $this->db->getSetting('map_runner_licence');
        $mapPalmares = $this->db->getSetting('map_palmares');
        if (!$mapRunnerId || !$mapLicence || !$mapPalmares) {
            throw new RuntimeException('Mapping UDF incomplet.');
        }

        $participants = $this->rr->participants($eventId);
        $total = count($participants);
        if ($total === 0) {
            return [
                'processed_this_run' => 0,
                'total_rr' => 0,
                'cursor_start' => 0,
                'cursor_end' => 0,
                'message' => 'Aucun participant RaceResult.',
            ];
        }

        $batchSize = max(1, (int)($this->config['rr_save_batch_size'] ?? 2));
        $ffaBatchSize = max(1, (int)($this->config['ffa_fetch_batch_size'] ?? 2));
        $maxRuntime = max(5, (int)($this->config['max_run_seconds'] ?? 25));
        $startedAt = microtime(true);

        $cursorStart = $this->db->getCursor($eventId);
        if ($cursorStart >= $total) {
            $cursorStart = 0;
        }
        $cursor = $cursorStart;

        $processed = 0;
        $inspected = 0;
        $cacheHits = 0;
        $cacheFound = 0;
        $cacheNotFound = 0;
        $ffaFetched = 0;
        $runnerIdFound = 0;
        $runnerIdNotFound = 0;
        $licenceFound = 0;
        $palmaresFound = 0;
        $cacheInserted = 0;
        $ready = 0;
        $pushed = 0;
        $errors = 0;
        $skipped = 0;
        $deferred = 0;
        $wrapped = false;

        $pendingRows = [];
        $pendingKeys = [];
        $pendingBibs = [];

        while ($inspected < $total && (microtime(true) - $startedAt) < $maxRuntime) {
            // Une fois le micro-lot FFA atteint, on laisse le prochain cron reprendre au curseur courant.
            if ($ffaFetched >= $ffaBatchSize) {
                $deferred = max(0, $total - $inspected);
                break;
            }

            $p = $participants[$cursor];
            $currentIndex = $cursor;
            $cursor = ($cursor + 1) % $total;
            if ($cursor === 0 && $currentIndex !== 0) {
                $wrapped = true;
            }
            $inspected++;
            $processed++;
            $this->db->setCursor($eventId, $cursor);

            $bib = (string)($p['Bib'] ?? '');
            if (!$bib || !trim((string)($p['Lastname'] ?? '')) || !trim((string)($p['Firstname'] ?? ''))) {
                $skipped++;
                continue;
            }

            $key = self::cacheKey($p);

            try {
                $cache = $this->readCache($key);

                if ($cache) {
                    $cacheHits++;
                    if (!empty($cache['runner_id'])) {
                        $cacheFound++;
                    } else {
                        $cacheNotFound++;
                        $skipped++;
                        continue;
                    }

                    // Déjà poussé : on avance simplement le curseur, sans refaire RR ni FFA.
                    if (!empty($cache['pushed_at'])) {
                        continue;
                    }
                } else {
                    $cache = $this->fetchAndCache($p, $key);
                    $ffaFetched++;
                    $cacheInserted++;

                    if (!$cache || empty($cache['runner_id'])) {
                        $runnerIdNotFound++;
                        $skipped++;
                        $this->db->log($eventId, $bib, 'skip', 'Runner FFA introuvable ou aucun résultat FFA');
                        continue;
                    }

                    $runnerIdFound++;
                    if (!empty($cache['licence'])) {
                        $licenceFound++;
                    }
                    if (!empty($cache['palmares'])) {
                        $palmaresFound++;
                    }
                }

                $pendingRows[] = $this->makeSaveRow($bib, $cache, $mapRunnerId, $mapLicence, $mapPalmares);
                $pendingKeys[] = $key;
                $pendingBibs[] = $bib;
                $ready++;

                if (count($pendingRows) >= $batchSize) {
                    $this->flushRows($eventId, $pendingRows, $pendingKeys, $pendingBibs, $pushed, $errors);
                }
            } catch (Throwable $e) {
                $errors++;
                $this->db->log($eventId, $bib, 'error', $e->getMessage());
            }
        }

        $this->flushRows($eventId, $pendingRows, $pendingKeys, $pendingBibs, $pushed, $errors);

        return [
            'processed_this_run' => $processed,
            'inspected_this_run' => $inspected,
            'total_rr' => $total,
            'cursor_start' => $cursorStart,
            'cursor_end' => $cursor,
            'wrapped_to_beginning' => $wrapped,
            'cache_hits' => $cacheHits,
            'cache_found' => $cacheFound,
            'cache_not_found' => $cacheNotFound,
            'ffa_fetched_this_run' => $ffaFetched,
            'ffa_fetch_batch_size' => $ffaBatchSize,
            'runnerid_found' => $runnerIdFound,
            'runnerid_not_found' => $runnerIdNotFound,
            'licence_found' => $licenceFound,
            'palmares_found' => $palmaresFound,
            'cache_inserted' => $cacheInserted,
            'ready_to_push' => $ready,
            'pushed' => $pushed,
            'skipped' => $skipped,
            'deferred_next_run' => $deferred,
            'errors' => $errors,
            'rr_save_batch_size' => $batchSize,
            'max_run_seconds' => $maxRuntime,
        ];
    }

    private function makeSaveRow(string $bib, array $cache, string $mapRunnerId, string $mapLicence, string $mapPalmares): array
    {
        return [
            'Bib' => $bib,
            $mapRunnerId => $cache['runner_id'] ?? '',
            $mapLicence => $cache['licence'] ?? '',
            $mapPalmares => $cache['palmares'] ?? '',
        ];
    }

    private function flushRows(string $eventId, array &$rows, array &$keys, array &$bibs, int &$pushed, int &$errors): void
    {
        if (!$rows) return;
        try {
            $this->rr->saveFields($eventId, $rows);
            foreach ($keys as $key) {
                $this->markPushed($key);
            }
            foreach ($bibs as $bib) {
                $this->db->log($eventId, (string)$bib, 'ok', 'Infos FFA poussées');
            }
            $pushed += count($rows);
        } catch (Throwable $e) {
            $errors += count($rows);
            foreach ($bibs as $bib) {
                $this->db->log($eventId, (string)$bib, 'error', $e->getMessage());
            }
        } finally {
            $rows = [];
            $keys = [];
            $bibs = [];
        }
    }

    private function readCache(string $key): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM runner_cache WHERE cache_key = :k');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function fetchAndCache(array $p, string $key): ?array
    {
        $data = $this->ffa->fetchRunner((string)$p['Lastname'], (string)$p['Firstname'], (string)$p['Sex'], (string)$p['DateofBirth']);
        usleep((int)($this->config['ffa_delay_us'] ?? 500000));

        if (!$data) {
            $this->saveCache($key, $p, '', '', '', []);
            return null;
        }

        $palmares = PalmaresFormatter::format($data['results'] ?? [], (int)($this->config['max_palmares_results'] ?? 8));
        $this->saveCache($key, $p, $data['runner_id'] ?? '', $data['licence'] ?? '', $palmares, $data);

        return [
            'runner_id' => $data['runner_id'] ?? '',
            'licence' => $data['licence'] ?? '',
            'palmares' => $palmares,
            'pushed_at' => null,
        ];
    }

    private function saveCache(string $key, array $p, string $runnerId, string $licence, string $palmares, array $raw): void
    {
        $stmt = $this->db->pdo()->prepare(<<<SQL
INSERT INTO runner_cache(cache_key, lastname, firstname, sex, dateofbirth, runner_id, licence, palmares, raw_payload, fetched_at, pushed_at)
VALUES(:k,:ln,:fn,:sx,:dob,:rid,:lic,:pal,:raw,:f,NULL)
ON CONFLICT(cache_key) DO UPDATE SET
runner_id=excluded.runner_id, licence=excluded.licence, palmares=excluded.palmares, raw_payload=excluded.raw_payload, fetched_at=excluded.fetched_at, pushed_at=NULL
SQL);
        $stmt->execute([
            ':k' => $key,
            ':ln' => $p['Lastname'] ?? '',
            ':fn' => $p['Firstname'] ?? '',
            ':sx' => $p['Sex'] ?? '',
            ':dob' => $p['DateofBirth'] ?? '',
            ':rid' => $runnerId,
            ':lic' => $licence,
            ':pal' => $palmares,
            ':raw' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':f' => gmdate('c'),
        ]);
    }

    private function markPushed(string $key): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE runner_cache SET pushed_at = :p WHERE cache_key = :k');
        $stmt->execute([':p' => gmdate('c'), ':k' => $key]);
    }
}
