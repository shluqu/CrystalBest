<?php

namespace app\controller\Internal;

use app\BaseController;
use app\service\Asset\AssetException;
use app\service\Wallet\DepositEventService;
use app\service\Wallet\InternalWalletRequestGuard;
use think\facade\Log;

final class WalletCallback extends BaseController
{
    private const MAX_BODY_BYTES = 65536;

    public function depositEvent()
    {
        $rawBody = (string) $this->request->getInput();

        try {
            $contentType = strtolower(trim((string) $this->request->header('content-type', '')));
            if (strpos($contentType, 'application/json') === false) {
                throw new AssetException('Wallet callback 仅接受 application/json', 415, 'WALLET_CALLBACK_CONTENT_TYPE_REQUIRED');
            }
            if ($rawBody === '') {
                throw new AssetException('Wallet callback 请求体为空', 422, 'WALLET_CALLBACK_EMPTY_BODY');
            }
            if (strlen($rawBody) > self::MAX_BODY_BYTES) {
                throw new AssetException('Wallet callback 请求体过大', 413, 'WALLET_CALLBACK_BODY_TOO_LARGE');
            }

            $payload = json_decode($rawBody, true);
            if (!is_array($payload)) {
                throw new AssetException('Wallet callback JSON 无效', 422, 'WALLET_CALLBACK_BAD_JSON');
            }
            $guardMeta = (new InternalWalletRequestGuard())->inspect($this->request, $rawBody, $payload);

            $result = (new DepositEventService())->handle($payload, $guardMeta);
            return json([
                'ok' => true,
                'code' => 'OK',
                'message' => 'processed',
                'data' => $result,
            ])->header([
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (AssetException $exception) {
            Log::warning('Wallet callback rejected code=' . $exception->getErrorCode() . ' message=' . $exception->getMessage());
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
            Log::error('Wallet callback error ' . get_class($exception) . ': ' . $exception->getMessage());
            return json([
                'ok' => false,
                'code' => 'WALLET_CALLBACK_INTERNAL_ERROR',
                'message' => 'internal error',
                'data' => null,
            ], 500)->header([
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
    }
}
