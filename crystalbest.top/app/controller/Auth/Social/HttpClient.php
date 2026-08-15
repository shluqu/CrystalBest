<?php

namespace app\controller\Auth\Social;

use app\controller\Auth\AuthException;

class HttpClient
{
    public static function getJson(string $url, array $headers = []): array
    {
        return self::requestJson('GET', $url, null, $headers);
    }

    public static function postForm(string $url, array $fields, array $headers = []): array
    {
        $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        return self::requestJson('POST', $url, $body, $headers);
    }

    private static function requestJson(string $method, string $url, ?string $body, array $headers): array
    {
        $timeout = max(3, min(30, (int) env('social_auth.http_timeout_seconds', 10)));

        if (function_exists('curl_init')) {
            return self::requestJsonWithCurl($method, $url, $body, $headers, $timeout);
        }

        $headerLines = array_merge(['Accept: application/json'], $headers);
        $options = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body === null ? '' : $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ];

        $context = stream_context_create($options);
        $responseBody = @file_get_contents($url, false, $context);
        $responseHeaders = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
        $status = self::statusFromHeaders($responseHeaders);

        if ($responseBody === false) {
            throw new AuthException('无法连接第三方登录服务', 503, 'SOCIAL_PROVIDER_UNAVAILABLE');
        }

        return self::decodeResponse($status, $responseBody);
    }

    private static function requestJsonWithCurl(string $method, string $url, ?string $body, array $headers, int $timeout): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new AuthException('无法初始化第三方登录请求', 500, 'SOCIAL_HTTP_INIT_FAILED');
        }

        $headerLines = array_merge(['Accept: application/json'], $headers);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($responseBody === false) {
            throw new AuthException(
                $curlError !== '' ? '第三方登录网络请求失败：' . $curlError : '第三方登录网络请求失败',
                503,
                'SOCIAL_PROVIDER_UNAVAILABLE'
            );
        }

        return self::decodeResponse($status, (string) $responseBody);
    }

    private static function decodeResponse(int $status, string $responseBody): array
    {
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new AuthException('第三方登录服务返回了无效响应', 502, 'SOCIAL_INVALID_PROVIDER_RESPONSE');
        }

        if ($status < 200 || $status >= 300) {
            throw new AuthException('第三方登录验证失败，请重新尝试', 502, 'SOCIAL_PROVIDER_REJECTED');
        }

        return $decoded;
    }

    private static function statusFromHeaders(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', (string) $header, $matches)) {
                return (int) $matches[1];
            }
        }
        return 200;
    }
}
