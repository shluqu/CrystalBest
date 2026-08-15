<?php

namespace app\controller\Auth\Social;

use app\controller\Auth\AuthException;

class ProviderConfig
{
    public static function get(string $provider): array
    {
        $provider = strtolower(trim($provider));
        if ($provider === 'google') {
            return self::google();
        }
        if ($provider === 'microsoft') {
            return self::microsoft();
        }
        throw new AuthException('不支持的第三方登录方式', 404, 'SOCIAL_PROVIDER_NOT_SUPPORTED');
    }

    public static function publicStatus(): array
    {
        return [
            'google' => self::isReady('google'),
            'microsoft' => self::isReady('microsoft'),
        ];
    }

    public static function isReady(string $provider): bool
    {
        try {
            $config = self::get($provider);
            return $config['enabled'] && $config['client_id'] !== '' && $config['client_secret'] !== '' && $config['redirect_uri'] !== '';
        } catch (\Throwable $exception) {
            return false;
        }
    }

    private static function google(): array
    {
        return [
            'provider' => 'google',
            'enabled' => self::boolEnv('social_auth.google_enabled', false),
            'client_id' => trim((string) env('social_auth.google_client_id', '')),
            'client_secret' => trim((string) env('social_auth.google_client_secret', '')),
            'redirect_uri' => trim((string) env('social_auth.google_redirect_uri', 'https://crystalbest.top/auth/google/callback')),
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
            'scope' => 'openid email profile',
        ];
    }

    private static function microsoft(): array
    {
        $tenant = trim((string) env('social_auth.microsoft_tenant', 'common'));
        if ($tenant === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $tenant)) {
            $tenant = 'common';
        }
        $base = 'https://login.microsoftonline.com/' . rawurlencode($tenant);

        return [
            'provider' => 'microsoft',
            'enabled' => self::boolEnv('social_auth.microsoft_enabled', false),
            'tenant' => $tenant,
            'client_id' => trim((string) env('social_auth.microsoft_client_id', '')),
            'client_secret' => trim((string) env('social_auth.microsoft_client_secret', '')),
            'redirect_uri' => trim((string) env('social_auth.microsoft_redirect_uri', 'https://crystalbest.top/auth/microsoft/callback')),
            'authorization_endpoint' => $base . '/oauth2/v2.0/authorize',
            'token_endpoint' => $base . '/oauth2/v2.0/token',
            'jwks_uri' => $base . '/discovery/v2.0/keys',
            'scope' => 'openid email profile',
        ];
    }

    private static function boolEnv(string $name, bool $default): bool
    {
        return filter_var(env($name, $default), FILTER_VALIDATE_BOOLEAN);
    }
}
