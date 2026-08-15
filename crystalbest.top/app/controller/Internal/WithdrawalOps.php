<?php

namespace app\controller\Internal;

use app\BaseController;
use app\service\Asset\AssetException;
use app\service\Wallet\InternalWalletRequestGuard;
use app\service\Wallet\WithdrawalOpsService;
use think\facade\Log;

final class WithdrawalOps extends BaseController
{
    public function pending()
    {
        return $this->handle(function ($guard) {
            $limit = filter_var($this->request->get('limit', 100), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
            if ($limit === false) throw new AssetException('limit 参数无效', 422, 'WITHDRAW_OPS_LIMIT_INVALID');
            return ['ok' => true, 'code' => 'OK', 'data' => (new WithdrawalOpsService())->pending((int) $limit)];
        });
    }

    public function approve(string $withdrawal)
    {
        return $this->handle(function ($guard) use ($withdrawal) {
            $p = $this->payload();
            return ['ok' => true, 'code' => 'OK', 'data' => (new WithdrawalOpsService())->approve(
                $withdrawal,
                $this->operatorRef($p),
                $this->optionalNote($p),
                (string) $guard['remote_ip']
            )];
        });
    }

    public function reject(string $withdrawal)
    {
        return $this->handle(function ($guard) use ($withdrawal) {
            $p = $this->payload();
            $reason = strtoupper(trim((string) ($p['reason_code'] ?? 'MANUAL_REJECTED')));
            if ($reason === '' || strlen($reason) > 64 || !preg_match('/^[A-Z0-9_\-]+$/', $reason)) {
                throw new AssetException('reason_code 参数无效', 422, 'WITHDRAW_OPS_REASON_INVALID');
            }
            return ['ok' => true, 'code' => 'OK', 'data' => (new WithdrawalOpsService())->reject(
                $withdrawal,
                $this->operatorRef($p),
                $reason,
                $this->optionalNote($p),
                (string) $guard['remote_ip']
            )];
        });
    }

    public function startPayout(string $withdrawal)
    {
        return $this->handle(function ($guard) use ($withdrawal) {
            $p = $this->payload();
            return ['ok' => true, 'code' => 'OK', 'data' => (new WithdrawalOpsService())->startPayout(
                $withdrawal,
                $this->operatorRef($p),
                $this->optionalNote($p),
                (string) $guard['remote_ip']
            )];
        });
    }

    public function failPayout(string $withdrawal)
    {
        return $this->handle(function ($guard) use ($withdrawal) {
            $p = $this->payload();
            $reason = strtoupper(trim((string) ($p['reason_code'] ?? 'PAYOUT_FAILED')));
            if ($reason === '' || strlen($reason) > 64 || !preg_match('/^[A-Z0-9_\-]+$/', $reason)) {
                throw new AssetException('reason_code 参数无效', 422, 'WITHDRAW_OPS_REASON_INVALID');
            }
            return ['ok' => true, 'code' => 'OK', 'data' => (new WithdrawalOpsService())->failPayout(
                $withdrawal,
                $this->operatorRef($p),
                $reason,
                $this->optionalNote($p),
                (string) $guard['remote_ip']
            )];
        });
    }

    public function broadcast(string $withdrawal)
    {
        return $this->handle(function ($guard) use ($withdrawal) {
            $p = $this->payload();
            return ['ok' => true, 'code' => 'OK', 'data' => (new WithdrawalOpsService())->broadcast(
                $withdrawal,
                $this->operatorRef($p),
                (string) ($p['tx_hash'] ?? ''),
                array_key_exists('actual_network_fee', $p) ? (string) $p['actual_network_fee'] : null,
                $this->optionalNote($p),
                (string) $guard['remote_ip']
            )];
        });
    }

    public function confirm(string $withdrawal)
    {
        return $this->handle(function ($guard) use ($withdrawal) {
            $p = $this->payload();
            $confirmations = filter_var($p['confirmations'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
            if ($confirmations === false) throw new AssetException('confirmations 参数无效', 422, 'WITHDRAW_OPS_CONFIRMATIONS_INVALID');
            return ['ok' => true, 'code' => 'OK', 'data' => (new WithdrawalOpsService())->confirm(
                $withdrawal,
                $this->operatorRef($p),
                (int) $confirmations,
                $this->optionalNote($p),
                (string) $guard['remote_ip']
            )];
        });
    }

    private function handle(callable $callback)
    {
        try {
            $guard = (new InternalWalletRequestGuard())->inspectWithdrawalOps($this->request);
            return json($callback($guard))->header(['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        } catch (AssetException $exception) {
            return json(['ok' => false, 'code' => $exception->getErrorCode(), 'message' => $exception->getMessage(), 'data' => null], $exception->getHttpStatus())
                ->header(['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        } catch (\Throwable $exception) {
            Log::error('Withdrawal ops error ' . get_class($exception) . ': ' . $exception->getMessage());
            return json(['ok' => false, 'code' => 'WITHDRAW_OPS_INTERNAL_ERROR', 'message' => 'withdrawal ops internal error', 'data' => null], 500)
                ->header(['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        }
    }

    private function payload(): array
    {
        $type = strtolower(trim((string) $this->request->header('content-type', '')));
        if (strpos($type, 'application/json') === false) throw new AssetException('仅接受 application/json', 415, 'WITHDRAW_OPS_CONTENT_TYPE_REQUIRED');
        $raw = (string) $this->request->getInput();
        if ($raw === '') return [];
        if (strlen($raw) > 16384) throw new AssetException('请求体过大', 413, 'WITHDRAW_OPS_BODY_TOO_LARGE');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) throw new AssetException('JSON 无效', 422, 'WITHDRAW_OPS_JSON_INVALID');
        return $decoded;
    }

    private function operatorRef(array $payload): string
    {
        $value = trim((string) ($payload['operator_ref'] ?? ''));
        if ($value === '' || strlen($value) > 64 || !preg_match('/^[A-Za-z0-9:_\-.@]+$/', $value)) {
            throw new AssetException('operator_ref 参数无效', 422, 'WITHDRAW_OPS_OPERATOR_REQUIRED');
        }
        return $value;
    }

    private function optionalNote(array $payload): ?string
    {
        $value = trim((string) ($payload['note'] ?? ''));
        if ($value === '') return null;
        if (mb_strlen($value) > 512) throw new AssetException('note 内容过长', 422, 'WITHDRAW_OPS_NOTE_TOO_LONG');
        return $value;
    }
}
