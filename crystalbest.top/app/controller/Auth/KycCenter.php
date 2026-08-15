<?php

namespace app\controller\Auth;

use app\BaseController;
use app\service\Kyc\KycService;
use think\facade\Log;

final class KycCenter extends BaseController
{
    public function page()
    {
        try {
            $auth = new AuthService($this->request);
            $cookie = (string) $this->request->cookie($auth->cookieName(), '');
            $me = $auth->me($cookie);
            $context = (new KycService($this->request))->overview();
        } catch (AuthException $exception) {
            return redirect('/login?next=/dashboard/kyc');
        }

        return view('auth/kyc', [
            'currentUser' => $me['user'],
            'kycContext' => $context,
            'activeGroup' => 'security',
            'activeItem' => 'kyc',
        ]);
    }

    public function context()
    {
        return $this->handle(function (KycService $service) {
            return json(['code' => 0, 'message' => 'success', 'data' => $service->overview()]);
        });
    }

    public function submit()
    {
        return $this->handle(function (KycService $service) {
            $front = $this->request->file('document_front');
            $back = $this->request->file('document_back');
            return json([
                'code' => 0,
                'message' => '身份认证资料已提交',
                'data' => $service->submit($this->request->post(), $front, $back),
            ]);
        });
    }

    private function handle(callable $callback)
    {
        try {
            $this->assertAllowedOrigin();
            return $callback(new KycService($this->request));
        } catch (AuthException $exception) {
            return json([
                'code' => $exception->getErrorCode(),
                'message' => $exception->getMessage(),
                'data' => null,
            ], $exception->getHttpStatus())->header(['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        } catch (\Throwable $exception) {
            Log::error('KYC center error ' . get_class($exception) . ': ' . $exception->getMessage());
            return json([
                'code' => 'KYC_INTERNAL_ERROR',
                'message' => '身份认证服务暂时不可用，请稍后重试',
                'data' => null,
            ], 500)->header(['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
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
