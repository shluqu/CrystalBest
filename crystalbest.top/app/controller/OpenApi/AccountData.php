<?php

namespace app\controller\OpenApi;

use app\BaseController;
use app\service\OpenApi\ApiException;
use app\service\OpenApi\ApiKeyService;
use app\service\OpenApi\ReadOnlyAccountService;
use think\facade\Log;

final class AccountData extends BaseController
{
    public function profile()
    {
        return $this->handle(
            ApiKeyService::SCOPE_PROFILE,
            static fn(ReadOnlyAccountService $service, array $auth) => $service->profile((int) $auth['user_id'])
        );
    }

    public function positions()
    {
        return $this->handle(
            ApiKeyService::SCOPE_POSITIONS,
            static fn(ReadOnlyAccountService $service, array $auth) => $service->perpetualPositions((int) $auth['user_id'])
        );
    }

    public function balances()
    {
        return $this->handle(
            ApiKeyService::SCOPE_BALANCES,
            static fn(ReadOnlyAccountService $service, array $auth) => $service->balances((int) $auth['user_id'])
        );
    }

    public function deposits()
    {
        [$page, $pageSize] = $this->pagination();
        return $this->handle(
            ApiKeyService::SCOPE_WALLET_HISTORY,
            static fn(ReadOnlyAccountService $service, array $auth) => $service->deposits((int) $auth['user_id'], $page, $pageSize)
        );
    }

    public function withdrawals()
    {
        [$page, $pageSize] = $this->pagination();
        return $this->handle(
            ApiKeyService::SCOPE_WALLET_HISTORY,
            static fn(ReadOnlyAccountService $service, array $auth) => $service->withdrawals((int) $auth['user_id'], $page, $pageSize)
        );
    }

    public function markets()
    {
        return $this->handle(
            ApiKeyService::SCOPE_MARKETS,
            static fn(ReadOnlyAccountService $service, array $auth) => $service->supportedMarkets()
        );
    }

    private function handle(string $scope, callable $callback)
    {
        try {
            $auth = (new ApiKeyService($this->request))->authenticate($scope);
            $data = $callback(new ReadOnlyAccountService(), $auth);
            return json([
                'code' => 0,
                'message' => 'success',
                'data' => $data,
                'meta' => [
                    'api_version' => 'v1',
                    'uid' => $auth['uid'],
                    'market_data_included' => false,
                    'server_time' => time(),
                ],
            ])->header([
                'Cache-Control' => 'no-store',
                'Pragma' => 'no-cache',
            ]);
        } catch (ApiException $exception) {
            return json([
                'code' => $exception->getErrorCode(),
                'message' => $exception->getMessage(),
                'data' => null,
            ], $exception->getHttpStatus())->header(['Cache-Control' => 'no-store']);
        } catch (\Throwable $exception) {
            Log::error(
                'OpenAPI read error action=' . $this->request->action()
                . ' exception=' . get_class($exception)
                . ' message=' . $exception->getMessage()
                . ' file=' . $exception->getFile()
                . ' line=' . $exception->getLine()
            );
            return json([
                'code' => 'OPENAPI_INTERNAL_ERROR',
                'message' => 'API 服务暂时不可用，请稍后重试',
                'data' => null,
            ], 500)->header(['Cache-Control' => 'no-store']);
        }
    }

    private function pagination(): array
    {
        $page = max(1, (int) $this->request->get('page', 1));
        $pageSize = max(1, min(100, (int) $this->request->get('page_size', 20)));
        return [$page, $pageSize];
    }
}
