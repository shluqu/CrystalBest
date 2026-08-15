<?php

namespace app\service\Wallet;

use app\controller\Auth\Ulid;
use app\controller\Auth\UtcClock;
use app\service\Asset\AssetException;

/**
 * Client for the private 10.0.0.1 Wallet/Custody Service.
 *
 * The service-to-service trust boundary is the private network, not an
 * application HMAC/token. Only public wallet metadata may cross this boundary.
 */
final class WalletCustodyClient
{
    private const MAX_RESPONSE_BYTES = 1048576;

    private array $config;

    public function __construct()
    {
        $this->config = (array) config('wallet.internal_api', []);
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['base_url'] ?? '')) !== '';
    }

    public function allocateBundle(string $accountPublicId, string $userUid, array $networks): array
    {
        if (!$this->isConfigured()) {
            throw new AssetException('钱包内网服务尚未配置，暂时无法分配充值地址', 503, 'WALLET_API_NOT_CONFIGURED');
        }
        if (!preg_match('/^USR_[A-HJ-NP-Z2-9]{16}$/', $accountPublicId)) {
            throw new AssetException('业务账户标识无效', 500, 'WALLET_ACCOUNT_REF_INVALID');
        }
        if (!preg_match('/^[A-HJ-NP-Z2-9]{16}$/', $userUid)) {
            throw new AssetException('用户 UID 无效', 500, 'WALLET_USER_REF_INVALID');
        }

        $expected = array_values((array) config('wallet.bundle_networks', []));
        $networks = array_values(array_unique(array_map('strtoupper', array_map('trim', $networks))));
        sort($networks);
        $expectedSorted = $expected;
        sort($expectedSorted);
        if ($networks !== $expectedSorted) {
            throw new AssetException('Wallet Bundle 网络集合必须完整', 500, 'WALLET_BUNDLE_NETWORK_SET_INVALID');
        }

        $requestId = Ulid::generate();
        $payload = [
            'request_id' => $requestId,
            'idempotency_key' => 'wallet-bundle:' . $accountPublicId,
            'purpose' => 'USER_DEPOSIT',
            'account_ref' => $accountPublicId,
            'user_uid' => $userUid,
            'networks' => $expected,
            'requested_at' => UtcClock::now(),
        ];

        $response = $this->request('POST', '/v1/wallet-bundles/allocate', $payload);
        $this->assertNoSecretMaterial($response);

        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : null;
        if (!$data) {
            throw new AssetException('钱包内网服务未返回 Wallet Bundle', 502, 'WALLET_API_RESPONSE_INVALID');
        }

        $bundleId = trim((string) ($data['bundle_id'] ?? ''));
        if ($bundleId === '' || strlen($bundleId) > 128 || !preg_match('/^[A-Za-z0-9:_\-.]+$/', $bundleId)) {
            throw new AssetException('钱包内网服务返回的 bundle_id 无效', 502, 'WALLET_BUNDLE_ID_INVALID');
        }

        $addresses = $data['addresses'] ?? null;
        if (!is_array($addresses) || count($addresses) !== count($expected)) {
            throw new AssetException('钱包内网服务必须一次返回完整的五链地址组', 502, 'WALLET_BUNDLE_ADDRESSES_INCOMPLETE');
        }

        $mapped = [];
        foreach ($addresses as $addressRow) {
            if (!is_array($addressRow)) {
                throw new AssetException('Wallet Bundle 地址结构无效', 502, 'WALLET_BUNDLE_ADDRESS_INVALID');
            }

            $network = strtoupper(trim((string) ($addressRow['network'] ?? '')));
            if (!in_array($network, $expected, true) || isset($mapped[$network])) {
                throw new AssetException('Wallet Bundle 网络重复或不受支持', 502, 'WALLET_BUNDLE_NETWORK_INVALID');
            }

            $address = trim((string) ($addressRow['address'] ?? ''));
            WalletAddressNormalizer::normalize($network, $address);

            $addressRef = trim((string) ($addressRow['address_ref'] ?? ''));
            if ($addressRef === '' || strlen($addressRef) > 128 || !preg_match('/^[A-Za-z0-9:_\-.]+$/', $addressRef)) {
                throw new AssetException('Wallet Bundle address_ref 无效', 502, 'WALLET_ADDRESS_REF_INVALID');
            }

            $derivationPath = isset($addressRow['derivation_path']) ? trim((string) $addressRow['derivation_path']) : null;
            if ($derivationPath !== null && ($derivationPath === '' || strlen($derivationPath) > 255 || !preg_match("#^m(?:/[0-9]+'?)*$#", $derivationPath))) {
                throw new AssetException('Wallet Bundle derivation_path 无效', 502, 'WALLET_DERIVATION_PATH_INVALID');
            }

            $mapped[$network] = [
                'network' => $network,
                'address_ref' => $addressRef,
                'address' => $address,
                'derivation_path' => $derivationPath,
                'memo' => null,
            ];
        }

        foreach ($expected as $network) {
            if (!isset($mapped[$network])) {
                throw new AssetException('Wallet Bundle 缺少 ' . $network . ' 地址', 502, 'WALLET_BUNDLE_NETWORK_MISSING');
            }
        }

        return [
            'request_id' => $requestId,
            'external_bundle_id' => $bundleId,
            'bundle_version' => $this->optionalAscii($data['bundle_version'] ?? null, 64),
            'keyset_version' => $this->optionalAscii($data['keyset_version'] ?? null, 64),
            'addresses' => $mapped,
        ];
    }

    private function request(string $method, string $path, array $payload): array
    {
        if (!function_exists('curl_init')) {
            throw new AssetException('服务器未安装 PHP cURL，无法访问钱包内网服务', 500, 'CURL_EXTENSION_MISSING');
        }

        $baseUrl = rtrim((string) ($this->config['base_url'] ?? ''), '/');
        $this->assertPrivateBaseUrl($baseUrl);

        if (!preg_match('#^/v1/[A-Za-z0-9/_\-]+$#', $path)) {
            throw new AssetException('Wallet API path 无效', 500, 'WALLET_API_PATH_INVALID');
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new AssetException('Wallet API 请求编码失败', 500, 'WALLET_API_JSON_ERROR');
        }

        $ch = curl_init($baseUrl . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => (int) ($this->config['connect_timeout_seconds'] ?? 3),
            CURLOPT_TIMEOUT => (int) ($this->config['timeout_seconds'] ?? 8),
            CURLOPT_SSL_VERIFYPEER => (bool) ($this->config['verify_tls'] ?? false),
            CURLOPT_SSL_VERIFYHOST => (bool) ($this->config['verify_tls'] ?? false) ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Cache-Control: no-store',
            ],
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        curl_setopt_array($ch, $options);

        $rawBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($rawBody === false || $curlError !== '') {
            throw new AssetException('钱包内网服务连接失败', 503, 'WALLET_API_UNREACHABLE');
        }
        $rawBody = (string) $rawBody;
        if (strlen($rawBody) > self::MAX_RESPONSE_BYTES) {
            throw new AssetException('钱包内网服务响应过大', 502, 'WALLET_API_RESPONSE_TOO_LARGE');
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new AssetException('钱包内网服务返回了无效 JSON', 502, 'WALLET_API_BAD_JSON');
        }

        if ($statusCode < 200 || $statusCode >= 300 || ($decoded['ok'] ?? false) !== true) {
            $remoteCode = trim((string) ($decoded['code'] ?? 'WALLET_API_REMOTE_ERROR'));
            $message = trim((string) ($decoded['message'] ?? '钱包内网服务拒绝了请求'));
            throw new AssetException(
                $message !== '' ? $message : '钱包内网服务拒绝了请求',
                $this->mapRemoteStatus($statusCode),
                $this->safeRemoteCode($remoteCode)
            );
        }

        return $decoded;
    }

    private function assertPrivateBaseUrl(string $baseUrl): void
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new AssetException('Wallet API BASE_URL 配置无效', 500, 'WALLET_API_URL_INVALID');
        }

        $host = (string) $parts['host'];
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            throw new AssetException('Wallet API 必须使用私网 IP', 500, 'WALLET_API_PRIVATE_IP_REQUIRED');
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            throw new AssetException('Wallet API 禁止配置为公网 IP', 500, 'WALLET_API_PUBLIC_IP_FORBIDDEN');
        }
    }

    private function assertNoSecretMaterial($value): void
    {
        if (!is_array($value)) {
            return;
        }

        $forbidden = [
            'private_key', 'privatekey', 'private_key_plaintext', 'secret_key', 'secretkey',
            'mnemonic', 'master_seed', 'seed', 'xpriv', 'extended_private_key', 'keystore',
            'key_material', 'raw_private_key', 'signing_secret', 'wif',
        ];

        foreach ($value as $key => $item) {
            $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '_', (string) $key));
            $normalized = trim($normalized, '_');
            $compact = str_replace('_', '', $normalized);
            $looksSecret = in_array($normalized, $forbidden, true)
                || str_contains($compact, 'privatekey')
                || str_contains($compact, 'secretkey')
                || str_contains($compact, 'mnemonic')
                || str_contains($compact, 'masterseed')
                || str_contains($compact, 'xpriv')
                || str_contains($compact, 'keystore')
                || $compact === 'wif';
            if ($looksSecret) {
                throw new AssetException('钱包内网服务响应包含禁止传输的私钥材料字段', 502, 'WALLET_SECRET_MATERIAL_REJECTED');
            }
            $this->assertNoSecretMaterial($item);
        }
    }

    private function optionalAscii($value, int $maxLength): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = trim((string) $value);
        if (strlen($value) > $maxLength || !preg_match('/^[A-Za-z0-9:_\-.]+$/', $value)) {
            throw new AssetException('钱包内网服务版本字段无效', 502, 'WALLET_API_VERSION_INVALID');
        }
        return $value;
    }

    private function mapRemoteStatus(int $statusCode): int
    {
        if ($statusCode === 409) {
            return 409;
        }
        if ($statusCode === 503) {
            return 503;
        }
        return 502;
    }

    private function safeRemoteCode(string $code): string
    {
        $code = strtoupper($code);
        $code = preg_replace('/[^A-Z0-9_\-]/', '_', $code);
        $code = substr($code ?: 'WALLET_API_REMOTE_ERROR', 0, 64);
        return 'REMOTE_' . $code;
    }
}
