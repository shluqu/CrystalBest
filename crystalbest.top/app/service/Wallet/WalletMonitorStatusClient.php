<?php

namespace app\service\Wallet;

use app\service\Asset\AssetException;

final class WalletMonitorStatusClient
{
    private const MAX_RESPONSE_BYTES = 1048576;

    public function fetch(): array
    {
        $url = trim((string) config('wallet.ops.monitor_status_url', 'http://10.0.0.1:3356/status'));
        $this->assertPrivateStatusUrl($url);

        if (!function_exists('curl_init')) {
            throw new AssetException('服务器未安装 PHP cURL', 500, 'WALLET_MONITOR_CURL_MISSING');
        }

        $ch = curl_init($url);
        $timeout = (int) config('wallet.ops.monitor_timeout_seconds', 3);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Cache-Control: no-store',
            ],
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $error !== '') {
            throw new AssetException('Wallet Monitor 状态接口不可达', 503, 'WALLET_MONITOR_UNREACHABLE');
        }
        $raw = (string) $raw;
        if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
            throw new AssetException('Wallet Monitor 状态响应过大', 502, 'WALLET_MONITOR_RESPONSE_TOO_LARGE');
        }

        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            throw new AssetException('Wallet Monitor 状态响应无效', 502, 'WALLET_MONITOR_BAD_RESPONSE');
        }

        return $this->sanitize($decoded);
    }

    private function sanitize(array $status): array
    {
        $networks = [];
        foreach ((array) ($status['networks'] ?? []) as $code => $row) {
            if (!is_array($row)) continue;
            $networks[(string) $code] = [
                'configured' => (bool) ($row['configured'] ?? false),
                'running' => (bool) ($row['running'] ?? false),
                'watch_count' => (int) ($row['watch_count'] ?? 0),
                'checkpoint' => isset($row['checkpoint']) ? (int) $row['checkpoint'] : null,
                'tip' => isset($row['tip']) ? (int) $row['tip'] : null,
                'last_observations' => (int) ($row['last_observations'] ?? 0),
                'last_success_at' => $this->textOrNull($row['last_success_at'] ?? null, 64),
                'last_recheck_at' => $this->textOrNull($row['last_recheck_at'] ?? null, 64),
                'last_error' => $this->textOrNull($row['last_error'] ?? null, 512),
            ];
        }

        return [
            'ok' => (bool) ($status['ok'] ?? false),
            'service' => $this->textOrNull($status['service'] ?? null, 64),
            'stopping' => (bool) ($status['stopping'] ?? false),
            'started_at' => $this->textOrNull($status['started_at'] ?? null, 64),
            'watch_address_count' => (int) ($status['watch_address_count'] ?? 0),
            'last_watch_refresh_at' => $this->textOrNull($status['last_watch_refresh_at'] ?? null, 64),
            'last_watch_refresh_error' => $this->textOrNull($status['last_watch_refresh_error'] ?? null, 512),
            'last_scan_cycle_at' => $this->textOrNull($status['last_scan_cycle_at'] ?? null, 64),
            'last_delivery_cycle_at' => $this->textOrNull($status['last_delivery_cycle_at'] ?? null, 64),
            'last_delivery_error' => $this->textOrNull($status['last_delivery_error'] ?? null, 512),
            'networks_running' => array_values(array_map('strval', (array) ($status['networks_running'] ?? []))),
            'networks' => $networks,
            'scan_interval_ms' => (int) ($status['scan_interval_ms'] ?? 0),
            'address_refresh_ms' => (int) ($status['address_refresh_ms'] ?? 0),
            'delivery_interval_ms' => (int) ($status['delivery_interval_ms'] ?? 0),
            'recheck_interval_ms' => (int) ($status['recheck_interval_ms'] ?? 0),
            'eth_getlogs_block_chunk' => (int) ($status['eth_getlogs_block_chunk'] ?? 0),
            'process' => [
                'node' => $this->textOrNull($status['process']['node'] ?? null, 32),
                'uptime_seconds' => (int) ($status['process']['uptime_seconds'] ?? 0),
            ],
            'time_utc' => $this->textOrNull($status['time_utc'] ?? null, 64),
        ];
    }

    private function assertPrivateStatusUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (string) ($parts['path'] ?? '') !== '/status') {
            throw new AssetException('Wallet Monitor status URL 配置无效', 500, 'WALLET_MONITOR_URL_INVALID');
        }

        $host = (string) $parts['host'];
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            throw new AssetException('Wallet Monitor status URL 必须使用私网 IP', 500, 'WALLET_MONITOR_PRIVATE_IP_REQUIRED');
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            throw new AssetException('Wallet Monitor status URL 禁止使用公网 IP', 500, 'WALLET_MONITOR_PUBLIC_IP_FORBIDDEN');
        }
    }

    private function textOrNull($value, int $max): ?string
    {
        if ($value === null) return null;
        $text = trim((string) $value);
        if ($text === '') return null;
        return substr($text, 0, $max);
    }
}
