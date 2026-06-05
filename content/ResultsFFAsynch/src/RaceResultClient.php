<?php
final class RaceResultClient
{
    private array $config;
    private Db $db;
    private HttpClient $http;

    public function __construct(array $config, Db $db)
    {
        $this->config = $config;
        $this->db = $db;
        $this->http = new HttpClient();
    }

    public function setApiKey(string $apiKey): void
    {
        $this->db->setSetting('rr_api_key', trim($apiKey));
        $this->db->setSetting('rr_token', null);
    }

    public function token(): string
    {
        $token = $this->db->getSetting('rr_token');
        if ($token) {
            return $token;
        }
        return $this->login();
    }

    public function login(): string
    {
        $apiKey = $this->db->getSetting('rr_api_key');
        if (!$apiKey) {
            throw new RuntimeException('API Key RaceResult manquante.');
        }
        // L'endpoint /api/public/login attend la clé dans le body x-www-form-urlencoded.
        // Si on envoie seulement Authorization: Bearer, RR répond :
        // {"error":"no user, apikey or rruser_token given"}
        $loginAttempts = [
            http_build_query(['apikey' => $apiKey]),
            http_build_query(['rruser_token' => $apiKey]),
        ];

        $res = null;
        foreach ($loginAttempts as $body) {
            $res = $this->http->post($this->config['rr_login_url'], $body, [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]);
            if ($res['status'] >= 200 && $res['status'] < 300) {
                break;
            }
        }
        if (!$res || $res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException('Login RR impossible HTTP ' . ($res['status'] ?? 0) . ' : ' . mb_substr($res['body'] ?? '', 0, 300));
        }
        $body = trim($res['body']);
        $json = json_decode($body, true);
        $token = '';
        if (is_array($json)) {
            $token = $json['token'] ?? $json['Token'] ?? $json['access_token'] ?? '';
        }
        if (!$token && preg_match('/[A-Za-z0-9_\-]{40,}/', $body, $m)) {
            $token = $m[0];
        }
        if (!$token) {
            throw new RuntimeException('Token RR non trouvé dans la réponse login. Réponse: ' . mb_substr($body, 0, 300));
        }
        $this->db->setSetting('rr_token', $token);
        return $token;
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token()];
    }

    private function requestWithRefresh(callable $fn): array
    {
        $res = $fn($this->authHeaders());
        if ((int)$res['status'] === 440 || (int)$res['status'] === 401) {
            $this->db->setSetting('rr_token', null);
            $res = $fn($this->authHeaders());
        }
        return $res;
    }

    public function eventList(int $year): array
    {
        $url = $this->config['rr_base_url'] . '/api/public/eventlist?year=' . $year . '&filter=&addsettings=' . rawurlencode('EventName,EventDate,EventDate2,EventLocation,EventCountry');
        $res = $this->requestWithRefresh(fn($h) => $this->http->get($url, $h));
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException('eventlist RR HTTP ' . $res['status'] . ': ' . mb_substr($res['body'], 0, 300));
        }
        $data = json_decode($res['body'], true);
        return is_array($data) ? $data : [];
    }

    public function eventListRange(string $dateFrom, string $dateTo): array
    {
        $years = range((int)substr($dateFrom, 0, 4), (int)substr($dateTo, 0, 4));
        $all = [];
        foreach ($years as $year) {
            foreach ($this->eventList($year) as $e) {
                $d1 = $e['EventDate'] ?? null;
                $d2 = $e['EventDate2'] ?? $d1;
                if (!$d1) continue;
                if ($d1 <= $dateTo && $d2 >= $dateFrom) {
                    $all[$e['ID']] = $e;
                }
            }
        }
        uasort($all, fn($a, $b) => strcmp(($a['EventDate'] ?? '') . ($a['EventName'] ?? ''), ($b['EventDate'] ?? '') . ($b['EventName'] ?? '')));
        return array_values($all);
    }

    public function fields(string $eventId): array
    {
        $url = $this->config['rr_base_url'] . '/_' . rawurlencode($eventId) . '/api/multirequest?lang=' . rawurlencode($this->config['rr_lang']);
        $res = $this->requestWithRefresh(fn($h) => $this->http->post($url, '["Fields"]', array_merge($h, ['Content-Type' => 'text/plain;charset=UTF-8'])));
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException('Fields RR HTTP ' . $res['status'] . ': ' . mb_substr($res['body'], 0, 300));
        }
        $json = json_decode($res['body'], true);
        $fields = $json['Fields'] ?? [];
        $names = [];
        foreach ($fields as $f) {
            if (!empty($f['Enabled']) && !empty($f['Name'])) {
                $names[] = (string)$f['Name'];
            }
        }
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }

    public function participants(string $eventId): array
    {
        $fields = ['BIB', 'LASTNAME', 'FIRSTNAME', 'Sex', 'DateofBirth'];
        $url = $this->config['rr_base_url'] . '/_' . rawurlencode($eventId) . '/api/data/list?lang=' . rawurlencode($this->config['rr_lang'])
            . '&fields=' . rawurlencode(json_encode($fields))
            . '&filter=&filterbib=0&filtercontest=0&filtersex=&sort=BIB&listformat=jSON';
        $res = $this->requestWithRefresh(fn($h) => $this->http->get($url, array_merge($h, ['Content-Type' => 'text/plain;charset=UTF-8'])));
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException('participants RR HTTP ' . $res['status'] . ': ' . mb_substr($res['body'], 0, 300));
        }
        $rows = json_decode($res['body'], true);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'Bib' => $r[0] ?? '',
                'Lastname' => $r[1] ?? '',
                'Firstname' => $r[2] ?? '',
                'Sex' => $r[3] ?? '',
                'DateofBirth' => $r[4] ?? '',
            ];
        }
        return $out;
    }

    public function saveFields(string $eventId, array $rows): void
    {
        if (!$rows) return;
        $url = $this->config['rr_base_url'] . '/_' . rawurlencode($eventId) . '/api/part/savefields';
        $body = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $res = $this->requestWithRefresh(fn($h) => $this->http->post($url, $body, array_merge($h, ['Content-Type' => 'application/json'])));
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException('savefields RR HTTP ' . $res['status'] . ': ' . mb_substr($res['body'], 0, 500));
        }
    }
}
