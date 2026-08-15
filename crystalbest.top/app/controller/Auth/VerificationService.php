<?php

namespace app\controller\Auth;

use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\Request;

/**
 * Email OTP + short-lived ticket orchestration.
 *
 * CAPTCHA is delegated to topthink/think-captcha. OTP/tickets live in cache,
 * so no additional user table is required and raw codes/tickets are never stored.
 */
class VerificationService
{
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function sendRegistrationCode(array $data): array
    {
        $email = Crypto::validateEmail((string) ($data['email'] ?? ''));
        $emailHash = Crypto::emailHash($email);
        $this->assertCaptcha((string) ($data['captcha'] ?? ''), 'register-email');
        $this->rateLimit('reg-code:ip:' . $this->clientIp(), 12, 3600);
        $this->rateLimit('reg-code:email:' . bin2hex($emailHash), 5, 3600);
        $this->rateLimit('reg-code:cooldown:' . bin2hex($emailHash), 1, 60);

        if (Db::table('cex_user_users')->where('email_hash', $emailHash)->field('id')->find()) {
            throw new AuthException('该邮箱已经注册，请直接登录', 409, 'EMAIL_EXISTS');
        }

        $ttl = $this->emailCodeTtl();
        $code = $this->issueCode('register', $emailHash, ['email' => $email], $ttl);
        try {
            $messageId = (new ResendMailer())->sendVerificationCode($email, $code, 'register', $ttl);
        } catch (\Throwable $exception) {
            Cache::delete($this->codeKey('register', $emailHash));
            Log::error('Registration email send failed message=' . $exception->getMessage());
            throw new AuthException('验证码邮件发送失败，请稍后重试', 503, 'EMAIL_DELIVERY_FAILED');
        }

        Log::info('Registration verification email issued message_id=' . $messageId);
        return [
            'sent' => true,
            'email_masked' => Crypto::maskEmail($email),
            'expires_in' => $ttl,
        ];
    }

    public function verifyRegistrationCode(array $data): array
    {
        $email = Crypto::validateEmail((string) ($data['email'] ?? ''));
        $emailHash = Crypto::emailHash($email);
        $this->rateLimit('reg-verify:ip:' . $this->clientIp(), 30, 900);

        $state = $this->verifyCode('register', $emailHash, (string) ($data['code'] ?? ''));
        if (Db::table('cex_user_users')->where('email_hash', $emailHash)->field('id')->find()) {
            Cache::delete($this->codeKey('register', $emailHash));
            throw new AuthException('该邮箱已经注册，请直接登录', 409, 'EMAIL_EXISTS');
        }

        Cache::delete($this->codeKey('register', $emailHash));
        $ticket = $this->issueTicket('register', $emailHash, [
            'email' => $email,
            'verified_at' => time(),
        ]);

        return [
            'verified' => true,
            'email' => $email,
            'email_masked' => Crypto::maskEmail($email),
            'registration_ticket' => $ticket,
            'ticket_expires_in' => $this->ticketTtl(),
        ];
    }

    public function validateRegistrationTicket(string $email, string $ticket): array
    {
        return $this->validateTicket('register', Crypto::emailHash($email), $ticket);
    }

    public function consumeRegistrationTicket(string $email, string $ticket): void
    {
        Cache::delete($this->ticketKey('register', Crypto::emailHash($email), $ticket));
    }

    public function sendLoginCode(array $data): array
    {
        $email = Crypto::validateEmail((string) ($data['email'] ?? ''));
        $emailHash = Crypto::emailHash($email);
        $this->assertCaptcha((string) ($data['captcha'] ?? ''), 'login-email');
        $this->rateLimit('login-code:ip:' . $this->clientIp(), 15, 3600);
        $this->rateLimit('login-code:email:' . bin2hex($emailHash), 6, 3600);
        $this->rateLimit('login-code:cooldown:' . bin2hex($emailHash), 1, 60);

        $generic = [
            'sent' => true,
            'email_masked' => Crypto::maskEmail($email),
            'expires_in' => $this->emailCodeTtl(),
        ];
        // Validate Resend configuration for every request without disclosing account existence.
        $mailer = new ResendMailer();

        $user = Db::table('cex_user_users')
            ->where('email_hash', $emailHash)
            ->field('id,uid,status')
            ->find();

        if (!$user || (int) $user['status'] !== 1) {
            usleep(random_int(80000, 180000));
            return $generic;
        }

        $ttl = $this->emailCodeTtl();
        $code = $this->issueCode('login', $emailHash, ['user_id' => (int) $user['id']], $ttl);
        try {
            $messageId = $mailer->sendVerificationCode($email, $code, 'login', $ttl);
            Log::info('Email login code issued uid=' . $user['uid'] . ' message_id=' . $messageId);
        } catch (\Throwable $exception) {
            Cache::delete($this->codeKey('login', $emailHash));
            Log::error('Email login send failed uid=' . $user['uid'] . ' message=' . $exception->getMessage());
            // Keep a generic response so the endpoint does not disclose whether an account exists.
        }

        return $generic;
    }

    public function verifyLoginCode(array $data): array
    {
        $email = Crypto::validateEmail((string) ($data['email'] ?? ''));
        $emailHash = Crypto::emailHash($email);
        $this->rateLimit('login-code-verify:ip:' . $this->clientIp(), 40, 900);

        $state = $this->verifyCode('login', $emailHash, (string) ($data['code'] ?? ''));
        $userId = (int) ($state['user_id'] ?? 0);
        $user = $userId > 0
            ? Db::table('cex_user_users')->where('id', $userId)->where('email_hash', $emailHash)->field('id,uid,status')->find()
            : null;
        if (!$user || (int) $user['status'] !== 1) {
            Cache::delete($this->codeKey('login', $emailHash));
            throw new AuthException('验证码无效或已过期', 422, 'INVALID_EMAIL_CODE');
        }

        Cache::delete($this->codeKey('login', $emailHash));
        return ['user_id' => (int) $user['id'], 'uid' => (string) $user['uid'], 'email' => $email];
    }

    public function sendPasswordResetCode(array $data): array
    {
        $email = Crypto::validateEmail((string) ($data['email'] ?? ''));
        $emailHash = Crypto::emailHash($email);
        $this->assertCaptcha((string) ($data['captcha'] ?? ''), 'password-reset-start');
        $this->rateLimit('pwd-code:ip:' . $this->clientIp(), 10, 3600);
        $this->rateLimit('pwd-code:email:' . bin2hex($emailHash), 5, 3600);
        $this->rateLimit('pwd-code:cooldown:' . bin2hex($emailHash), 1, 60);

        $ttl = $this->resetCodeTtl();
        $mailer = new ResendMailer();
        $generic = [
            'sent' => true,
            'email_masked' => Crypto::maskEmail($email),
            'expires_in' => $ttl,
        ];

        $user = Db::table('cex_user_users')
            ->where('email_hash', $emailHash)
            ->field('id,uid,status')
            ->find();
        if (!$user || (int) $user['status'] === 4) {
            usleep(random_int(80000, 180000));
            return $generic;
        }

        $credential = Db::table('cex_user_credentials')->where('user_id', (int) $user['id'])->field('user_id')->find();
        if (!$credential) {
            usleep(random_int(80000, 180000));
            return $generic;
        }

        $code = $this->issueCode('password_reset', $emailHash, ['user_id' => (int) $user['id']], $ttl);
        try {
            $messageId = $mailer->sendVerificationCode($email, $code, 'password_reset', $ttl);
            Log::info('Password reset code issued uid=' . $user['uid'] . ' message_id=' . $messageId);
        } catch (\Throwable $exception) {
            Cache::delete($this->codeKey('password_reset', $emailHash));
            Log::error('Password reset email failed uid=' . $user['uid'] . ' message=' . $exception->getMessage());
            // Generic result by design: do not reveal account existence.
        }

        return $generic;
    }

    public function verifyPasswordResetCode(array $data): array
    {
        $email = Crypto::validateEmail((string) ($data['email'] ?? ''));
        $emailHash = Crypto::emailHash($email);
        $this->rateLimit('pwd-code-verify:ip:' . $this->clientIp(), 30, 900);

        $state = $this->verifyCode('password_reset', $emailHash, (string) ($data['code'] ?? ''));
        $userId = (int) ($state['user_id'] ?? 0);
        $credential = $userId > 0
            ? Db::table('cex_user_credentials')->where('user_id', $userId)->field('user_id')->find()
            : null;
        $user = $userId > 0
            ? Db::table('cex_user_users')->where('id', $userId)->where('email_hash', $emailHash)->field('id,uid,status')->find()
            : null;
        if (!$user || !$credential || (int) $user['status'] === 4) {
            Cache::delete($this->codeKey('password_reset', $emailHash));
            throw new AuthException('验证码无效或已过期', 422, 'INVALID_RESET_CODE');
        }

        Cache::delete($this->codeKey('password_reset', $emailHash));
        $ticket = $this->issueTicket('password_reset', $emailHash, [
            'user_id' => (int) $user['id'],
            'verified_at' => time(),
        ]);

        return [
            'verified' => true,
            'email' => $email,
            'email_masked' => Crypto::maskEmail($email),
            'reset_ticket' => $ticket,
            'ticket_expires_in' => $this->ticketTtl(),
        ];
    }

    public function validatePasswordResetTicket(string $email, string $ticket): array
    {
        return $this->validateTicket('password_reset', Crypto::emailHash($email), $ticket);
    }

    public function consumePasswordResetTicket(string $email, string $ticket): void
    {
        Cache::delete($this->ticketKey('password_reset', Crypto::emailHash($email), $ticket));
    }

    public function assertCaptcha(string $value, string $id): void
    {
        $value = trim($value);
        if ($value === '') {
            throw new AuthException('请输入人机验证码', 422, 'CAPTCHA_REQUIRED');
        }
        if (!function_exists('captcha_check')) {
            throw new AuthException('think-captcha 尚未安装，请先安装验证码扩展', 500, 'CAPTCHA_NOT_INSTALLED');
        }
        if (!captcha_check($value, $id)) {
            throw new AuthException('人机验证码不正确或已过期，请刷新后重试', 422, 'CAPTCHA_INVALID');
        }
    }

    private function issueCode(string $purpose, string $emailHash, array $extra, int $ttl): string
    {
        $code = (string) random_int(100000, 999999);
        $state = array_merge($extra, [
            'code_hash' => hash_hmac('sha256', $code, $this->hmacKey()),
            'attempts' => 0,
            'created_at' => time(),
            'expires_at' => time() + $ttl,
        ]);
        Cache::set($this->codeKey($purpose, $emailHash), $state, $ttl);
        return $code;
    }

    private function verifyCode(string $purpose, string $emailHash, string $code): array
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new AuthException('验证码无效或已过期', 422, $purpose === 'password_reset' ? 'INVALID_RESET_CODE' : 'INVALID_EMAIL_CODE');
        }

        $key = $this->codeKey($purpose, $emailHash);
        $state = Cache::get($key);
        $errorCode = $purpose === 'password_reset' ? 'INVALID_RESET_CODE' : 'INVALID_EMAIL_CODE';
        if (!is_array($state) || empty($state['code_hash']) || (int) ($state['expires_at'] ?? 0) <= time()) {
            Cache::delete($key);
            throw new AuthException('验证码无效或已过期', 422, $errorCode);
        }

        $attempts = (int) ($state['attempts'] ?? 0);
        if ($attempts >= 5) {
            Cache::delete($key);
            throw new AuthException('验证码无效或已过期', 422, $errorCode);
        }

        $provided = hash_hmac('sha256', $code, $this->hmacKey());
        if (!Crypto::secureEquals((string) $state['code_hash'], $provided)) {
            $state['attempts'] = $attempts + 1;
            $remaining = max(1, (int) $state['expires_at'] - time());
            Cache::set($key, $state, $remaining);
            throw new AuthException('验证码无效或已过期', 422, $errorCode);
        }

        return $state;
    }

    private function issueTicket(string $purpose, string $emailHash, array $extra): string
    {
        $raw = Crypto::base64UrlEncode(random_bytes(32));
        $ttl = $this->ticketTtl();
        Cache::set($this->ticketKey($purpose, $emailHash, $raw), array_merge($extra, [
            'created_at' => time(),
            'expires_at' => time() + $ttl,
        ]), $ttl);
        return $raw;
    }

    private function validateTicket(string $purpose, string $emailHash, string $ticket): array
    {
        $ticket = trim($ticket);
        if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $ticket)) {
            throw new AuthException('验证状态已失效，请重新进行邮箱验证', 422, 'VERIFICATION_TICKET_INVALID');
        }
        $state = Cache::get($this->ticketKey($purpose, $emailHash, $ticket));
        if (!is_array($state) || (int) ($state['expires_at'] ?? 0) <= time()) {
            throw new AuthException('验证状态已失效，请重新进行邮箱验证', 422, 'VERIFICATION_TICKET_INVALID');
        }
        return $state;
    }

    private function codeKey(string $purpose, string $emailHash): string
    {
        return 'auth:email_code:' . $purpose . ':' . bin2hex($emailHash);
    }

    private function ticketKey(string $purpose, string $emailHash, string $ticket): string
    {
        return 'auth:ticket:' . $purpose . ':' . bin2hex($emailHash) . ':' . hash('sha256', $ticket);
    }

    private function hmacKey(): string
    {
        $key = trim((string) env('auth.email_code_hmac_key', ''));
        if ($key === '') {
            $key = trim((string) env('auth.reset_hmac_key', ''));
        }
        if ($key === '') {
            $key = trim((string) env('auth.email_hmac_key', ''));
        }
        if ($key === '') {
            throw new AuthException('账户安全配置缺失：AUTH_EMAIL_CODE_HMAC_KEY', 500, 'AUTH_CONFIG_MISSING');
        }
        return $key;
    }

    private function emailCodeTtl(): int
    {
        return max(300, min(1800, (int) env('auth.email_code_ttl_seconds', 600)));
    }

    private function resetCodeTtl(): int
    {
        return max(300, min(1800, (int) env('auth.reset_code_ttl_seconds', 600)));
    }

    private function ticketTtl(): int
    {
        return max(300, min(1800, (int) env('auth.verification_ticket_ttl_seconds', 900)));
    }

    private function rateLimit(string $key, int $limit, int $windowSeconds): void
    {
        $cacheKey = 'auth:verification:rl:' . hash('sha256', $key);
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

    private function clientIp(): string
    {
        return ClientContext::ip($this->request);
    }
}
