<?php

namespace app\controller\Auth\Social;

use app\controller\Auth\AuthException;
use app\controller\Auth\AuthService;
use app\controller\Auth\ClientContext;
use app\controller\Auth\Crypto;
use app\controller\Auth\DefaultAvatar;
use app\controller\Auth\PublicUid;
use app\controller\Auth\ResendMailer;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\Request;

class SocialAuthService
{
    private $request;
    private $authService;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->authService = new AuthService($request);
    }

    public function authorizationUrl(string $provider, string $next = '/dashboard'): string
    {
        return $this->buildAuthorizationUrl($provider, [
            'mode' => 'login',
            'next' => $this->safeNext($next),
        ]);
    }

    public function authorizationUrlForLink(string $provider, array $linkContext, string $next = '/dashboard/security'): string
    {
        if (empty($linkContext['user_id']) || empty($linkContext['session_id'])) {
            throw new AuthException('社交账号绑定状态无效', 422, 'SOCIAL_LINK_INTENT_INVALID');
        }

        return $this->buildAuthorizationUrl($provider, [
            'mode' => 'link',
            'user_id' => (int) $linkContext['user_id'],
            'session_id' => (string) $linkContext['session_id'],
            'next' => $this->safeNext($next),
        ]);
    }

    public function callbackMode(string $state): string
    {
        $state = trim($state);
        if ($state === '') {
            return 'login';
        }
        $oauthState = Cache::get($this->stateCacheKey($state));
        return is_array($oauthState) && (($oauthState['mode'] ?? '') === 'link') ? 'link' : 'login';
    }

    public function discardState(string $state): void
    {
        $state = trim($state);
        if ($state !== '') {
            Cache::delete($this->stateCacheKey($state));
        }
    }

    public function callback(string $provider, string $code, string $state): array
    {
        $config = $this->readyProvider($provider);
        if ($code === '' || $state === '') {
            throw new AuthException('第三方登录回调参数不完整', 400, 'SOCIAL_CALLBACK_INVALID');
        }

        $cacheKey = $this->stateCacheKey($state);
        $oauthState = Cache::get($cacheKey);
        Cache::delete($cacheKey);

        if (!is_array($oauthState)
            || empty($oauthState['provider'])
            || !hash_equals((string) $oauthState['provider'], $provider)
            || empty($oauthState['nonce'])
            || empty($oauthState['code_verifier'])) {
            throw new AuthException('第三方登录状态已失效，请重新登录', 400, 'SOCIAL_STATE_INVALID');
        }

        $token = HttpClient::postForm($config['token_endpoint'], [
            'code' => $code,
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $config['redirect_uri'],
            'grant_type' => 'authorization_code',
            'code_verifier' => (string) $oauthState['code_verifier'],
        ]);

        if (empty($token['id_token']) || !is_string($token['id_token'])) {
            throw new AuthException('第三方登录没有返回身份令牌', 502, 'SOCIAL_ID_TOKEN_MISSING');
        }

        $claims = JwtVerifier::verify((string) $token['id_token'], $config, (string) $oauthState['nonce']);
        $profile = $this->profileFromClaims($provider, $claims);

        if (($oauthState['mode'] ?? 'login') === 'link') {
            $result = $this->linkIdentity($profile, $oauthState);
            $result['mode'] = 'link';
            $result['next'] = '/dashboard/security?social_linked=' . rawurlencode($provider);
            return $result;
        }

        $remember = filter_var(env('social_auth.session_remember', true), FILTER_VALIDATE_BOOLEAN);
        $result = $this->loginOrRegister($profile, $remember);
        $result['mode'] = 'login';
        $result['next'] = $this->safeNext((string) ($oauthState['next'] ?? '/dashboard'));
        $result['remember'] = $remember;
        return $result;
    }

    public function providers(): array
    {
        return ProviderConfig::publicStatus();
    }

    private function buildAuthorizationUrl(string $provider, array $extraState): string
    {
        $config = $this->readyProvider($provider);
        $state = Crypto::base64UrlEncode(random_bytes(32));
        $nonce = Crypto::base64UrlEncode(random_bytes(32));
        $verifier = Crypto::base64UrlEncode(random_bytes(48));
        $challenge = Crypto::base64UrlEncode(hash('sha256', $verifier, true));
        $ttl = max(300, min(900, (int) env('social_auth.state_ttl_seconds', 600)));

        $statePayload = array_merge([
            'provider' => $provider,
            'nonce' => $nonce,
            'code_verifier' => $verifier,
            'created_at' => time(),
            'expires_at' => time() + $ttl,
        ], $extraState);
        Cache::set($this->stateCacheKey($state), $statePayload, $ttl);

        $params = [
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => $config['scope'],
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        return $config['authorization_endpoint'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function linkIdentity(array $profile, array $oauthState): array
    {
        $cookie = (string) $this->request->cookie($this->authService->cookieName(), '');
        $auth = $this->authService->authenticatedSession($cookie, true);
        $targetUserId = (int) ($oauthState['user_id'] ?? 0);
        $targetSessionId = (string) ($oauthState['session_id'] ?? '');
        if ($targetUserId <= 0
            || $targetUserId !== (int) $auth['user_id']
            || $targetSessionId === ''
            || !hash_equals($targetSessionId, (string) $auth['id'])) {
            throw new AuthException('社交账号绑定会话已失效，请重新操作', 401, 'SOCIAL_LINK_SESSION_CHANGED');
        }

        $exact = Db::table('cex_user_oauth_identities')
            ->where('provider', $profile['provider'])
            ->where('provider_issuer', $profile['issuer'])
            ->where('provider_subject', $profile['subject'])
            ->field('id,user_id,status')
            ->find();

        if ($exact && (int) $exact['user_id'] !== $targetUserId) {
            throw new AuthException('该第三方账号已经绑定到其他 CrystalBest 账户', 409, 'SOCIAL_IDENTITY_ALREADY_BOUND');
        }
        if ($exact && (int) $exact['status'] === 3) {
            throw new AuthException('该第三方账号已被禁用，请联系支持人员', 403, 'SOCIAL_IDENTITY_DISABLED');
        }

        $activeSameProvider = Db::table('cex_user_oauth_identities')
            ->where('user_id', $targetUserId)
            ->where('provider', $profile['provider'])
            ->where('status', 1)
            ->field('id,provider_subject')
            ->find();
        if ($activeSameProvider && (!$exact || (int) $activeSameProvider['id'] !== (int) $exact['id'])) {
            throw new AuthException('当前账户已经绑定了另一个 ' . $this->providerLabel($profile['provider']) . ' 账号，请先解绑原账号', 409, 'SOCIAL_PROVIDER_ALREADY_BOUND');
        }

        $now = $this->now();
        $oauthId = $exact ? (int) $exact['id'] : 0;
        $alreadyLinked = $exact && (int) $exact['status'] === 1;

        Db::transaction(function () use ($targetUserId, $profile, $exact, &$oauthId, $auth, $now) {
            $identityData = [
                'provider_tenant_id' => $profile['tenant_id'],
                'provider_object_id' => $profile['object_id'],
                'email_ciphertext' => $profile['email'] !== null ? Crypto::encryptEmail($profile['email']) : null,
                'email_hash' => $profile['email_hash'],
                'email_masked' => $profile['email_masked'],
                'email_verified' => $profile['email'] !== null ? 1 : 0,
                'display_name' => $profile['display_name'],
                'avatar_url' => $profile['avatar_url'],
                'status' => 1,
                'unlinked_at' => null,
                'updated_at' => $now,
            ];

            if ($exact) {
                if ((int) $exact['status'] === 2) {
                    $identityData['linked_at'] = $now;
                }
                Db::table('cex_user_oauth_identities')->where('id', (int) $exact['id'])->update($identityData);
            } else {
                $oauthId = (int) Db::table('cex_user_oauth_identities')->insertGetId(array_merge($identityData, [
                    'user_id' => $targetUserId,
                    'provider' => $profile['provider'],
                    'provider_issuer' => $profile['issuer'],
                    'provider_subject' => $profile['subject'],
                    'is_private_email' => 0,
                    'linked_at' => $now,
                    'created_at' => $now,
                ]));
            }

            $user = Db::table('cex_user_users')->where('id', $targetUserId)->field('nickname')->find();
            if ($user && empty($user['nickname']) && $profile['display_name'] !== null) {
                Db::table('cex_user_users')->where('id', $targetUserId)->update([
                    'nickname' => $profile['display_name'],
                    'version' => Db::raw('version + 1'),
                ]);
            }

            Db::table('cex_user_sessions')
                ->where('user_id', $targetUserId)
                ->where('status', 1)
                ->where('id', '<>', (string) $auth['id'])
                ->update([
                    'status' => 2,
                    'revoked_at' => $now,
                    'revoke_reason' => 'SOCIAL_ACCOUNT_LINKED',
                ]);
        });

        $this->securityNotice(
            $targetUserId,
            $this->providerLabel($profile['provider']) . ' 已绑定',
            '你的 CrystalBest 账户刚刚绑定了 ' . $this->providerLabel($profile['provider']) . ' 登录方式，其他登录设备已退出。若非本人操作，请立即检查账户安全。'
        );
        Log::warning('Social account linked uid=' . $auth['uid'] . ' provider=' . $profile['provider'] . ' oauth_id=' . $oauthId . ' ip=' . ClientContext::ip($this->request));

        return [
            'linked' => true,
            'already_linked' => $alreadyLinked,
            'provider' => $profile['provider'],
            'other_sessions_revoked' => true,
        ];
    }

    private function loginOrRegister(array $profile, bool $remember): array
    {
        $identity = Db::table('cex_user_oauth_identities')->alias('o')
            ->join('cex_user_users u', 'u.id = o.user_id')
            ->where('o.provider', $profile['provider'])
            ->where('o.provider_issuer', $profile['issuer'])
            ->where('o.provider_subject', $profile['subject'])
            ->field('o.id AS oauth_id,o.user_id,o.status AS oauth_status,u.uid,u.email_hash AS user_email_hash,u.email_masked,u.email_verified_at,u.nickname,u.avatar_url,u.status AS user_status')
            ->find();

        if ($identity) {
            if ((int) $identity['oauth_status'] !== 1 || (int) $identity['user_status'] !== 1) {
                throw new AuthException('当前账户暂不可登录', 403, 'ACCOUNT_UNAVAILABLE');
            }

            Db::transaction(function () use ($identity, $profile) {
                Db::table('cex_user_oauth_identities')->where('id', (int) $identity['oauth_id'])->update([
                    'provider_tenant_id' => $profile['tenant_id'],
                    'provider_object_id' => $profile['object_id'],
                    'email_ciphertext' => $profile['email'] !== null ? Crypto::encryptEmail($profile['email']) : null,
                    'email_hash' => $profile['email_hash'],
                    'email_masked' => $profile['email_masked'],
                    'email_verified' => $profile['email'] !== null ? 1 : 0,
                    'display_name' => $profile['display_name'],
                    'avatar_url' => $profile['avatar_url'],
                    'last_login_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);

                $update = [
                    'last_login_at' => $this->now(),
                    'version' => Db::raw('version + 1'),
                ];
                if ($profile['email_hash'] !== null
                    && !empty($identity['user_email_hash'])
                    && hash_equals((string) $identity['user_email_hash'], (string) $profile['email_hash'])
                    && empty($identity['email_verified_at'])) {
                    $update['email_verified_at'] = $this->now();
                }
                if (empty($identity['nickname']) && $profile['display_name'] !== null) {
                    $update['nickname'] = $profile['display_name'];
                }
                Db::table('cex_user_users')->where('id', (int) $identity['user_id'])->update($update);
            });

            $session = $this->authService->createSessionForUser((int) $identity['user_id'], $remember);
            Log::info('Social login success provider=' . $profile['provider'] . ' uid=' . $identity['uid'] . ' ip=' . ClientContext::ip($this->request));

            return [
                'registered' => false,
                'user' => [
                    'uid' => (string) $identity['uid'],
                    'email_masked' => $profile['email_masked'] !== null ? $profile['email_masked'] : (string) $identity['email_masked'],
                    'nickname' => $profile['display_name'] !== null ? $profile['display_name'] : $identity['nickname'],
                    'avatar_url' => !empty($identity['avatar_url']) ? (string) $identity['avatar_url'] : DefaultAvatar::forUserId((int) $identity['user_id']),
                    'provider' => $profile['provider'],
                ],
                'session' => $session,
            ];
        }

        // 首次社交登录：如果邮箱已被本站其他账户占用，不自动合并，避免账户接管。
        if ($profile['email_hash'] !== null) {
            $sameEmailUser = Db::table('cex_user_users')->where('email_hash', $profile['email_hash'])->field('id,uid')->find();
            if ($sameEmailUser) {
                throw new AuthException('该邮箱已存在账户，请先使用原登录方式登录后再绑定第三方账户', 409, 'ACCOUNT_LINK_REQUIRED');
            }
        }

        $uid = PublicUid::generate();
        $avatarUrl = DefaultAvatar::randomPath();
        $referralCode = $this->generateReferralCode();
        $ipPacked = ClientContext::packedIp($this->request);

        $userId = Db::transaction(function () use ($profile, $uid, $avatarUrl, $referralCode, $ipPacked) {
            $userData = [
                'uid' => $uid,
                'email_ciphertext' => $profile['email'] !== null ? Crypto::encryptEmail($profile['email']) : null,
                'email_hash' => $profile['email_hash'],
                'email_masked' => $profile['email_masked'],
                'email_verified_at' => $profile['email'] !== null ? $this->now() : null,
                'nickname' => $profile['display_name'],
                'avatar_url' => $avatarUrl,
                'avatar_storage_key' => null,
                'status' => 1,
                'registration_channel' => 'web',
                'registration_ip' => $ipPacked,
                'referral_code' => $referralCode,
                'last_login_at' => $this->now(),
            ];

            $userId = (int) Db::table('cex_user_users')->insertGetId($userData);

            Db::table('cex_user_oauth_identities')->insert([
                'user_id' => $userId,
                'provider' => $profile['provider'],
                'provider_issuer' => $profile['issuer'],
                'provider_subject' => $profile['subject'],
                'provider_tenant_id' => $profile['tenant_id'],
                'provider_object_id' => $profile['object_id'],
                'email_ciphertext' => $profile['email'] !== null ? Crypto::encryptEmail($profile['email']) : null,
                'email_hash' => $profile['email_hash'],
                'email_masked' => $profile['email_masked'],
                'email_verified' => $profile['email'] !== null ? 1 : 0,
                'is_private_email' => 0,
                'display_name' => $profile['display_name'],
                'avatar_url' => $profile['avatar_url'],
                'status' => 1,
                'last_login_at' => $this->now(),
            ]);

            Db::table('cex_user_security')->insert([
                'user_id' => $userId,
                'security_level' => $profile['email'] !== null ? 1 : 0,
            ]);

            return $userId;
        });

        $session = $this->authService->createSessionForUser($userId, $remember);
        Log::info('Social user registered provider=' . $profile['provider'] . ' uid=' . $uid . ' ip=' . ClientContext::ip($this->request));

        return [
            'registered' => true,
            'user' => [
                'uid' => $uid,
                'email_masked' => $profile['email_masked'],
                'nickname' => $profile['display_name'],
                'avatar_url' => $avatarUrl,
                'provider' => $profile['provider'],
            ],
            'session' => $session,
        ];
    }

    private function profileFromClaims(string $provider, array $claims): array
    {
        $email = null;
        foreach (['email', 'preferred_username'] as $field) {
            if (!empty($claims[$field]) && is_string($claims[$field])) {
                $candidate = strtolower(trim((string) $claims[$field]));
                if (filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                    $email = $candidate;
                    break;
                }
            }
        }

        $displayName = trim((string) ($claims['name'] ?? ''));
        if ($displayName === '') {
            $displayName = null;
        } elseif (function_exists('mb_substr')) {
            $displayName = mb_substr($displayName, 0, 64);
        } else {
            $displayName = substr($displayName, 0, 64);
        }

        $avatar = null;
        if ($provider === 'google' && !empty($claims['picture']) && is_string($claims['picture'])) {
            $candidateAvatar = trim((string) $claims['picture']);
            if (strpos($candidateAvatar, 'https://') === 0 && strlen($candidateAvatar) <= 1024) {
                $avatar = $candidateAvatar;
            }
        }

        // 本系统策略：用户已经通过 Google / Microsoft 官方 OIDC 完成账号认证，
        // 且 Provider 返回了可用邮箱时，本站直接把该邮箱视为已验证主邮箱。
        $providerEmailVerified = $email !== null;

        return [
            'provider' => $provider,
            'issuer' => (string) $claims['iss'],
            'subject' => (string) $claims['sub'],
            'tenant_id' => $provider === 'microsoft' && !empty($claims['tid']) ? (string) $claims['tid'] : null,
            'object_id' => $provider === 'microsoft' && !empty($claims['oid']) ? (string) $claims['oid'] : null,
            'email' => $email,
            'email_hash' => $email !== null ? Crypto::emailHash($email) : null,
            'email_masked' => $email !== null ? Crypto::maskEmail($email) : null,
            'email_verified' => $providerEmailVerified,
            'display_name' => $displayName,
            'avatar_url' => $avatar,
        ];
    }

    private function readyProvider(string $provider): array
    {
        $config = ProviderConfig::get($provider);
        if (!$config['enabled']) {
            throw new AuthException('该第三方登录方式尚未启用', 404, 'SOCIAL_PROVIDER_DISABLED');
        }
        if ($config['client_id'] === '' || $config['client_secret'] === '' || $config['redirect_uri'] === '') {
            throw new AuthException('第三方登录配置不完整', 500, 'SOCIAL_CONFIG_MISSING');
        }
        return $config;
    }

    private function stateCacheKey(string $state): string
    {
        return 'auth:oauth:state:' . hash('sha256', $state);
    }

    private function safeNext(string $next): string
    {
        $next = trim($next);
        if ($next === '' || $next[0] !== '/' || strpos($next, '//') === 0 || strpos($next, "\r") !== false || strpos($next, "\n") !== false) {
            return '/dashboard';
        }
        if (preg_match('~^/(login|register|forgot-password)(?:[/?#]|$)~', $next)) {
            return '/dashboard';
        }
        if (preg_match('~^/(?:auth/|api/auth/)~', $next)) {
            return '/dashboard';
        }
        return $next;
    }

    private function generateReferralCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            if (!Db::table('cex_user_users')->where('referral_code', $code)->find()) {
                return $code;
            }
        }
        throw new AuthException('无法生成用户邀请码', 500, 'REFERRAL_GENERATION_FAILED');
    }

    private function securityNotice(int $userId, string $title, string $message): void
    {
        try {
            $user = Db::table('cex_user_users')->where('id', $userId)->field('email_ciphertext')->find();
            if (!$user || empty($user['email_ciphertext'])) {
                return;
            }
            $email = Crypto::decryptEmail((string) $user['email_ciphertext']);
            (new ResendMailer())->sendSecurityNotice($email, $title, $message);
        } catch (\Throwable $exception) {
            Log::error('Social security notice failed user_id=' . $userId . ' message=' . $exception->getMessage());
        }
    }

    private function providerLabel(string $provider): string
    {
        return $provider === 'microsoft' ? 'Microsoft' : 'Google';
    }

    private function packIp(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        return $packed === false ? null : $packed;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.u');
    }
}
