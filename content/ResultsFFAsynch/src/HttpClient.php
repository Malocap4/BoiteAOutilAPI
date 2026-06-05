<?php
final class HttpClient
{
    private array $cookies = [];

    public function get(string $url, array $headers = [], int $timeout = 30): array
    {
        return $this->request('GET', $url, null, $headers, $timeout);
    }

    public function post(string $url, ?string $body = '', array $headers = [], int $timeout = 30): array
    {
        return $this->request('POST', $url, $body, $headers, $timeout);
    }

    private function request(string $method, string $url, ?string $body, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        $responseHeaders = [];
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = is_int($k) ? $v : ($k . ': ' . $v);
        }
        if ($this->cookies) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = $k . '=' . $v;
            }
            $headerLines[] = 'Cookie: ' . implode('; ', $pairs);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$responseHeaders) {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    $responseHeaders[$name][] = $value;
                    if ($name === 'set-cookie') {
                        $cookie = explode(';', $value, 2)[0];
                        $kv = explode('=', $cookie, 2);
                        if (count($kv) === 2) {
                            $this->cookies[$kv[0]] = $kv[1];
                        }
                    }
                }
                return $len;
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
        }
        $content = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($content === false) {
            throw new RuntimeException('HTTP error: ' . $err);
        }
        return ['status' => $code, 'headers' => $responseHeaders, 'body' => $content];
    }
}
