<?php
class SyncService {
    private array $s; private RaceResultClient $rr; private FfaClient $ffa;
    public function __construct(array $settings){ $this->s=$settings; foreach(['udf_license','udf_ffa_id','udf_results'] as $k) if(empty($settings[$k])) throw new RuntimeException("Champ $k manquant."); $this->rr=new RaceResultClient($settings); $this->ffa=new FfaClient((int)$settings['ffa_season']); }
    public function run(): array {
        $participants = Store::read('participants.json', []); if(!$participants) $participants=$this->rr->loadParticipants();
        $queue = Store::read('retry_queue.json', []); $queueBy=[]; foreach($queue as $q) $queueBy[$q['rr_id']]=$q;
        $todo=[]; foreach($participants as $p){ $q=$queueBy[$p['rr_id']]??null; if(!$q || (($q['tries']??0)<3 && ($q['status']??'')!=='done')) $todo[]=$p; }
        $stats=['processed'=>0,'updated'=>0,'retry'=>0,'abandoned'=>0,'errors'=>0];
        foreach(array_chunk($todo,3) as $chunk){
            foreach($chunk as $p){
                $stats['processed']++;
                try{
                    $found=$this->ffa->findAthlete($p);
                    if(!$found){ $this->markRetry($queueBy,$p,'not_found'); $stats['retry']++; Store::log('NON MATCH '.$p['rr_id'].' '.$p['firstname'].' '.$p['lastname']); continue; }
                    $rows=$this->ffa->results($found['ffa_id']);
                    $values=[$this->s['udf_license']=>$found['license'],$this->s['udf_ffa_id']=>$found['ffa_id'],$this->s['udf_results']=>FfaClient::formatResults($rows)];
                    $this->rr->saveFields($p['rr_id'],$values);
                    $queueBy[$p['rr_id']]=array_merge($p,['tries'=>0,'status'=>'done','ffa_id'=>$found['ffa_id']]);
                    $stats['updated']++; Store::log('OK '.$p['rr_id'].' => FFA '.$found['ffa_id'].' résultats='.count($rows));
                } catch(Throwable $e){ $this->markRetry($queueBy,$p,'error: '.$e->getMessage()); $stats['errors']++; Store::log('ERROR '.$p['rr_id'].' '.$e->getMessage()); }
            }
            usleep(300000);
        }
        foreach($queueBy as $q){ if(($q['tries']??0)>=3 && ($q['status']??'')!=='done') $stats['abandoned']++; }
        Store::write('retry_queue.json', array_values($queueBy));
        return $stats;
    }
    private function markRetry(array &$queueBy,array $p,string $status): void { $old=$queueBy[$p['rr_id']]??$p; $tries=(int)($old['tries']??0)+1; $queueBy[$p['rr_id']]=array_merge($p,['tries'=>$tries,'status'=>$tries>=3?'abandoned':$status,'last_try'=>date('c')]); }
}
