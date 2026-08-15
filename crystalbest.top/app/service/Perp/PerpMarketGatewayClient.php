<?php

namespace app\service\Perp;

use app\service\Asset\AssetException;

final class PerpMarketGatewayClient
{
    private string $baseUrl;
    private int $timeoutMs;

    public function __construct(?string $baseUrl = null, ?int $timeoutMs = null)
    {
        $this->baseUrl = rtrim((string) ($baseUrl ?: config('perp.execution_gateway_http_base', 'http://127.0.0.1:3100')), '/');
        $this->timeoutMs = max(200, min(5000, (int) ($timeoutMs ?: config('perp.execution_gateway_timeout_ms', 1200))));
    }

    public function bestPrices(string $symbol): array
    {
        if (!function_exists('curl_init')) {
            throw new AssetException('行情服务暂时不可用', 503, 'PERP_MARKET_HTTP_UNAVAILABLE');
        }

        $url = $this->baseUrl . '/api/v2/trade/perpetual/' . rawurlencode($symbol) . '/panel';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min($this->timeoutMs, 800),
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false || $status !== 200) {
            throw new AssetException('行情服务暂时不可用，请稍后重试', 503, 'PERP_MARKET_HTTP_FAILED');
        }
        $decoded = json_decode((string) $raw, true);
        $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;
        $best = is_array($data) ? ($data['best_prices'] ?? null) : null;
        $bid = is_array($best) && isset($best['best_bid']) ? trim((string) $best['best_bid']) : '';
        $ask = is_array($best) && isset($best['best_ask']) ? trim((string) $best['best_ask']) : '';
        if ($bid === '' || $ask === '' || !preg_match('/^\d+(?:\.\d+)?$/', $bid) || !preg_match('/^\d+(?:\.\d+)?$/', $ask)) {
            throw new AssetException('当前盘口暂不可用，请稍后重试', 503, 'PERP_BBO_UNAVAILABLE');
        }
        return ['best_bid' => $bid, 'best_ask' => $ask];
    }
}
