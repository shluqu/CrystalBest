<?php

namespace app\service\Wallet;

use app\service\Asset\AssetException;

final class InternalApiSignature
{
    public const VERSION = 'v1';

    public static function bodyHash(string $body): string
    {
        return hash('sha256', $body);
    }

    public static function requestCanonical(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $bodyHash,
        string $idempotencyKey
    ): string {
        return strtoupper($method) . "\n"
            . $path . "\n"
            . $timestamp . "\n"
            . $nonce . "\n"
            . strtolower($bodyHash) . "\n"
            . $idempotencyKey;
    }

    public static function responseCanonical(
        int $statusCode,
        string $timestamp,
        string $nonce,
        string $bodyHash,
        string $requestNonce
    ): string {
        return $statusCode . "\n"
            . $timestamp . "\n"
            . $nonce . "\n"
            . strtolower($bodyHash) . "\n"
            . $requestNonce;
    }

    public static function sign(string $canonical, string $secret): string
    {
        if ($secret === '') {
            throw new AssetException('Wallet API HMAC 密钥尚未配置', 503, 'WALLET_API_SECRET_MISSING');
        }
        return hash_hmac('sha256', $canonical, $secret);
    }

    public static function verify(string $canonical, string $secret, string $signature): bool
    {
        if ($secret === '' || !preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            return false;
        }
        return hash_equals(self::sign($canonical, $secret), strtolower($signature));
    }

    public static function assertTimestamp(string $timestamp, int $maxSkewSeconds): void
    {
        if (!preg_match('/^\d{10}$/', $timestamp)) {
            throw new AssetException('Wallet API 时间戳格式无效', 401, 'WALLET_API_TIMESTAMP_INVALID');
        }
        $delta = abs(time() - (int) $timestamp);
        if ($delta > $maxSkewSeconds) {
            throw new AssetException('Wallet API 请求时间戳已过期', 401, 'WALLET_API_TIMESTAMP_EXPIRED');
        }
    }

    private function __construct()
    {
    }
}
