<?php

namespace app\service\Wallet;

use app\service\Asset\AssetException;
use think\Request;

/**
 * Private-network guard for Wallet Monitor callbacks and wallet ops endpoints.
 *
 * There is intentionally no application-layer HMAC/token between the two
 * private servers. REMOTE_ADDR is authoritative; X-Forwarded-For is never
 * trusted for this boundary.
 */
final class InternalWalletRequestGuard
{
    public function inspect(Request $request, string $rawBody, array $payload): array
    {
        $remoteIp = $this->assertAllowed(
            $request,
            array_values((array) config('wallet.internal_api.allowed_callback_ips', ['10.0.0.1'])),
            'WALLET_CALLBACK_PRIVATE_NETWORK_ONLY',
            'Wallet callback 仅允许私网 Wallet Monitor 来源'
        );

        $eventId = trim((string) ($payload['event_id'] ?? ''));
        if ($eventId === '' || strlen($eventId) > 64 || !preg_match('/^[A-Za-z0-9:_\-.]+$/', $eventId)) {
            throw new AssetException('Wallet callback event_id 无效', 422, 'WALLET_CALLBACK_EVENT_ID_INVALID');
        }

        return [
            'source_service' => 'crystalbest-wallet-monitor',
            'remote_ip' => $remoteIp,
            'idempotency_key' => 'wallet-event:' . $eventId,
            'body_hash_hex' => hash('sha256', $rawBody),
        ];
    }

    public function inspectWithdrawalOps(Request $request): array
    {
        $remoteIp = $this->assertAllowed(
            $request,
            array_values((array) config('wallet.withdrawal.ops_allowed_ips', ['127.0.0.1', '::1'])),
            'WITHDRAW_OPS_PRIVATE_ONLY',
            '提币人工审核接口仅允许主站本机后台调用'
        );

        return [
            'source_service' => 'withdrawal-ops',
            'remote_ip' => $remoteIp,
        ];
    }

    public function inspectOps(Request $request): array
    {
        $remoteIp = $this->assertAllowed(
            $request,
            array_values((array) config('wallet.ops.allowed_ips', ['10.0.0.1', '127.0.0.1', '::1'])),
            'WALLET_OPS_PRIVATE_NETWORK_ONLY',
            'Wallet ops 仅允许私网/本机来源'
        );

        return [
            'source_service' => 'wallet-ops',
            'remote_ip' => $remoteIp,
        ];
    }

    private function assertAllowed(Request $request, array $allowedIps, string $code, string $message): string
    {
        $allowedIps = array_values(array_unique(array_filter(array_map('trim', $allowedIps))));
        $remoteIp = trim((string) $request->server('REMOTE_ADDR', ''));

        if ($remoteIp === '' || !in_array($remoteIp, $allowedIps, true)) {
            throw new AssetException($message, 403, $code);
        }

        return $remoteIp;
    }
}
