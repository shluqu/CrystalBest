<?php

namespace app\controller\Auth;

use app\BaseController;
use think\facade\Log;

class SecurityCenter extends BaseController
{
    public function page()
    {
        $service = new AuthService($this->request);
        $cookie = (string) $this->request->cookie($service->cookieName(), '');
        try {
            $me = $service->me($cookie);
            $security = (new SecurityService($this->request))->overview();
        } catch (AuthException $exception) {
            return redirect('/login?next=/dashboard/security');
        }

        return view('auth/security', [
            'currentUser' => $me['user'],
            'securityOverview' => $security,
            'socialGoogle' => $security['social_accounts']['google'] ?? [],
            'socialMicrosoft' => $security['social_accounts']['microsoft'] ?? [],
            'activeGroup' => 'security',
            'activeItem' => 'security',
        ]);
    }

    public function loginDevicesPage()
    {
        $authService = new AuthService($this->request);
        $cookie = (string) $this->request->cookie($authService->cookieName(), '');
        try {
            $me = $authService->me($cookie);
            $securityService = new SecurityService($this->request);
            $devices = $securityService->sessions();
            $history = $securityService->sessionHistory(30);
        } catch (AuthException $exception) {
            return redirect('/login?next=/dashboard/security/devices');
        }

        return view('auth/login_history', [
            'currentUser' => $me['user'],
            'activeSessions' => $devices['sessions'],
            'activeSessionCount' => $devices['count'],
            'loginHistory' => $history['sessions'],
            'historyCount' => $history['count'],
            'activeGroup' => 'security',
            'activeItem' => 'login-devices',
        ]);
    }

    public function loginHistoryLegacy()
    {
        return redirect('/dashboard/security/devices');
    }

    public function overview()
    {
        return $this->handle(function (SecurityService $service) {
            return json(['code' => 0, 'message' => 'success', 'data' => $service->overview()]);
        });
    }

    public function sendEmailCode()
    {
        return $this->handle(function (SecurityService $service) {
            $data = $this->payload();
            return json([
                'code' => 0,
                'message' => '安全验证码已发送到你的邮箱',
                'data' => $service->sendEmailCode((string) ($data['action'] ?? '')),
            ]);
        });
    }

    public function verifyEmailCode()
    {
        return $this->handle(function (SecurityService $service) {
            $data = $this->payload();
            return json([
                'code' => 0,
                'message' => '邮箱验证成功',
                'data' => $service->verifyEmailCode(
                    (string) ($data['action'] ?? ''),
                    (string) ($data['code'] ?? '')
                ),
            ]);
        });
    }

    public function sendNewEmailCode()
    {
        return $this->handle(function (SecurityService $service) {
            $data = $this->payload();
            return json([
                'code' => 0,
                'message' => '验证码已发送到新的安全邮箱',
                'data' => $service->sendNewEmailCode(
                    (string) ($data['security_ticket'] ?? ''),
                    (string) ($data['new_email'] ?? '')
                ),
            ]);
        });
    }

    public function confirmEmailChange()
    {
        return $this->handle(function (SecurityService $service) {
            $data = $this->payload();
            return json([
                'code' => 0,
                'message' => '安全邮箱已修改并完成验证',
                'data' => $service->confirmEmailChange(
                    (string) ($data['security_ticket'] ?? ''),
                    (string) ($data['new_email'] ?? ''),
                    (string) ($data['code'] ?? ''),
                    (string) ($data['totp_code'] ?? '')
                ),
            ]);
        });
    }

    public function totpSetup()
    {
        return $this->handle(function (SecurityService $service) {
            $data = $this->payload();
            return json([
                'code' => 0,
                'message' => '请使用 Google Authenticator 扫描二维码并输入动态验证码',
                'data' => $service->setupTotp((string) ($data['security_ticket'] ?? '')),
            ]);
        });
    }

    public function totpEnable()
    {
        return $this->handle(function (SecurityService $service) {
            $data = $this->payload();
            return json([
                'code' => 0,
                'message' => 'Google 身份验证器已启用',
                'data' => $service->enableTotp(
                    (string) ($data['security_ticket'] ?? ''),
                    (string) ($data['totp_code'] ?? '')
                ),
            ]);
        });
    }

    public function totpDisable()
    {
        return $this->handle(function (SecurityService $service) {
            $data = $this->payload();
            return json([
                'code' => 0,
                'message' => 'Google 身份验证器已关闭',
                'data' => $service->disableTotp(
                    (string) ($data['security_ticket'] ?? ''),
                    (string) ($data['totp_code'] ?? '')
                ),
            ]);
        });
    }

    public function changePassword()
    {
        return $this->handle(function (SecurityService $service) {
            return json([
                'code' => 0,
                'message' => '登录密码已更新，其他设备已退出',
                'data' => $service->changePassword($this->payload()),
            ]);
        });
    }

    public function socialLinkIntent(string $provider)
    {
        return $this->handle(function (SecurityService $service) use ($provider) {
            $data = $this->payload();
            return json([
                'code' => 0,
                'message' => '社交账号绑定验证已完成，即将前往授权页面',
                'data' => $service->createSocialLinkIntent(
                    $provider,
                    (string) ($data['security_ticket'] ?? ''),
                    (string) ($data['totp_code'] ?? '')
                ),
            ]);
        });
    }

    public function socialUnlink(string $provider)
    {
        return $this->handle(function (SecurityService $service) use ($provider) {
            $data = $this->payload();
            return json([
                'code' => 0,
                'message' => '社交账号已解绑',
                'data' => $service->unlinkSocial(
                    $provider,
                    (string) ($data['security_ticket'] ?? ''),
                    (string) ($data['totp_code'] ?? '')
                ),
            ]);
        });
    }

    public function sessionHistory()
    {
        return $this->handle(function (SecurityService $service) {
            return json(['code' => 0, 'message' => 'success', 'data' => $service->sessionHistory(30)]);
        });
    }

    public function sessions()
    {
        return $this->handle(function (SecurityService $service) {
            return json(['code' => 0, 'message' => 'success', 'data' => $service->sessions()]);
        });
    }

    public function revokeSession(string $id)
    {
        return $this->handle(function (SecurityService $service) use ($id) {
            return json([
                'code' => 0,
                'message' => '该登录设备已退出',
                'data' => $service->revokeSession($id),
            ]);
        });
    }

    public function revokeOthers()
    {
        return $this->handle(function (SecurityService $service) {
            $data = $this->payload();
            return json([
                'code' => 0,
                'message' => '其他登录设备已全部退出',
                'data' => $service->revokeOtherSessions(
                    (string) ($data['security_ticket'] ?? ''),
                    (string) ($data['totp_code'] ?? '')
                ),
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
            return $callback(new SecurityService($this->request));
        } catch (AuthException $exception) {
            return json([
                'code' => $exception->getErrorCode(),
                'message' => $exception->getMessage(),
                'data' => null,
            ], $exception->getHttpStatus());
        } catch (\Throwable $exception) {
            Log::error(
                'Security center error exception=' . get_class($exception)
                . ' message=' . $exception->getMessage()
                . ' file=' . $exception->getFile()
                . ' line=' . $exception->getLine()
            );
            return json([
                'code' => 'INTERNAL_ERROR',
                'message' => '安全中心暂时不可用，请稍后重试',
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
