<?php

namespace app\controller\Auth\Social;

use app\controller\Auth\AuthException;
use think\facade\Cache;

class JwtVerifier
{
    public static function verify(string $jwt, array $providerConfig, string $expectedNonce): array
    {
        if (!function_exists('openssl_verify')) {
            throw new AuthException('服务器缺少 OpenSSL 扩展，无法验证第三方登录令牌', 500, 'OPENSSL_REQUIRED');
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new AuthException('第三方身份令牌格式无效', 401, 'SOCIAL_INVALID_ID_TOKEN');
        }

        $header = self::decodeJsonPart($parts[0]);
        $claims = self::decodeJsonPart($parts[1]);
        $signature = self::base64UrlDecode($parts[2]);

        if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            throw new AuthException('第三方身份令牌签名算法不受支持', 401, 'SOCIAL_INVALID_ID_TOKEN');
        }

        $jwk = self::findJwk($providerConfig['jwks_uri'], (string) $header['kid'], false);
        if ($jwk === null) {
            $jwk = self::findJwk($providerConfig['jwks_uri'], (string) $header['kid'], true);
        }
        if ($jwk === null) {
            throw new AuthException('无法找到第三方身份令牌签名密钥', 401, 'SOCIAL_SIGNING_KEY_NOT_FOUND');
        }

        $publicKey = self::publicKeyFromJwk($jwk);
        $verified = openssl_verify($parts[0] . '.' . $parts[1], $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new AuthException('第三方身份令牌签名验证失败', 401, 'SOCIAL_INVALID_ID_TOKEN');
        }

        self::validateClaims($claims, $providerConfig, $expectedNonce);
        return $claims;
    }

    private static function validateClaims(array $claims, array $config, string $expectedNonce): void
    {
        $now = time();
        $skew = 120;
        $audience = $claims['aud'] ?? null;
        $clientId = (string) $config['client_id'];
        $audienceValid = is_string($audience)
            ? hash_equals($clientId, $audience)
            : (is_array($audience) && in_array($clientId, $audience, true));

        if (!$audienceValid) {
            throw new AuthException('第三方身份令牌受众不匹配', 401, 'SOCIAL_INVALID_ID_TOKEN');
        }
        if (empty($claims['sub']) || !is_string($claims['sub'])) {
            throw new AuthException('第三方身份令牌缺少用户标识', 401, 'SOCIAL_INVALID_ID_TOKEN');
        }
        if (!isset($claims['exp']) || (int) $claims['exp'] < $now - $skew) {
            throw new AuthException('第三方身份令牌已过期', 401, 'SOCIAL_ID_TOKEN_EXPIRED');
        }
        if (isset($claims['nbf']) && (int) $claims['nbf'] > $now + $skew) {
            throw new AuthException('第三方身份令牌尚未生效', 401, 'SOCIAL_INVALID_ID_TOKEN');
        }
        if (isset($claims['iat']) && (int) $claims['iat'] > $now + $skew) {
            throw new AuthException('第三方身份令牌签发时间异常', 401, 'SOCIAL_INVALID_ID_TOKEN');
        }
        if ($expectedNonce === '' || empty($claims['nonce']) || !hash_equals($expectedNonce, (string) $claims['nonce'])) {
            throw new AuthException('第三方登录 nonce 验证失败', 401, 'SOCIAL_NONCE_MISMATCH');
        }

        $issuer = (string) ($claims['iss'] ?? '');
        if ($config['provider'] === 'google') {
            if (!in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)) {
                throw new AuthException('Google 身份令牌签发方无效', 401, 'SOCIAL_INVALID_ISSUER');
            }
            if (isset($claims['azp']) && (string) $claims['azp'] !== '' && !hash_equals($clientId, (string) $claims['azp'])) {
                throw new AuthException('Google 身份令牌授权方不匹配', 401, 'SOCIAL_INVALID_ID_TOKEN');
            }
            return;
        }

        if ($config['provider'] === 'microsoft') {
            if (!preg_match('#^https://login\.microsoftonline\.com/([0-9a-fA-F-]{36})/v2\.0$#', $issuer, $matches)) {
                throw new AuthException('Microsoft 身份令牌签发方无效', 401, 'SOCIAL_INVALID_ISSUER');
            }
            $tid = strtolower((string) ($claims['tid'] ?? ''));
            if ($tid === '' || strtolower($matches[1]) !== $tid) {
                throw new AuthException('Microsoft 租户声明不匹配', 401, 'SOCIAL_INVALID_ISSUER');
            }
            $configuredTenant = strtolower((string) ($config['tenant'] ?? 'common'));
            if (!in_array($configuredTenant, ['common', 'organizations', 'consumers'], true) && $configuredTenant !== $tid) {
                throw new AuthException('Microsoft 登录租户不允许', 401, 'SOCIAL_TENANT_NOT_ALLOWED');
            }
        }
    }

    private static function findJwk(string $jwksUri, string $kid, bool $forceRefresh): ?array
    {
        $cacheKey = 'auth:oauth:jwks:' . hash('sha256', $jwksUri);
        $jwks = $forceRefresh ? null : Cache::get($cacheKey);
        if (!is_array($jwks) || !isset($jwks['keys']) || !is_array($jwks['keys'])) {
            $jwks = HttpClient::getJson($jwksUri);
            Cache::set($cacheKey, $jwks, 3600);
        }

        foreach ($jwks['keys'] as $key) {
            if (is_array($key) && isset($key['kid']) && hash_equals($kid, (string) $key['kid'])) {
                return $key;
            }
        }
        return null;
    }

    private static function publicKeyFromJwk(array $jwk): string
    {
        if (!empty($jwk['x5c'][0])) {
            $certificate = chunk_split((string) $jwk['x5c'][0], 64, "\n");
            return "-----BEGIN CERTIFICATE-----\n" . $certificate . "-----END CERTIFICATE-----\n";
        }

        if (empty($jwk['n']) || empty($jwk['e'])) {
            throw new AuthException('第三方签名公钥格式无效', 502, 'SOCIAL_INVALID_JWKS');
        }

        $modulus = self::base64UrlDecode((string) $jwk['n']);
        $exponent = self::base64UrlDecode((string) $jwk['e']);
        $rsaPublicKey = self::derSequence(self::derInteger($modulus) . self::derInteger($exponent));

        $rsaAlgorithmIdentifier = hex2bin('300d06092a864886f70d0101010500');
        if ($rsaAlgorithmIdentifier === false) {
            throw new AuthException('无法构造 RSA 公钥', 500, 'SOCIAL_PUBLIC_KEY_FAILED');
        }
        $subjectPublicKeyInfo = self::derSequence(
            $rsaAlgorithmIdentifier . self::derBitString($rsaPublicKey)
        );

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $bytes): string
    {
        return "\x30" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derBitString(string $bytes): string
    {
        $bytes = "\x00" . $bytes;
        return "\x03" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $encoded = '';
        while ($length > 0) {
            $encoded = chr($length & 0xff) . $encoded;
            $length >>= 8;
        }
        return chr(0x80 | strlen($encoded)) . $encoded;
    }

    private static function decodeJsonPart(string $part): array
    {
        $decoded = json_decode(self::base64UrlDecode($part), true);
        if (!is_array($decoded)) {
            throw new AuthException('第三方身份令牌格式无效', 401, 'SOCIAL_INVALID_ID_TOKEN');
        }
        return $decoded;
    }

    private static function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new AuthException('第三方身份令牌编码无效', 401, 'SOCIAL_INVALID_ID_TOKEN');
        }
        return $decoded;
    }
}
