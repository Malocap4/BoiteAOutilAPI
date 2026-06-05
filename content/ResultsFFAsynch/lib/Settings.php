<?php
class Settings {
    public static function defaults(): array { return [
        'rr_api_key'=>'', 'rr_token'=>'', 'rr_token_expires_at'=>0, 'rr_event_id'=>'',
        'rr_public_base'=>'https://events.raceresult.com/api/public',
        'rr_event_base'=>'https://events.raceresult.com',
        'event_date_from'=>date('Y-m-d', strtotime('-15 days')),
        'event_date_to'=>date('Y-m-d', strtotime('+1 month')),
        'ffa_season'=>(int)date('Y'),
        'udf_license'=>'', 'udf_ffa_id'=>'', 'udf_results'=>'',
    ]; }
    public static function load(): array { return array_merge(self::defaults(), Store::read('settings.json', [])); }
    public static function save(array $s): void { Store::write('settings.json', $s); }
}
