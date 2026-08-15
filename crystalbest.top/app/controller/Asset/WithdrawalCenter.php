<?php

namespace app\controller\Asset;

use app\BaseController;
use app\controller\Auth\AuthException;
use app\controller\Auth\AuthService;
use app\service\Asset\AssetException;
use app\service\Wallet\WithdrawalService;
use think\facade\Log;

final class WithdrawalCenter extends BaseController
{
    public function page()
    {
        try {
            $auth = new AuthService($this->request);
            $cookie = (string) $this->request->cookie($auth->cookieName(), '');
            $me = $auth->me($cookie);
            $context = (new WithdrawalService($this->request))->context();
        } catch (AuthException $exception) {
            return redirect('/login?next=/dashboard/withdraw');
        } catch (AssetException $exception) {
            Log::warning('Withdrawal page unavailable: ' . $exception->getErrorCode() . ' ' . $exception->getMessage());
            return redirect('/dashboard/assets');
        }

        $selectedAsset = strtoupper(trim((string) $this->request->get('asset', 'USDT')));
        if (!in_array($selectedAsset, ['BTC', 'ETH', 'USDT', 'DOGE', 'SOL'], true)) {
            $selectedAsset = 'USDT';
        }

        return view('auth/withdraw', [
            'currentUser' => $me['user'],
            'withdrawContext' => $context,
            'selectedAsset' => $selectedAsset,
            'activeGroup' => 'assets',
            'activeItem' => 'withdraw',
        ]);
    }

    public function context()
    {
        return $this->handle(function (WithdrawalService $service) {
            return json(['code' => 0, 'message' => 'success', 'data' => $service->context()]);
        });
    }

    public function requestWithdrawal()
    {
        return $this->handle(function (WithdrawalService $service) {
            return json([
                'code' => 0,
                'message' => '提币申请已提交，正在处理中',
                'data' => $service->requestWithdrawal($this->payload()),
            ]);
        });
    }

    public function cancel()
    {
        return $this->handle(function (WithdrawalService $service) {
            $payload = $this->payload();
            return json([
                'code' => 0,
                'message' => '提币申请已取消，冻结资金已退回可用余额',
                'data' => $service->cancel((string) ($payload['withdrawal_no'] ?? '')),
            ]);
        });
    }

    private function handle(callable $callback)
    {
        try {
            $this->assertAllowedOrigin();
            return $callback(new WithdrawalService($this->request));
        } catch (AuthException $exception) {
            return json(['code' => $exception->getErrorCode(), 'message' => $exception->getMessage(), 'data' => null], $exception->getHttpStatus());
        } catch (AssetException $exception) {
            return json(['code' => $exception->getErrorCode(), 'message' => $exception->getMessage(), 'data' => null], $exception->getHttpStatus());
        } catch (\Throwable $exception) {
            Log::error('Withdrawal center error: ' . get_class($exception) . ' ' . $exception->getMessage() . ' file=' . $exception->getFile() . ' line=' . $exception->getLine());
            return json(['code' => 'WITHDRAW_INTERNAL_ERROR', 'message' => '提币服务暂时不可用，请稍后重试', 'data' => null], 500);
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
