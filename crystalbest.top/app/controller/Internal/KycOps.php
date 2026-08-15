<?php

namespace app\controller\Internal;

use app\BaseController;
use app\controller\Auth\AuthException;
use app\service\Kyc\KycOpsGuard;
use app\service\Kyc\KycOpsService;
use think\facade\Log;

final class KycOps extends BaseController
{
    public function pending()
    {
        return $this->handle(function ($guard) {
            $limit = filter_var($this->request->get('limit', 100), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
            if ($limit === false) {
                throw new AuthException('limit 参数无效', 422, 'KYC_OPS_LIMIT_INVALID');
            }
            return ['ok' => true, 'code' => 'OK', 'data' => (new KycOpsService())->pending((int) $limit)];
        });
    }

    public function detail(string $case)
    {
        return $this->handle(function ($guard) use ($case) {
            return ['ok' => true, 'code' => 'OK', 'data' => (new KycOpsService())->detail($case)];
        });
    }

    public function document(string $case, string $slot)
    {
        try {
            (new KycOpsGuard())->inspect($this->request);
            $doc = (new KycOpsService())->document($case, $slot);
            $extension = strpos((string) $doc['content_type'], 'webp') !== false ? 'webp' : 'jpg';
            return response((string) $doc['body'], 200, [
                'Content-Type' => (string) $doc['content_type'],
                'Content-Length' => (string) $doc['bytes'],
                'Content-Disposition' => 'inline; filename="kyc-' . strtolower($slot) . '.' . $extension . '"',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ]);
        } catch (AuthException $exception) {
            return json(['ok' => false, 'code' => $exception->getErrorCode(), 'message' => $exception->getMessage(), 'data' => null], $exception->getHttpStatus())
                ->header(['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        } catch (\Throwable $exception) {
            Log::error('KYC document ops error ' . get_class($exception) . ': ' . $exception->getMessage());
            return json(['ok' => false, 'code' => 'KYC_OPS_INTERNAL_ERROR', 'message' => 'kyc ops internal error', 'data' => null], 500)
                ->header(['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        }
    }

    public function startReview(string $case)
    {
        return $this->handle(function ($guard) use ($case) {
            $p = $this->payload();
            return ['ok' => true, 'code' => 'OK', 'data' => (new KycOpsService())->startReview(
                $case, $this->operatorRef($p), $this->optionalNote($p), (string) $guard['remote_ip']
            )];
        });
    }

    public function approve(string $case)
    {
        return $this->handle(function ($guard) use ($case) {
            $p = $this->payload();
            return ['ok' => true, 'code' => 'OK', 'data' => (new KycOpsService())->approve(
                $case, $this->operatorRef($p), $this->optionalNote($p), (string) $guard['remote_ip']
            )];
        });
    }

    public function reject(string $case)
    {
        return $this->handle(function ($guard) use ($case) {
            $p = $this->payload();
            $code = strtoupper(trim((string) ($p['reason_code'] ?? 'DOCUMENT_REJECTED')));
            if ($code === '' || strlen($code) > 64 || !preg_match('/^[A-Z0-9_\-]+$/', $code)) {
                throw new AuthException('reason_code 参数无效', 422, 'KYC_OPS_REASON_CODE_INVALID');
            }
            $reason = trim((string) ($p['reason'] ?? ''));
            if ($reason === '' || mb_strlen($reason) > 512) {
                throw new AuthException('请填写有效的拒绝原因', 422, 'KYC_OPS_REASON_REQUIRED');
            }
            return ['ok' => true, 'code' => 'OK', 'data' => (new KycOpsService())->reject(
                $case, $this->operatorRef($p), $code, $reason, $this->optionalNote($p), (string) $guard['remote_ip']
            )];
        });
    }

    private function handle(callable $callback)
    {
        try {
            $guard = (new KycOpsGuard())->inspect($this->request);
            return json($callback($guard))->header(['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        } catch (AuthException $exception) {
            return json(['ok' => false, 'code' => $exception->getErrorCode(), 'message' => $exception->getMessage(), 'data' => null], $exception->getHttpStatus())
                ->header(['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        } catch (\Throwable $exception) {
            Log::error('KYC ops error ' . get_class($exception) . ': ' . $exception->getMessage());
            return json(['ok' => false, 'code' => 'KYC_OPS_INTERNAL_ERROR', 'message' => 'kyc ops internal error', 'data' => null], 500)
                ->header(['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        }
    }

    private function payload(): array
    {
        $type = strtolower(trim((string) $this->request->header('content-type', '')));
        if (strpos($type, 'application/json') === false) {
            throw new AuthException('仅接受 application/json', 415, 'KYC_OPS_CONTENT_TYPE_REQUIRED');
        }
        $raw = (string) $this->request->getInput();
        if ($raw === '') {
            return [];
        }
        if (strlen($raw) > 16384) {
            throw new AuthException('请求体过大', 413, 'KYC_OPS_BODY_TOO_LARGE');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new AuthException('JSON 无效', 422, 'KYC_OPS_JSON_INVALID');
        }
        return $decoded;
    }

    private function operatorRef(array $payload): string
    {
        $value = trim((string) ($payload['operator_ref'] ?? ''));
        if ($value === '' || strlen($value) > 64 || !preg_match('/^[A-Za-z0-9:_\-.@]+$/', $value)) {
            throw new AuthException('operator_ref 参数无效', 422, 'KYC_OPS_OPERATOR_REQUIRED');
        }
        return $value;
    }

    private function optionalNote(array $payload): ?string
    {
        $value = trim((string) ($payload['note'] ?? ''));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > 512) {
            throw new AuthException('note 内容过长', 422, 'KYC_OPS_NOTE_TOO_LONG');
        }
        return $value;
    }
}
