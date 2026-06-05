<?php
class RaceResultClient {
    private array $s;
    public function __construct(array $settings, bool $eventRequired = true){
        $this->s=$settings;
        if(!$this->s['rr_api_key']) throw new RuntimeException('Clé API RaceResult manquante.');
        if($eventRequired && !$this->s['rr_event_id']) throw new RuntimeException('Event ID RaceResult manquant.');
    }
    private function rootBase(): string {
        $tpl = trim($this->s['rr_api_base_template'] ?? 'https://api.raceresult.com/event/{eventId}/');
        $pos = strpos($tpl, '{eventId}');
        if ($pos !== false) return rtrim(substr($tpl, 0, $pos), '/').'/';
        $tpl = preg_replace('~/event/[^/]+/?$~', '/', rtrim($tpl,'/')) ?: $tpl;
        return rtrim($tpl, '/').'/';
    }
    private function url(string $endpoint, array $q=[]): string {
        $base = str_replace('{eventId}', rawurlencode($this->s['rr_event_id'] ?? ''), rtrim($this->s['rr_api_base_template'],'/').'/');
        $q['apiKey']=$this->s['rr_api_key'];
        return $base.ltrim($endpoint,'/').'?'.http_build_query($q);
    }
    private function accountUrl(string $endpoint, array $q=[]): string {
        $q['apiKey']=$this->s['rr_api_key'];
        return $this->rootBase().ltrim($endpoint,'/').'?'.http_build_query($q);
    }
    private function headers(): array { return ['Accept: application/json','Authorization: Bearer '.$this->s['rr_api_key'], 'X-API-Key: '.$this->s['rr_api_key']]; }
    private function decode(string $raw): array {
        $data=json_decode($raw,true);
        if(!is_array($data)) throw new RuntimeException('Réponse JSON invalide: '.substr($raw,0,500));
        return $data;
    }
    private function firstWorking(array $urls, callable $normalizer, string $label): array {
        $last='';
        foreach($urls as $url){
            try {
                $raw=Http::get($url,$this->headers());
                $data=$this->decode($raw);
                $rows=$normalizer($data);
                if(is_array($rows)) return $rows;
            } catch(Throwable $e){ $last=$e->getMessage(); }
        }
        throw new RuntimeException("Impossible de charger $label. Dernière erreur: ".$last);
    }
    public function loadEvents(): array {
        $attempts = [
            $this->accountUrl('events'),
            $this->accountUrl('event/list'),
            $this->accountUrl('events/list'),
            $this->accountUrl('event/getevents'),
            $this->accountUrl('account/events'),
        ];
        return $this->firstWorking($attempts, fn($d)=>$this->normalizeEvents($d), 'les évènements RaceResult');
    }
    private function normalizeEvents(array $data): array {
        $rows = $data['data'] ?? $data['rows'] ?? $data['events'] ?? $data['Event'] ?? $data;
        if(!is_array($rows)) return [];
        $out=[];
        foreach($rows as $r){
            if(!is_array($r)) continue;
            $id=$r['ID']??$r['Id']??$r['id']??$r['EventID']??$r['eventID']??$r['eventId']??null;
            $name=$r['Name']??$r['name']??$r['EventName']??$r['eventName']??$r['Title']??$r['title']??('Event '.$id);
            $date=$r['Date']??$r['date']??$r['StartDate']??$r['startDate']??'';
            if($id) $out[]=['id'=>(string)$id,'name'=>(string)$name,'date'=>(string)$date];
        }
        usort($out, fn($a,$b)=>strcmp(($b['date']??''),($a['date']??'')) ?: strcmp($a['name'],$b['name']));
        return $out;
    }
    public function loadUdfs(): array {
        $attempts = [
            $this->url('userdefinedfields'),
            $this->url('userdefinedfields/list'),
            $this->url('userdefinedfields/get'),
            $this->url('udf/list'),
            $this->url('customfields'),
            $this->url('customfields/list'),
        ];
        return $this->firstWorking($attempts, fn($d)=>$this->normalizeUdfs($d), 'les UDF RaceResult');
    }
    private function normalizeUdfs(array $data): array {
        $rows = $data['data'] ?? $data['rows'] ?? $data['userDefinedFields'] ?? $data['UserDefinedFields'] ?? $data['customFields'] ?? $data;
        if(!is_array($rows)) return [];
        $out=[];
        foreach($rows as $k=>$r){
            if(is_string($r)) { $out[]=['field'=>$r,'label'=>$r]; continue; }
            if(!is_array($r)) continue;
            $field=$r['Field']??$r['field']??$r['Name']??$r['name']??$r['Key']??$r['key']??$r['ID']??$r['id']??(is_string($k)?$k:null);
            $label=$r['Label']??$r['label']??$r['Caption']??$r['caption']??$r['Title']??$r['title']??$field;
            if($field) $out[]=['field'=>(string)$field,'label'=>(string)$label];
        }
        usort($out, fn($a,$b)=>strcmp($a['label'],$b['label']));
        return $out;
    }
    public function loadParticipants(): array {
        $fields = ['ID','Firstname','Lastname','DateOfBirth'];
        $attempts = [
            $this->url('list/data', ['fields'=>implode(',',$fields),'format'=>'json']),
            $this->url('table/get', ['name'=>'Participants','fields'=>implode(',',$fields)]),
        ];
        return $this->firstWorking($attempts, fn($d)=>$this->normalizeParticipants($d), 'les participants RaceResult');
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
        $url = $this->url('part/savefields', ['id'=>$rrId,'ID'=>$rrId,'noHistory'=>'1']);
        Http::postJson($url, $values, $this->headers());
    }
}
