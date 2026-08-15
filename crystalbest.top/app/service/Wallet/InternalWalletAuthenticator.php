<?php

namespace app\service\Wallet;

use app\service\Asset\AssetException;
use think\Request;

final class InternalWalletAuthenticator
{
    /**
     * Private-network callback guard only.
     *
     * There is intentionally no HMAC, client secret, token, timestamp or nonce
     * verification. The 10.0.0.1 -> 10.0.0.2 private network is the trust
     * boundary. We retain the source-IP allowlist so the deposit callback is
     * never an unauthenticated public Internet endpoint.
     */
    public function authenticate(Request $request, string $path, string $rawBody): array
    {
        $config = (array) config('wallet.internal_api', []);
        $allowedIps = array_values((array) ($config['allowed_callback_ips'] ?? ['10.0.0.1']));
        $remoteIp = trim((string) $request->server('REMOTE_ADDR', ''));
        if ($remoteIp === '' || !in_array($remoteIp, $allowedIps, true)) {
            throw new AssetException('Wallet callback 仅允许私网 Wallet Monitor 来源', 403, 'WALLET_CALLBACK_PRIVATE_NETWORK_ONLY');
        }

        $payload = json_decode($rawBody, true);
        $eventId = is_array($payload) ? trim((string) ($payload['event_id'] ?? '')) : '';
        if ($eventId === '' || strlen($eventId) > 128 || !preg_match('/^[A-Za-z0-9:_\-.]+$/', $eventId)) {
            throw new AssetException('Wallet callback event_id 无效', 422, 'WALLET_CALLBACK_EVENT_ID_INVALID');
        }

        return [
            'client_id' => 'crystalbest-wallet-monitor',
            'remote_ip' => $remoteIp,
            'nonce' => '',
            'idempotency_key' => 'wallet-event:' . $eventId,
            'body_hash_hex' => hash('sha256', $rawBody),
        ];
    }
}
