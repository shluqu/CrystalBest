<?php
namespace app\service\Internal;

use app\service\Asset\AssetException;
use app\service\Wallet\InternalApiSignature;
use think\Request;
use think\facade\Cache;

final class ServiceRequestAuthenticator
{
    public function authenticate(Request $request, string $path, string $rawBody): array
    {
        $allowed = array_values((array) config('user_worker.allowed_ips', []));
        $remoteIp = trim((string) $request->server('REMOTE_ADDR', ''));
        if ($remoteIp === '' || !in_array($remoteIp, $allowed, true)) {
            throw new AssetException('User worker 来源 IP 不允许', 403, 'USER_WORKER_IP_DENIED');
        }
        $clientId = trim((string) $request->header('x-cb-client-id', ''));
        $timestamp = trim((string) $request->header('x-cb-timestamp', ''));
        $nonce = strtolower(trim((string) $request->header('x-cb-nonce', '')));
        $bodyHash = strtolower(trim((string) $request->header('x-cb-content-sha256', '')));
        $idempotencyKey = trim((string) $request->header('x-cb-idempotency-key', ''));
        $signature = strtolower(trim((string) $request->header('x-cb-signature', '')));
        $version = trim((string) $request->header('x-cb-signature-version', ''));

        if ($version !== InternalApiSignature::VERSION
            || $clientId === ''
            || !hash_equals((string) config('user_worker.client_id'), $clientId)
            || !preg_match('/^[a-f0-9]{32}$/', $nonce)
            || $idempotencyKey === ''
            || strlen($idempotencyKey) > 128) {
            throw new AssetException('User worker 身份验证失败', 401, 'USER_WORKER_AUTH_FAILED');
        }
        InternalApiSignature::assertTimestamp($timestamp, (int) config('user_worker.max_clock_skew_seconds', 120));
        $actualHash = InternalApiSignature::bodyHash($rawBody);
        if (!preg_match('/^[a-f0-9]{64}$/', $bodyHash) || !hash_equals($actualHash, $bodyHash)) {
            throw new AssetException('User worker body hash 不匹配', 401, 'USER_WORKER_BODY_HASH_MISMATCH');
        }
        $clientSecret = (string) config('user_worker.client_secret', '');
        if ($clientSecret === '') {
            throw new AssetException('User worker HMAC 密钥尚未配置', 503, 'USER_WORKER_SECRET_MISSING');
        }
        $canonical = InternalApiSignature::requestCanonical($request->method(), $path, $timestamp, $nonce, $actualHash, $idempotencyKey);
        if (!InternalApiSignature::verify($canonical, $clientSecret, $signature)) {
            throw new AssetException('User worker HMAC 签名无效', 401, 'USER_WORKER_SIGNATURE_INVALID');
        }
        $nonceKey = 'internal:user-worker:nonce:' . hash('sha256', $clientId . ':' . $nonce);
        if (Cache::get($nonceKey)) {
            throw new AssetException('User worker 请求 nonce 已使用', 409, 'USER_WORKER_NONCE_REPLAYED');
        }
        Cache::set($nonceKey, 1, max(180, (int) config('user_worker.max_clock_skew_seconds', 120) * 2));
        return ['client_id'=>$clientId,'remote_ip'=>$remoteIp,'nonce'=>$nonce,'idempotency_key'=>$idempotencyKey];
    }
}
