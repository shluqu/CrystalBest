<?php

namespace app\controller\Auth;

class R2Storage
{
    private $accountId;
    private $accessKeyId;
    private $secretAccessKey;
    private $bucket;
    private $publicBaseUrl;
    private $endpoint;
    private $timeout;

    public function __construct(?string $bucketOverride = null, ?string $publicBaseUrlOverride = null)
    {
        $this->accountId = trim((string) env('r2.account_id', ''));
        $this->accessKeyId = trim((string) env('r2.access_key_id', ''));
        $this->secretAccessKey = trim((string) env('r2.secret_access_key', ''));
        $this->bucket = $bucketOverride !== null
            ? trim($bucketOverride)
            : trim((string) env('r2.bucket', ''));
        $this->publicBaseUrl = $publicBaseUrlOverride !== null
            ? rtrim(trim($publicBaseUrlOverride), '/')
            : rtrim(trim((string) env('r2.public_base_url', '')), '/');
        $this->timeout = max(5, min(60, (int) env('r2.timeout_seconds', 20)));

        $configuredEndpoint = rtrim(trim((string) env('r2.endpoint', '')), '/');
        if ($configuredEndpoint !== '') {
            $this->endpoint = $configuredEndpoint;
        } elseif ($this->accountId !== '') {
            $this->endpoint = 'https://' . $this->accountId . '.r2.cloudflarestorage.com';
        } else {
            $this->endpoint = '';
        }
    }

    /** Public-object configuration used by avatars. */
    public function isConfigured(): bool
    {
        return $this->isApiConfigured() && $this->publicBaseUrl !== '';
    }

    /** S3 API configuration; a public URL is intentionally not required. */
    public function isApiConfigured(): bool
    {
        return $this->endpoint !== ''
            && $this->accessKeyId !== ''
            && $this->secretAccessKey !== ''
            && $this->bucket !== '';
    }

    public function put(string $key, string $body, string $contentType): string
    {
        $this->assertPublicConfigured();
        $key = $this->normalizeKey($key);
        $headers = [
            'content-type' => $contentType,
            'cache-control' => 'public, max-age=31536000, immutable',
        ];
        $this->request('PUT', $key, $body, $headers);
        return $this->publicUrl($key);
    }

    /**
     * Store an object through the signed S3 API without generating a public URL.
     * If the bucket also has a public custom domain, sensitive prefixes must be
     * blocked at Cloudflare (WAF/Access); Cache-Control alone is not access control.
     */
    public function putPrivate(string $key, string $body, string $contentType): array
    {
        $this->assertApiConfigured();
        $key = $this->normalizeKey($key);
        $this->request('PUT', $key, $body, [
            'content-type' => $contentType,
            'cache-control' => 'private, no-store, max-age=0',
        ]);
        return [
            'storage_key' => $key,
            'content_type' => $contentType,
            'bytes' => strlen($body),
        ];
    }

    public function getPrivate(string $key): array
    {
        $this->assertApiConfigured();
        $key = $this->normalizeKey($key);
        $response = $this->request('GET', $key, '', []);
        $contentType = isset($response['headers']['content-type'])
            ? trim((string) $response['headers']['content-type'])
            : 'application/octet-stream';
        return [
            'storage_key' => $key,
            'content_type' => $contentType !== '' ? $contentType : 'application/octet-stream',
            'bytes' => strlen((string) $response['body']),
            'body' => (string) $response['body'],
        ];
    }

    public function delete(string $key): void
    {
        if (!$this->isApiConfigured()) {
            return;
        }
        $key = $this->normalizeKey($key);
        $this->request('DELETE', $key, '', []);
    }

    public function publicUrl(string $key): string
    {
        $this->assertPublicConfigured();
        return $this->publicBaseUrl . '/' . $this->encodePath($this->normalizeKey($key));
    }

    private function request(string $method, string $key, string $body, array $extraHeaders): array
    {
        if (!extension_loaded('curl')) {
            throw new AuthException('服务器缺少 cURL 扩展，无法连接 Cloudflare R2', 500, 'R2_CURL_MISSING');
        }

        $endpoint = parse_url($this->endpoint);
        if (!is_array($endpoint) || empty($endpoint['scheme']) || empty($endpoint['host'])) {
            throw new AuthException('Cloudflare R2 Endpoint 配置无效', 500, 'R2_CONFIG_INVALID');
        }

        $host = (string) $endpoint['host'];
        if (!empty($endpoint['port'])) {
            $host .= ':' . (int) $endpoint['port'];
        }
        $basePath = isset($endpoint['path']) ? rtrim((string) $endpoint['path'], '/') : '';
        $canonicalUri = ($basePath !== '' ? $basePath : '')
            . '/' . rawurlencode($this->bucket)
            . '/' . $this->encodePath($key);
        if ($canonicalUri === '') {
            $canonicalUri = '/';
        }

        $url = $endpoint['scheme'] . '://' . $host . $canonicalUri;
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', $body);

        $canonicalHeadersMap = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        foreach ($extraHeaders as $name => $value) {
            $canonicalHeadersMap[strtolower(trim((string) $name))] = trim((string) $value);
        }
        ksort($canonicalHeadersMap);

        $canonicalHeaders = '';
        foreach ($canonicalHeadersMap as $name => $value) {
            $canonicalHeaders .= $name . ':' . preg_replace('/\s+/', ' ', $value) . "\n";
        }
        $signedHeaders = implode(';', array_keys($canonicalHeadersMap));
        $canonicalRequest = $method . "\n"
            . $canonicalUri . "\n\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $payloadHash;

        $credentialScope = $dateStamp . '/auto/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n"
            . $amzDate . "\n"
            . $credentialScope . "\n"
            . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretAccessKey, true);
        $kRegion = hash_hmac('sha256', 'auto', $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $this->accessKeyId . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        $httpHeaders = [
            'Host: ' . $host,
            'X-Amz-Date: ' . $amzDate,
            'X-Amz-Content-Sha256: ' . $payloadHash,
            'Authorization: ' . $authorization,
            'Expect:',
        ];
        foreach ($extraHeaders as $name => $value) {
            $httpHeaders[] = $this->headerName((string) $name) . ': ' . (string) $value;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $this->timeout));
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
        if ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($response === false || $error !== '') {
            throw new AuthException('Cloudflare R2 存储连接失败', 502, 'R2_CONNECTION_FAILED');
        }
        if ($status < 200 || $status >= 300) {
            throw new AuthException('Cloudflare R2 存储请求失败（HTTP ' . $status . '）', 502, 'R2_REQUEST_FAILED');
        }

        $headerText = substr((string) $response, 0, $headerSize);
        $responseBody = substr((string) $response, $headerSize);
        return [
            'status' => $status,
            'headers' => $this->parseResponseHeaders($headerText),
            'body' => $responseBody,
        ];
    }

    private function parseResponseHeaders(string $text): array
    {
        $headers = [];
        $blocks = preg_split('/\r?\n\r?\n/', trim($text));
        $block = is_array($blocks) && $blocks ? (string) end($blocks) : $text;
        foreach (preg_split('/\r?\n/', $block) as $line) {
            $pos = strpos((string) $line, ':');
            if ($pos === false) {
                continue;
            }
            $name = strtolower(trim(substr((string) $line, 0, $pos)));
            if ($name === '') {
                continue;
            }
            $headers[$name] = trim(substr((string) $line, $pos + 1));
        }
        return $headers;
    }

    private function normalizeKey(string $key): string
    {
        $key = trim(str_replace('\\', '/', $key), '/');
        if ($key === '' || strpos($key, '..') !== false || strpos($key, "\0") !== false) {
            throw new AuthException('R2 对象路径无效', 500, 'R2_KEY_INVALID');
        }
        return $key;
    }

    private function encodePath(string $path): string
    {
        $parts = explode('/', $path);
        $encoded = [];
        foreach ($parts as $part) {
            $encoded[] = rawurlencode($part);
        }
        return implode('/', $encoded);
    }

    private function assertApiConfigured(): void
    {
        if (!$this->isApiConfigured()) {
            throw new AuthException('Cloudflare R2 尚未完成配置', 503, 'R2_NOT_CONFIGURED');
        }
    }

    private function assertPublicConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new AuthException('Cloudflare R2 公共资源配置尚未完成', 503, 'R2_NOT_CONFIGURED');
        }
    }

    private function headerName(string $name): string
    {
        return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
    }
}
