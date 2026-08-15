<?php

namespace app\controller\Auth;

use app\BaseController;
use app\service\OpenApi\ApiException;
use app\service\OpenApi\ApiKeyService;
use think\facade\Log;

final class ApiCenter extends BaseController
{
    public function page()
    {
        try {
            $auth = new AuthService($this->request);
            $cookie = (string) $this->request->cookie($auth->cookieName(), '');
            $me = $auth->me($cookie);
            $context = (new ApiKeyService($this->request))->dashboardContext();
        } catch (AuthException $exception) {
            return redirect('/login?next=' . rawurlencode('/dashboard/api'));
        } catch (\Throwable $exception) {
            Log::error(
                'API center page error exception=' . get_class($exception)
                . ' message=' . $exception->getMessage()
                . ' file=' . $exception->getFile()
                . ' line=' . $exception->getLine()
            );
            return redirect('/dashboard');
        }

        return view('auth/api', [
            'currentUser' => $me['user'],
            'apiDashboard' => $context,
            'activeGroup' => 'security',
            'activeItem' => 'api',
        ]);
    }

    public function create()
    {
        return $this->handle(function (ApiKeyService $service) {
            return json([
                'code' => 0,
                'message' => 'API Key 已创建',
                'data' => $service->create($this->payload()),
            ])->header(['Cache-Control' => 'no-store']);
        });
    }

    public function revoke()
    {
        return $this->handle(function (ApiKeyService $service) {
            return json([
                'code' => 0,
                'message' => 'API Key 已撤销',
                'data' => $service->revoke($this->payload()),
            ])->header(['Cache-Control' => 'no-store']);
        });
    }

    private function handle(callable $callback)
    {
        try {
            $this->assertAllowedOrigin();
            return $callback(new ApiKeyService($this->request));
        } catch (AuthException $exception) {
            return json([
                'code' => $exception->getErrorCode(),
                'message' => $exception->getMessage(),
                'data' => null,
            ], $exception->getHttpStatus())->header(['Cache-Control' => 'no-store']);
        } catch (ApiException $exception) {
            return json([
                'code' => $exception->getErrorCode(),
                'message' => $exception->getMessage(),
                'data' => null,
            ], $exception->getHttpStatus())->header(['Cache-Control' => 'no-store']);
        } catch (\Throwable $exception) {
            Log::error(
                'API key management error exception=' . get_class($exception)
                . ' message=' . $exception->getMessage()
                . ' file=' . $exception->getFile()
                . ' line=' . $exception->getLine()
            );
            return json([
                'code' => 'API_MANAGEMENT_INTERNAL_ERROR',
                'message' => 'API 管理暂时不可用，请稍后重试',
                'data' => null,
            ], 500)->header(['Cache-Control' => 'no-store']);
        }
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
            throw new ApiException('请求来源不允许', 403, 'ORIGIN_NOT_ALLOWED');
        }
    }
}
