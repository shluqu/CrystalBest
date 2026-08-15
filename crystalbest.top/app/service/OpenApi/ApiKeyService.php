<?php

namespace app\service\OpenApi;

use app\controller\Auth\AuditLog;
use app\controller\Auth\AuthService;
use app\controller\Auth\ClientContext;
use app\controller\Auth\Clock;
use app\controller\Auth\Crypto;
use app\controller\Auth\Ulid;
use think\facade\Cache;
use think\facade\Db;
use think\Request;

/**
 * User-managed HMAC API keys backed by the pre-existing cex_user_api_keys table.
 *
 * Database contract used as-is:
 * - public_id
 * - user_id
 * - name
 * - key_prefix
 * - api_key_hash
 * - secret_ciphertext
 * - secret_key_version
 * - permissions_json
 * - ip_whitelist_json
 * - status / expires_at / last_used_at / revoked_at / revoke_reason
 *
 * No schema migration is required for V12 DB-native.
 */
final class ApiKeyService
{
    public const SCOPE_PROFILE = 'profile.read';
    public const SCOPE_POSITIONS = 'positions.read';
    public const SCOPE_BALANCES = 'balances.read';
    public const SCOPE_WALLET_HISTORY = 'wallet_history.read';
    public const SCOPE_MARKETS = 'markets.read';

    private const MAX_ACTIVE_KEYS = 5;
    private const REQUESTS_PER_MINUTE = 120;
    private const SIGNATURE_WINDOW_SECONDS = 300;
    private const NONCE_TTL_SECONDS = 330;
    private const SECRET_PURPOSE = 'openapi:hmac-secret';

    private Request $request;
    private ?array $webAuth = null;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function dashboardContext(): array
    {
        $auth = $this->webAuth();
        $rows = Db::table('cex_user_api_keys')
            ->where('user_id', (int) $auth['user_id'])
            ->field('public_id,name,key_prefix,permissions_json,ip_whitelist_json,status,expires_at,last_used_at,revoked_at,revoke_reason,created_at,updated_at')
            ->order('id', 'desc')
            ->limit(100)
            ->select()
            ->toArray();

        $items = [];
        $activeCount = 0;
        foreach ($rows as $row) {
            $effectiveStatus = $this->effectiveStatus((int) $row['status'], $row['expires_at'] ?? null);
            if ($effectiveStatus === 1) {
                $activeCount++;
            }
            $items[] = [
                'public_id' => (string) $row['public_id'],
                'name' => (string) $row['name'],
                'key_prefix' => (string) $row['key_prefix'],
                'display_key' => (string) $row['key_prefix'] . '••••••••••••',
                'status' => $effectiveStatus,
                'status_label' => $this->statusLabel($effectiveStatus),
                'permissions' => $this->permissionLabels($this->decodePermissions($row['permissions_json'] ?? null)),
                'ip_whitelist' => $this->decodeIpWhitelist($row['ip_whitelist_json'] ?? null),
                'expires_at' => $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
                'created_at' => (string) $row['created_at'],
                'updated_at' => (string) $row['updated_at'],
                'last_used_at' => $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
                'revoked_at' => $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
                'revoke_reason' => $row['revoke_reason'] !== null ? (string) $row['revoke_reason'] : null,
            ];
        }

        return [
            'keys' => $items,
            'active_count' => $activeCount,
            'max_active_keys' => self::MAX_ACTIVE_KEYS,
            'available_slots' => max(0, self::MAX_ACTIVE_KEYS - $activeCount),
            'permission_labels' => $this->permissionLabels($this->defaultPermissions()),
            'base_path' => '/openapi/v1',
            'auth_headers' => [
                'X-CB-API-KEY',
                'X-CB-API-TIMESTAMP',
                'X-CB-API-NONCE',
                'X-CB-API-SIGNATURE',
            ],
            'signature_window_seconds' => self::SIGNATURE_WINDOW_SECONDS,
            'market_data_included' => false,
        ];
    }

    public function create(array $data): array
    {
        $auth = $this->webAuth();
        $userId = (int) $auth['user_id'];
        $this->rateLimit('create:user:' . $userId, 10, 3600, 'API_KEY_CREATE_RATE_LIMITED');

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $name = '只读 API';
        }
        $length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($length > 64) {
            throw new ApiException('API 名称最多 64 个字符', 422, 'API_KEY_NAME_TOO_LONG');
        }
        if ($this->activeKeyCount($userId) >= self::MAX_ACTIVE_KEYS) {
            throw new ApiException('最多只能同时启用 ' . self::MAX_ACTIVE_KEYS . ' 个 API Key', 409, 'API_KEY_LIMIT_REACHED');
        }

        $publicId = Ulid::generate();
        $apiKey = 'CBK_' . $this->base64Url(random_bytes(24));
        $secret = $this->base64Url(random_bytes(32));
        $keyPrefix = substr($apiKey, 0, 18);
        $permissions = $this->defaultPermissions();
        $now = Clock::now();

        Db::table('cex_user_api_keys')->insert([
            'public_id' => $publicId,
            'user_id' => $userId,
            'name' => $name,
            'key_prefix' => $keyPrefix,
            'api_key_hash' => hash('sha256', $apiKey, true),
            'secret_ciphertext' => Crypto::encryptSensitive($secret, self::SECRET_PURPOSE),
            'secret_key_version' => $this->secretKeyVersion(),
            'permissions_json' => json_encode($permissions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ip_whitelist_json' => null,
            'status' => 1,
            'expires_at' => null,
            'last_used_at' => null,
            'revoked_at' => null,
            'revoke_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        AuditLog::record($this->request, 'API_KEY_CREATED', $userId, 1, 'api_key', $publicId, [
            'name' => $name,
            'key_prefix' => $keyPrefix,
            'scopes' => $permissions['scopes'],
        ]);

        return [
            'public_id' => $publicId,
            'api_key' => $apiKey,
            'api_secret' => $secret,
            'key_prefix' => $keyPrefix,
            'name' => $name,
            'permissions' => $this->permissionLabels($permissions),
            'created_at' => $now,
            'credentials_visible_once' => true,
            'message' => '完整 API Key 与 API Secret 只显示这一次，请立即复制并安全保存。',
        ];
    }

    public function revoke(array $data): array
    {
        $auth = $this->webAuth();
        $userId = (int) $auth['user_id'];
        $publicId = strtoupper(trim((string) ($data['public_id'] ?? '')));
        if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $publicId)) {
            throw new ApiException('API Key 标识格式不正确', 422, 'INVALID_API_KEY_ID');
        }

        $row = Db::table('cex_user_api_keys')
            ->where('public_id', $publicId)
            ->where('user_id', $userId)
            ->field('id,public_id,key_prefix,status,revoked_at')
            ->find();
        if (!$row) {
            throw new ApiException('API Key 不存在', 404, 'API_KEY_NOT_FOUND');
        }
        if ((int) $row['status'] === 3) {
            return [
                'public_id' => $publicId,
                'revoked' => true,
                'revoked_at' => $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
            ];
        }

        $now = Clock::now();
        Db::table('cex_user_api_keys')
            ->where('id', (int) $row['id'])
            ->where('user_id', $userId)
            ->update([
                'status' => 3,
                'revoked_at' => $now,
                'revoke_reason' => 'USER_REVOKED',
                'updated_at' => $now,
            ]);

        AuditLog::record($this->request, 'API_KEY_REVOKED', $userId, 1, 'api_key', $publicId, [
            'key_prefix' => (string) $row['key_prefix'],
        ]);

        return [
            'public_id' => $publicId,
            'revoked' => true,
            'revoked_at' => $now,
        ];
    }

    /**
     * Authenticate a signed OpenAPI request and assert one read scope.
     */
    public function authenticate(string $requiredScope): array
    {
        $apiKey = trim((string) $this->request->header('x-cb-api-key', ''));
        $timestampRaw = trim((string) $this->request->header('x-cb-api-timestamp', ''));
        $nonce = trim((string) $this->request->header('x-cb-api-nonce', ''));
        $signature = strtolower(trim((string) $this->request->header('x-cb-api-signature', '')));

        if ($apiKey === '' || $timestampRaw === '' || $nonce === '' || $signature === '') {
            throw new ApiException('缺少 API 签名认证信息', 401, 'API_SIGNATURE_REQUIRED');
        }
        if (!preg_match('/^CBK_[A-Za-z0-9_-]{32}$/', $apiKey)) {
            throw new ApiException('API Key 格式不正确', 401, 'INVALID_API_KEY');
        }
        if (!preg_match('/^\d{10}(?:\d{3})?$/', $timestampRaw)) {
            throw new ApiException('API 时间戳格式不正确', 401, 'INVALID_API_TIMESTAMP');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce)) {
            throw new ApiException('API Nonce 格式不正确', 401, 'INVALID_API_NONCE');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $signature)) {
            throw new ApiException('API 签名格式不正确', 401, 'INVALID_API_SIGNATURE');
        }

        $requestSeconds = strlen($timestampRaw) === 13
            ? (int) floor(((int) $timestampRaw) / 1000)
            : (int) $timestampRaw;
        if (abs(time() - $requestSeconds) > self::SIGNATURE_WINDOW_SECONDS) {
            throw new ApiException('API 请求时间已过期，请校准系统时间后重试', 401, 'API_TIMESTAMP_EXPIRED');
        }

        $keyHash = hash('sha256', $apiKey, true);
        $row = Db::table('cex_user_api_keys')->alias('k')
            ->join('cex_user_users u', 'u.id = k.user_id')
            ->where('k.api_key_hash', $keyHash)
            ->field('k.id,k.public_id,k.user_id,k.key_prefix,k.secret_ciphertext,k.secret_key_version,k.permissions_json,k.ip_whitelist_json,k.status,k.expires_at,k.last_used_at,u.uid,u.status AS user_status')
            ->find();

        if (!$row || (int) $row['status'] !== 1 || (int) $row['user_status'] !== 1) {
            throw new ApiException('API Key 无效或已停用', 401, 'INVALID_API_KEY');
        }
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) <= time()) {
            throw new ApiException('API Key 已过期', 401, 'API_KEY_EXPIRED');
        }

        $permissions = $this->decodePermissions($row['permissions_json'] ?? null);
        if (!$this->hasScope($permissions, $requiredScope)) {
            throw new ApiException('当前 API Key 没有该读取权限', 403, 'API_PERMISSION_DENIED');
        }

        $clientIp = ClientContext::ip($this->request);
        $whitelist = $this->decodeIpWhitelist($row['ip_whitelist_json'] ?? null);
        if (!empty($whitelist) && !$this->ipAllowed($clientIp, $whitelist)) {
            throw new ApiException('当前 IP 不在 API 白名单中', 403, 'API_IP_NOT_ALLOWED');
        }

        $secret = Crypto::decryptSensitive((string) $row['secret_ciphertext'], self::SECRET_PURPOSE);
        $canonical = $this->canonicalRequest($timestampRaw, $nonce);
        $expected = hash_hmac('sha256', $canonical, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new ApiException('API 请求签名不正确', 401, 'INVALID_API_SIGNATURE');
        }

        $nonceKey = 'openapi:nonce:' . (int) $row['id'] . ':' . hash('sha256', $nonce);
        if (Cache::get($nonceKey)) {
            throw new ApiException('检测到重复 API 请求', 409, 'API_REPLAY_DETECTED');
        }
        Cache::set($nonceKey, 1, self::NONCE_TTL_SECONDS);

        $this->rateLimit('request:key:' . (int) $row['id'], self::REQUESTS_PER_MINUTE, 60, 'API_RATE_LIMITED');
        $this->touchUsage((int) $row['id'], $row['last_used_at'] ?? null);

        return [
            'api_key_id' => (int) $row['id'],
            'public_id' => (string) $row['public_id'],
            'key_prefix' => (string) $row['key_prefix'],
            'user_id' => (int) $row['user_id'],
            'uid' => (string) $row['uid'],
            'permissions' => $permissions,
        ];
    }

    public function canonicalRequest(string $timestamp, string $nonce): string
    {
        $method = strtoupper((string) $this->request->method());
        $path = '/' . ltrim((string) $this->request->pathinfo(), '/');
        $query = $this->canonicalQuery($this->request->get());
        $bodyHash = hash('sha256', (string) $this->request->getInput());

        return implode("\n", [
            $timestamp,
            $nonce,
            $method,
            $path,
            $query,
            $bodyHash,
        ]);
    }

    public function permissionLabels(array $permissions): array
    {
        $map = [
            self::SCOPE_PROFILE => '用户资料',
            self::SCOPE_POSITIONS => '合约持仓',
            self::SCOPE_BALANCES => '用户持币',
            self::SCOPE_WALLET_HISTORY => '充值/提币记录',
            self::SCOPE_MARKETS => '支持交易币种',
        ];
        $labels = [];
        $scopes = isset($permissions['scopes']) && is_array($permissions['scopes']) ? $permissions['scopes'] : [];
        foreach ($map as $scope => $label) {
            if (in_array($scope, $scopes, true)) {
                $labels[] = $label;
            }
        }
        return $labels;
    }

    private function defaultPermissions(): array
    {
        return [
            'read' => true,
            'trade' => false,
            'withdraw' => false,
            'scopes' => [
                self::SCOPE_PROFILE,
                self::SCOPE_POSITIONS,
                self::SCOPE_BALANCES,
                self::SCOPE_WALLET_HISTORY,
                self::SCOPE_MARKETS,
            ],
        ];
    }

    private function hasScope(array $permissions, string $requiredScope): bool
    {
        if (($permissions['read'] ?? false) !== true) {
            return false;
        }
        $scopes = isset($permissions['scopes']) && is_array($permissions['scopes']) ? $permissions['scopes'] : [];
        return in_array($requiredScope, $scopes, true);
    }

    private function decodePermissions($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function decodeIpWhitelist($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value)));
        }
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode((string) $value, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter(array_map(static fn($item) => trim((string) $item), $decoded)));
    }

    private function webAuth(): array
    {
        if ($this->webAuth !== null) {
            return $this->webAuth;
        }
        $auth = new AuthService($this->request);
        $cookie = (string) $this->request->cookie($auth->cookieName(), '');
        $this->webAuth = $auth->authenticatedSession($cookie, true);
        return $this->webAuth;
    }

    private function activeKeyCount(int $userId): int
    {
        $rows = Db::table('cex_user_api_keys')
            ->where('user_id', $userId)
            ->field('status,expires_at')
            ->select()
            ->toArray();
        $count = 0;
        foreach ($rows as $row) {
            if ($this->effectiveStatus((int) $row['status'], $row['expires_at'] ?? null) === 1) {
                $count++;
            }
        }
        return $count;
    }

    private function effectiveStatus(int $status, $expiresAt): int
    {
        if ($status === 1 && $expiresAt !== null && $expiresAt !== '' && strtotime((string) $expiresAt) <= time()) {
            return 4;
        }
        return $status;
    }

    private function statusLabel(int $status): string
    {
        return [
            1 => '启用中',
            2 => '已停用',
            3 => '已撤销',
            4 => '已过期',
        ][$status] ?? '未知状态';
    }

    private function canonicalQuery(array $query): string
    {
        $query = $this->sortRecursive($query);
        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }
        ksort($value, SORT_STRING);
        return $value;
    }

    private function touchUsage(int $id, $lastUsedAt): void
    {
        if ($lastUsedAt !== null && $lastUsedAt !== '' && strtotime((string) $lastUsedAt) > time() - 60) {
            return;
        }
        $cacheKey = 'openapi:last-used:' . $id;
        if (Cache::get($cacheKey)) {
            return;
        }
        Cache::set($cacheKey, 1, 60);
        Db::table('cex_user_api_keys')
            ->where('id', $id)
            ->where('status', 1)
            ->update([
                'last_used_at' => Clock::now(),
                'updated_at' => Clock::now(),
            ]);
    }

    private function rateLimit(string $subject, int $limit, int $window, string $errorCode): void
    {
        $key = 'openapi:rl:' . hash('sha256', $subject);
        $state = Cache::get($key);
        $now = time();
        if (!is_array($state) || (int) ($state['reset_at'] ?? 0) <= $now) {
            Cache::set($key, ['count' => 1, 'reset_at' => $now + $window], $window);
            return;
        }
        $count = (int) ($state['count'] ?? 0);
        if ($count >= $limit) {
            throw new ApiException('请求过于频繁，请稍后再试', 429, $errorCode);
        }
        $state['count'] = $count + 1;
        Cache::set($key, $state, max(1, (int) $state['reset_at'] - $now));
    }

    private function ipAllowed(string $clientIp, array $whitelist): bool
    {
        foreach ($whitelist as $rule) {
            if ($rule === $clientIp) {
                return true;
            }
            if (strpos($rule, '/') !== false && $this->ipInCidr($clientIp, $rule)) {
                return true;
            }
        }
        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($network === null || $prefix === null || !ctype_digit((string) $prefix)) {
            return false;
        }
        $ipBin = @inet_pton($ip);
        $networkBin = @inet_pton($network);
        if ($ipBin === false || $networkBin === false || strlen($ipBin) !== strlen($networkBin)) {
            return false;
        }
        $bits = (int) $prefix;
        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $remaining = $bits % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($networkBin, 0, $bytes)) {
            return false;
        }
        if ($remaining === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remaining)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($networkBin[$bytes]) & $mask);
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function secretKeyVersion(): string
    {
        $value = trim((string) env('auth.data_encryption_key_version', 'APP_DATA_KEY_S1'));
        if ($value === '' || strlen($value) > 64) {
            return 'APP_DATA_KEY_S1';
        }
        return $value;
    }
}
