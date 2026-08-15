<?php

namespace app\controller\Auth;

use think\Request;

final class DeviceIdentity
{
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public static function resolve(Request $request): array
    {
        $name = self::cookieName();
        $token = trim((string) $request->cookie($name, ''));
        if (!preg_match(self::TOKEN_PATTERN, $token)) {
            $token = Crypto::base64UrlEncode(random_bytes(32));
        }

        return [
            'token' => $token,
            'hash' => hash('sha256', $token, true),
        ];
    }

    public static function cookieName(): string
    {
        return 'cex_device';
    }

    public static function cookieOptions(): array
    {
        return [
            'expire' => 31536000,
            'path' => '/',
            'domain' => trim((string) env('auth.cookie_domain', '')),
            'secure' => filter_var(env('auth.cookie_secure', true), FILTER_VALIDATE_BOOLEAN),
            'httponly' => true,
            'samesite' => 'lax',
        ];
    }
}
