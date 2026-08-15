<?php

namespace app\controller\Asset;

use app\BaseController;
use app\controller\Auth\AuthException;
use app\controller\Auth\AuthService;
use app\service\Asset\AssetException;
use app\service\Asset\AssetService;
use think\facade\Log;

final class AssetCenter extends BaseController
{
    public function overviewPage()
    {
        try {
            $auth = new AuthService($this->request);
            $cookie = (string) $this->request->cookie($auth->cookieName(), '');
            $me = $auth->me($cookie);
            $overview = (new AssetService($this->request))->overview();
        } catch (AuthException $exception) {
            return redirect('/login?next=/dashboard/assets');
        } catch (AssetException $exception) {
            return $this->assetErrorPage($exception->getMessage(), '/dashboard/assets');
        }

        return view('auth/assets', [
            'currentUser' => $me['user'],
            'assetOverview' => $overview,
            'activeGroup' => 'assets',
            'activeItem' => 'assets',
        ]);
    }

    public function transferPage()
    {
        try {
            $auth = new AuthService($this->request);
            $cookie = (string) $this->request->cookie($auth->cookieName(), '');
            $me = $auth->me($cookie);
            $context = (new AssetService($this->request))->transferContext(20);
        } catch (AuthException $exception) {
            return redirect('/login?next=/dashboard/transfer');
        } catch (AssetException $exception) {
            return $this->assetErrorPage($exception->getMessage(), '/dashboard/transfer');
        }

        return view('auth/transfer', [
            'currentUser' => $me['user'],
            'transferContext' => $context,
            'activeGroup' => 'assets',
            'activeItem' => 'transfer',
        ]);
    }

    public function overview()
    {
        return $this->handle(function (AssetService $service) {
            return json(['code' => 0, 'message' => 'success', 'data' => $service->overview()]);
        });
    }

    public function transferContext()
    {
        return $this->handle(function (AssetService $service) {
            return json(['code' => 0, 'message' => 'success', 'data' => $service->transferContext(20)]);
        });
    }

    public function transfer()
    {
        return $this->handle(function (AssetService $service) {
            return json([
                'code' => 0,
                'message' => '资金划转已完成',
                'data' => $service->transfer($this->payload()),
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
                if (is_array($decoded)) return $decoded;
            }
        }
        return $this->request->post();
    }

    private function handle(callable $callback)
    {
        try {
            $this->assertAllowedOrigin();
            return $callback(new AssetService($this->request));
        } catch (AuthException $exception) {
            return json([
                'code' => $exception->getErrorCode(),
                'message' => $exception->getMessage(),
                'data' => null,
            ], $exception->getHttpStatus());
        } catch (AssetException $exception) {
            return json([
                'code' => $exception->getErrorCode(),
                'message' => $exception->getMessage(),
                'data' => null,
            ], $exception->getHttpStatus());
        } catch (\Throwable $exception) {
            Log::error(
                'Asset center error exception=' . get_class($exception)
                . ' message=' . $exception->getMessage()
                . ' file=' . $exception->getFile()
                . ' line=' . $exception->getLine()
            );
            return json([
                'code' => 'ASSET_INTERNAL_ERROR',
                'message' => '资产服务暂时不可用，请稍后重试',
                'data' => null,
            ], 500);
        }
    }

    private function assertAllowedOrigin(): void
    {
        if (!$this->request->isPost()) return;
        $origin = trim((string) $this->request->header('origin', ''));
        if ($origin === '') return;
        $configured = trim((string) env('auth.allowed_origins', 'https://crystalbest.top'));
        $allowed = array_values(array_filter(array_map('trim', explode(',', $configured))));
        if (!in_array($origin, $allowed, true)) {
            throw new AssetException('请求来源不允许', 403, 'ORIGIN_NOT_ALLOWED');
        }
    }

    private function assetErrorPage(string $message, string $path)
    {
        Log::warning('Asset page unavailable path=' . $path . ' message=' . $message);
        return redirect('/dashboard');
    }
}
