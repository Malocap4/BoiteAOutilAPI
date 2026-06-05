<?php
final class FfaClient
{
    private array $config;
    private HttpClient $http;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->http = new HttpClient();
    }

    public function fetchRunner(string $lastname, string $firstname, string $sex = '', string $dateOfBirth = ''): ?array
    {
        $runnerId = $this->findRunnerId($lastname, $firstname, $sex, $dateOfBirth);
        if (!$runnerId) {
            return null;
        }
        $details = $this->fetchRunnerResults($runnerId);
        $details['runner_id'] = $runnerId;
        return $details;
    }

    public function findRunnerId(string $lastname, string $firstname, string $sex = '', string $dateOfBirth = ''): ?string
    {
        $params = [
            'frmpostback' => 'true',
            'frmbase' => 'resultats',
            'frmmode' => '1',
            'frmespace' => '0',
            'frmsaison' => '',
            'frmclub' => '',
            'frmlicence' => '',
            'frmnom' => $lastname,
            'frmprenom' => $firstname,
            'frmsexe' => $this->normalizeSexForFfa($sex),
            'frmdepartement' => '',
            'frmligue' => '',
            'frmcomprch' => '',
        ];
        $url = $this->config['ffa_base_url'] . '/bases/liste.aspx?' . http_build_query($params);
        $html = $this->getHtml($url);
        $candidates = $this->parseRunnerSearchResults($html);
        if (!$candidates) {
            return null;
        }
        // Filtrage prudent : nom/prénom normalisés, sexe et date de naissance si disponibles dans la page.
        $ln = self::norm($lastname);
        $fn = self::norm($firstname);
        $sexNorm = strtolower(substr(trim($sex), 0, 1));
        foreach ($candidates as $c) {
            $okName = (!$c['lastname'] || self::norm($c['lastname']) === $ln) && (!$c['firstname'] || self::norm($c['firstname']) === $fn);
            $okSex = !$sexNorm || empty($c['sex']) || strtolower(substr($c['sex'], 0, 1)) === $sexNorm;
            $okDob = !$dateOfBirth || empty($c['dateofbirth']) || $this->sameDate($dateOfBirth, $c['dateofbirth']);
            if ($okName && $okSex && $okDob) {
                return $c['runner_id'];
            }
        }
        return $candidates[0]['runner_id'] ?? null;
    }

    public function fetchRunnerResults(string $runnerId): array
    {
        $url = $this->config['ffa_base_url'] . '/athletes/' . rawurlencode($runnerId) . '/resultats';
        $html = $this->getHtml($url);
        return [
            'licence' => $this->parseLicence($html),
            'results' => $this->parseResults($html),
            'raw_hash' => sha1($html),
        ];
    }

    private function getHtml(string $url): string
    {
        $res = $this->http->get($url, [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
            'User-Agent' => $this->config['ffa_user_agent'],
        ], 40);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException('FFA HTTP ' . $res['status'] . ' : ' . mb_substr($res['body'], 0, 300));
        }
        return $this->toUtf8($res['body']);
    }

    private function parseRunnerSearchResults(string $html): array
    {
        $dom = $this->dom($html);
        $xp = new DOMXPath($dom);
        $rows = $xp->query('//tr[.//a[contains(@href,"/athletes/") or contains(@href,"athletes/")]]');
        $out = [];
        foreach ($rows as $tr) {
            $a = $xp->query('.//a[contains(@href,"/athletes/") or contains(@href,"athletes/")]', $tr)->item(0);
            if (!$a) continue;
            $href = $a->getAttribute('href');
            if (!preg_match('~/athletes/(\d+)~', $href, $m)) continue;
            $cells = $this->rowCells($tr);
            $headerMap = $this->nearestHeaderMap($xp, $tr);
            $row = $this->mapRowByHeaders($cells, $headerMap);
            $nameText = trim($a->textContent);
            [$first, $last] = $this->splitName($nameText);
            $out[] = [
                'runner_id' => $m[1],
                'firstname' => $row['prenom'] ?? $row['firstname'] ?? $first,
                'lastname' => $row['nom'] ?? $row['lastname'] ?? $last,
                'sex' => $row['sexe'] ?? $row['sex'] ?? '',
                'dateofbirth' => $row['naissance'] ?? $row['date naissance'] ?? $row['dateofbirth'] ?? '',
            ];
        }
        // Fallback global si les lignes ne sont pas tabulaires.
        if (!$out && preg_match_all('~/athletes/(\d+)[^>]*>(.*?)</a>~isu', $html, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                [$first, $last] = $this->splitName(strip_tags($m[2]));
                $out[] = ['runner_id' => $m[1], 'firstname' => $first, 'lastname' => $last, 'sex' => '', 'dateofbirth' => ''];
            }
        }
        return $out;
    }

    private function parseLicence(string $html): string
    {
        $text = $this->visibleText($html);
        // Recherche par appellation, pas par position.
        $patterns = [
            '/\bLicence\s*[:#\-]?\s*([A-Z0-9]{4,})/iu',
            '/\bN[°o]\s*licence\s*[:#\-]?\s*([A-Z0-9]{4,})/iu',
            '/\bLicenci[ée]\s*[:#\-]?\s*([A-Z0-9]{4,})/iu',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $text, $m)) {
                return trim($m[1]);
            }
        }
        return '';
    }

    private function parseResults(string $html): array
    {
        $dom = $this->dom($html);
        $xp = new DOMXPath($dom);
        $results = [];
        foreach ($xp->query('//table[.//tr]') as $table) {
            $headers = $this->headersForTable($table);
            if (!$headers) continue;
            $map = $this->buildHeaderMap($headers);
            // On ne considère une table comme résultat que si elle contient des libellés compatibles.
            $score = 0;
            foreach (['date', 'epreuve', 'event', 'place', 'temps', 'time', 'distance', 'type', 'cat', 'categorie'] as $needle) {
                if (array_key_exists($needle, $map)) $score++;
            }
            if ($score < 2) continue;
            foreach ($xp->query('.//tr[td]', $table) as $tr) {
                $cells = $this->rowCells($tr);
                if (count($cells) < 2) continue;
                $row = $this->mapRowByHeaders($cells, $map);
                $result = [
                    'event' => $this->pick($row, ['epreuve', 'competition', 'meeting', 'event', 'nom', 'libelle']),
                    'distance' => $this->pick($row, ['distance', 'dist']),
                    'type' => $this->pick($row, ['type', 'discipline', 'nature']),
                    'date' => $this->pick($row, ['date', 'jour']),
                    'place' => $this->pick($row, ['place', 'clt', 'classement', 'rang', 'rank']),
                    'sex_place' => $this->pick($row, ['place sexe', 'clt sexe', 'classement sexe', 'rang sexe']),
                    'cat_place' => $this->pick($row, ['place cat', 'clt cat', 'classement cat', 'place categorie', 'clt categorie']),
                    'sex' => $this->pick($row, ['sexe', 'sex']),
                    'category' => $this->pick($row, ['cat', 'categorie', 'category']),
                    'time' => $this->pick($row, ['temps', 'chrono', 'performance', 'perf', 'time']),
                ];
                if ($result['event'] || $result['time'] || $result['place']) {
                    $results[] = $result;
                }
            }
        }
        return $results;
    }

    private function headersForTable(DOMElement $table): array
    {
        $xp = new DOMXPath($table->ownerDocument);
        foreach ($xp->query('.//tr[th]', $table) as $tr) {
            $cells = $this->rowCells($tr, true);
            if ($cells) return $cells;
        }
        foreach ($xp->query('.//tr[1]', $table) as $tr) {
            $cells = $this->rowCells($tr);
            if ($cells) return $cells;
        }
        return [];
    }

    private function nearestHeaderMap(DOMXPath $xp, DOMNode $tr): array
    {
        $table = $tr;
        while ($table && !($table instanceof DOMElement && strtolower($table->tagName) === 'table')) {
            $table = $table->parentNode;
        }
        if (!$table) return [];
        return $this->buildHeaderMap($this->headersForTable($table));
    }

    private function buildHeaderMap(array $headers): array
    {
        $map = [];
        foreach ($headers as $i => $h) {
            $k = $this->canonicalHeader($h);
            if ($k !== '') $map[$k] = $i;
        }
        return $map;
    }

    private function canonicalHeader(string $h): string
    {
        $h = self::norm($h);
        $aliases = [
            'nom' => ['nom', 'lastname', 'name'],
            'prenom' => ['prenom', 'firstname', 'first name'],
            'sexe' => ['sexe', 'sex', 'genre'],
            'dateofbirth' => ['date naissance', 'naissance', 'date de naissance', 'birth', 'birthday'],
            'date' => ['date', 'jour'],
            'epreuve' => ['epreuve', 'competition', 'meeting', 'event', 'libelle', 'nom epreuve'],
            'distance' => ['distance', 'dist'],
            'type' => ['type', 'discipline', 'nature'],
            'place' => ['place', 'clt', 'classement', 'rang', 'rank', 'scratch'],
            'place sexe' => ['place sexe', 'clt sexe', 'classement sexe', 'rang sexe'],
            'place cat' => ['place cat', 'clt cat', 'classement cat', 'place categorie', 'clt categorie', 'rang categorie'],
            'cat' => ['cat', 'categorie', 'category'],
            'temps' => ['temps', 'chrono', 'performance', 'perf', 'time'],
        ];
        foreach ($aliases as $canon => $list) {
            foreach ($list as $a) {
                if ($h === $a || str_contains($h, $a)) return $canon;
            }
        }
        return $h;
    }

    private function mapRowByHeaders(array $cells, array $map): array
    {
        $out = [];
        foreach ($map as $name => $idx) {
            $out[$name] = $cells[$idx] ?? '';
        }
        return $out;
    }

    private function rowCells(DOMNode $tr, bool $includeTh = false): array
    {
        $cells = [];
        foreach ($tr->childNodes as $td) {
            if (!$td instanceof DOMElement) continue;
            $tag = strtolower($td->tagName);
            if ($tag === 'td' || ($includeTh && $tag === 'th') || $tag === 'th') {
                $cells[] = trim(preg_replace('/\s+/u', ' ', $td->textContent));
            }
        }
        return $cells;
    }

    private function pick(array $row, array $keys): string
    {
        foreach ($keys as $k) {
            if (isset($row[$k]) && trim((string)$row[$k]) !== '') {
                return trim((string)$row[$k]);
            }
        }
        return '';
    }

    private function dom(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        return $dom;
    }

    private function visibleText(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function toUtf8(string $s): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            return mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1,Windows-1252,UTF-8');
        }
        return $s;
    }

    private static function norm(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    private function splitName(string $s): array
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        if (!$s) return ['', ''];
        $parts = explode(' ', $s);
        if (count($parts) === 1) return ['', $parts[0]];
        return [$parts[0], implode(' ', array_slice($parts, 1))];
    }

    private function normalizeSexForFfa(string $sex): string
    {
        $s = strtolower(trim($sex));
        if ($s === 'm' || str_starts_with($s, 'h')) return 'M';
        if ($s === 'f' || str_starts_with($s, 'w')) return 'F';
        return '';
    }

    private function sameDate(string $a, string $b): bool
    {
        $ta = strtotime(str_replace('/', '-', $a));
        $tb = strtotime(str_replace('/', '-', $b));
        return $ta && $tb && date('Y-m-d', $ta) === date('Y-m-d', $tb);
    }
}
