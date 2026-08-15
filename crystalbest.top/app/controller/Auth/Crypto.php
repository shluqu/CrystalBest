<?php

namespace app\controller\Auth;

class Crypto
{
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function validateEmail(string $email): string
    {
        $email = self::normalizeEmail($email);
        if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new AuthException('请输入有效的邮箱地址', 422, 'INVALID_EMAIL');
        }
        return $email;
    }

    public static function emailHash(string $normalizedEmail): string
    {
        $key = (string) env('auth.email_hmac_key', '');
        if ($key === '') {
            throw new AuthException('账户安全配置缺失：AUTH_EMAIL_HMAC_KEY', 500, 'AUTH_CONFIG_MISSING');
        }
        return hash_hmac('sha256', $normalizedEmail, $key, true);
    }

    public static function encryptEmail(string $normalizedEmail): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new AuthException('服务器缺少 OpenSSL 扩展', 500, 'OPENSSL_REQUIRED');
        }

        $key = self::encryptionKey();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $normalizedEmail,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new AuthException('邮箱加密失败', 500, 'ENCRYPTION_FAILED');
        }

        // 二进制格式：版本(2) + IV(12) + TAG(16) + ciphertext
        return 'A1' . $iv . $tag . $ciphertext;
    }

    public static function decryptEmail(string $payload): string
    {
        if (substr($payload, 0, 2) !== 'A1' || strlen($payload) < 31) {
            throw new AuthException('邮箱密文格式不受支持', 500, 'INVALID_CIPHERTEXT');
        }

        $iv = substr($payload, 2, 12);
        $tag = substr($payload, 14, 16);
        $ciphertext = substr($payload, 30);
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            self::encryptionKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new AuthException('邮箱解密失败', 500, 'DECRYPTION_FAILED');
        }

        return $plaintext;
    }


    public static function encryptTotpSecret(string $secret): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new AuthException('服务器缺少 OpenSSL 扩展', 500, 'OPENSSL_REQUIRED');
        }

        $secret = trim($secret);
        if ($secret === '') {
            throw new AuthException('TOTP 密钥为空', 500, 'TOTP_SECRET_INVALID');
        }

        $iv = random_bytes(12);
        $tag = '';
        $aad = 'crystalbest:totp:v1';
        $ciphertext = openssl_encrypt(
            $secret,
            'aes-256-gcm',
            self::totpEncryptionKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
            16
        );

        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new AuthException('TOTP 密钥加密失败', 500, 'ENCRYPTION_FAILED');
        }

        return 'T1' . $iv . $tag . $ciphertext;
    }

    public static function decryptTotpSecret(string $payload): string
    {
        if (substr($payload, 0, 2) !== 'T1' || strlen($payload) < 31) {
            throw new AuthException('TOTP 密钥密文格式不受支持', 500, 'INVALID_CIPHERTEXT');
        }

        $iv = substr($payload, 2, 12);
        $tag = substr($payload, 14, 16);
        $ciphertext = substr($payload, 30);
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            self::totpEncryptionKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'crystalbest:totp:v1'
        );

        if ($plaintext === false || $plaintext === '') {
            throw new AuthException('TOTP 密钥解密失败', 500, 'DECRYPTION_FAILED');
        }

        return $plaintext;
    }

    public static function encryptSensitive(string $plaintext, string $purpose = 'generic'): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new AuthException('服务器缺少 OpenSSL 扩展', 500, 'OPENSSL_REQUIRED');
        }
        $plaintext = trim($plaintext);
        if ($plaintext === '') {
            throw new AuthException('敏感数据不能为空', 422, 'SENSITIVE_DATA_EMPTY');
        }
        $purpose = self::sensitivePurpose($purpose);
        $iv = random_bytes(12);
        $tag = '';
        $aad = 'crystalbest:sensitive:v1:' . $purpose;
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            self::encryptionKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
            16
        );
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new AuthException('敏感数据加密失败', 500, 'ENCRYPTION_FAILED');
        }
        return 'S1' . $iv . $tag . $ciphertext;
    }

    public static function decryptSensitive(string $payload, string $purpose = 'generic'): string
    {
        if (substr($payload, 0, 2) !== 'S1' || strlen($payload) < 31) {
            throw new AuthException('敏感数据密文格式不受支持', 500, 'INVALID_CIPHERTEXT');
        }
        $purpose = self::sensitivePurpose($purpose);
        $iv = substr($payload, 2, 12);
        $tag = substr($payload, 14, 16);
        $ciphertext = substr($payload, 30);
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            self::encryptionKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'crystalbest:sensitive:v1:' . $purpose
        );
        if ($plaintext === false) {
            throw new AuthException('敏感数据解密失败', 500, 'DECRYPTION_FAILED');
        }
        return $plaintext;
    }

    public static function sensitiveHash(string $value, string $purpose = 'generic'): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new AuthException('敏感数据摘要输入不能为空', 422, 'SENSITIVE_HASH_EMPTY');
        }
        $purpose = self::sensitivePurpose($purpose);
        $key = trim((string) env('auth.kyc_hmac_key', ''));
        if ($key === '') {
            $key = trim((string) env('auth.email_hmac_key', ''));
        }
        if ($key === '') {
            throw new AuthException('账户安全配置缺失：AUTH_KYC_HMAC_KEY', 500, 'AUTH_CONFIG_MISSING');
        }
        return hash_hmac('sha256', $purpose . "\n" . $value, $key, true);
    }

    private static function sensitivePurpose(string $purpose): string
    {
        $purpose = strtolower(trim($purpose));
        if ($purpose === '' || strlen($purpose) > 64 || !preg_match('/^[a-z0-9:_\-.]+$/', $purpose)) {
            throw new AuthException('敏感数据用途标识无效', 500, 'SENSITIVE_PURPOSE_INVALID');
        }
        return $purpose;
    }

    public static function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***';
        }

        $local = $parts[0];
        $domain = $parts[1];
        $len = strlen($local);
        if ($len <= 1) {
            $maskedLocal = '*';
        } elseif ($len === 2) {
            $maskedLocal = substr($local, 0, 1) . '*';
        } else {
            $maskedLocal = substr($local, 0, 2) . str_repeat('*', min(6, $len - 2));
        }

        return $maskedLocal . '@' . $domain;
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function secureEquals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    public static function deviceFingerprintHash(string $deviceToken): string
    {
        $deviceToken = trim($deviceToken);
        if ($deviceToken === '') {
            throw new AuthException('设备标识为空', 500, 'DEVICE_TOKEN_INVALID');
        }

        $key = trim((string) env('auth.device_hmac_key', ''));
        if ($key === '') {
            // Backward-compatible fallback. Production should configure a dedicated key.
            $key = trim((string) env('auth.email_hmac_key', ''));
        }
        if ($key === '') {
            throw new AuthException('账户安全配置缺失：AUTH_DEVICE_HMAC_KEY', 500, 'AUTH_CONFIG_MISSING');
        }

        return hash_hmac('sha256', $deviceToken, $key, true);
    }

    private static function totpEncryptionKey(): string
    {
        $raw = trim((string) env('auth.totp_encryption_key', ''));
        if ($raw === '') {
            return self::encryptionKey();
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $raw)) {
            $decoded = hex2bin($raw);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        $decoded = base64_decode($raw, true);
        if ($decoded !== false && strlen($decoded) >= 32) {
            return substr($decoded, 0, 32);
        }

        return hash('sha256', $raw, true);
    }

    private static function encryptionKey(): string
    {
        $raw = trim((string) env('auth.data_encryption_key', ''));
        if ($raw === '') {
            throw new AuthException('账户安全配置缺失：AUTH_DATA_ENCRYPTION_KEY', 500, 'AUTH_CONFIG_MISSING');
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $raw)) {
            $decoded = hex2bin($raw);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        $decoded = base64_decode($raw, true);
        if ($decoded !== false && strlen($decoded) >= 32) {
            return substr($decoded, 0, 32);
        }

        // 兼容普通随机字符串，但生产建议使用 64 位 hex 或 32 字节 base64。
        return hash('sha256', $raw, true);
    }
}
