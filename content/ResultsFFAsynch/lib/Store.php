<?php
class Store {
    public static function path(string $name): string { return dirname(__DIR__) . '/data/' . basename($name); }
    public static function read(string $name, $default=null) { $p=self::path($name); if(!is_file($p)) return $default; $j=json_decode(file_get_contents($p), true); return $j ?? $default; }
    public static function write(string $name, $data): void { $p=self::path($name); if(!is_dir(dirname($p))) mkdir(dirname($p),0775,true); file_put_contents($p, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX); }
    public static function log(string $msg): void { file_put_contents(self::path('sync.log'), '['.date('Y-m-d H:i:s').'] '.$msg."\n", FILE_APPEND|LOCK_EX); }
    public static function readLines(string $name): array { $p=self::path($name); return is_file($p) ? file($p, FILE_IGNORE_NEW_LINES) : []; }
}
