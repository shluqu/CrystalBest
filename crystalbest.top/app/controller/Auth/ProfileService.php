<?php

namespace app\controller\Auth;

use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\Request;
use think\file\UploadedFile;

class ProfileService
{
    private $request;
    private $authService;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->authService = new AuthService($request);
    }

    public function overview(): array
    {
        $auth = $this->context(true);
        $row = Db::table('cex_user_users')
            ->where('id', (int) $auth['user_id'])
            ->field('id,uid,email_masked,email_verified_at,nickname,status,kyc_level,risk_level,registration_channel,avatar_url,avatar_storage_key,created_at,updated_at,last_login_at')
            ->find();
        if (!$row) {
            throw new AuthException('用户不存在', 404, 'USER_NOT_FOUND');
        }

        $avatarUrl = trim((string) ($row['avatar_url'] ?? ''));
        if ($avatarUrl === '') {
            $avatarUrl = DefaultAvatar::forUserId((int) $row['id']);
        }

        return [
            'uid' => (string) $row['uid'],
            'nickname' => $row['nickname'] !== null ? (string) $row['nickname'] : '',
            'avatar_url' => $avatarUrl,
            'avatar_storage_key' => !empty($row['avatar_storage_key']) ? (string) $row['avatar_storage_key'] : null,
            'email_masked' => (string) $row['email_masked'],
            'email_verified' => !empty($row['email_verified_at']),
            'email_verified_at' => $row['email_verified_at'],
            'status' => (int) $row['status'],
            'kyc_level' => (int) $row['kyc_level'],
            'risk_level' => (int) $row['risk_level'],
            'registration_channel' => (string) $row['registration_channel'],
            'registration_channel_label' => $this->registrationChannelLabel((string) $row['registration_channel']),
            'registered_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'last_login_at' => $row['last_login_at'],
            'r2_configured' => (new R2Storage())->isConfigured(),
        ];
    }

    public function updateNickname(string $nickname): array
    {
        $auth = $this->context(true);
        $nickname = trim($nickname);
        $length = function_exists('mb_strlen') ? mb_strlen($nickname) : strlen($nickname);
        if ($length > 64) {
            throw new AuthException('昵称最多 64 个字符', 422, 'INVALID_NICKNAME');
        }
        if ($nickname !== '' && $length < 2) {
            throw new AuthException('昵称至少 2 个字符', 422, 'INVALID_NICKNAME');
        }

        $this->rateLimit('profile:nickname:user:' . (int) $auth['user_id'], 20, 3600);
        Db::table('cex_user_users')
            ->where('id', (int) $auth['user_id'])
            ->update([
                'nickname' => $nickname !== '' ? $nickname : null,
                'version' => Db::raw('version + 1'),
            ]);

        AuditLog::record($this->request, 'PROFILE_NICKNAME_CHANGED', (int) $auth['user_id'], 1, 'user', (string) $auth['uid']);
        Log::info('User nickname updated uid=' . $auth['uid'] . ' ip=' . $this->clientIp());
        return [
            'nickname' => $nickname !== '' ? $nickname : null,
        ];
    }

    public function uploadAvatar(UploadedFile $file): array
    {
        $auth = $this->context(true);
        $this->rateLimit('profile:avatar:user:' . (int) $auth['user_id'], 20, 3600);

        $old = Db::table('cex_user_users')
            ->where('id', (int) $auth['user_id'])
            ->field('avatar_storage_key')
            ->find();

        $result = (new AvatarService())->upload($file, (string) $auth['uid']);
        try {
            Db::table('cex_user_users')
                ->where('id', (int) $auth['user_id'])
                ->update([
                    'avatar_url' => $result['url'],
                    'avatar_storage_key' => $result['storage_key'],
                    'version' => Db::raw('version + 1'),
                ]);
        } catch (\Throwable $exception) {
            try {
                (new R2Storage())->delete((string) $result['storage_key']);
            } catch (\Throwable $cleanupException) {
                // Best effort cleanup only.
            }
            throw $exception;
        }

        $this->deleteOldR2Object($old ? (string) ($old['avatar_storage_key'] ?? '') : '');
        AuditLog::record($this->request, 'PROFILE_AVATAR_CHANGED', (int) $auth['user_id'], 1, 'user', (string) $auth['uid']);
        Log::info('User avatar uploaded uid=' . $auth['uid'] . ' key=' . $result['storage_key'] . ' ip=' . $this->clientIp());
        return [
            'avatar_url' => (string) $result['url'],
            'avatar_storage_key' => (string) $result['storage_key'],
        ];
    }

    private function context(bool $touch): array
    {
        $cookie = (string) $this->request->cookie($this->authService->cookieName(), '');
        return $this->authService->authenticatedSession($cookie, $touch);
    }

    private function deleteOldR2Object(string $key): void
    {
        $key = trim($key);
        if ($key === '') {
            return;
        }
        try {
            (new R2Storage())->delete($key);
        } catch (\Throwable $exception) {
            Log::warning('Old avatar cleanup failed key=' . $key . ' message=' . $exception->getMessage());
        }
    }

    private function rateLimit(string $key, int $limit, int $seconds): void
    {
        $cacheKey = 'auth:' . $key;
        $state = Cache::get($cacheKey);
        $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
        $startedAt = is_array($state) ? (int) ($state['started_at'] ?? time()) : time();
        if ((time() - $startedAt) >= $seconds) {
            $count = 0;
            $startedAt = time();
        }
        $count++;
        if ($count > $limit) {
            throw new AuthException('操作过于频繁，请稍后再试', 429, 'RATE_LIMITED');
        }
        Cache::set($cacheKey, ['count' => $count, 'started_at' => $startedAt], $seconds);
    }

    private function registrationChannelLabel(string $channel): string
    {
        $labels = [
            'web' => '网页注册',
            'ios' => 'iOS',
            'android' => 'Android',
            'admin' => '管理员创建',
        ];
        return isset($labels[$channel]) ? $labels[$channel] : strtoupper($channel);
    }

    private function clientIp(): string
    {
        return ClientContext::ip($this->request);
    }
}
