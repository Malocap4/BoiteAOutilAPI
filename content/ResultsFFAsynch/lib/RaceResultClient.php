<?php
class RaceResultClient {
    private array $s;
    public function __construct(array $settings){ $this->s=$settings; if(!$this->s['rr_api_key']) throw new RuntimeException('Clé API RaceResult manquante.'); if(!$this->s['rr_event_id']) throw new RuntimeException('Event ID RaceResult manquant.'); }
    private function url(string $endpoint, array $q=[]): string {
        $base = str_replace('{eventId}', rawurlencode($this->s['rr_event_id']), rtrim($this->s['rr_api_base_template'],'/').'/');
        $q['apiKey']=$this->s['rr_api_key'];
        return $base.ltrim($endpoint,'/').'?'.http_build_query($q);
    }
    private function headers(): array { return ['Accept: application/json','Authorization: Bearer '.$this->s['rr_api_key'], 'X-API-Key: '.$this->s['rr_api_key']]; }
    public function loadParticipants(): array {
        $fields = ['ID','Firstname','Lastname','DateOfBirth'];
        $attempts = [
            $this->url('list/data', ['fields'=>implode(',',$fields),'format'=>'json']),
            $this->url('table/get', ['name'=>'Participants','fields'=>implode(',',$fields)]),
        ];
        $last='';
        foreach($attempts as $url){
            try { $raw=Http::get($url,$this->headers()); $data=json_decode($raw,true); if(is_array($data)) return $this->normalizeParticipants($data); $last=substr($raw,0,500); } catch(Throwable $e){ $last=$e->getMessage(); }
        }
        throw new RuntimeException('Impossible de charger les participants RR. Vérifie rr_api_base_template. Dernière réponse: '.$last);
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
        $payload = $values;
        $url = $this->url('part/savefields', ['id'=>$rrId,'ID'=>$rrId,'noHistory'=>'1']);
        Http::postJson($url, $payload, $this->headers());
    }
}
