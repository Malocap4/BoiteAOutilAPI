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
     * Exécution volontairement incrémentale pour éviter les 502/timeouts HTTP :
     * - on parcourt tous les participants RR ;
     * - on pousse vers RR toutes les données déjà prêtes en cache ;
     * - on limite les nouvelles requêtes FFA à ffa_fetch_batch_size coureurs par exécution ;
     * - après chaque micro-lot FFA, on pousse immédiatement les nouvelles données vers RR.
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
        $batchSize = max(1, (int)($this->config['rr_save_batch_size'] ?? 2));
        $ffaBatchSize = max(1, (int)($this->config['ffa_fetch_batch_size'] ?? 2));
        $maxRuntime = max(5, (int)($this->config['max_run_seconds'] ?? 25));
        $startedAt = microtime(true);

        $processed = 0;
        $cacheHits = 0;
        $ffaFetched = 0;
        $ready = 0;
        $pushed = 0;
        $errors = 0;
        $skipped = 0;
        $deferred = 0;

        $pendingRows = [];
        $pendingKeys = [];
        $pendingBibs = [];

        foreach ($participants as $p) {
            if ((microtime(true) - $startedAt) >= $maxRuntime) {
                $deferred++;
                continue;
            }

            $bib = (string)($p['Bib'] ?? '');
            if (!$bib || !trim((string)($p['Lastname'] ?? '')) || !trim((string)($p['Firstname'] ?? ''))) {
                $skipped++;
                continue;
            }
            $processed++;
            $key = self::cacheKey($p);

            try {
                $cache = $this->readCache($key);

                if (!$cache) {
                    if ($ffaFetched >= $ffaBatchSize) {
                        // On ne surcharge pas athle.fr dans la même exécution : prochain cron/run.
                        $deferred++;
                        continue;
                    }
                    $cache = $this->fetchAndCache($p, $key);
                    $ffaFetched++;
                    // Après chaque récupération FFA, on pousse ce qui est prêt sans attendre la fin.
                } else {
                    $cacheHits++;
                    // Si déjà poussé, inutile de renvoyer à chaque cron.
                    if (!empty($cache['pushed_at'])) {
                        continue;
                    }
                }

                if (!$cache || empty($cache['runner_id'])) {
                    $skipped++;
                    $this->db->log($eventId, $bib, 'skip', 'Runner FFA introuvable');
                    continue;
                }

                $pendingRows[] = $this->makeSaveRow($bib, $cache, $mapRunnerId, $mapLicence, $mapPalmares);
                $pendingKeys[] = $key;
                $pendingBibs[] = $bib;
                $ready++;

                if (count($pendingRows) >= $batchSize || $ffaFetched > 0) {
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
            'total_rr' => count($participants),
            'cache_hits' => $cacheHits,
            'ffa_fetched_this_run' => $ffaFetched,
            'ffa_fetch_batch_size' => $ffaBatchSize,
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
