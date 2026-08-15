<?php

namespace app\controller\Auth;

use think\Request;

final class ClientContext
{
    /** Cloudflare proxy ranges. Only trust CF headers when REMOTE_ADDR belongs here. */
    private const CLOUDFLARE_CIDRS = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    public static function ip(Request $request): string
    {
        $remote = trim((string) $request->server('REMOTE_ADDR', ''));
        if ($remote !== '' && self::isCloudflareIp($remote)) {
            $candidate = trim((string) $request->header('cf-connecting-ip', ''));
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        if (filter_var($remote, FILTER_VALIDATE_IP) !== false) {
            return $remote;
        }

        $fallback = trim((string) $request->ip());
        return filter_var($fallback, FILTER_VALIDATE_IP) !== false ? $fallback : '';
    }

    public static function countryCode(Request $request): ?string
    {
        $remote = trim((string) $request->server('REMOTE_ADDR', ''));
        if ($remote === '' || !self::isCloudflareIp($remote)) {
            return null;
        }

        $country = strtoupper(trim((string) $request->header('cf-ipcountry', '')));
        return preg_match('/^[A-Z]{2}$/', $country) ? $country : null;
    }

    public static function packedIp(Request $request): ?string
    {
        $ip = self::ip($request);
        if ($ip === '') {
            return null;
        }
        $packed = @inet_pton($ip);
        return $packed === false ? null : $packed;
    }

    private static function isCloudflareIp(string $ip): bool
    {
        foreach (self::CLOUDFLARE_CIDRS as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        $networkBin = @inet_pton($parts[0]);
        if ($ipBin === false || $networkBin === false || strlen($ipBin) !== strlen($networkBin)) {
            return false;
        }

        $prefix = (int) $parts[1];
        $maxBits = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if ($wholeBytes > 0 && substr($ipBin, 0, $wholeBytes) !== substr($networkBin, 0, $wholeBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($ipBin[$wholeBytes]) & $mask) === (ord($networkBin[$wholeBytes]) & $mask);
    }
}
