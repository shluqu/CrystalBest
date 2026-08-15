<?php

namespace app\controller\Auth;

use app\BaseController;
use think\facade\Log;

class ProfileCenter extends BaseController
{
    public function page()
    {
        try {
            $auth = new AuthService($this->request);
            $cookie = (string) $this->request->cookie($auth->cookieName(), '');
            $me = $auth->me($cookie);
            $profile = (new ProfileService($this->request))->overview();
        } catch (AuthException $exception) {
            return redirect('/login?next=/dashboard/profile');
        }

        return view('auth/profile', [
            'currentUser' => $me['user'],
            'profile' => $profile,
            'activeGroup' => 'overview',
            'activeItem' => 'profile',
        ]);
    }

    public function overview()
    {
        return $this->handle(function (ProfileService $service) {
            return json(['code' => 0, 'message' => 'success', 'data' => $service->overview()]);
        });
    }

    public function updateNickname()
    {
        return $this->handle(function (ProfileService $service) {
            $data = $this->payload();
            return json([
                'code' => 0,
                'message' => '昵称已更新',
                'data' => $service->updateNickname((string) ($data['nickname'] ?? '')),
            ]);
        });
    }

    public function uploadAvatar()
    {
        return $this->handle(function (ProfileService $service) {
            $file = $this->request->file('avatar');
            if (!$file) {
                throw new AuthException('请选择头像图片', 422, 'AVATAR_REQUIRED');
            }
            return json([
                'code' => 0,
                'message' => '头像已上传',
                'data' => $service->uploadAvatar($file),
            ]);
        });
    }

    private function payload(): array
    {
        $contentType = strtolower((string) $this->request->header('content-type', ''));
        if (strpos($contentType, 'application/json') !== false) {
            $raw = (string) $this->request->getInput();
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        return $this->request->post();
    }

    private function handle(callable $callback)
    {
        try {
            $this->assertAllowedOrigin();
            return $callback(new ProfileService($this->request));
        } catch (AuthException $exception) {
            return json([
                'code' => $exception->getErrorCode(),
                'message' => $exception->getMessage(),
                'data' => null,
            ], $exception->getHttpStatus());
        } catch (\Throwable $exception) {
            Log::error(
                'Profile center error exception=' . get_class($exception)
                . ' message=' . $exception->getMessage()
                . ' file=' . $exception->getFile()
                . ' line=' . $exception->getLine()
            );
            return json([
                'code' => 'INTERNAL_ERROR',
                'message' => '个人资料服务暂时不可用，请稍后重试',
                'data' => null,
            ], 500);
        }
    }

    private function assertAllowedOrigin(): void
    {
        if (!$this->request->isPost()) {
            return;
        }
        $origin = trim((string) $this->request->header('origin', ''));
        if ($origin === '') {
            return;
        }
        $configured = trim((string) env('auth.allowed_origins', 'https://crystalbest.top'));
        $allowed = array_values(array_filter(array_map('trim', explode(',', $configured))));
        if (!in_array($origin, $allowed, true)) {
            throw new AuthException('请求来源不允许', 403, 'ORIGIN_NOT_ALLOWED');
        }
    }
}
