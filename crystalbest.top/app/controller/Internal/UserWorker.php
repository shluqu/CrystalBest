<?php

namespace app\controller\Internal;

use app\service\Internal\ServiceAuditLog;
use app\controller\Auth\BusinessAccountService;
use app\service\Asset\AssetException;
use app\service\Wallet\WalletBundleService;
use app\service\Wallet\WalletEligibilityService;
use think\facade\Db;
use think\Request;

final class UserWorker
{
    private const MAX_BODY_BYTES = 8192;
    private const MAX_REQUEST_AGE_SECONDS = 300;

    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function allocateWallet()
    {
        try {
            $this->assertLocalSource();

            $raw = (string) $this->request->getInput();
            if ($raw === '' || strlen($raw) > self::MAX_BODY_BYTES) {
                throw new AssetException(
                    $raw === '' ? '请求体为空' : '请求体过大',
                    $raw === '' ? 422 : 413,
                    $raw === '' ? 'USER_WORKER_EMPTY_BODY' : 'USER_WORKER_BODY_TOO_LARGE'
                );
            }

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw new AssetException('请求 JSON 无效', 422, 'USER_WORKER_JSON_INVALID');
            }

            $this->validateEnvelope($data);
            $uid = strtoupper(trim((string) $data['user_uid']));

            $user = Db::table('cex_user_users')
                ->where('uid', $uid)
                ->field('id,uid')
                ->find();
            if (!$user) {
                throw new AssetException('用户不存在', 404, 'USER_NOT_FOUND');
            }

            // Wallet allocation must not depend on the user having visited an asset page first.
            // If the business account has not been materialized yet, Main creates it here before
            // authoritative eligibility validation. The Node worker itself remains read-only.
            $account = Db::table('cex_account_accounts')
                ->where('user_id', (int) $user['id'])
                ->where('account_kind', 1)
                ->field('id,status')
                ->find();
            if (!$account) {
                try {
                    BusinessAccountService::createForUser((int) $user['id'], (string) $user['uid']);
                } catch (\Throwable $exception) {
                    // A simultaneous dashboard request may have created the same unique
                    // business account between our SELECT and INSERT. Treat that race as
                    // success when the account now exists; otherwise preserve the error.
                    $account = Db::table('cex_account_accounts')
                        ->where('user_id', (int) $user['id'])
                        ->where('account_kind', 1)
                        ->field('id,status')
                        ->find();
                    if (!$account) {
                        throw $exception;
                    }
                }
            }

            // Main ThinkPHP is authoritative. The worker's read-only SQL is only
            // a candidate pre-filter; all eligibility is revalidated here.
            $eligibility = (new WalletEligibilityService())->forUser((int) $user['id']);
            if (!(bool) ($eligibility['eligible'] ?? false)) {
                throw new AssetException(
                    (string) ($eligibility['message'] ?? '当前不满足钱包分配条件'),
                    409,
                    (string) ($eligibility['code'] ?? 'WALLET_NOT_ELIGIBLE')
                );
            }

            if (!empty($eligibility['bundle'])) {
                return json([
                    'ok' => true,
                    'code' => 'ALREADY_ASSIGNED',
                    'data' => ['bundle' => $eligibility['bundle']],
                ])->header(['Cache-Control' => 'no-store']);
            }

            $bundle = (new WalletBundleService())->ensureBundle((array) $eligibility['account'], $uid);
            ServiceAuditLog::record(
                $this->request,
                'DEPOSIT_WALLET_BUNDLE_AUTO_ALLOCATED',
                (int) $user['id'],
                'wallet_bundle',
                (string) $bundle['bundle_no'],
                ['source' => 'USER_WORKER']
            );

            return json([
                'ok' => true,
                'code' => ($bundle['existing'] ?? false) ? 'ALREADY_ASSIGNED' : 'ALLOCATED',
                'data' => [
                    'bundle_no' => (string) $bundle['bundle_no'],
                    'external_bundle_id' => (string) $bundle['external_bundle_id'],
                    'address_count' => count((array) ($bundle['addresses'] ?? [])),
                ],
            ])->header(['Cache-Control' => 'no-store']);
        } catch (AssetException $e) {
            return json([
                'ok' => false,
                'code' => $e->getErrorCode(),
                'message' => $e->getMessage(),
                'data' => null,
            ], $e->getHttpStatus())->header(['Cache-Control' => 'no-store']);
        } catch (\Throwable $e) {
            return json([
                'ok' => false,
                'code' => 'INTERNAL_ERROR',
                'message' => '后台钱包分配失败',
                'data' => null,
            ], 500)->header(['Cache-Control' => 'no-store']);
        }
    }

    private function assertLocalSource(): void
    {
        $allowed = array_values((array) config('user_worker.allowed_ips', ['127.0.0.1', '::1']));
        $remoteIp = trim((string) $this->request->server('REMOTE_ADDR', ''));
        if ($remoteIp === '' || !in_array($remoteIp, $allowed, true)) {
            throw new AssetException(
                'User worker endpoint only accepts local requests',
                403,
                'USER_WORKER_LOCAL_ONLY'
            );
        }
    }

    private function validateEnvelope(array $data): void
    {
        $uid = strtoupper(trim((string) ($data['user_uid'] ?? '')));
        if (!preg_match('/^[A-HJ-NP-Z2-9]{16}$/', $uid)) {
            throw new AssetException('用户 UID 无效', 422, 'USER_WORKER_UID_INVALID');
        }

        $reason = strtoupper(trim((string) ($data['reason'] ?? '')));
        if ($reason !== 'ELIGIBILITY_SCAN') {
            throw new AssetException('请求 reason 无效', 422, 'USER_WORKER_REASON_INVALID');
        }

        $requestId = trim((string) ($data['request_id'] ?? ''));
        if ($requestId === '' || strlen($requestId) > 64 || !preg_match('/^[A-Za-z0-9:_\-.]+$/', $requestId)) {
            throw new AssetException('request_id 无效', 422, 'USER_WORKER_REQUEST_ID_INVALID');
        }

        $requestedAt = trim((string) ($data['requested_at'] ?? ''));
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $requestedAt, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$dt || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
            throw new AssetException('requested_at 必须是 UTC datetime(6)', 422, 'USER_WORKER_TIME_INVALID');
        }

        $age = abs(time() - $dt->getTimestamp());
        if ($age > self::MAX_REQUEST_AGE_SECONDS) {
            throw new AssetException('requested_at 已过期', 422, 'USER_WORKER_TIME_STALE');
        }
    }
}
