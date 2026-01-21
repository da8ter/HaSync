<?php

declare(strict_types=1);

class HaRestHelper
{
    public static function GetJson(string $baseUrl, string $token, string $path, int $timeout = 10, int $connectTimeout = 5): array
    {
        $r = self::Request('GET', $baseUrl, $token, $path, null, $timeout, $connectTimeout);
        if (!$r['ok']) {
            return ['ok' => false, 'http' => $r['http'], 'error' => $r['error'], 'json' => null, 'body' => $r['body']];
        }

        $json = json_decode($r['body'], true);
        if (!is_array($json)) {
            return ['ok' => false, 'http' => $r['http'], 'error' => 'Invalid JSON response', 'json' => null, 'body' => $r['body']];
        }

        return ['ok' => true, 'http' => $r['http'], 'error' => '', 'json' => $json, 'body' => $r['body']];
    }

    public static function PostJson(string $baseUrl, string $token, string $path, array $payload, int $timeout = 10, int $connectTimeout = 5): array
    {
        return self::Request('POST', $baseUrl, $token, $path, $payload, $timeout, $connectTimeout);
    }

    public static function Request(string $method, string $baseUrl, string $token, string $path, ?array $payload = null, int $timeout = 10, int $connectTimeout = 5): array
    {
        $method = strtoupper(trim($method));
        if ($baseUrl === '' || $token === '') {
            return ['ok' => false, 'http' => 0, 'error' => 'Missing HA URL or token', 'body' => ''];
        }

        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ];

        $curl = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload ?? []);
        }

        curl_setopt_array($curl, $opts);
        $result = curl_exec($curl);
        $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $err = $result === false ? (string)curl_error($curl) : '';
        curl_close($curl);

        return [
            'ok' => ($result !== false),
            'http' => $http,
            'error' => $err,
            'body' => $result === false ? '' : (string)$result
        ];
    }
}
