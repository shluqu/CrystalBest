<?php

namespace app\controller\Auth;

use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\Request;

class AuthService
{
    private const DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$M0J1bEJEMDBWbTgzYUMzQg$/uN38x2nNqDN1xEuehWca4FhMtq+19g5//jKyvc8u4U';

    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function register(array $data): array
    {
        $this->rateLimit('register:ip:' . $this->clientIp(), 10, 3600);

        $email = Crypto::validateEmail((string) ($data['email'] ?? ''));
        $emailHash = Crypto::emailHash($email);
        $password = (string) ($data['password'] ?? '');
        $confirmPassword = (string) ($data['confirm_password'] ?? $data['confirmPassword'] ?? '');
        $nickname = trim((string) ($data['nickname'] ?? ''));
        $ticket = trim((string) ($data['registration_ticket'] ?? ''));

        $this->validatePassword($password, $confirmPassword);
        if ($nickname !== '' && (function_exists('mb_strlen') ? mb_strlen($nickname) : strlen($nickname)) > 64) {
            throw new AuthException('昵称最多 64 个字符', 422, 'INVALID_NICKNAME');
        }
        if (!(bool) ($data['accept_terms'] ?? false)) {
            throw new AuthException('请先同意服务条款和隐私政策', 422, 'TERMS_REQUIRED');
        }

        $verification = new VerificationService($this->request);
        $verification->validateRegistrationTicket($email, $ticket);
        $verification->assertCaptcha((string) ($data['captcha'] ?? ''), 'register-final');

        if (Db::table('cex_user_users')->where('email_hash', $emailHash)->field('id')->find()) {
            throw new AuthException('该邮箱已经注册，请直接登录', 409, 'EMAIL_EXISTS');
        }

        $passwordInfo = $this->hashPassword($password);
        $ipPacked = $this->packIp($this->clientIp());
        $uid = PublicUid::generate();
        $avatarUrl = DefaultAvatar::randomPath();
        $ownReferralCode = $this->generateReferralCode();

        $userId = Db::transaction(function () use (
            $uid,
            $email,
            $emailHash,
            $nickname,
            $ipPacked,
            $ownReferralCode,
            $avatarUrl,
            $passwordInfo
        ) {
            $userId = Db::table('cex_user_users')->insertGetId([
                'uid' => $uid,
                'email_ciphertext' => Crypto::encryptEmail($email),
                'email_hash' => $emailHash,
                'email_masked' => Crypto::maskEmail($email),
                'email_verified_at' => $this->now(),
                'nickname' => $nickname !== '' ? $nickname : null,
                'avatar_url' => $avatarUrl,
                'avatar_storage_key' => null,
                'status' => 1,
                'registration_channel' => 'web',
                'registration_ip' => $ipPacked,
                'referral_code' => $ownReferralCode,
                'referrer_id' => null,
            ]);

            Db::table('cex_user_credentials')->insert([
                'user_id' => $userId,
                'password_hash' => $passwordInfo['hash'],
                'password_algorithm' => $passwordInfo['algorithm'],
                'password_version' => 1,
                'failed_login_count' => 0,
                'must_change_password' => 0,
            ]);

            Db::table('cex_user_security')->insert(['user_id' => $userId, 'security_level' => 1]);
            return (int) $userId;
        });

        $verification->consumeRegistrationTicket($email, $ticket);
        $session = $this->createSession($userId, true);
        Log::info('User registered with verified email uid=' . $uid . ' ip=' . $this->clientIp());

        return [
            'user' => [
                'uid' => $uid,
                'email_masked' => Crypto::maskEmail($email),
                'nickname' => $nickname !== '' ? $nickname : null,
                'avatar_url' => $avatarUrl,
                'avatar_storage_key' => null,
                'status' => 1,
                'email_verified' => true,
            ],
            'session' => $session,
        ];
    }

    public function requestRegistrationCode(array $data): array
    {
        return (new VerificationService($this->request))->sendRegistrationCode($data);
    }

    public function verifyRegistrationCode(array $data): array
    {
        return (new VerificationService($this->request))->verifyRegistrationCode($data);
    }

    /** Password login: account may be public UID or email. */
    public function login(array $data): array
    {
        $this->rateLimit('login:ip:' . $this->clientIp(), 30, 900);

        $account = trim((string) ($data['account'] ?? $data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $remember = (bool) ($data['remember'] ?? false);
        if ($account === '') {
            throw new AuthException('请输入账号或邮箱', 422, 'ACCOUNT_REQUIRED');
        }
        if ($password === '') {
            throw new AuthException('请输入密码', 422, 'PASSWORD_REQUIRED');
        }

        $query = Db::table('cex_user_users')->alias('u')
            ->join('cex_user_credentials c', 'c.user_id = u.id');
        if (strpos($account, '@') !== false) {
            $email = Crypto::validateEmail($account);
            $query->where('u.email_hash', Crypto::emailHash($email));
        } else {
            if (!preg_match('/^[A-Za-z0-9_-]{6,64}$/', $account)) {
                password_verify($password, self::DUMMY_PASSWORD_HASH);
                $this->smallFailureDelay();
                throw new AuthException('账号或密码不正确', 401, 'INVALID_CREDENTIALS');
            }
            if (preg_match('/^[A-Za-z0-9]{16}$/', $account)) {
                $account = strtoupper($account);
            }
            $query->where('u.uid', $account);
        }

        $row = $query
            ->field('u.id,u.uid,u.email_masked,u.nickname,u.status,u.last_login_at,c.password_hash,c.password_algorithm,c.password_version,c.failed_login_count,c.locked_until,c.must_change_password')
            ->find();

        if (!$row) {
            password_verify($password, self::DUMMY_PASSWORD_HASH);
            $this->smallFailureDelay();
            throw new AuthException('账号或密码不正确', 401, 'INVALID_CREDENTIALS');
        }
        if ((int) $row['status'] !== 1) {
            throw new AuthException('当前账户暂不可登录', 403, 'ACCOUNT_UNAVAILABLE');
        }
        if (!empty($row['locked_until']) && strtotime((string) $row['locked_until']) > time()) {
            throw new AuthException('登录尝试过多，请稍后再试', 429, 'ACCOUNT_LOCKED');
        }
        if (!password_verify($password, (string) $row['password_hash'])) {
            $this->recordFailedLogin((int) $row['id'], (int) $row['failed_login_count']);
            $this->smallFailureDelay();
            throw new AuthException('账号或密码不正确', 401, 'INVALID_CREDENTIALS');
        }

        $this->maybeRehashPassword((int) $row['id'], $password, (string) $row['password_hash']);
        Db::table('cex_user_credentials')->where('user_id', (int) $row['id'])->update([
            'failed_login_count' => 0,
            'locked_until' => null,
        ]);
        Db::table('cex_user_users')->where('id', (int) $row['id'])->update([
            'last_login_at' => $this->now(),
            'version' => Db::raw('version + 1'),
        ]);

        $session = $this->createSession((int) $row['id'], $remember);
        Log::info('Password login success uid=' . $row['uid'] . ' ip=' . $this->clientIp());

        return [
            'user' => [
                'uid' => (string) $row['uid'],
                'email_masked' => (string) $row['email_masked'],
                'nickname' => $row['nickname'],
                'status' => (int) $row['status'],
                'must_change_password' => (bool) $row['must_change_password'],
            ],
            'session' => $session,
        ];
    }

    public function requestEmailLoginCode(array $data): array
    {
        return (new VerificationService($this->request))->sendLoginCode($data);
    }

    public function loginByEmailCode(array $data): array
    {
        $remember = (bool) ($data['remember'] ?? false);
        $verified = (new VerificationService($this->request))->verifyLoginCode($data);
        $userId = (int) $verified['user_id'];

        $row = Db::table('cex_user_users')
            ->where('id', $userId)
            ->field('id,uid,email_masked,nickname,status,email_verified_at')
            ->find();
        if (!$row || (int) $row['status'] !== 1) {
            throw new AuthException('当前账户暂不可登录', 403, 'ACCOUNT_UNAVAILABLE');
        }

        Db::table('cex_user_users')->where('id', $userId)->update([
            'email_verified_at' => $row['email_verified_at'] ?: $this->now(),
            'last_login_at' => $this->now(),
            'version' => Db::raw('version + 1'),
        ]);

        $session = $this->createSession($userId, $remember);
        Log::info('Email code login success uid=' . $row['uid'] . ' ip=' . $this->clientIp());
        return [
            'user' => [
                'uid' => (string) $row['uid'],
                'email_masked' => (string) $row['email_masked'],
                'nickname' => $row['nickname'],
                'status' => (int) $row['status'],
                'email_verified' => true,
            ],
            'session' => $session,
        ];
    }

    public function requestPasswordReset(array $data): array
    {
        return (new VerificationService($this->request))->sendPasswordResetCode($data);
    }

    public function verifyPasswordResetCode(array $data): array
    {
        return (new VerificationService($this->request))->verifyPasswordResetCode($data);
    }

    public function resetPassword(array $data): array
    {
        $this->rateLimit('pwdreset:final:ip:' . $this->clientIp(), 20, 900);

        $email = Crypto::validateEmail((string) ($data['email'] ?? ''));
        $emailHash = Crypto::emailHash($email);
        $ticket = trim((string) ($data['reset_ticket'] ?? ''));
        $password = (string) ($data['password'] ?? $data['new_password'] ?? '');
        $confirmPassword = (string) ($data['confirm_password'] ?? $data['confirmPassword'] ?? '');
        $this->validatePassword($password, $confirmPassword);

        $verification = new VerificationService($this->request);
        $ticketState = $verification->validatePasswordResetTicket($email, $ticket);
        $verification->assertCaptcha((string) ($data['captcha'] ?? ''), 'password-reset-final');
        $userId = (int) ($ticketState['user_id'] ?? 0);

        $user = $userId > 0
            ? Db::table('cex_user_users')->where('id', $userId)->where('email_hash', $emailHash)->field('id,uid,status')->find()
            : null;
        $credential = $userId > 0
            ? Db::table('cex_user_credentials')->where('user_id', $userId)->field('password_hash')->find()
            : null;
        if (!$user || !$credential || (int) $user['status'] === 4) {
            throw new AuthException('重置密码验证状态无效，请重新开始', 422, 'RESET_TICKET_INVALID');
        }
        if (password_verify($password, (string) $credential['password_hash'])) {
            throw new AuthException('新密码不能与当前密码相同', 422, 'PASSWORD_REUSED');
        }

        $passwordInfo = $this->hashPassword($password);
        Db::transaction(function () use ($user, $passwordInfo) {
            Db::table('cex_user_credentials')->where('user_id', (int) $user['id'])->update([
                'password_hash' => $passwordInfo['hash'],
                'password_algorithm' => $passwordInfo['algorithm'],
                'password_version' => Db::raw('password_version + 1'),
                'failed_login_count' => 0,
                'locked_until' => null,
                'must_change_password' => 0,
                'password_changed_at' => $this->now(),
                'version' => Db::raw('version + 1'),
            ]);

            Db::table('cex_user_sessions')
                ->where('user_id', (int) $user['id'])
                ->where('status', 1)
                ->update([
                    'status' => 2,
                    'revoked_at' => $this->now(),
                    'revoke_reason' => 'PASSWORD_RESET',
                ]);
        });

        $verification->consumePasswordResetTicket($email, $ticket);
        Log::warning('User password reset uid=' . $user['uid'] . ' ip=' . $this->clientIp());
        return ['reset' => true, 'sessions_revoked' => true];
    }

    public function authenticatedSession(string $cookieValue, bool $touch = true): array
    {
        return $this->authenticateSession($cookieValue, $touch);
    }

    public function me(string $cookieValue): array
    {
        $auth = $this->authenticateSession($cookieValue, true);
        $social = Db::table('cex_user_oauth_identities')
            ->where('user_id', (int) $auth['user_id'])
            ->where('status', 1)
            ->field('provider,avatar_url,display_name,last_login_at')
            ->order('last_login_at', 'desc')
            ->find();
        $hasPassword = (bool) Db::table('cex_user_credentials')
            ->where('user_id', (int) $auth['user_id'])
            ->field('user_id')
            ->find();
        $security = Db::table('cex_user_security')
            ->where('user_id', (int) $auth['user_id'])
            ->field('totp_enabled,security_level,login_notice_enabled,withdraw_mfa_required,withdraw_whitelist_enabled')
            ->find();

        return [
            'authenticated' => true,
            'user' => [
                'uid' => (string) $auth['uid'],
                'email_masked' => (string) $auth['email_masked'],
                'email_verified' => !empty($auth['email_verified_at']),
                'email_verified_at' => $auth['email_verified_at'] ?? null,
                'phone_masked' => $auth['phone_masked'],
                'nickname' => $auth['nickname'],
                'status' => (int) $auth['user_status'],
                'kyc_level' => (int) $auth['kyc_level'],
                'risk_level' => (int) $auth['risk_level'],
                'last_login_at' => $auth['last_login_at'],
                'avatar_url' => !empty($auth['avatar_url'])
                    ? (string) $auth['avatar_url']
                    : DefaultAvatar::forUserId((int) $auth['user_id']),
                'social_provider' => $social ? (string) $social['provider'] : null,
                'has_password' => $hasPassword,
                'totp_enabled' => $security ? (bool) $security['totp_enabled'] : false,
                'security_level' => $security ? (int) $security['security_level'] : 0,
                'login_notice_enabled' => $security ? (bool) $security['login_notice_enabled'] : true,
                'withdraw_mfa_required' => $security ? (bool) $security['withdraw_mfa_required'] : true,
                'withdraw_whitelist_enabled' => $security ? (bool) $security['withdraw_whitelist_enabled'] : false,
                'auth_level' => isset($auth['auth_level']) ? (int) $auth['auth_level'] : 1,
            ],
        ];
    }

    public function createSessionForUser(int $userId, bool $remember): array
    {
        return $this->createSession($userId, $remember);
    }

    public function logout(string $cookieValue): array
    {
        $parts = $this->parseSessionCookie($cookieValue);
        if (!$parts) {
            return ['logged_out' => true];
        }

        $session = Db::table('cex_user_sessions')->where('id', $parts['id'])->field('id,refresh_token_hash,status')->find();
        if ($session && hash_equals((string) $session['refresh_token_hash'], hash('sha256', $parts['token'], true))) {
            if ((int) $session['status'] === 1) {
                Db::table('cex_user_sessions')->where('id', $parts['id'])->update([
                    'status' => 2,
                    'revoked_at' => $this->now(),
                    'revoke_reason' => 'USER_LOGOUT',
                ]);
            }
        }

        return ['logged_out' => true];
    }

    public function cookieName(): string
    {
        $name = trim((string) env('auth.cookie_name', 'cex_session'));
        return $name !== '' ? $name : 'cex_session';
    }

    public function cookieOptions(bool $remember): array
    {
        $ttl = $remember
            ? (int) env('auth.remember_session_ttl_seconds', 2592000)
            : (int) env('auth.session_ttl_seconds', 43200);

        return [
            'expire' => max(1800, $ttl),
            'path' => '/',
            'domain' => trim((string) env('auth.cookie_domain', '')),
            'secure' => filter_var(env('auth.cookie_secure', true), FILTER_VALIDATE_BOOLEAN),
            'httponly' => true,
            'samesite' => 'lax',
        ];
    }

    public function expiredCookieOptions(): array
    {
        return [
            'expire' => time() - 3600,
            'path' => '/',
            'domain' => trim((string) env('auth.cookie_domain', '')),
            'secure' => filter_var(env('auth.cookie_secure', true), FILTER_VALIDATE_BOOLEAN),
            'httponly' => true,
            'samesite' => 'lax',
        ];
    }


    public function deviceCookieName(): string
    {
        return DeviceIdentity::cookieName();
    }

    public function deviceCookieOptions(): array
    {
        return DeviceIdentity::cookieOptions();
    }

    private function authenticateSession(string $cookieValue, bool $touch): array
    {
        $parts = $this->parseSessionCookie($cookieValue);
        if (!$parts) {
            throw new AuthException('未登录', 401, 'UNAUTHENTICATED');
        }

        $row = Db::table('cex_user_sessions')->alias('s')
            ->join('cex_user_users u', 'u.id = s.user_id')
            ->where('s.id', $parts['id'])
            ->field('s.id,s.user_id,s.refresh_token_hash,s.status AS session_status,s.auth_level,s.device_name,s.platform,s.ip_address,s.country_code,s.user_agent,s.created_at,s.expires_at,s.last_active_at,u.uid,u.email_masked,u.email_verified_at,u.phone_masked,u.nickname,u.avatar_url,u.avatar_storage_key,u.status AS user_status,u.kyc_level,u.risk_level,u.last_login_at')
            ->find();

        if (!$row || (int) $row['session_status'] !== 1 || (int) $row['user_status'] !== 1) {
            throw new AuthException('登录状态已失效', 401, 'SESSION_INVALID');
        }

        if (strtotime((string) $row['expires_at']) <= time()) {
            Db::table('cex_user_sessions')->where('id', $parts['id'])->where('status', 1)->update(['status' => 3]);
            throw new AuthException('登录状态已过期', 401, 'SESSION_EXPIRED');
        }

        $expected = hash('sha256', $parts['token'], true);
        if (!hash_equals((string) $row['refresh_token_hash'], $expected)) {
            throw new AuthException('登录状态已失效', 401, 'SESSION_INVALID');
        }

        if ($touch) {
            $touchValues = [];
            if (empty($row['last_active_at']) || strtotime((string) $row['last_active_at']) < time() - 60) {
                $touchValues['last_active_at'] = $this->now();
            }

            // Older sessions may have stored Cloudflare's edge IP because ThinkPHP's
            // request->ip() resolves REMOTE_ADDR. Refresh the active session from the
            // trusted Cloudflare visitor header on the next authenticated request.
            $currentPackedIp = ClientContext::packedIp($this->request);
            if ($currentPackedIp !== null && (string) ($row['ip_address'] ?? '') !== $currentPackedIp) {
                $touchValues['ip_address'] = $currentPackedIp;
            }
            $currentCountry = ClientContext::countryCode($this->request);
            if ($currentCountry !== null && (string) ($row['country_code'] ?? '') !== $currentCountry) {
                $touchValues['country_code'] = $currentCountry;
            }

            if ($touchValues !== []) {
                Db::table('cex_user_sessions')->where('id', $parts['id'])->update($touchValues);
            }
        }

        return $row;
    }

    private function createSession(int $userId, bool $remember): array
    {
        $sessionId = Ulid::generate();
        $familyId = Ulid::generate();
        $rawToken = Crypto::base64UrlEncode(random_bytes(32));
        $ttl = $remember
            ? (int) env('auth.remember_session_ttl_seconds', 2592000)
            : (int) env('auth.session_ttl_seconds', 43200);
        $ttl = max(1800, $ttl);

        $expires = (new \DateTimeImmutable('now'))->modify('+' . $ttl . ' seconds')->format('Y-m-d H:i:s.u');
        $userAgent = substr((string) $this->request->header('user-agent', ''), 0, 1024);
        $platform = $this->detectPlatform($userAgent);
        $deviceIdentity = DeviceIdentity::resolve($this->request);

        Db::table('cex_user_sessions')->insert([
            'id' => $sessionId,
            'user_id' => $userId,
            'refresh_token_hash' => hash('sha256', $rawToken, true),
            'token_family_id' => $familyId,
            'device_name' => $this->deviceName($userAgent),
            'device_fingerprint_hash' => $deviceIdentity['hash'],
            'platform' => $platform,
            'ip_address' => ClientContext::packedIp($this->request),
            'user_agent' => $userAgent !== '' ? $userAgent : null,
            'country_code' => ClientContext::countryCode($this->request),
            'auth_level' => 1,
            'status' => 1,
            'expires_at' => $expires,
            'last_active_at' => $this->now(),
        ]);

        return [
            'cookie_value' => $sessionId . '.' . $rawToken,
            'device_cookie_value' => $deviceIdentity['token'],
            'expires_at' => $expires,
            'remember' => $remember,
        ];
    }

    private function parseSessionCookie(string $cookieValue): ?array
    {
        $cookieValue = trim($cookieValue);
        if ($cookieValue === '' || strpos($cookieValue, '.') === false) {
            return null;
        }
        [$id, $token] = explode('.', $cookieValue, 2);
        if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id)) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token)) {
            return null;
        }
        return ['id' => $id, 'token' => $token];
    }

    private function validatePassword(string $password, string $confirmPassword): void
    {
        $length = strlen($password);
        if ($length < 8 || $length > 128) {
            throw new AuthException('密码长度必须为 8-128 个字符', 422, 'WEAK_PASSWORD');
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            throw new AuthException('密码至少需要同时包含字母和数字', 422, 'WEAK_PASSWORD');
        }
        if ($confirmPassword === '' || !hash_equals($password, $confirmPassword)) {
            throw new AuthException('两次输入的密码不一致', 422, 'PASSWORD_MISMATCH');
        }
    }

    private function hashPassword(string $password): array
    {
        if (defined('PASSWORD_ARGON2ID')) {
            $hash = password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => (int) env('auth.argon2_memory_cost', 65536),
                'time_cost' => (int) env('auth.argon2_time_cost', 4),
                'threads' => (int) env('auth.argon2_threads', 1),
            ]);
            if ($hash !== false) {
                return ['hash' => $hash, 'algorithm' => 'argon2id'];
            }
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        if ($hash === false) {
            throw new AuthException('密码哈希失败', 500, 'PASSWORD_HASH_FAILED');
        }
        return ['hash' => $hash, 'algorithm' => 'bcrypt'];
    }

    private function maybeRehashPassword(int $userId, string $password, string $currentHash): void
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        if (!password_needs_rehash($currentHash, $algo)) {
            return;
        }
        $new = $this->hashPassword($password);
        Db::table('cex_user_credentials')->where('user_id', $userId)->update([
            'password_hash' => $new['hash'],
            'password_algorithm' => $new['algorithm'],
            'password_version' => Db::raw('password_version + 1'),
            'password_changed_at' => $this->now(),
            'version' => Db::raw('version + 1'),
        ]);
    }

    private function recordFailedLogin(int $userId, int $currentCount): void
    {
        $newCount = min(1000, $currentCount + 1);
        $maxAttempts = max(3, (int) env('auth.max_login_attempts', 5));
        $values = ['failed_login_count' => $newCount];
        if ($newCount >= $maxAttempts) {
            $lockSeconds = max(60, (int) env('auth.login_lock_seconds', 900));
            $values['locked_until'] = (new \DateTimeImmutable('now'))->modify('+' . $lockSeconds . ' seconds')->format('Y-m-d H:i:s.u');
        }
        Db::table('cex_user_credentials')->where('user_id', $userId)->update($values);
    }

    private function rateLimit(string $key, int $limit, int $windowSeconds): void
    {
        $cacheKey = 'auth:rl:' . hash('sha256', $key);
        $state = Cache::get($cacheKey);
        $now = time();
        if (!is_array($state) || (int) ($state['reset_at'] ?? 0) <= $now) {
            Cache::set($cacheKey, ['count' => 1, 'reset_at' => $now + $windowSeconds], $windowSeconds);
            return;
        }

        $count = (int) ($state['count'] ?? 0);
        if ($count >= $limit) {
            throw new AuthException('请求过于频繁，请稍后再试', 429, 'RATE_LIMITED');
        }

        $state['count'] = $count + 1;
        Cache::set($cacheKey, $state, max(1, (int) $state['reset_at'] - $now));
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

    private function clientIp(): string
    {
        return ClientContext::ip($this->request);
    }

    private function packIp(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        return $packed === false ? null : $packed;
    }

    private function detectPlatform(string $userAgent): ?string
    {
        $ua = strtolower($userAgent);
        if ($ua === '') {
            return null;
        }
        if (strpos($ua, 'android') !== false) {
            return 'android';
        }
        if (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false) {
            return 'ios';
        }
        return 'web';
    }

    private function deviceName(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        $os = 'Web';
        if (stripos($userAgent, 'Windows NT') !== false) {
            $os = 'Windows';
        } elseif (stripos($userAgent, 'Android') !== false) {
            $os = 'Android';
        } elseif (stripos($userAgent, 'iPhone') !== false || stripos($userAgent, 'iPad') !== false) {
            $os = 'iOS';
        } elseif (stripos($userAgent, 'Mac OS X') !== false || stripos($userAgent, 'Macintosh') !== false) {
            $os = 'macOS';
        } elseif (stripos($userAgent, 'Linux') !== false) {
            $os = 'Linux';
        }

        $browser = 'Browser';
        if (stripos($userAgent, 'Edg/') !== false) {
            $browser = 'Microsoft Edge';
        } elseif (stripos($userAgent, 'Chrome/') !== false) {
            $browser = 'Google Chrome';
        } elseif (stripos($userAgent, 'Firefox/') !== false) {
            $browser = 'Mozilla Firefox';
        } elseif (stripos($userAgent, 'Safari/') !== false) {
            $browser = 'Safari';
        }

        return $os . ' · ' . $browser;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.u');
    }

    private function smallFailureDelay(): void
    {
        usleep(random_int(80000, 180000));
    }
}
