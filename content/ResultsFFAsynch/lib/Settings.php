<?php
class Settings {
    public static function defaults(): array { return [
        'rr_api_key'=>'', 'rr_event_id'=>'',
        'rr_api_base_template'=>'https://api.raceresult.com/event/{eventId}/',
        'ffa_season'=>(int)date('Y'),
        'udf_license'=>'', 'udf_ffa_id'=>'', 'udf_results'=>'',
    ]; }
    public static function load(): array { return array_merge(self::defaults(), Store::read('settings.json', [])); }
    public static function save(array $s): void { Store::write('settings.json', $s); }
}
