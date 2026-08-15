<?php

namespace app\controller\Auth;

use app\BaseController;
use app\controller\Auth\Social\ProviderConfig;
use think\facade\Log;

class Account extends BaseController
{
    public function loginPage()
    {
        if ($this->currentUser() !== null) {
            return redirect('/dashboard');
        }
        return view('auth/login', $this->socialViewData());
    }

    public function registerPage()
    {
        if ($this->currentUser() !== null) {
            return redirect('/dashboard');
        }
        return view('auth/register', array_merge($this->socialViewData(), [
            'prefillEmail' => trim((string) $this->request->get('email', '')),
        ]));
    }

    public function forgotPage()
    {
        if ($this->currentUser() !== null) {
            return redirect('/dashboard');
        }
        return view('auth/forgot');
    }

    public function dashboardPage()
    {
        $user = $this->currentUser();
        if ($user === null) {
            return redirect('/login?next=/dashboard');
        }
        return view('auth/dashboard', [
            'currentUser' => $user,
            'activeGroup' => 'overview',
            'activeItem' => 'dashboard',
        ]);
    }

    public function registerSendCode()
    {
        return $this->handle(function (AuthService $service) {
            return json([
                'code' => 0,
                'message' => '验证码已发送，请检查邮箱',
                'data' => $service->requestRegistrationCode($this->payload()),
            ]);
        });
    }

    public function registerVerifyCode()
    {
        return $this->handle(function (AuthService $service) {
            return json([
                'code' => 0,
                'message' => '邮箱验证成功，请设置登录密码',
                'data' => $service->verifyRegistrationCode($this->payload()),
            ]);
        });
    }

    public function register()
    {
        return $this->handle(function (AuthService $service) {
            $result = $service->register($this->payload());
            $cookieValue = $result['session']['cookie_value'];
            $deviceCookieValue = (string) ($result['session']['device_cookie_value'] ?? '');
            unset($result['session']['cookie_value'], $result['session']['device_cookie_value']);

            $response = json([
                'code' => 0,
                'message' => '注册成功',
                'data' => $result,
            ])->cookie($service->cookieName(), $cookieValue, $service->cookieOptions(true));
            if ($deviceCookieValue !== '') {
                $response->cookie($service->deviceCookieName(), $deviceCookieValue, $service->deviceCookieOptions());
            }
            return $response;
        });
    }

    public function login()
    {
        return $this->handle(function (AuthService $service) {
            $payload = $this->payload();
            $remember = (bool) ($payload['remember'] ?? false);
            $result = $service->login($payload);
            $cookieValue = $result['session']['cookie_value'];
            $deviceCookieValue = (string) ($result['session']['device_cookie_value'] ?? '');
            unset($result['session']['cookie_value'], $result['session']['device_cookie_value']);

            $response = json([
                'code' => 0,
                'message' => '登录成功',
                'data' => $result,
            ])->cookie($service->cookieName(), $cookieValue, $service->cookieOptions($remember));
            if ($deviceCookieValue !== '') {
                $response->cookie($service->deviceCookieName(), $deviceCookieValue, $service->deviceCookieOptions());
            }
            return $response;
        });
    }

    public function loginEmailSendCode()
    {
        return $this->handle(function (AuthService $service) {
            return json([
                'code' => 0,
                'message' => '如果该邮箱存在可登录账户，验证码将发送到邮箱',
                'data' => $service->requestEmailLoginCode($this->payload()),
            ]);
        });
    }

    public function loginEmailVerify()
    {
        return $this->handle(function (AuthService $service) {
            $payload = $this->payload();
            $remember = (bool) ($payload['remember'] ?? false);
            $result = $service->loginByEmailCode($payload);
            $cookieValue = $result['session']['cookie_value'];
            $deviceCookieValue = (string) ($result['session']['device_cookie_value'] ?? '');
            unset($result['session']['cookie_value'], $result['session']['device_cookie_value']);

            $response = json([
                'code' => 0,
                'message' => '登录成功',
                'data' => $result,
            ])->cookie($service->cookieName(), $cookieValue, $service->cookieOptions($remember));
            if ($deviceCookieValue !== '') {
                $response->cookie($service->deviceCookieName(), $deviceCookieValue, $service->deviceCookieOptions());
            }
            return $response;
        });
    }

    public function forgot()
    {
        return $this->handle(function (AuthService $service) {
            $result = $service->requestPasswordReset($this->payload());
            return json([
                'code' => 0,
                'message' => '如果该邮箱已注册且设置过密码，验证码将发送到邮箱，请注意查收',
                'data' => $result,
            ]);
        });
    }

    public function verifyResetCode()
    {
        return $this->handle(function (AuthService $service) {
            return json([
                'code' => 0,
                'message' => '邮箱验证成功，请设置新密码',
                'data' => $service->verifyPasswordResetCode($this->payload()),
            ]);
        });
    }

    public function resetPassword()
    {
        return $this->handle(function (AuthService $service) {
            $result = $service->resetPassword($this->payload());
            return json([
                'code' => 0,
                'message' => '密码重置成功，请重新登录',
                'data' => $result,
            ])->cookie($service->cookieName(), '', $service->expiredCookieOptions());
        });
    }

    public function status()
    {
        $user = $this->currentUser();
        return json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'authenticated' => $user !== null,
                'user' => $user,
            ],
        ]);
    }

    public function me()
    {
        return $this->handle(function (AuthService $service) {
            $cookie = (string) $this->request->cookie($service->cookieName(), '');
            return json([
                'code' => 0,
                'message' => 'success',
                'data' => $service->me($cookie),
            ]);
        });
    }

    public function logout()
    {
        return $this->handle(function (AuthService $service) {
            $cookie = (string) $this->request->cookie($service->cookieName(), '');
            $result = $service->logout($cookie);
            return json([
                'code' => 0,
                'message' => '已退出登录',
                'data' => $result,
            ])->cookie($service->cookieName(), '', $service->expiredCookieOptions());
        });
    }

    private function currentUser(): ?array
    {
        $service = new AuthService($this->request);
        $cookie = (string) $this->request->cookie($service->cookieName(), '');
        if ($cookie === '') {
            return null;
        }

        try {
            $result = $service->me($cookie);
            return isset($result['user']) && is_array($result['user']) ? $result['user'] : null;
        } catch (AuthException $exception) {
            return null;
        }
    }

    private function socialViewData(): array
    {
        $providers = ProviderConfig::publicStatus();
        return [
            'googleEnabled' => (bool) ($providers['google'] ?? false),
            'microsoftEnabled' => (bool) ($providers['microsoft'] ?? false),
        ];
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
            return $callback(new AuthService($this->request));
        } catch (AuthException $exception) {
            return json([
                'code' => $exception->getErrorCode(),
                'message' => $exception->getMessage(),
                'data' => null,
            ], $exception->getHttpStatus());
        } catch (\Throwable $exception) {
            Log::error('Auth controller error', [
                'message' => $exception->getMessage(),
                'trace' => (bool) env('app_debug', false) ? $exception->getTraceAsString() : null,
            ]);
            return json([
                'code' => 'INTERNAL_ERROR',
                'message' => '账户服务暂时不可用，请稍后重试',
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
