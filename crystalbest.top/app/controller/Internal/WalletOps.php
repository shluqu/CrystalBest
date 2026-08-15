<?php

namespace app\controller\Internal;

use app\BaseController;
use app\service\Asset\AssetException;
use app\service\Wallet\DepositReconciliationService;
use app\service\Wallet\InternalWalletRequestGuard;
use app\service\Wallet\WalletOpsService;
use think\facade\Log;

final class WalletOps extends BaseController
{
    public function health()
    {
        return $this->handle(function () {
            return [
                'ok' => true,
                'code' => 'OK',
                'data' => (new WalletOpsService())->health(),
            ];
        });
    }

    public function anomalies()
    {
        return $this->handle(function () {
            $limit = filter_var($this->request->get('limit', 50), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 200],
            ]);
            if ($limit === false) {
                throw new AssetException('limit 参数无效', 422, 'WALLET_OPS_LIMIT_INVALID');
            }

            return [
                'ok' => true,
                'code' => 'OK',
                'data' => (new WalletOpsService())->anomalies((int) $limit),
            ];
        });
    }

    public function reconcile()
    {
        return $this->handle(function () {
            $payload = $this->jsonPayload();
            $repairSafe = array_key_exists('repair_safe', $payload) ? (bool) $payload['repair_safe'] : false;
            $limit = null;
            if (array_key_exists('limit', $payload)) {
                $limit = filter_var($payload['limit'], FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 10, 'max_range' => 2000],
                ]);
                if ($limit === false) {
                    throw new AssetException('limit 参数无效', 422, 'WALLET_OPS_LIMIT_INVALID');
                }
            }

            $result = (new DepositReconciliationService())->run($repairSafe, $limit === null ? null : (int) $limit);
            return [
                'ok' => true,
                'code' => 'OK',
                'data' => $result,
            ];
        });
    }

    private function handle(callable $callback)
    {
        try {
            (new InternalWalletRequestGuard())->inspectOps($this->request);
            $payload = $callback();
            return json($payload)->header([
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (AssetException $exception) {
            return json([
                'ok' => false,
                'code' => $exception->getErrorCode(),
                'message' => $exception->getMessage(),
                'data' => null,
            ], $exception->getHttpStatus())->header([
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (\Throwable $exception) {
            Log::error('Wallet ops error ' . get_class($exception) . ': ' . $exception->getMessage());
            return json([
                'ok' => false,
                'code' => 'WALLET_OPS_INTERNAL_ERROR',
                'message' => 'wallet ops internal error',
                'data' => null,
            ], 500)->header([
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
    }

    private function jsonPayload(): array
    {
        $contentType = strtolower(trim((string) $this->request->header('content-type', '')));
        if (strpos($contentType, 'application/json') === false) {
            throw new AssetException('仅接受 application/json', 415, 'WALLET_OPS_CONTENT_TYPE_REQUIRED');
        }

        $raw = (string) $this->request->getInput();
        if ($raw === '') return [];
        if (strlen($raw) > 16384) {
            throw new AssetException('请求体过大', 413, 'WALLET_OPS_BODY_TOO_LARGE');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new AssetException('JSON 无效', 422, 'WALLET_OPS_JSON_INVALID');
        }
        return $decoded;
    }
}
