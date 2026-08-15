<?php

namespace app\controller;

use app\BaseController;
use app\controller\Auth\AuthException;
use app\service\Asset\AssetException;
use app\service\Spot\SpotTradingService;
use think\facade\Log;

final class SpotTradeCenter extends BaseController
{
    public function account()
    {
        return $this->handle(function (SpotTradingService $service) {
            return json([
                'code' => 0,
                'message' => 'success',
                'data' => $service->accountContext((string) $this->request->get('symbol', '')),
            ]);
        });
    }

    public function orders()
    {
        return $this->handle(function (SpotTradingService $service) {
            return json([
                'code' => 0,
                'message' => 'success',
                'data' => $service->orders(
                    (string) $this->request->get('scope', 'open'),
                    (string) $this->request->get('symbol', ''),
                    (int) $this->request->get('limit', 20)
                ),
            ]);
        });
    }

    public function createOrder()
    {
        return $this->handle(function (SpotTradingService $service) {
            $result = $service->createOrder($this->payload());
            return json([
                'code' => 0,
                'message' => (string) $result['message'],
                'data' => $result,
            ]);
        });
    }

    public function cancel(string $order)
    {
        return $this->handle(function (SpotTradingService $service) use ($order) {
            $result = $service->cancel($order);
            return json([
                'code' => 0,
                'message' => (string) $result['message'],
                'data' => $result,
            ]);
        });
    }

    private function handle(callable $callback)
    {
        try {
            $this->assertAllowedOrigin();
            return $callback(new SpotTradingService($this->request));
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
                'Spot trade center error exception=' . get_class($exception)
                . ' message=' . $exception->getMessage()
                . ' file=' . $exception->getFile()
                . ' line=' . $exception->getLine()
            );
            return json([
                'code' => 'SPOT_INTERNAL_ERROR',
                'message' => '现货交易服务暂时不可用，请稍后重试',
                'data' => null,
            ], 500);
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
