<?php
class Http {
    public static function get(string $url, array $headers=[]): string { return self::request('GET',$url,null,$headers); }
    public static function postJson(string $url, $payload, array $headers=[]): string { $headers[]='Content-Type: application/json'; return self::request('POST',$url,json_encode($payload, JSON_UNESCAPED_UNICODE),$headers); }
    private static function request(string $method,string $url,?string $body,array $headers): string {
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>45,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'Mozilla/5.0 FFA-RR14-Sync']);
        if($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
        $res=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
        if($res===false || $code>=400) throw new RuntimeException("HTTP $code $err sur $url : ".substr((string)$res,0,500));
        return (string)$res;
    }
}
