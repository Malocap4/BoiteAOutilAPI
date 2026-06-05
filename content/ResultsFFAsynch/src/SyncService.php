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

    public function run(?int $limit = null): array
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
        $limit = $limit ?? (int)$this->config['max_participants_per_run'];
        $processed = 0;
        $pushed = 0;
        $errors = 0;

        foreach ($participants as $p) {
            if ($processed >= $limit) break;
            $bib = (string)($p['Bib'] ?? '');
            if (!$bib || !trim((string)$p['Lastname']) || !trim((string)$p['Firstname'])) {
                continue;
            }
            $processed++;
            try {
                $cache = $this->getOrFetchCache($p);
                if (!$cache || empty($cache['runner_id'])) {
                    $this->db->log($eventId, $bib, 'skip', 'Runner FFA introuvable');
                    continue;
                }
                $row = ['Bib' => $bib];
                $row[$mapRunnerId] = $cache['runner_id'] ?? '';
                $row[$mapLicence] = $cache['licence'] ?? '';
                $row[$mapPalmares] = $cache['palmares'] ?? '';
                $this->rr->saveFields($eventId, [$row]);
                $this->markPushed(self::cacheKey($p));
                $pushed++;
                $this->db->log($eventId, $bib, 'ok', 'Infos FFA poussées');
            } catch (Throwable $e) {
                $errors++;
                $this->db->log($eventId, $bib, 'error', $e->getMessage());
            }
        }
        return ['processed' => $processed, 'pushed' => $pushed, 'errors' => $errors, 'total_rr' => count($participants)];
    }

    private function getOrFetchCache(array $p): ?array
    {
        $key = self::cacheKey($p);
        $stmt = $this->db->pdo()->prepare('SELECT * FROM runner_cache WHERE cache_key = :k');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        $data = $this->ffa->fetchRunner((string)$p['Lastname'], (string)$p['Firstname'], (string)$p['Sex'], (string)$p['DateofBirth']);
        usleep((int)$this->config['ffa_delay_us']);
        if (!$data) {
            $this->saveCache($key, $p, '', '', '', []);
            return null;
        }
        $palmares = PalmaresFormatter::format($data['results'] ?? [], (int)$this->config['max_palmares_results']);
        $this->saveCache($key, $p, $data['runner_id'] ?? '', $data['licence'] ?? '', $palmares, $data);
        return [
            'runner_id' => $data['runner_id'] ?? '',
            'licence' => $data['licence'] ?? '',
            'palmares' => $palmares,
        ];
    }

    private function saveCache(string $key, array $p, string $runnerId, string $licence, string $palmares, array $raw): void
    {
        $stmt = $this->db->pdo()->prepare(<<<SQL
INSERT INTO runner_cache(cache_key, lastname, firstname, sex, dateofbirth, runner_id, licence, palmares, raw_payload, fetched_at)
VALUES(:k,:ln,:fn,:sx,:dob,:rid,:lic,:pal,:raw,:f)
ON CONFLICT(cache_key) DO UPDATE SET
runner_id=excluded.runner_id, licence=excluded.licence, palmares=excluded.palmares, raw_payload=excluded.raw_payload, fetched_at=excluded.fetched_at
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
