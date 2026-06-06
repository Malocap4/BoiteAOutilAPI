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
        $defaultYear = $this->detectResultsYear($html);

        // La page FFA peut répéter plusieurs blocs de résultats et ajouter des lignes "detail-row" mobile.
        // On lit uniquement les lignes de données qui suivent une ligne d'en-têtes et on mappe par libellé,
        // jamais par position fixe globale.
        foreach ($xp->query('//tr') as $tr) {
            if (!$tr instanceof DOMElement) continue;
            $class = ' ' . $tr->getAttribute('class') . ' ';
            if (str_contains($class, ' detail-row ')) continue;
            if ($xp->query('./th', $tr)->length > 0) continue;
            if ($xp->query('./td', $tr)->length === 0) continue;

            $headerMap = $this->nearestPreviousHeaderMap($xp, $tr);
            if (!$this->looksLikeResultsHeaderMap($headerMap)) continue;

            $cells = $this->rowCells($tr);
            $row = $this->mapRowByHeaders($cells, $headerMap);

            $event = $this->pick($row, ['epreuve', 'competition', 'meeting', 'event', 'libelle', 'nom epreuve']);
            $resultRaw = $this->pick($row, ['resultat', 'résultat', 'temps', 'chrono', 'performance', 'perf', 'time']);
            $dateRaw = $this->pick($row, ['date', 'jour']);
            $location = $this->pick($row, ['ville', 'lieu', 'location', 'city']);
            $infos = $this->pick($row, ['infos', 'info']);

            // Ignore les lignes décoratives ou incomplètes : elles sont la cause des faux blocs
            // "Tour / Niveau / Ville" observés dans le palmarès.
            if ($event === '' && $resultRaw === '' && $dateRaw === '' && $location === '') {
                continue;
            }
            if ($event === '' && $resultRaw === '') {
                continue;
            }

            [$place, $time] = $this->splitFfaResult($resultRaw);
            if ($place === '') {
                $place = $this->pick($row, ['place', 'clt', 'classement', 'rang', 'rank']);
            }
            if ($time === '') {
                $time = $this->pick($row, ['temps', 'chrono', 'performance', 'perf', 'time']);
            }

            [$category, $sex] = $this->parseInfosCategorySex($infos);

            $results[] = [
                'event' => $event,
                'location' => $location,
                'date' => $this->formatFfaDate($dateRaw, $defaultYear),
                'place' => $place,
                'sex_place' => $this->pick($row, ['place sexe', 'clt sexe', 'classement sexe', 'rang sexe']),
                'cat_place' => $this->pick($row, ['place cat', 'clt cat', 'classement cat', 'place categorie', 'clt categorie']),
                'sex' => $this->pick($row, ['sexe', 'sex']) ?: $sex,
                'category' => $this->pick($row, ['cat', 'categorie', 'category']) ?: $category,
                'time' => $time,
            ];
        }
        return $results;
    }

    private function nearestPreviousHeaderMap(DOMXPath $xp, DOMElement $tr): array
    {
        // Cherche la ligne d'en-tête précédente dans le même tbody/table. C'est plus fiable que
        // "première ligne th de la table", car athle.fr répète les en-têtes dans la même table.
        $prev = $tr->previousSibling;
        while ($prev) {
            if ($prev instanceof DOMElement && strtolower($prev->tagName) === 'tr') {
                if ($xp->query('./th', $prev)->length > 0) {
                    return $this->buildHeaderMap($this->rowCells($prev, true));
                }
            }
            $prev = $prev->previousSibling;
        }
        return [];
    }

    private function looksLikeResultsHeaderMap(array $map): bool
    {
        return isset($map['epreuve'], $map['resultat'])
            || isset($map['epreuve'], $map['temps'])
            || isset($map['event'], $map['resultat']);
    }

    private function splitFfaResult(string $raw): array
    {
        $raw = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $raw = str_replace("\xC2\xA0", ' ', $raw);
        $raw = trim(preg_replace('/\s+/u', ' ', strip_tags($raw)));
        if ($raw === '' || $raw === '-') return ['', ''];

        // Exemples FFA : "2. 16h34'44''", "211. 3h44'57''", "18. 7h59'58''".
        if (preg_match('/^([0-9]+)\s*[\.\)\-:]\s*(.+)$/u', $raw, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        return ['', $raw];
    }

    private function parseInfosCategorySex(string $infos): array
    {
        $infos = strtoupper(trim($infos));
        if ($infos === '' || $infos === '-') return ['', ''];
        // Exemple : SEM/91, M0M/91. On garde la catégorie lisible (SE, M0...),
        // et le sexe final M/F si présent. On ne l'affiche que si un classement sexe/catégorie existe.
        $main = preg_split('/[\/\s]+/', $infos)[0] ?? '';
        if (preg_match('/^([A-Z]+\d*)([MF])$/u', $main, $m)) {
            return [$m[1], $m[2]];
        }
        return [$main, ''];
    }

    private function detectResultsYear(string $html): string
    {
        $text = $this->visibleText($html);
        if (preg_match('/Année\s*:\s*(20\d{2})/iu', $text, $m)) {
            return $m[1];
        }
        if (preg_match('/Saison\s*:\s*(20\d{2})/iu', $text, $m)) {
            return $m[1];
        }
        return date('Y');
    }

    private function formatFfaDate(string $date, string $year = ''): string
    {
        $date = trim(html_entity_decode($date, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($date === '' || $date === '-') return '';
        if (preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?$/', $date, $m)) {
            $d = (int)$m[1];
            $mo = (int)$m[2];
            $y = $m[3] ?? $year;
            if (strlen($y) === 2) $y = '20' . $y;
            $months = [1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre'];
            return $d . ' ' . ($months[$mo] ?? $mo) . ($y ? ' ' . $y : '');
        }
        return $date;
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
