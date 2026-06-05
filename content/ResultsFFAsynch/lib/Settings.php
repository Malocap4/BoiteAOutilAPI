<?php
class Settings {
    public static function defaults(): array { return [
        'rr_api_key'=>'', 'rr_token'=>'', 'rr_token_expires_at'=>0, 'rr_event_id'=>'',
        'rr_public_base'=>'https://events.raceresult.com/api/public',
        'rr_event_base'=>'https://events.raceresult.com',
        'event_date_from'=>date('Y-m-d', strtotime('-15 days')),
        'event_date_to'=>date('Y-m-d', strtotime('+1 month')),
        'ffa_season'=>(int)date('Y'),
        'ffa_seasons'=>[(int)date('Y')],
        'udf_license'=>'', 'udf_ffa_id'=>'', 'udf_results'=>'',
    ]; }
    public static function load(): array {
        $s = array_merge(self::defaults(), Store::read('settings.json', []));
        if (empty($s['ffa_seasons']) || !is_array($s['ffa_seasons'])) $s['ffa_seasons'] = [(int)($s['ffa_season'] ?? date('Y'))];
        $s['ffa_seasons'] = array_values(array_unique(array_filter(array_map('intval', $s['ffa_seasons']))));
        return $s;
    }
    public static function save(array $s): void { Store::write('settings.json', $s); }
}
