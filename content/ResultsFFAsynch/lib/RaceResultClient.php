<?php
class RaceResultClient {
    private array $s;
    public function __construct(array $settings, bool $eventRequired = true){
        $this->s=$settings;
        if(empty($this->s['rr_api_key'])) throw new RuntimeException('Clé API RaceResult manquante.');
        if($eventRequired && empty($this->s['rr_event_id'])) throw new RuntimeException('Event ID RaceResult manquant.');
    }

    private function hostBase(): string {
        // On accepte les anciennes valeurs saisies (/api/public), mais on les ramène au host.
        $base = rtrim($this->s['rr_event_base'] ?? $this->s['rr_public_base'] ?? 'https://events.raceresult.com','/');
        $base = preg_replace('~/api/public$~', '', $base);
        return $base ?: 'https://events.raceresult.com';
    }
    private function decode(string $raw): array {
        $data=json_decode($raw,true);
        if(!is_array($data)) throw new RuntimeException('Réponse JSON RaceResult invalide: '.substr($raw,0,800));
        return $data;
    }
    private function decodeXmlList(string $raw): array {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw);
        if ($xml === false) throw new RuntimeException('Réponse RaceResult ni JSON ni XML exploitable: '.substr($raw,0,800));
        $rows=[];
        foreach ($xml->record as $rec) {
            $row=[];
            foreach ($rec->children() as $k=>$v) $row[$k]=trim((string)$v);
            if($row) $rows[]=$row;
        }
        // Tolérance pour d'autres enveloppes XML éventuelles.
        if (!$rows) {
            foreach ($xml->xpath('//record') ?: [] as $rec) {
                $row=[];
                foreach ($rec->children() as $k=>$v) $row[$k]=trim((string)$v);
                if($row) $rows[]=$row;
            }
        }
        return $rows;
    }
    private function decodeJsonOrXmlList(string $raw): array {
        $trim=ltrim($raw);
        if ($trim !== '' && $trim[0] === '<') return $this->decodeXmlList($raw);
        return $this->decode($raw);
    }
    private function tokenHeaders(): array { return ['Accept: application/json, text/plain, */*','Authorization: Bearer '.$this->getToken()]; }

    public function getToken(bool $force=false): string {
        $now=time();
        if(!$force && !empty($this->s['rr_token']) && (int)($this->s['rr_token_expires_at']??0) > $now+120) return $this->s['rr_token'];
        return $this->login();
    }

    private function login(): string {
        $url=$this->hostBase().'/api/public/login';
        $raw = Http::postRaw(
            $url,
            http_build_query(['apikey' => $this->s['rr_api_key']]),
            ['Accept: application/json, text/plain, */*', 'Content-Type: application/x-www-form-urlencoded']
        );
        $token = $this->extractToken($raw);
        if ($token === '') throw new RuntimeException('Token/session RaceResult vide après login. Réponse: '.substr($raw,0,500));

        $this->s['rr_token']=$token;
        $this->s['rr_token_expires_at']=time()+3300;
        $saved=Settings::load();
        $saved['rr_api_key']=$this->s['rr_api_key'];
        $saved['rr_token']=$token;
        $saved['rr_token_expires_at']=$this->s['rr_token_expires_at'];
        $saved['rr_event_base']=$this->hostBase();
        $saved['rr_public_base']=$this->hostBase().'/api/public';
        Settings::save($saved);
        Store::log('RR login OK, token reçu.');
        return $token;
    }

    private function extractToken(string $raw): string {
        $trim = trim($raw);
        $json = json_decode($trim, true);
        if (is_string($json) && $json !== '') return $json;
        if (is_array($json)) {
            $keys = ['token','Token','session','Session','sessionID','SessionID','sessionId','SessionId','id','ID','access_token','accessToken'];
            foreach ($keys as $k) if (!empty($json[$k]) && is_string($json[$k])) return trim($json[$k]);
            // Certains endpoints renvoient {"data":{"token":"..."}}
            foreach (['data','result','response'] as $node) {
                if (!empty($json[$node]) && is_array($json[$node])) {
                    foreach ($keys as $k) if (!empty($json[$node][$k]) && is_string($json[$node][$k])) return trim($json[$node][$k]);
                }
            }
        }
        // Sinon, l'API peut répondre le token en texte brut.
        if (preg_match('/^[A-Za-z0-9_\-.~+\/=]{20,}$/', $trim)) return trim($trim, " \t\r\n\"");
        return '';
    }

    private function rrGet(string $url): string {
        try { return Http::get($url, $this->tokenHeaders()); }
        catch(Throwable $e){
            Store::log('RR GET retry après erreur: '.$e->getMessage());
            $this->getToken(true);
            return Http::get($url, $this->tokenHeaders());
        }
    }
    private function rrPostRaw(string $url, string $body, array $headers=[]): string {
        $headers=array_merge($headers, $this->tokenHeaders());
        try { return Http::postRaw($url, $body, $headers); }
        catch(Throwable $e){
            Store::log('RR POST retry après erreur: '.$e->getMessage());
            $this->getToken(true);
            $headers=array_filter($headers, fn($h)=>stripos($h,'Authorization:')!==0);
            $headers[]='Authorization: Bearer '.$this->s['rr_token'];
            return Http::postRaw($url, $body, $headers);
        }
    }
    private function rrPostJson(string $url, $payload): string {
        try { return Http::postJson($url, $payload, $this->tokenHeaders()); }
        catch(Throwable $e){ $this->getToken(true); return Http::postJson($url, $payload, $this->tokenHeaders()); }
    }

    public function loadEvents(?string $from=null, ?string $to=null): array {
        $from=$from ?: ($this->s['event_date_from'] ?? date('Y-m-d', strtotime('-15 days')));
        $to=$to ?: ($this->s['event_date_to'] ?? date('Y-m-d', strtotime('+1 month')));
        $y1=(int)substr($from,0,4); $y2=(int)substr($to,0,4); if($y2<$y1) [$y1,$y2]=[$y2,$y1];
        $all=[]; $debug=[];
        for($y=$y1;$y<=$y2;$y++){
            $q=['year'=>$y,'filter'=>'','addsettings'=>'EventName,EventDate,EventDate2,EventLocation,EventCountry'];
            $url=$this->hostBase().'/api/public/eventlist?'.http_build_query($q);
            $raw=$this->rrGet($url);
            $rows=$this->decode($raw);
            // Tolérance si RR enveloppe la liste dans une clé.
            if (isset($rows['events']) && is_array($rows['events'])) $rows=$rows['events'];
            if (isset($rows['Events']) && is_array($rows['Events'])) $rows=$rows['Events'];
            if (isset($rows['data']) && is_array($rows['data'])) $rows=$rows['data'];
            $debug[]=['year'=>$y,'count'=>is_array($rows)?count($rows):0,'url'=>$url];
            foreach($rows as $r){ if(is_array($r)) $all[]=$r; }
        }
        Store::write('rr_eventlist_debug.json', $debug);
        Store::log('RR eventlist brut: '.count($all).' évènement(s), années '.$y1.'-'.$y2.'.');

        $out=[]; $seen=[];
        foreach($all as $r){
            $id=(string)($r['ID']??$r['Id']??$r['id']??''); if(!$id || isset($seen[$id])) continue;
            $d1=(string)($r['EventDate']??$r['Date']??''); $d2=(string)($r['EventDate2']??$d1);
            if($d1 && $d1>$to) continue; if($d2 && $d2<$from) continue;
            $seen[$id]=1;
            $out[]=['id'=>$id,'name'=>(string)($r['EventName']??$r['Name']??('Event '.$id)),'date'=>$d1,'date2'=>$d2,'location'=>(string)($r['EventLocation']??''),'participants'=>(int)($r['Participants']??0),'raw'=>$r];
        }
        usort($out, fn($a,$b)=>strcmp($a['date'],$b['date']) ?: strcmp($a['name'],$b['name']));
        Store::log('RR eventlist filtré '.$from.' -> '.$to.' : '.count($out).' évènement(s).');
        return $out;
    }

    public function loadUdfs(): array {
        $eventId=trim((string)$this->s['rr_event_id']);
        $url=$this->hostBase().'/_'.rawurlencode($eventId).'/api/multirequest?lang=en-fr';
        $data=$this->decode($this->rrPostRaw($url, '["Fields"]', ['Content-Type: text/plain;charset=UTF-8']));
        $rows=$data['Fields']??[];
        $out=[];
        foreach($rows as $r){
            if(!is_array($r) || empty($r['Name'])) continue;
            $name=(string)$r['Name']; $label=trim((string)($r['Label']??'')); $group=trim((string)($r['Group']??''));
            $title=($group?"[$group] ":'').($label ?: $name);
            if($label && $label!==$name) $title.=' — '.$name;
            $out[]=['field'=>$name,'label'=>$title,'id'=>(string)($r['ID']??''),'enabled'=>(bool)($r['Enabled']??true),'raw'=>$r];
        }
        usort($out, fn($a,$b)=>strcmp($a['label'],$b['label']));
        return $out;
    }

    public function loadParticipants(): array {
        $fields = ['ID','Firstname','Lastname','DateOfBirth'];
        $eventId=trim((string)$this->s['rr_event_id']);
        // RaceResult peut renvoyer la liste en XML même si un format JSON est demandé
        // selon la configuration/export côté évènement. On parse donc les deux formats.
        $url=$this->hostBase().'/_'.rawurlencode($eventId).'/api/data/list?'.http_build_query(['fields'=>implode(',',$fields)]);
        $data=$this->decodeJsonOrXmlList($this->rrGet($url));
        return $this->normalizeParticipants($data);
    }
    private function normalizeParticipants(array $data): array {
        $rows = $data['data'] ?? $data['rows'] ?? $data['participants'] ?? $data;
        $out=[];
        foreach($rows as $r){
            if(!is_array($r)) continue;
            $id=$r['ID']??$r['Id']??$r['id']??$r['Bib']??$r['bib']??null;
            $fn=$r['Firstname']??$r['FirstName']??$r['firstname']??'';
            $ln=$r['Lastname']??$r['LastName']??$r['lastname']??'';
            $dob=$r['DateOfBirth']??$r['DOB']??$r['dateOfBirth']??'';
            if($id && ($fn || $ln)) $out[]=['rr_id'=>(string)$id,'firstname'=>(string)$fn,'lastname'=>(string)$ln,'date_of_birth'=>(string)$dob];
        }
        return $out;
    }
    public function saveFields(string $rrId, array $values): void {
        $eventId=trim((string)$this->s['rr_event_id']);
        $url=$this->hostBase().'/_'.rawurlencode($eventId).'/api/part/savefields?'.http_build_query(['id'=>$rrId,'ID'=>$rrId,'noHistory'=>'1']);
        $this->rrPostJson($url, $values);
    }
}
