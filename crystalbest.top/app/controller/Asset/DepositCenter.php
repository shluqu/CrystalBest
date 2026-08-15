<?php

namespace app\controller\Asset;

use app\BaseController;
use app\controller\Auth\AuthException;
use app\controller\Auth\AuthService;
use app\service\Asset\AssetException;
use app\service\Wallet\DepositService;
use think\facade\Log;

final class DepositCenter extends BaseController
{
    public function page()
    {
        try {
            $auth = new AuthService($this->request);
            $cookie = (string) $this->request->cookie($auth->cookieName(), '');
            $me = $auth->me($cookie);
            $context = (new DepositService($this->request))->context();
        } catch (AuthException $exception) {
            return redirect('/login?next=/dashboard/deposit');
        } catch (AssetException $exception) {
            Log::warning('Deposit page unavailable: ' . $exception->getErrorCode() . ' ' . $exception->getMessage());
            return redirect('/dashboard/assets');
        }

        $selectedAsset = strtoupper(trim((string) $this->request->get('asset', 'BTC')));
        if (!in_array($selectedAsset, ['BTC', 'ETH', 'USDT', 'DOGE', 'SOL'], true)) {
            $selectedAsset = 'BTC';
        }

        return view('auth/deposit', [
            'currentUser' => $me['user'],
            'depositContext' => $context,
            'selectedAsset' => $selectedAsset,
            'activeGroup' => 'assets',
            'activeItem' => 'deposit',
        ]);
    }

    public function context()
    {
        return $this->handle(function (DepositService $service) {
            return json(['code' => 0, 'message' => 'success', 'data' => $service->context()]);
        });
    }

    public function address()
    {
        return $this->handle(function (DepositService $service) {
            $payload = $this->payload();
            return json([
                'code' => 0,
                'message' => '充值地址已准备',
                'data' => $service->ensureAddress((string) ($payload['route_code'] ?? '')),
            ]);
        });
    }

    private function handle(callable $callback)
    {
        try {
            $this->assertAllowedOrigin();
            return $callback(new DepositService($this->request));
        } catch (AuthException $exception) {
            return json(['code' => $exception->getErrorCode(), 'message' => $exception->getMessage(), 'data' => null], $exception->getHttpStatus());
        } catch (AssetException $exception) {
            return json(['code' => $exception->getErrorCode(), 'message' => $exception->getMessage(), 'data' => null], $exception->getHttpStatus());
        } catch (\Throwable $exception) {
            Log::error('Deposit center error: ' . get_class($exception) . ' ' . $exception->getMessage());
            return json(['code' => 'DEPOSIT_INTERNAL_ERROR', 'message' => '充值服务暂时不可用，请稍后重试', 'data' => null], 500);
        }
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
}
