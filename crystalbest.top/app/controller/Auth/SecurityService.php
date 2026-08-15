<?php

namespace app\controller\Auth;

use app\controller\Auth\Social\ProviderConfig;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\Request;

class SecurityService
{
    private $request;
    private $authService;

    private const ALLOWED_ACTIONS = [
        'totp_enable',
        'totp_disable',
        'password_change',
        'email_change',
        'revoke_others',
        'social_link_google',
        'social_unlink_google',
        'social_link_microsoft',
        'social_unlink_microsoft',
    ];

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->authService = new AuthService($request);
    }

    public function overview(): array
    {
        $auth = $this->context(true);
        $security = $this->securityRow((int) $auth['user_id']);
        $hasPassword = (bool) Db::table('cex_user_credentials')
            ->where('user_id', (int) $auth['user_id'])
            ->field('user_id')
            ->find();
        $socialAccounts = $this->socialAccountsForUser((int) $auth['user_id'], $hasPassword);
        $emailState = Db::table('cex_user_users')
            ->where('id', (int) $auth['user_id'])
            ->field('email_verified_at')
            ->find();

        return [
            'email_masked' => (string) $auth['email_masked'],
            'email_verified' => !empty($emailState['email_verified_at']),
            'email_verified_at' => $emailState['email_verified_at'] ?? null,
            'has_password' => $hasPassword,
            'totp_enabled' => (bool) ($security['totp_enabled'] ?? false),
            'security_level' => (int) ($security['security_level'] ?? 0),
            'login_notice_enabled' => (bool) ($security['login_notice_enabled'] ?? true),
            'withdraw_mfa_required' => (bool) ($security['withdraw_mfa_required'] ?? true),
            'current_auth_level' => (int) ($auth['auth_level'] ?? 1),
            'social_accounts' => $socialAccounts['providers'],
            'active_social_count' => $socialAccounts['active_social_count'],
            'login_methods_count' => $socialAccounts['login_methods_count'],
        ];
    }

    public function sendEmailCode(string $action): array
    {
        $action = $this->assertAction($action);
        $auth = $this->context(true);
        $userId = (int) $auth['user_id'];
        $email = $this->userEmail($userId);

        if ($action === 'totp_enable' && $this->isTotpEnabled($userId)) {
            throw new AuthException('Google 身份验证器已经启用', 409, 'TOTP_ALREADY_ENABLED');
        }
        if ($action === 'totp_disable' && !$this->isTotpEnabled($userId)) {
            throw new AuthException('Google 身份验证器尚未启用', 409, 'TOTP_NOT_ENABLED');
        }
        if (strpos($action, 'social_link_') === 0) {
            $provider = $this->assertSocialProvider(substr($action, strlen('social_link_')));
            $status = ProviderConfig::publicStatus();
            if (empty($status[$provider])) {
                throw new AuthException('该第三方登录方式当前未启用', 409, 'SOCIAL_PROVIDER_DISABLED');
            }
            $existing = Db::table('cex_user_oauth_identities')
                ->where('user_id', $userId)
                ->where('provider', $provider)
                ->where('status', 1)
                ->field('id')
                ->find();
            if ($existing) {
                throw new AuthException('该社交账号类型已经绑定', 409, 'SOCIAL_PROVIDER_ALREADY_BOUND');
            }
        }
        if (strpos($action, 'social_unlink_') === 0) {
            $provider = $this->assertSocialProvider(substr($action, strlen('social_unlink_')));
            $hasPassword = (bool) Db::table('cex_user_credentials')->where('user_id', $userId)->field('user_id')->find();
            $social = $this->socialAccountsForUser($userId, $hasPassword);
            $account = $social['providers'][$provider] ?? null;
            if (!$account || empty($account['linked'])) {
                throw new AuthException('该社交账号尚未绑定', 409, 'SOCIAL_PROVIDER_NOT_LINKED');
            }
            if (empty($account['can_unlink'])) {
                throw new AuthException('不能解绑当前最后一种登录方式，请先设置密码或绑定其他社交账号', 409, 'LAST_LOGIN_METHOD');
            }
        }

        $this->rateLimit('security-code:ip:' . $this->clientIp(), 20, 3600);
        $this->rateLimit('security-code:user:' . $userId, 12, 3600);
        $this->rateLimit('security-code:cooldown:' . $userId . ':' . $action, 1, 60);

        $ttl = $this->emailCodeTtl();
        $code = (string) random_int(100000, 999999);
        $key = $this->emailCodeKey($userId, $action);
        Cache::set($key, [
            'code_hash' => hash_hmac('sha256', $code, $this->hmacKey()),
            'user_id' => $userId,
            'session_id' => (string) $auth['id'],
            'action' => $action,
            'attempts' => 0,
            'created_at' => time(),
            'expires_at' => time() + $ttl,
        ], $ttl);

        try {
            $messageId = (new ResendMailer())->sendVerificationCode($email, $code, 'security_' . $action, $ttl);
            Log::info('Security email verification issued uid=' . $auth['uid'] . ' action=' . $action . ' message_id=' . $messageId);
        } catch (\Throwable $exception) {
            Cache::delete($key);
            Log::error('Security email delivery failed uid=' . $auth['uid'] . ' action=' . $action . ' message=' . $exception->getMessage());
            throw new AuthException('安全验证码邮件发送失败，请稍后重试', 503, 'EMAIL_DELIVERY_FAILED');
        }

        return [
            'sent' => true,
            'action' => $action,
            'email_masked' => Crypto::maskEmail($email),
            'expires_in' => $ttl,
        ];
    }

    public function verifyEmailCode(string $action, string $code): array
    {
        $action = $this->assertAction($action);
        $auth = $this->context(true);
        $userId = (int) $auth['user_id'];
        $this->rateLimit('security-code-verify:ip:' . $this->clientIp(), 50, 900);
        $this->rateLimit('security-code-verify:user:' . $userId, 30, 900);

        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new AuthException('邮箱验证码无效或已过期', 422, 'INVALID_EMAIL_CODE');
        }

        $key = $this->emailCodeKey($userId, $action);
        $state = Cache::get($key);
        if (!is_array($state)
            || (int) ($state['user_id'] ?? 0) !== $userId
            || !hash_equals((string) ($state['session_id'] ?? ''), (string) $auth['id'])
            || !hash_equals((string) ($state['action'] ?? ''), $action)
            || (int) ($state['expires_at'] ?? 0) <= time()) {
            Cache::delete($key);
            throw new AuthException('邮箱验证码无效或已过期', 422, 'INVALID_EMAIL_CODE');
        }

        $attempts = (int) ($state['attempts'] ?? 0);
        if ($attempts >= 5) {
            Cache::delete($key);
            throw new AuthException('邮箱验证码无效或已过期', 422, 'INVALID_EMAIL_CODE');
        }

        $provided = hash_hmac('sha256', $code, $this->hmacKey());
        if (!Crypto::secureEquals((string) $state['code_hash'], $provided)) {
            $state['attempts'] = $attempts + 1;
            Cache::set($key, $state, max(1, (int) $state['expires_at'] - time()));
            throw new AuthException('邮箱验证码无效或已过期', 422, 'INVALID_EMAIL_CODE');
        }

        Cache::delete($key);
        Db::table('cex_user_users')
            ->where('id', $userId)
            ->whereNull('email_verified_at')
            ->update([
                'email_verified_at' => $this->now(),
                'version' => Db::raw('version + 1'),
            ]);
        $ticket = Crypto::base64UrlEncode(random_bytes(32));
        $ttl = $this->ticketTtl();
        Cache::set($this->ticketKey($userId, $action, $ticket), [
            'user_id' => $userId,
            'session_id' => (string) $auth['id'],
            'action' => $action,
            'created_at' => time(),
            'expires_at' => time() + $ttl,
        ], $ttl);

        Db::table('cex_user_sessions')->where('id', (string) $auth['id'])->update([
            'auth_level' => 2,
            'last_active_at' => $this->now(),
        ]);

        return [
            'verified' => true,
            'action' => $action,
            'security_ticket' => $ticket,
            'ticket_expires_in' => $ttl,
        ];
    }

    public function sendNewEmailCode(string $ticket, string $newEmail): array
    {
        $auth = $this->context(true);
        $userId = (int) $auth['user_id'];
        $this->validateTicket($userId, 'email_change', $ticket);

        $newEmail = Crypto::validateEmail($newEmail);
        $newHash = Crypto::emailHash($newEmail);
        $currentEmail = $this->userEmail($userId);
        $currentHash = Crypto::emailHash($currentEmail);
        if (Crypto::secureEquals($newHash, $currentHash)) {
            throw new AuthException('新邮箱不能与当前安全邮箱相同', 422, 'EMAIL_UNCHANGED');
        }

        $occupied = Db::table('cex_user_users')
            ->where('email_hash', $newHash)
            ->where('id', '<>', $userId)
            ->field('id')
            ->find();
        if ($occupied) {
            throw new AuthException('该邮箱已经被其他账户使用', 409, 'EMAIL_EXISTS');
        }

        $this->rateLimit('email-change-new:ip:' . $this->clientIp(), 15, 3600);
        $this->rateLimit('email-change-new:user:' . $userId, 8, 3600);
        $this->rateLimit('email-change-new:email:' . bin2hex($newHash), 5, 3600);
        $this->rateLimit('email-change-new:cooldown:' . $userId . ':' . bin2hex($newHash), 1, 60);

        $ttl = $this->emailCodeTtl();
        $code = (string) random_int(100000, 999999);
        $key = $this->newEmailCodeKey($userId, $newHash);
        Cache::set($key, [
            'code_hash' => hash_hmac('sha256', $code, $this->hmacKey()),
            'user_id' => $userId,
            'session_id' => (string) $auth['id'],
            'ticket_hash' => hash('sha256', $ticket),
            'new_email_hash' => bin2hex($newHash),
            'attempts' => 0,
            'created_at' => time(),
            'expires_at' => time() + $ttl,
        ], $ttl);

        try {
            $messageId = (new ResendMailer())->sendVerificationCode($newEmail, $code, 'security_email_change_new', $ttl);
            Log::info('New security email verification issued uid=' . $auth['uid'] . ' message_id=' . $messageId);
        } catch (\Throwable $exception) {
            Cache::delete($key);
            Log::error('New security email delivery failed uid=' . $auth['uid'] . ' message=' . $exception->getMessage());
            throw new AuthException('新邮箱验证码发送失败，请稍后重试', 503, 'EMAIL_DELIVERY_FAILED');
        }

        return [
            'sent' => true,
            'email_masked' => Crypto::maskEmail($newEmail),
            'expires_in' => $ttl,
        ];
    }

    public function confirmEmailChange(string $ticket, string $newEmail, string $code, string $totpCode): array
    {
        $auth = $this->context(true);
        $userId = (int) $auth['user_id'];
        $this->validateTicket($userId, 'email_change', $ticket);
        $newEmail = Crypto::validateEmail($newEmail);
        $newHash = Crypto::emailHash($newEmail);
        $oldEmail = $this->userEmail($userId);
        $oldHash = Crypto::emailHash($oldEmail);
        if (Crypto::secureEquals($newHash, $oldHash)) {
            throw new AuthException('新邮箱不能与当前安全邮箱相同', 422, 'EMAIL_UNCHANGED');
        }

        $this->rateLimit('email-change-confirm:ip:' . $this->clientIp(), 30, 900);
        $this->rateLimit('email-change-confirm:user:' . $userId, 20, 900);
        $this->verifyNewEmailCode($userId, (string) $auth['id'], $ticket, $newHash, $code);

        $totpEnabled = $this->isTotpEnabled($userId);
        if ($totpEnabled) {
            $this->assertTotpCode($userId, $this->storedTotpSecret($userId), $totpCode, true);
        }

        $now = $this->now();
        try {
            $revokedCount = Db::transaction(function () use ($userId, $auth, $newEmail, $newHash, $now, $totpEnabled) {
            $current = Db::table('cex_user_users')
                ->where('id', $userId)
                ->lock(true)
                ->field('id,email_hash')
                ->find();
            if (!$current) {
                throw new AuthException('用户不存在', 404, 'USER_NOT_FOUND');
            }

            $occupied = Db::table('cex_user_users')
                ->where('email_hash', $newHash)
                ->where('id', '<>', $userId)
                ->field('id')
                ->find();
            if ($occupied) {
                throw new AuthException('该邮箱已经被其他账户使用', 409, 'EMAIL_EXISTS');
            }

            Db::table('cex_user_users')->where('id', $userId)->update([
                'email_ciphertext' => Crypto::encryptEmail($newEmail),
                'email_hash' => $newHash,
                'email_masked' => Crypto::maskEmail($newEmail),
                'email_verified_at' => $now,
                'version' => Db::raw('version + 1'),
            ]);

            $count = Db::table('cex_user_sessions')
                ->where('user_id', $userId)
                ->where('status', 1)
                ->where('id', '<>', (string) $auth['id'])
                ->update([
                    'status' => 2,
                    'revoked_at' => $now,
                    'revoke_reason' => 'EMAIL_CHANGED',
                ]);

            Db::table('cex_user_sessions')->where('id', (string) $auth['id'])->update([
                'auth_level' => $totpEnabled ? 3 : 2,
                'last_active_at' => $now,
            ]);
                return (int) $count;
            });
        } catch (AuthException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $message = strtolower($exception->getMessage());
            if (strpos($message, 'duplicate') !== false || strpos($message, '23000') !== false) {
                throw new AuthException('该邮箱已经被其他账户使用', 409, 'EMAIL_EXISTS');
            }
            throw $exception;
        }

        Cache::delete($this->newEmailCodeKey($userId, $newHash));
        $this->consumeTicket($userId, 'email_change', $ticket);
        if ($totpEnabled) {
            $this->markTotpReplay($userId, $totpCode);
        }

        $notice = '你的 CrystalBest 安全邮箱刚刚完成修改。其他登录设备已退出；当前设备继续保持登录。';
        try {
            (new ResendMailer())->sendSecurityNotice($oldEmail, '安全邮箱已修改', $notice);
        } catch (\Throwable $exception) {
            Log::warning('Old email change notice failed uid=' . $auth['uid'] . ' message=' . $exception->getMessage());
        }
        try {
            (new ResendMailer())->sendSecurityNotice($newEmail, '新的安全邮箱已生效', $notice);
        } catch (\Throwable $exception) {
            Log::warning('New email change notice failed uid=' . $auth['uid'] . ' message=' . $exception->getMessage());
        }

        Log::warning('Security email changed uid=' . $auth['uid'] . ' ip=' . $this->clientIp());
        return [
            'changed' => true,
            'email_masked' => Crypto::maskEmail($newEmail),
            'email_verified' => true,
            'other_sessions_revoked' => $revokedCount,
        ];
    }

    public function setupTotp(string $ticket): array
    {
        $auth = $this->context(true);
        $userId = (int) $auth['user_id'];
        $this->validateTicket($userId, 'totp_enable', $ticket);
        if ($this->isTotpEnabled($userId)) {
            throw new AuthException('Google 身份验证器已经启用', 409, 'TOTP_ALREADY_ENABLED');
        }

        $pendingKey = $this->totpPendingKey($userId, $ticket);
        $pending = Cache::get($pendingKey);
        if (is_array($pending) && !empty($pending['secret_ciphertext']) && (int) ($pending['expires_at'] ?? 0) > time()) {
            $secret = Crypto::decryptTotpSecret((string) $pending['secret_ciphertext']);
            $totp = new TotpService();
            return $this->enrollmentPayload($totp, $secret, $this->accountLabel($userId), (int) $pending['expires_at'] - time());
        }

        $totp = new TotpService();
        $enrollment = $totp->createEnrollment($this->accountLabel($userId));
        $ttl = $this->ticketRemaining($userId, 'totp_enable', $ticket);
        Cache::set($pendingKey, [
            'secret_ciphertext' => Crypto::encryptTotpSecret($enrollment['secret']),
            'created_at' => time(),
            'expires_at' => time() + $ttl,
        ], $ttl);
        $enrollment['expires_in'] = $ttl;
        return $enrollment;
    }

    public function enableTotp(string $ticket, string $code): array
    {
        $auth = $this->context(true);
        $userId = (int) $auth['user_id'];
        $this->validateTicket($userId, 'totp_enable', $ticket);
        if ($this->isTotpEnabled($userId)) {
            throw new AuthException('Google 身份验证器已经启用', 409, 'TOTP_ALREADY_ENABLED');
        }

        $pendingKey = $this->totpPendingKey($userId, $ticket);
        $pending = Cache::get($pendingKey);
        if (!is_array($pending) || empty($pending['secret_ciphertext']) || (int) ($pending['expires_at'] ?? 0) <= time()) {
            throw new AuthException('TOTP 设置状态已失效，请重新进行邮箱验证', 422, 'TOTP_SETUP_EXPIRED');
        }

        $secret = Crypto::decryptTotpSecret((string) $pending['secret_ciphertext']);
        $this->assertTotpCode($userId, $secret, $code, false);

        Db::transaction(function () use ($userId, $secret, $auth) {
            Db::table('cex_user_security')->where('user_id', $userId)->update([
                'totp_enabled' => 1,
                'totp_secret_ciphertext' => Crypto::encryptTotpSecret($secret),
                'totp_key_version' => trim((string) env('auth.totp_key_version', 'local-aes-256-gcm-v1')),
                'totp_verified_at' => $this->now(),
                'security_level' => 2,
                'last_security_review_at' => $this->now(),
                'version' => Db::raw('version + 1'),
            ]);
            Db::table('cex_user_sessions')->where('id', (string) $auth['id'])->update([
                'auth_level' => 3,
                'last_active_at' => $this->now(),
            ]);
        });

        Cache::delete($pendingKey);
        $this->consumeTicket($userId, 'totp_enable', $ticket);
        $this->markTotpReplay($userId, $code);
        $this->securityNotice($userId, 'Google 身份验证器已启用', '你的账户已成功启用 Google Authenticator 双重验证。');
        Log::warning('TOTP enabled uid=' . $auth['uid'] . ' ip=' . $this->clientIp());

        return ['enabled' => true, 'security_level' => 2];
    }

    public function disableTotp(string $ticket, string $code): array
    {
        $auth = $this->context(true);
        $userId = (int) $auth['user_id'];
        $this->validateTicket($userId, 'totp_disable', $ticket);
        $secret = $this->storedTotpSecret($userId);
        $this->assertTotpCode($userId, $secret, $code, true);

        Db::transaction(function () use ($userId, $auth) {
            Db::table('cex_user_security')->where('user_id', $userId)->update([
                'totp_enabled' => 0,
                'totp_secret_ciphertext' => null,
                'totp_key_version' => null,
                'totp_verified_at' => null,
                'security_level' => 1,
                'last_security_review_at' => $this->now(),
                'version' => Db::raw('version + 1'),
            ]);
            Db::table('cex_user_sessions')->where('id', (string) $auth['id'])->update([
                'auth_level' => 2,
                'last_active_at' => $this->now(),
            ]);
        });

        $this->consumeTicket($userId, 'totp_disable', $ticket);
        $this->markTotpReplay($userId, $code);
        $this->securityNotice($userId, 'Google 身份验证器已关闭', '你的账户已关闭 Google Authenticator。若非本人操作，请立即重置密码并检查登录设备。');
        Log::warning('TOTP disabled uid=' . $auth['uid'] . ' ip=' . $this->clientIp());

        return ['disabled' => true, 'security_level' => 1];
    }

    public function changePassword(array $data): array
    {
        $auth = $this->context(true);
        $userId = (int) $auth['user_id'];
        $ticket = trim((string) ($data['security_ticket'] ?? ''));
        $this->validateTicket($userId, 'password_change', $ticket);

        $newPassword = (string) ($data['new_password'] ?? $data['password'] ?? '');
        $confirmPassword = (string) ($data['confirm_password'] ?? '');
        $this->validatePassword($newPassword, $confirmPassword);

        $credential = Db::table('cex_user_credentials')->where('user_id', $userId)->field('password_hash')->find();
        if ($credential) {
            $current = (string) ($data['current_password'] ?? '');
            if ($current === '' || !password_verify($current, (string) $credential['password_hash'])) {
                throw new AuthException('当前密码不正确', 422, 'CURRENT_PASSWORD_INVALID');
            }
            if (password_verify($newPassword, (string) $credential['password_hash'])) {
                throw new AuthException('新密码不能与当前密码相同', 422, 'PASSWORD_REUSED');
            }
        }

        $totpEnabled = $this->isTotpEnabled($userId);
        if ($totpEnabled) {
            $secret = $this->storedTotpSecret($userId);
            $this->assertTotpCode($userId, $secret, (string) ($data['totp_code'] ?? ''), true);
        }

        $passwordInfo = $this->hashPassword($newPassword);
        Db::transaction(function () use ($userId, $credential, $passwordInfo, $auth, $totpEnabled) {
            if ($credential) {
                Db::table('cex_user_credentials')->where('user_id', $userId)->update([
                    'password_hash' => $passwordInfo['hash'],
                    'password_algorithm' => $passwordInfo['algorithm'],
                    'password_version' => Db::raw('password_version + 1'),
                    'failed_login_count' => 0,
                    'locked_until' => null,
                    'must_change_password' => 0,
                    'password_changed_at' => $this->now(),
                    'version' => Db::raw('version + 1'),
                ]);
            } else {
                Db::table('cex_user_credentials')->insert([
                    'user_id' => $userId,
                    'password_hash' => $passwordInfo['hash'],
                    'password_algorithm' => $passwordInfo['algorithm'],
                    'password_version' => 1,
                    'failed_login_count' => 0,
                    'must_change_password' => 0,
                    'password_changed_at' => $this->now(),
                ]);
            }

            Db::table('cex_user_sessions')
                ->where('user_id', $userId)
                ->where('status', 1)
                ->where('id', '<>', (string) $auth['id'])
                ->update([
                    'status' => 2,
                    'revoked_at' => $this->now(),
                    'revoke_reason' => 'PASSWORD_CHANGED',
                ]);

            Db::table('cex_user_sessions')->where('id', (string) $auth['id'])->update([
                'auth_level' => $totpEnabled ? 3 : 2,
                'last_active_at' => $this->now(),
            ]);
        });

        $this->consumeTicket($userId, 'password_change', $ticket);
        if ($totpEnabled) {
            $this->markTotpReplay($userId, (string) ($data['totp_code'] ?? ''));
        }
        $this->securityNotice($userId, $credential ? '登录密码已修改' : '登录密码已设置', '你的 CrystalBest 本地登录密码刚刚发生变更，其他登录设备已退出。');
        Log::warning('Password changed from security center uid=' . $auth['uid'] . ' ip=' . $this->clientIp());

        return [
            'changed' => true,
            'password_created' => !$credential,
            'other_sessions_revoked' => true,
        ];
    }

    public function sessions(): array
    {
        $auth = $this->context(true);
        $rows = Db::table('cex_user_sessions')
            ->where('user_id', (int) $auth['user_id'])
            ->where('status', 1)
            ->where('expires_at', '>', $this->now())
            ->field('id,device_name,platform,ip_address,user_agent,country_code,auth_level,expires_at,last_active_at,created_at')
            ->order('last_active_at', 'desc')
            ->limit(50)
            ->select();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (string) $row['id'],
                'device_name' => $row['device_name'] ?: '未知设备',
                'platform' => $row['platform'] ?: 'web',
                'ip' => $this->unpackIp($row['ip_address']),
                'user_agent' => $row['user_agent'] ? (string) $row['user_agent'] : null,
                'country_code' => $row['country_code'] ? (string) $row['country_code'] : null,
                'auth_level' => (int) $row['auth_level'],
                'expires_at' => (string) $row['expires_at'],
                'last_active_at' => (string) $row['last_active_at'],
                'created_at' => (string) $row['created_at'],
                'current' => hash_equals((string) $auth['id'], (string) $row['id']),
            ];
        }

        return ['sessions' => $items, 'count' => count($items)];
    }

    public function sessionHistory(int $limit = 30): array
    {
        $auth = $this->context(true);
        $limit = max(1, min(30, $limit));
        $rows = Db::table('cex_user_sessions')
            ->where('user_id', (int) $auth['user_id'])
            ->field('id,device_name,platform,ip_address,user_agent,country_code,auth_level,status,expires_at,last_active_at,revoked_at,revoke_reason,created_at')
            ->order('created_at', 'desc')
            ->limit($limit)
            ->select();

        $items = [];
        $now = time();
        foreach ($rows as $row) {
            $status = (int) $row['status'];
            $expiredByTime = !empty($row['expires_at']) && strtotime((string) $row['expires_at']) <= $now;
            $current = hash_equals((string) $auth['id'], (string) $row['id']);
            if ($status === 1 && !$expiredByTime) {
                $statusCode = $current ? 'current' : 'active';
                $statusLabel = $current ? '当前会话' : '活动';
            } elseif ($status === 3 || ($status === 1 && $expiredByTime)) {
                $statusCode = 'expired';
                $statusLabel = '已过期';
            } else {
                $statusCode = 'revoked';
                $statusLabel = $this->sessionReasonLabel((string) ($row['revoke_reason'] ?? ''));
            }

            $items[] = [
                'id' => (string) $row['id'],
                'device_name' => $row['device_name'] ?: '未知设备',
                'platform' => $row['platform'] ?: 'web',
                'ip' => $this->unpackIp($row['ip_address']),
                'country_code' => $row['country_code'] ? (string) $row['country_code'] : null,
                'user_agent' => $row['user_agent'] ? (string) $row['user_agent'] : null,
                'auth_level' => (int) $row['auth_level'],
                'status' => $statusCode,
                'status_label' => $statusLabel,
                'revoke_reason' => $row['revoke_reason'] ? (string) $row['revoke_reason'] : null,
                'created_at' => (string) $row['created_at'],
                'last_active_at' => (string) $row['last_active_at'],
                'expires_at' => (string) $row['expires_at'],
                'revoked_at' => $row['revoked_at'] ? (string) $row['revoked_at'] : null,
                'current' => $current,
            ];
        }

        return ['sessions' => $items, 'count' => count($items), 'limit' => $limit];
    }

    public function revokeSession(string $sessionId): array
    {
        $auth = $this->context(true);
        $sessionId = strtoupper(trim($sessionId));
        if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $sessionId)) {
            throw new AuthException('登录设备标识无效', 422, 'SESSION_ID_INVALID');
        }
        if (hash_equals((string) $auth['id'], $sessionId)) {
            throw new AuthException('当前设备请使用“退出登录”，不能在设备列表中撤销', 422, 'CURRENT_SESSION_REVOKE_NOT_ALLOWED');
        }

        $row = Db::table('cex_user_sessions')
            ->where('id', $sessionId)
            ->where('user_id', (int) $auth['user_id'])
            ->field('id,status')
            ->find();
        if (!$row) {
            throw new AuthException('未找到该登录设备', 404, 'SESSION_NOT_FOUND');
        }
        if ((int) $row['status'] === 1) {
            Db::table('cex_user_sessions')->where('id', $sessionId)->update([
                'status' => 2,
                'revoked_at' => $this->now(),
                'revoke_reason' => 'USER_REVOKED_DEVICE',
            ]);
        }

        Log::warning('Session revoked uid=' . $auth['uid'] . ' target=' . $sessionId . ' ip=' . $this->clientIp());
        return ['revoked' => true, 'session_id' => $sessionId];
    }

    public function revokeOtherSessions(string $ticket, string $totpCode): array
    {
        $auth = $this->context(true);
        $userId = (int) $auth['user_id'];
        $this->validateTicket($userId, 'revoke_others', $ticket);
        $totpEnabled = $this->isTotpEnabled($userId);
        if ($totpEnabled) {
            $this->assertTotpCode($userId, $this->storedTotpSecret($userId), $totpCode, true);
        }

        $count = Db::table('cex_user_sessions')
            ->where('user_id', $userId)
            ->where('status', 1)
            ->where('id', '<>', (string) $auth['id'])
            ->update([
                'status' => 2,
                'revoked_at' => $this->now(),
                'revoke_reason' => 'USER_REVOKED_OTHER_SESSIONS',
            ]);

        Db::table('cex_user_sessions')->where('id', (string) $auth['id'])->update([
            'auth_level' => $totpEnabled ? 3 : 2,
            'last_active_at' => $this->now(),
        ]);
        $this->consumeTicket($userId, 'revoke_others', $ticket);
        if ($totpEnabled) {
            $this->markTotpReplay($userId, $totpCode);
        }
        $this->securityNotice($userId, '其他登录设备已退出', '你的账户已主动退出其他登录设备。若非本人操作，请立即修改密码。');
        Log::warning('Other sessions revoked uid=' . $auth['uid'] . ' count=' . $count . ' ip=' . $this->clientIp());

        return ['revoked' => true, 'count' => (int) $count];
    }

    public function createSocialLinkIntent(string $provider, string $ticket, string $totpCode): array
    {
        $provider = $this->assertSocialProvider($provider);
        $auth = $this->context(true);
        $userId = (int) $auth['user_id'];
        $action = 'social_link_' . $provider;
        $this->validateTicket($userId, $action, $ticket);

        $status = ProviderConfig::publicStatus();
        if (empty($status[$provider])) {
            throw new AuthException('该第三方登录方式当前未启用', 409, 'SOCIAL_PROVIDER_DISABLED');
        }

        $existing = Db::table('cex_user_oauth_identities')
            ->where('user_id', $userId)
            ->where('provider', $provider)
            ->where('status', 1)
            ->field('id')
            ->find();
        if ($existing) {
            throw new AuthException('该社交账号类型已经绑定', 409, 'SOCIAL_PROVIDER_ALREADY_BOUND');
        }

        $totpEnabled = $this->isTotpEnabled($userId);
        if ($totpEnabled) {
            $this->assertTotpCode($userId, $this->storedTotpSecret($userId), $totpCode, true);
        }

        $token = Crypto::base64UrlEncode(random_bytes(32));
        $ttl = min(300, $this->ticketRemaining($userId, $action, $ticket));
        Cache::set($this->socialLinkIntentKey($token), [
            'provider' => $provider,
            'user_id' => $userId,
            'session_id' => (string) $auth['id'],
            'created_at' => time(),
            'expires_at' => time() + $ttl,
        ], $ttl);

        $this->consumeTicket($userId, $action, $ticket);
        if ($totpEnabled) {
            $this->markTotpReplay($userId, $totpCode);
        }
        Db::table('cex_user_sessions')->where('id', (string) $auth['id'])->update([
            'auth_level' => $totpEnabled ? 3 : 2,
            'last_active_at' => $this->now(),
        ]);

        return [
            'ready' => true,
            'provider' => $provider,
            'redirect_url' => '/auth/' . $provider . '/link?token=' . rawurlencode($token),
            'expires_in' => $ttl,
        ];
    }

    public function claimSocialLinkIntent(string $provider, string $token): array
    {
        $provider = $this->assertSocialProvider($provider);
        $token = trim($token);
        if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token)) {
            throw new AuthException('社交账号绑定状态已失效，请重新操作', 422, 'SOCIAL_LINK_INTENT_INVALID');
        }

        $auth = $this->context(true);
        $key = $this->socialLinkIntentKey($token);
        $state = Cache::get($key);
        if (!is_array($state)
            || !hash_equals((string) ($state['provider'] ?? ''), $provider)
            || (int) ($state['user_id'] ?? 0) !== (int) $auth['user_id']
            || !hash_equals((string) ($state['session_id'] ?? ''), (string) $auth['id'])
            || (int) ($state['expires_at'] ?? 0) <= time()) {
            Cache::delete($key);
            throw new AuthException('社交账号绑定状态已失效，请重新操作', 422, 'SOCIAL_LINK_INTENT_INVALID');
        }

        Cache::delete($key);
        return [
            'provider' => $provider,
            'user_id' => (int) $auth['user_id'],
            'session_id' => (string) $auth['id'],
            'uid' => (string) $auth['uid'],
        ];
    }

    public function unlinkSocial(string $provider, string $ticket, string $totpCode): array
    {
        $provider = $this->assertSocialProvider($provider);
        $auth = $this->context(true);
        $userId = (int) $auth['user_id'];
        $action = 'social_unlink_' . $provider;
        $this->validateTicket($userId, $action, $ticket);

        $social = $this->socialAccountsForUser($userId, (bool) Db::table('cex_user_credentials')->where('user_id', $userId)->field('user_id')->find());
        $account = $social['providers'][$provider] ?? null;
        if (!$account || empty($account['linked'])) {
            throw new AuthException('该社交账号尚未绑定', 409, 'SOCIAL_PROVIDER_NOT_LINKED');
        }
        if (empty($account['can_unlink'])) {
            throw new AuthException('不能解绑当前最后一种登录方式，请先设置密码或绑定其他社交账号', 409, 'LAST_LOGIN_METHOD');
        }

        $totpEnabled = $this->isTotpEnabled($userId);
        if ($totpEnabled) {
            $this->assertTotpCode($userId, $this->storedTotpSecret($userId), $totpCode, true);
        }

        $now = $this->now();
        Db::transaction(function () use ($userId, $provider, $auth, $totpEnabled, $now) {
            Db::table('cex_user_oauth_identities')
                ->where('user_id', $userId)
                ->where('provider', $provider)
                ->where('status', 1)
                ->update([
                    'status' => 2,
                    'unlinked_at' => $now,
                    'updated_at' => $now,
                ]);

            Db::table('cex_user_sessions')
                ->where('user_id', $userId)
                ->where('status', 1)
                ->where('id', '<>', (string) $auth['id'])
                ->update([
                    'status' => 2,
                    'revoked_at' => $now,
                    'revoke_reason' => 'SOCIAL_ACCOUNT_UNLINKED',
                ]);

            Db::table('cex_user_sessions')->where('id', (string) $auth['id'])->update([
                'auth_level' => $totpEnabled ? 3 : 2,
                'last_active_at' => $now,
            ]);
        });

        $this->consumeTicket($userId, $action, $ticket);
        if ($totpEnabled) {
            $this->markTotpReplay($userId, $totpCode);
        }
        $this->securityNotice(
            $userId,
            $this->providerLabel($provider) . ' 已解绑',
            '你的 CrystalBest 账户已解绑 ' . $this->providerLabel($provider) . ' 登录方式，其他登录设备已退出。若非本人操作，请立即检查账户安全。'
        );
        Log::warning('Social account unlinked uid=' . $auth['uid'] . ' provider=' . $provider . ' ip=' . $this->clientIp());

        return [
            'unlinked' => true,
            'provider' => $provider,
            'other_sessions_revoked' => true,
        ];
    }

    private function context(bool $touch): array
    {
        $cookie = (string) $this->request->cookie($this->authService->cookieName(), '');
        return $this->authService->authenticatedSession($cookie, $touch);
    }

    private function securityRow(int $userId): array
    {
        $row = Db::table('cex_user_security')->where('user_id', $userId)->find();
        if (!$row) {
            Db::table('cex_user_security')->insert(['user_id' => $userId]);
            $row = Db::table('cex_user_security')->where('user_id', $userId)->find();
        }
        return is_array($row) ? $row : [];
    }

    private function userEmail(int $userId): string
    {
        $row = Db::table('cex_user_users')->where('id', $userId)->field('email_ciphertext,email_masked')->find();
        if (!$row || empty($row['email_ciphertext'])) {
            throw new AuthException('当前账户尚未配置可验证邮箱，请先完成邮箱绑定', 422, 'EMAIL_NOT_AVAILABLE');
        }
        return Crypto::decryptEmail((string) $row['email_ciphertext']);
    }

    private function accountLabel(int $userId): string
    {
        $row = Db::table('cex_user_users')->where('id', $userId)->field('uid,email_masked')->find();
        if (!$row) {
            throw new AuthException('账户不存在', 404, 'ACCOUNT_NOT_FOUND');
        }
        $masked = trim((string) ($row['email_masked'] ?? ''));
        return $masked !== '' ? $masked : (string) $row['uid'];
    }

    private function isTotpEnabled(int $userId): bool
    {
        $row = $this->securityRow($userId);
        return !empty($row['totp_enabled']);
    }

    private function storedTotpSecret(int $userId): string
    {
        $row = $this->securityRow($userId);
        if (empty($row['totp_enabled']) || empty($row['totp_secret_ciphertext'])) {
            throw new AuthException('Google 身份验证器尚未启用', 409, 'TOTP_NOT_ENABLED');
        }
        return Crypto::decryptTotpSecret((string) $row['totp_secret_ciphertext']);
    }

    private function assertTotpCode(int $userId, string $secret, string $code, bool $checkReplay): void
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new AuthException('请输入 6 位 Google Authenticator 动态验证码', 422, 'TOTP_CODE_REQUIRED');
        }
        if ($checkReplay && Cache::get($this->totpReplayKey($userId, $code))) {
            throw new AuthException('该动态验证码已使用，请等待下一组验证码', 422, 'TOTP_CODE_REPLAYED');
        }
        if (!(new TotpService())->verify($secret, $code)) {
            throw new AuthException('Google Authenticator 验证码不正确，请确认服务器与手机时间同步', 422, 'TOTP_CODE_INVALID');
        }
    }

    private function markTotpReplay(int $userId, string $code): void
    {
        $code = trim($code);
        if (preg_match('/^\d{6}$/', $code)) {
            Cache::set($this->totpReplayKey($userId, $code), 1, 90);
        }
    }

    private function enrollmentPayload(TotpService $totp, string $secret, string $accountLabel, int $expiresIn): array
    {
        // Rebuild the same otpauth URI/QR while keeping the existing pending secret.
        $issuer = trim((string) env('auth.totp_issuer', 'CrystalBest')) ?: 'CrystalBest';
        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $uri = $google2fa->getQRCodeUrl($issuer, $accountLabel, $secret);
        $qr = new \Endroid\QrCode\QrCode($uri);
        $qr->setWriterByName('svg');
        $qr->setSize(260);
        $qr->setMargin(12);
        $qr->setEncoding('UTF-8');
        $qr->setValidateResult(false);
        return [
            'secret' => $secret,
            'issuer' => $issuer,
            'account_label' => $accountLabel,
            'otpauth_uri' => $uri,
            'qr_data_uri' => $qr->writeDataUri(),
            'digits' => 6,
            'period' => 30,
            'algorithm' => 'SHA1',
            'expires_in' => max(1, $expiresIn),
        ];
    }

    private function validateTicket(int $userId, string $action, string $ticket): array
    {
        $ticket = trim($ticket);
        if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $ticket)) {
            throw new AuthException('安全验证状态已失效，请重新验证邮箱', 422, 'SECURITY_TICKET_INVALID');
        }
        $auth = $this->context(false);
        $state = Cache::get($this->ticketKey($userId, $action, $ticket));
        if (!is_array($state)
            || (int) ($state['user_id'] ?? 0) !== $userId
            || !hash_equals((string) ($state['session_id'] ?? ''), (string) $auth['id'])
            || !hash_equals((string) ($state['action'] ?? ''), $action)
            || (int) ($state['expires_at'] ?? 0) <= time()) {
            throw new AuthException('安全验证状态已失效，请重新验证邮箱', 422, 'SECURITY_TICKET_INVALID');
        }
        return $state;
    }

    private function ticketRemaining(int $userId, string $action, string $ticket): int
    {
        $state = $this->validateTicket($userId, $action, $ticket);
        return max(1, (int) $state['expires_at'] - time());
    }

    private function consumeTicket(int $userId, string $action, string $ticket): void
    {
        Cache::delete($this->ticketKey($userId, $action, $ticket));
    }

    private function assertAction(string $action): string
    {
        $action = strtolower(trim($action));
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            throw new AuthException('不支持的安全验证类型', 422, 'SECURITY_ACTION_INVALID');
        }
        return $action;
    }

    private function socialAccountsForUser(int $userId, bool $hasPassword): array
    {
        $providerStatus = ProviderConfig::publicStatus();
        $rows = Db::table('cex_user_oauth_identities')
            ->where('user_id', $userId)
            ->where('status', 1)
            ->whereIn('provider', ['google', 'microsoft'])
            ->field('id,provider,email_masked,email_verified,display_name,avatar_url,linked_at,last_login_at,status')
            ->order('id', 'desc')
            ->select();

        $active = [];
        foreach ($rows as $row) {
            $provider = strtolower((string) ($row['provider'] ?? ''));
            if (!in_array($provider, ['google', 'microsoft'], true) || isset($active[$provider])) {
                continue;
            }
            $active[$provider] = $row;
        }

        $activeSocialCount = count($active);
        $loginMethodsCount = ($hasPassword ? 1 : 0) + $activeSocialCount;
        $providers = [];
        foreach (['google', 'microsoft'] as $provider) {
            $row = $active[$provider] ?? null;
            $linked = is_array($row);
            $providers[$provider] = [
                'provider' => $provider,
                'label' => $this->providerLabel($provider),
                'available' => !empty($providerStatus[$provider]),
                'linked' => $linked,
                'email_masked' => $linked ? ($row['email_masked'] !== null ? (string) $row['email_masked'] : null) : null,
                'email_verified' => $linked ? (bool) $row['email_verified'] : false,
                'display_name' => $linked ? ($row['display_name'] !== null ? (string) $row['display_name'] : null) : null,
                'avatar_url' => $linked ? ($row['avatar_url'] !== null ? (string) $row['avatar_url'] : null) : null,
                'linked_at' => $linked ? (string) $row['linked_at'] : null,
                'last_login_at' => $linked && !empty($row['last_login_at']) ? (string) $row['last_login_at'] : null,
                'can_unlink' => $linked && $loginMethodsCount > 1,
            ];
        }

        return [
            'providers' => $providers,
            'active_social_count' => $activeSocialCount,
            'login_methods_count' => $loginMethodsCount,
        ];
    }

    private function assertSocialProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (!in_array($provider, ['google', 'microsoft'], true)) {
            throw new AuthException('不支持的社交账号类型', 422, 'SOCIAL_PROVIDER_INVALID');
        }
        return $provider;
    }

    private function providerLabel(string $provider): string
    {
        return $provider === 'microsoft' ? 'Microsoft' : 'Google';
    }

    private function socialLinkIntentKey(string $token): string
    {
        return 'auth:security:social_link_intent:' . hash('sha256', $token);
    }

    private function verifyNewEmailCode(int $userId, string $sessionId, string $ticket, string $newHash, string $code): void
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new AuthException('新邮箱验证码无效或已过期', 422, 'INVALID_NEW_EMAIL_CODE');
        }
        $key = $this->newEmailCodeKey($userId, $newHash);
        $state = Cache::get($key);
        if (!is_array($state)
            || (int) ($state['user_id'] ?? 0) !== $userId
            || !hash_equals((string) ($state['session_id'] ?? ''), $sessionId)
            || !hash_equals((string) ($state['ticket_hash'] ?? ''), hash('sha256', $ticket))
            || !hash_equals((string) ($state['new_email_hash'] ?? ''), bin2hex($newHash))
            || (int) ($state['expires_at'] ?? 0) <= time()) {
            Cache::delete($key);
            throw new AuthException('新邮箱验证码无效或已过期', 422, 'INVALID_NEW_EMAIL_CODE');
        }

        $attempts = (int) ($state['attempts'] ?? 0);
        if ($attempts >= 5) {
            Cache::delete($key);
            throw new AuthException('新邮箱验证码无效或已过期', 422, 'INVALID_NEW_EMAIL_CODE');
        }
        $provided = hash_hmac('sha256', $code, $this->hmacKey());
        if (!Crypto::secureEquals((string) $state['code_hash'], $provided)) {
            $state['attempts'] = $attempts + 1;
            Cache::set($key, $state, max(1, (int) $state['expires_at'] - time()));
            throw new AuthException('新邮箱验证码无效或已过期', 422, 'INVALID_NEW_EMAIL_CODE');
        }
    }

    private function newEmailCodeKey(int $userId, string $emailHash): string
    {
        return 'auth:security:new-email:' . $userId . ':' . bin2hex($emailHash);
    }

    private function sessionReasonLabel(string $reason): string
    {
        $labels = [
            'USER_LOGOUT' => '已退出',
            'USER_REVOKED_DEVICE' => '已由你退出',
            'USER_REVOKED_OTHERS' => '已批量退出',
            'PASSWORD_CHANGED' => '密码修改后退出',
            'PASSWORD_RESET' => '密码重置后退出',
            'SOCIAL_ACCOUNT_LINKED' => '社交账号变更后退出',
            'SOCIAL_ACCOUNT_UNLINKED' => '社交账号变更后退出',
            'EMAIL_CHANGED' => '邮箱修改后退出',
        ];
        return $labels[$reason] ?? ($reason !== '' ? '已撤销' : '已退出');
    }

    private function emailCodeKey(int $userId, string $action): string
    {
        return 'auth:security:email_code:' . $userId . ':' . $action;
    }

    private function ticketKey(int $userId, string $action, string $ticket): string
    {
        return 'auth:security:ticket:' . $userId . ':' . $action . ':' . hash('sha256', $ticket);
    }

    private function totpPendingKey(int $userId, string $ticket): string
    {
        return 'auth:security:totp_pending:' . $userId . ':' . hash('sha256', $ticket);
    }

    private function totpReplayKey(int $userId, string $code): string
    {
        return 'auth:security:totp_replay:' . $userId . ':' . hash_hmac('sha256', $code, $this->hmacKey());
    }

    private function hmacKey(): string
    {
        $key = trim((string) env('auth.email_code_hmac_key', ''));
        if ($key === '') {
            $key = trim((string) env('auth.reset_hmac_key', ''));
        }
        if ($key === '') {
            throw new AuthException('账户安全配置缺失：AUTH_EMAIL_CODE_HMAC_KEY', 500, 'AUTH_CONFIG_MISSING');
        }
        return $key;
    }

    private function emailCodeTtl(): int
    {
        return max(300, min(1800, (int) env('auth.security_email_code_ttl_seconds', 600)));
    }

    private function ticketTtl(): int
    {
        return max(300, min(1800, (int) env('auth.security_ticket_ttl_seconds', 900)));
    }

    private function rateLimit(string $key, int $limit, int $windowSeconds): void
    {
        $cacheKey = 'auth:security:rl:' . hash('sha256', $key);
        $state = Cache::get($cacheKey);
        $now = time();
        if (!is_array($state) || (int) ($state['reset_at'] ?? 0) <= $now) {
            Cache::set($cacheKey, ['count' => 1, 'reset_at' => $now + $windowSeconds], $windowSeconds);
            return;
        }
        if ((int) ($state['count'] ?? 0) >= $limit) {
            throw new AuthException('安全验证请求过于频繁，请稍后再试', 429, 'RATE_LIMITED');
        }
        $state['count'] = (int) $state['count'] + 1;
        Cache::set($cacheKey, $state, max(1, (int) $state['reset_at'] - $now));
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

    private function unpackIp($packed): ?string
    {
        if ($packed === null || $packed === '') {
            return null;
        }
        $ip = @inet_ntop((string) $packed);
        return $ip === false ? null : $ip;
    }

    private function securityNotice(int $userId, string $title, string $message): void
    {
        try {
            $email = $this->userEmail($userId);
            (new ResendMailer())->sendSecurityNotice($email, $title, $message);
        } catch (\Throwable $exception) {
            Log::error('Security notice delivery failed user_id=' . $userId . ' message=' . $exception->getMessage());
        }
    }

    private function clientIp(): string
    {
        return ClientContext::ip($this->request);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.u');
    }
}
