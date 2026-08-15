<?php

namespace app\controller;

use app\BaseController;
use app\controller\Auth\AuthException;
use app\service\Asset\AssetException;
use app\service\C2c\C2cException;
use app\service\C2c\C2cService;
use think\facade\Log;

final class C2cCenter extends BaseController
{
    public function market()
    {
        return $this->handle(function (C2cService $service) {
            return json(['code' => 0, 'message' => 'success', 'data' => $service->market($this->request->get())]);
        });
    }

    public function context()
    {
        return $this->handle(function (C2cService $service) {
            return json(['code' => 0, 'message' => 'success', 'data' => $service->context()]);
        });
    }

    public function applyMerchant()
    {
        return $this->handle(function (C2cService $service) {
            return json(['code' => 0, 'message' => 'C2C 商家资格已激活', 'data' => $service->applyMerchant()]);
        });
    }

    public function paymentMethods()
    {
        return $this->handle(function (C2cService $service) {
            return json(['code' => 0, 'message' => 'success', 'data' => $service->paymentMethods()]);
        });
    }

    public function createPaymentMethod()
    {
        return $this->handle(function (C2cService $service) {
            $payload = $this->request->post();

            // 银行卡不需要二维码。ThinkPHP 的 Request::file('qr_image')
            // 在 multipart/form-data 中没有该文件时会直接抛出“没有文件被上传！”。
            // 因此只有浏览器确实上传了 qr_image 时才调用 Request::file()。
            // 支付宝/微信没有二维码时由 C2cService 返回明确的业务错误。
            $file = null;
            if (
                isset($_FILES['qr_image'])
                && is_array($_FILES['qr_image'])
                && (int)($_FILES['qr_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
            ) {
                $file = $this->request->file('qr_image');
            }

            return json([
                'code' => 0,
                'message' => '收款方式已添加',
                'data' => $service->createPaymentMethod($payload, $file ?: null),
            ]);
        });
    }

    public function disablePaymentMethod(string $payment)
    {
        return $this->handle(function (C2cService $service) use ($payment) {
            return json(['code' => 0, 'message' => '收款方式已停用', 'data' => $service->disablePaymentMethod($payment)]);
        });
    }

    public function paymentMethodQr(string $payment)
    {
        return $this->binary(function (C2cService $service) use ($payment) {
            return $service->paymentMethodQr($payment);
        });
    }

    public function merchantAds()
    {
        return $this->handle(function (C2cService $service) {
            return json(['code' => 0, 'message' => 'success', 'data' => $service->merchantAds()]);
        });
    }

    public function createAd()
    {
        return $this->handle(function (C2cService $service) {
            return json(['code' => 0, 'message' => 'C2C 广告已发布', 'data' => $service->createAd($this->payload())]);
        });
    }

    public function setAdStatus(string $ad)
    {
        return $this->handle(function (C2cService $service) use ($ad) {
            return json(['code' => 0, 'message' => '广告状态已更新', 'data' => $service->setAdStatus($ad, $this->payload())]);
        });
    }

    public function createOrder()
    {
        return $this->handle(function (C2cService $service) {
            return json(['code' => 0, 'message' => 'C2C 订单已创建，加密货币已进入平台托管', 'data' => $service->createOrder($this->payload())]);
        });
    }

    public function orders()
    {
        return $this->handle(function (C2cService $service) {
            return json(['code' => 0, 'message' => 'success', 'data' => $service->myOrders((int) $this->request->get('limit', 100))]);
        });
    }

    public function order(string $order)
    {
        return $this->handle(function (C2cService $service) use ($order) {
            return json(['code' => 0, 'message' => 'success', 'data' => $service->orderDetail($order)]);
        });
    }

    public function orderPaymentQr(string $order)
    {
        return $this->binary(function (C2cService $service) use ($order) {
            return $service->orderPaymentQr($order);
        });
    }

    public function markPaid(string $order)
    {
        return $this->handle(function (C2cService $service) use ($order) {
            return json(['code' => 0, 'message' => '已标记付款完成，请等待对方确认收款并放币', 'data' => $service->markPaid($order)]);
        });
    }

    public function confirmReceipt(string $order)
    {
        return $this->handle(function (C2cService $service) use ($order) {
            return json(['code' => 0, 'message' => '收款已确认，加密货币已放行', 'data' => $service->confirmReceipt($order)]);
        });
    }

    public function cancelOrder(string $order)
    {
        return $this->handle(function (C2cService $service) use ($order) {
            return json(['code' => 0, 'message' => '订单已取消，托管资产已退回卖方', 'data' => $service->cancelOrder($order)]);
        });
    }

    public function appeal(string $order)
    {
        return $this->handle(function (C2cService $service) use ($order) {
            return json(['code' => 0, 'message' => '申诉已提交，订单资产继续保持托管状态', 'data' => $service->openAppeal($order, $this->payload())]);
        });
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
            return $callback(new C2cService($this->request));
        } catch (AuthException $exception) {
            return json(['code' => $exception->getErrorCode(), 'message' => $exception->getMessage(), 'data' => null], $exception->getHttpStatus());
        } catch (AssetException $exception) {
            return json(['code' => $exception->getErrorCode(), 'message' => $exception->getMessage(), 'data' => null], $exception->getHttpStatus());
        } catch (C2cException $exception) {
            return json(['code' => $exception->getErrorCode(), 'message' => $exception->getMessage(), 'data' => null], $exception->getHttpStatus());
        } catch (\Throwable $exception) {
            Log::error('C2C error class=' . get_class($exception) . ' message=' . $exception->getMessage() . ' file=' . $exception->getFile() . ' line=' . $exception->getLine());
            return json(['code' => 'C2C_INTERNAL_ERROR', 'message' => 'C2C 服务暂时不可用，请稍后重试', 'data' => null], 500);
        }
    }

    private function binary(callable $callback)
    {
        try {
            $this->assertAllowedOrigin();
            $file = $callback(new C2cService($this->request));
            return response((string) $file['body'], 200, [
                'Content-Type' => (string) ($file['content_type'] ?? 'image/webp'),
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => 'inline',
            ]);
        } catch (AuthException $exception) {
            return response($exception->getMessage(), $exception->getHttpStatus());
        } catch (AssetException $exception) {
            return response($exception->getMessage(), $exception->getHttpStatus());
        } catch (C2cException $exception) {
            return response($exception->getMessage(), $exception->getHttpStatus());
        } catch (\Throwable $exception) {
            Log::error('C2C binary error message=' . $exception->getMessage());
            return response('C2C 文件读取失败', 500);
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
            throw new C2cException('请求来源不允许', 403, 'C2C_ORIGIN_NOT_ALLOWED');
        }
    }
}
