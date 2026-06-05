<?php
class FfaClient {
    private array $seasons;
    public function __construct($seasons){
        if (!is_array($seasons)) $seasons = [$seasons];
        $this->seasons = array_values(array_unique(array_filter(array_map('intval', $seasons))));
        if (!$this->seasons) $this->seasons = [(int)date('Y')];
    }
    public function findAthlete(array $p): ?array {
        foreach ($this->seasons as $season) {
            $q = ['frmpostback'=>'true','frmbase'=>'resultats','frmmode'=>'1','frmespace'=>'0','frmsaison'=>$season,'frmclub'=>'','frmlicence'=>'','frmnom'=>$p['lastname'],'frmprenom'=>$p['firstname'],'frmsexe'=>'','frmdepartement'=>'','frmligue'=>'','frmcomprch'=>''];
            $html = Http::get('https://www.athle.fr/bases/liste.aspx?'.http_build_query($q), ['Accept-Language: fr-FR,fr;q=0.9']);
            $found = $this->parseAthleteSearch($html, $p);
            if ($found) { $found['season'] = $season; return $found; }
            usleep(120000);
        }
        return null;
    }
    private function parseAthleteSearch(string $html, array $p): ?array {
        libxml_use_internal_errors(true); $dom=new DOMDocument(); $dom->loadHTML('<?xml encoding="utf-8"?>'.$html); $xp=new DOMXPath($dom);
        foreach($xp->query('//a[contains(@href,"/athletes/")]') as $a){
            $href=$a->getAttribute('href'); if(!preg_match('~/athletes/(\d+)~',$href,$m)) continue;
            $row=$a; while($row && strtolower($row->nodeName)!=='tr') $row=$row->parentNode;
            $txt=$row ? $row->textContent : $a->textContent;
            if($this->looksLike($txt,$p)) return ['ffa_id'=>$m[1], 'license'=>$this->extractLicense($txt), 'raw'=>trim(preg_replace('/\s+/u',' ',$txt))];
        }
        return null;
    }
    private function looksLike(string $txt,array $p): bool { $n=$this->norm($txt); return str_contains($n,$this->norm($p['lastname'])) && str_contains($n,$this->norm($p['firstname'])); }
    private function norm(string $s): string { $s=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s); return strtolower(preg_replace('/[^a-z0-9]/i','',$s)); }
    private function extractLicense(string $txt): string { return preg_match('/\b(\d{5,9}|[A-Z]{1,3}\d{4,9})\b/u',$txt,$m) ? $m[1] : ''; }
    public function results(string $ffaId): array {
        $html = Http::get('https://www.athle.fr/athletes/'.rawurlencode($ffaId).'/resultats', ['Accept-Language: fr-FR,fr;q=0.9']);
        return $this->parseResults($html);
    }
    private function parseResults(string $html): array {
        libxml_use_internal_errors(true); $dom=new DOMDocument(); $dom->loadHTML('<?xml encoding="utf-8"?>'.$html); $xp=new DOMXPath($dom); $results=[];
        foreach($xp->query('//tr[td]') as $tr){
            $cells=[]; foreach($xp->query('./td',$tr) as $td) $cells[]=trim(preg_replace('/\s+/u',' ',$td->textContent));
            if(count($cells)<3) continue;
            $line=implode(' | ',$cells);
            if(!preg_match('/\b\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4}\b/',$line,$dm)) continue;
            $results[]=$this->mapResult($cells,$dm[0]);
        }
        return array_values(array_filter($results));
    }
    private function mapResult(array $c,string $date): ?array {
        $event=$c[0] ?? ''; $type=$c[1] ?? ''; $distance=''; $place=''; $time='';
        foreach($c as $v){ if(!$distance && preg_match('/\b\d+(?:[,.]\d+)?\s*(m|km)\b/i',$v)) $distance=$v; if(!$place && preg_match('/\b\d{1,5}\s*(?:e|er|ème|\/)/iu',$v)) $place=$v; if(!$time && preg_match('/\b\d{1,2}:\d{2}(?::\d{2})?(?:[.,]\d+)?\b/',$v)) $time=$v; }
        $event = $this->firstNonEmpty($c, ['meeting','course','épreuve']) ?: $event;
        if(!$event && !$time) return null;
        return ['event'=>$event,'distance'=>$distance,'type'=>$type,'date'=>$date,'rank'=>$place,'time'=>$time,'cells'=>$c];
    }
    private function firstNonEmpty(array $c,array $h): string { foreach($c as $v){ if(trim($v)!=='') return $v; } return ''; }
    public static function formatResults(array $rows): string {
        $blocks=[]; foreach($rows as $r){ $blocks[] = trim(($r['event'] ?: 'Résultat FFA').' ('.($r['distance'] ?: '-').' - '.($r['type'] ?: '-').")\n".($r['date'] ?: '-')."\nPlace : ".($r['rank'] ?: '-').' / Temps : '.($r['time'] ?: '-')); }
        return implode("\n\n", $blocks);
    }
}
