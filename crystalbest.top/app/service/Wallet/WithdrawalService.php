<?php

namespace app\service\Wallet;

use app\controller\Auth\AuthService;
use app\controller\Auth\BusinessAccountService;
use app\controller\Auth\Crypto;
use app\controller\Auth\TotpService;
use app\controller\Auth\Ulid;
use app\controller\Auth\UtcClock;
use app\service\Asset\AssetException;
use app\service\Asset\Decimal18;
use app\service\Asset\LedgerService;
use think\facade\Cache;
use think\facade\Db;
use think\Request;

final class WithdrawalService
{
    public const STATUS_PENDING_REVIEW = 1;
    public const STATUS_APPROVED = 2;
    public const STATUS_PAYOUT_PROCESSING = 3;
    public const STATUS_BROADCASTED = 4;
    public const STATUS_COMPLETED = 5;
    public const STATUS_REJECTED = 6;
    public const STATUS_FAILED = 7;
    public const STATUS_CANCELLED = 8;
    public const STATUS_REFUNDED = 9;

    private $request;
    private $authContext;
    private $businessAccount;
    private $ledger;
    private $validator;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->ledger = new LedgerService();
        $this->validator = new WithdrawalAddressValidator();
    }

    public function context(): array
    {
        $auth = $this->authContext();
        $account = $this->businessAccount();
        $security = $this->securityState((int) $auth['user_id']);
        $routes = $this->withdrawRoutes((int) $account['id']);

        return [
            'manual_review' => true,
            'security' => [
                'email_verified' => !empty($auth['email_verified_at']),
                'kyc_level' => (int) $auth['kyc_level'],
                'totp_enabled' => (bool) $security['totp_enabled'],
                'totp_required' => (bool) $security['withdraw_mfa_required'],
                'whitelist_enabled' => (bool) $security['withdraw_whitelist_enabled'],
            ],
            'routes' => $routes,
            'history' => $this->recentWithdrawals((int) $account['id'], (int) config('wallet.withdrawal.history_limit', 30)),
            'policy' => [
                'debit_scope' => 'SPOT_AVAILABLE',
                'funds_locked_on_submit' => true,
                'manual_review' => true,
                'manual_payout' => true,
                'address_format_validation' => true,
            ],
        ];
    }

    public function requestWithdrawal(array $payload): array
    {
        $account = $this->businessAccount();
        $auth = $this->authContext();
        $route = $this->routeByCode((string) ($payload['route_code'] ?? ''), (int) $account['id']);
        $this->assertRouteAvailable($route);

        $clientRequestId = $this->clientRequestId((string) ($payload['client_request_id'] ?? ''));
        $idempotencyKey = 'withdraw-request:' . (int) $account['id'] . ':' . $clientRequestId;
        $normalizedDestination = $this->validator->validate(
            (string) $route['network_code'],
            (string) ($payload['destination_address'] ?? ''),
            (bool) $route['memo_required'],
            array_key_exists('destination_memo', $payload) ? (string) $payload['destination_memo'] : null
        );
        $receiveAmount = Decimal18::positive((string) ($payload['receive_amount'] ?? ''));
        $platformFee = Decimal18::normalize((string) $route['withdraw_fee']);
        $grossDebit = Decimal18::add($receiveAmount, $platformFee);

        $this->assertAmountPolicy($route, $receiveAmount);

        $existing = Db::table('cex_wallet_withdrawals')
            ->where('account_id', (int) $account['id'])
            ->where('idempotency_key', $idempotencyKey)
            ->find();
        if ($existing) {
            $this->assertSameRequest($existing, $route, $normalizedDestination, $receiveAmount, $platformFee, $grossDebit);
            return $this->withdrawalSummary($existing, $route, true);
        }

        $this->rateLimit('user:' . (int) $auth['user_id'], 10, 3600);
        $this->rateLimit('ip:' . $this->clientIp(), 30, 3600);
        $security = $this->assertEligibilityAndVerifyTotp((int) $auth['user_id'], (string) ($payload['totp_code'] ?? ''));
        $addressRecord = $this->resolveWithdrawalAddress(
            (int) $account['id'],
            (int) $route['asset_network_id'],
            $normalizedDestination,
            (bool) $security['withdraw_whitelist_enabled']
        );

        $availableLedger = $this->ledger->ensureLedgerAccount(
            (int) $account['id'], (int) $route['asset_id'], LedgerService::SCOPE_SPOT, LedgerService::BUCKET_AVAILABLE
        );
        $lockedLedger = $this->ledger->ensureLedgerAccount(
            (int) $account['id'], (int) $route['asset_id'], LedgerService::SCOPE_SPOT, LedgerService::BUCKET_LOCKED
        );

        $result = Db::transaction(function () use (
            $account, $auth, $route, $normalizedDestination, $receiveAmount, $platformFee, $grossDebit,
            $idempotencyKey, $addressRecord, $availableLedger, $lockedLedger
        ) {
            $concurrent = Db::table('cex_wallet_withdrawals')
                ->where('account_id', (int) $account['id'])
                ->where('idempotency_key', $idempotencyKey)
                ->lock(true)
                ->find();
            if ($concurrent) {
                $this->assertSameRequest($concurrent, $route, $normalizedDestination, $receiveAmount, $platformFee, $grossDebit);
                return ['row' => $concurrent, 'existing' => true];
            }

            $state = $this->ledger->lockBalanceForDimensions(
                (int) $account['id'], (int) $route['asset_id'], LedgerService::SCOPE_SPOT, LedgerService::BUCKET_AVAILABLE
            );
            if (Decimal18::compare((string) $state['balance'], $grossDebit) < 0) {
                throw new AssetException('可用余额不足，无法提交提币申请', 422, 'WITHDRAW_INSUFFICIENT_BALANCE');
            }

            $now = UtcClock::now();
            $withdrawalNo = Ulid::generate();
            $requestId = Ulid::generate();
            try {
                $withdrawalId = (int) Db::table('cex_wallet_withdrawals')->insertGetId([
                    'withdrawal_no' => $withdrawalNo,
                    'account_id' => (int) $account['id'],
                    'asset_network_id' => (int) $route['asset_network_id'],
                    'withdrawal_address_id' => $addressRecord ? (int) $addressRecord['id'] : null,
                    'destination_address' => $normalizedDestination['address'],
                    'destination_memo' => $normalizedDestination['memo'],
                    'receive_amount' => $receiveAmount,
                    'platform_fee' => $platformFee,
                    'gross_debit_amount' => $grossDebit,
                    'estimated_network_fee' => null,
                    'actual_network_fee' => null,
                    'status' => self::STATUS_PENDING_REVIEW,
                    'idempotency_key' => $idempotencyKey,
                    'request_id' => $requestId,
                    'risk_decision_code' => 'MANUAL_REVIEW_REQUIRED',
                    'requested_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (\Throwable $exception) {
                $concurrent = Db::table('cex_wallet_withdrawals')
                    ->where('account_id', (int) $account['id'])
                    ->where('idempotency_key', $idempotencyKey)
                    ->lock(true)
                    ->find();
                if (!$concurrent) throw $exception;
                $this->assertSameRequest($concurrent, $route, $normalizedDestination, $receiveAmount, $platformFee, $grossDebit);
                return ['row' => $concurrent, 'existing' => true];
            }

            $holdNo = Ulid::generate();
            $holdId = (int) Db::table('cex_asset_holds')->insertGetId([
                'hold_no' => $holdNo,
                'account_id' => (int) $account['id'],
                'asset_id' => (int) $route['asset_id'],
                'hold_type' => 3,
                'business_type' => 'WALLET_WITHDRAWAL',
                'business_id' => $withdrawalNo,
                'available_ledger_account_id' => (int) $availableLedger['id'],
                'locked_ledger_account_id' => (int) $lockedLedger['id'],
                'original_amount' => $grossDebit,
                'remaining_amount' => $grossDebit,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $freeze = $this->ledger->postWithinTransaction([
                'business_type' => 'WALLET_WITHDRAW_FREEZE',
                'business_id' => $withdrawalNo,
                'idempotency_key' => 'withdraw-freeze:' . $withdrawalNo,
                'request_id' => $requestId,
                'description' => 'Withdrawal request balance freeze',
                'metadata' => [
                    'withdrawal_no' => $withdrawalNo,
                    'route_code' => (string) $route['route_code'],
                ],
                'occurred_at' => $now,
            ], [
                [
                    'ledger_account_id' => (int) $availableLedger['id'],
                    'asset_id' => (int) $route['asset_id'],
                    'direction' => LedgerService::DIRECTION_DECREASE,
                    'amount' => $grossDebit,
                ],
                [
                    'ledger_account_id' => (int) $lockedLedger['id'],
                    'asset_id' => (int) $route['asset_id'],
                    'direction' => LedgerService::DIRECTION_INCREASE,
                    'amount' => $grossDebit,
                ],
            ]);

            Db::table('cex_wallet_withdrawals')->where('id', $withdrawalId)->update([
                'hold_id' => $holdId,
                'freeze_ledger_transaction_id' => (int) $freeze['id'],
                'updated_at' => $now,
            ]);

            $this->recordAction(
                $withdrawalId,
                'REQUESTED',
                1,
                (string) $auth['uid'],
                $requestId,
                $this->clientIp(),
                '用户提交提币申请',
                ['route_code' => (string) $route['route_code']]
            );

            $row = Db::table('cex_wallet_withdrawals')->where('id', $withdrawalId)->find();
            return ['row' => $row, 'existing' => false];
        });

        if ((bool) $security['withdraw_mfa_required']) {
            $this->markTotpReplay((int) $auth['user_id'], (string) ($payload['totp_code'] ?? ''));
        }

        return $this->withdrawalSummary($result['row'], $route, (bool) $result['existing']);
    }

    public function cancel(string $withdrawalNo): array
    {
        $account = $this->businessAccount();
        $withdrawalNo = $this->withdrawalNo($withdrawalNo);

        $row = Db::transaction(function () use ($account, $withdrawalNo) {
            $withdrawal = Db::table('cex_wallet_withdrawals')
                ->where('withdrawal_no', $withdrawalNo)
                ->where('account_id', (int) $account['id'])
                ->lock(true)
                ->find();
            if (!$withdrawal) {
                throw new AssetException('提币申请不存在', 404, 'WITHDRAW_NOT_FOUND');
            }
            if ((int) $withdrawal['status'] === self::STATUS_CANCELLED) {
                return $withdrawal;
            }
            if ((int) $withdrawal['status'] !== self::STATUS_PENDING_REVIEW) {
                throw new AssetException('当前提币状态不允许取消', 409, 'WITHDRAW_CANCEL_NOT_ALLOWED');
            }

            $this->refundLockedWithdrawal($withdrawal, self::STATUS_CANCELLED, 'USER_CANCELLED', '用户取消提币申请', 1, $this->authContext()['uid']);
            return Db::table('cex_wallet_withdrawals')->where('id', (int) $withdrawal['id'])->find();
        });

        return $this->historyRow($row);
    }

    public function recentWithdrawals(int $accountId, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $rows = Db::table('cex_wallet_withdrawals')->alias('w')
            ->join('cex_asset_asset_networks an', 'an.id = w.asset_network_id')
            ->join('cex_asset_assets a', 'a.id = an.asset_id')
            ->join('cex_asset_networks n', 'n.id = an.network_id')
            ->leftJoin('cex_wallet_chain_transactions ct', 'ct.id = w.chain_transaction_id')
            ->where('w.account_id', $accountId)
            ->field('w.*,an.route_code,a.code AS asset_code,a.display_decimals,n.code AS network_code,n.name AS network_name,n.explorer_tx_url,ct.tx_hash')
            ->order('w.id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row = $this->historyRow($row);
        }
        unset($row);
        return $rows;
    }

    private function withdrawRoutes(int $accountId): array
    {
        $allowedAssets = array_values((array) config('assets.overview_assets', ['USDT', 'BTC', 'ETH', 'DOGE', 'SOL']));
        $enabledRoutes = array_values((array) config('wallet.withdrawal.enabled_routes', [
            'BTC-BITCOIN', 'ETH-ETHEREUM', 'USDT-ERC20', 'USDT-TRC20', 'DOGE-DOGECOIN', 'SOL-SOLANA',
        ]));

        $rows = Db::table('cex_asset_asset_networks')->alias('an')
            ->join('cex_asset_assets a', 'a.id = an.asset_id')
            ->join('cex_asset_networks n', 'n.id = an.network_id')
            ->whereIn('a.code', $allowedAssets)
            ->whereIn('an.route_code', $enabledRoutes)
            ->where('a.status', 1)
            ->field([
                'an.id AS asset_network_id','an.route_code','an.token_standard','an.min_withdraw_amount','an.max_withdraw_amount','an.withdraw_fee','an.memo_required','an.status AS route_status','an.asset_decimals_on_chain',
                'a.id AS asset_id','a.code AS asset_code','a.name AS asset_name','a.display_decimals','a.withdraw_enabled',
                'n.id AS network_id','n.code AS network_code','n.name AS network_name','n.status AS network_status',
            ])
            ->order('a.id', 'asc')
            ->order('an.id', 'asc')
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['asset_network_id'] = (int) $row['asset_network_id'];
            $row['asset_id'] = (int) $row['asset_id'];
            $row['network_id'] = (int) $row['network_id'];
            $row['display_decimals'] = (int) $row['display_decimals'];
            $row['route_status'] = (int) $row['route_status'];
            $row['network_status'] = (int) $row['network_status'];
            $row['withdraw_enabled'] = (bool) $row['withdraw_enabled'];
            $row['memo_required'] = (bool) $row['memo_required'];
            $row['min_withdraw_amount'] = Decimal18::normalize((string) $row['min_withdraw_amount']);
            $row['max_withdraw_amount'] = $row['max_withdraw_amount'] === null ? null : Decimal18::normalize((string) $row['max_withdraw_amount']);
            $row['withdraw_fee'] = Decimal18::normalize((string) $row['withdraw_fee']);
            $row['available_balance'] = $this->ledger->balanceForDimensions(
                $accountId, (int) $row['asset_id'], LedgerService::SCOPE_SPOT, LedgerService::BUCKET_AVAILABLE
            );

            $reasons = [];
            if (!$row['withdraw_enabled']) $reasons[] = '资产提币未启用';
            if ($row['network_status'] !== 1) $reasons[] = '网络维护中';
            if ($row['route_status'] !== 1) $reasons[] = '提币路线维护中';
            $row['available'] = $reasons === [];
            $row['availability_reason'] = $row['available'] ? '可提币' : implode('；', $reasons);
            $row['min_withdraw_display'] = Decimal18::trim($row['min_withdraw_amount'], $row['display_decimals']);
            $row['max_withdraw_display'] = $row['max_withdraw_amount'] === null ? null : Decimal18::trim($row['max_withdraw_amount'], $row['display_decimals']);
            $row['withdraw_fee_display'] = Decimal18::trim($row['withdraw_fee'], $row['display_decimals']);
            $row['available_balance_display'] = Decimal18::trim($row['available_balance'], $row['display_decimals']);
        }
        unset($row);
        return $rows;
    }

    private function routeByCode(string $routeCode, int $accountId): array
    {
        $routeCode = strtoupper(trim($routeCode));
        if ($routeCode === '' || strlen($routeCode) > 48 || !preg_match('/^[A-Z0-9\-]+$/', $routeCode)) {
            throw new AssetException('提币网络参数无效', 422, 'WITHDRAW_ROUTE_INVALID');
        }
        foreach ($this->withdrawRoutes($accountId) as $route) {
            if ((string) $route['route_code'] === $routeCode) return $route;
        }
        throw new AssetException('该提币网络不存在', 404, 'WITHDRAW_ROUTE_NOT_FOUND');
    }

    private function assertRouteAvailable(array $route): void
    {
        if (!(bool) ($route['available'] ?? false)) {
            throw new AssetException('该提币网络当前维护中', 409, 'WITHDRAW_ROUTE_UNAVAILABLE');
        }
    }

    private function assertAmountPolicy(array $route, string $receiveAmount): void
    {
        if (Decimal18::compare($receiveAmount, (string) $route['min_withdraw_amount']) < 0) {
            throw new AssetException('提币数量低于当前网络最小提币数量', 422, 'WITHDRAW_BELOW_MINIMUM');
        }
        if ($route['max_withdraw_amount'] !== null
            && Decimal18::compare($receiveAmount, (string) $route['max_withdraw_amount']) > 0) {
            throw new AssetException('提币数量超过当前网络单笔上限', 422, 'WITHDRAW_ABOVE_MAXIMUM');
        }
    }

    private function assertEligibilityAndVerifyTotp(int $userId, string $totpCode): array
    {
        $auth = $this->authContext();
        if ((int) $auth['user_status'] !== 1) {
            throw new AssetException('账户当前不可提交提币申请', 409, 'WITHDRAW_USER_UNAVAILABLE');
        }
        if (empty($auth['email_verified_at'])) {
            throw new AssetException('请先完成安全邮箱验证', 409, 'WITHDRAW_EMAIL_REQUIRED');
        }
        if ((int) $auth['kyc_level'] < 1) {
            throw new AssetException('请先完成实名认证', 409, 'WITHDRAW_KYC_REQUIRED');
        }
        $approvedKyc = Db::table('cex_user_kyc')
            ->where('user_id', $userId)
            ->where('status', 3)
            ->whereNotNull('approved_at')
            ->whereRaw('(expires_at IS NULL OR expires_at > UTC_TIMESTAMP(6))')
            ->field('id')
            ->find();
        if (!$approvedKyc) {
            throw new AssetException('实名认证当前不可用于提币，请重新完成认证', 409, 'WITHDRAW_KYC_NOT_APPROVED');
        }

        $restrictionType = (int) config('wallet.withdrawal.restriction_type', 4);
        $restriction = Db::table('cex_user_restrictions')
            ->where('user_id', $userId)
            ->where('restriction_type', $restrictionType)
            ->where('status', 1)
            ->where('starts_at', '<=', UtcClock::now())
            ->whereRaw('(expires_at IS NULL OR expires_at > UTC_TIMESTAMP(6))')
            ->field('id,reason_code')
            ->find();
        if ($restriction) {
            throw new AssetException('账户提币功能当前受限，请联系支持', 403, 'WITHDRAW_RESTRICTED');
        }

        $security = $this->securityState($userId);
        if ((bool) $security['withdraw_mfa_required']) {
            if (!(bool) $security['totp_enabled'] || empty($security['totp_secret_ciphertext'])) {
                throw new AssetException('请先绑定并验证 Google Authenticator', 409, 'WITHDRAW_TOTP_REQUIRED');
            }
            $code = trim($totpCode);
            if (!preg_match('/^\d{6}$/', $code)) {
                throw new AssetException('请输入 6 位 Google Authenticator 动态验证码', 422, 'WITHDRAW_TOTP_CODE_REQUIRED');
            }
            if (Cache::get($this->totpReplayKey($userId, $code))) {
                throw new AssetException('该动态验证码已使用，请等待下一组验证码', 422, 'WITHDRAW_TOTP_REPLAYED');
            }
            $secret = Crypto::decryptTotpSecret((string) $security['totp_secret_ciphertext']);
            if (!(new TotpService())->verify($secret, $code)) {
                throw new AssetException('Google Authenticator 验证码不正确', 422, 'WITHDRAW_TOTP_INVALID');
            }
        }
        return $security;
    }

    private function securityState(int $userId): array
    {
        $row = Db::table('cex_user_security')->where('user_id', $userId)->find();
        if (!$row) {
            Db::table('cex_user_security')->insert(['user_id' => $userId]);
            $row = Db::table('cex_user_security')->where('user_id', $userId)->find();
        }
        return is_array($row) ? $row : [
            'totp_enabled' => 0,
            'withdraw_mfa_required' => 1,
            'withdraw_whitelist_enabled' => 0,
        ];
    }

    private function resolveWithdrawalAddress(int $accountId, int $assetNetworkId, array $destination, bool $whitelistEnabled): ?array
    {
        $query = Db::table('cex_wallet_withdrawal_addresses')
            ->where('account_id', $accountId)
            ->where('asset_network_id', $assetNetworkId)
            ->where('address_hash', $destination['address_hash'])
            ->where('memo_hash', $destination['memo_hash']);
        $existing = $query->find();

        if ($whitelistEnabled) {
            if (!$existing || (int) $existing['status'] !== 2 || !(bool) $existing['whitelisted']) {
                throw new AssetException('当前账户已启用提币地址白名单，请使用已验证白名单地址', 409, 'WITHDRAW_WHITELIST_REQUIRED');
            }
            if (!empty($existing['security_delay_until']) && (string) $existing['security_delay_until'] > UtcClock::now()) {
                throw new AssetException('该白名单地址仍处于安全等待期', 409, 'WITHDRAW_ADDRESS_SECURITY_DELAY');
            }
            return $existing;
        }

        if ($existing) {
            if ((int) $existing['status'] === 3) {
                throw new AssetException('该提币地址已被停用', 409, 'WITHDRAW_ADDRESS_DISABLED');
            }
            return $existing;
        }

        try {
            $id = (int) Db::table('cex_wallet_withdrawal_addresses')->insertGetId([
                'account_id' => $accountId,
                'asset_network_id' => $assetNetworkId,
                'label' => null,
                'address' => $destination['address'],
                'address_hash' => $destination['address_hash'],
                'memo' => $destination['memo'],
                'memo_hash' => $destination['memo_hash'],
                'status' => 1,
                'whitelisted' => 0,
            ]);
            return Db::table('cex_wallet_withdrawal_addresses')->where('id', $id)->find();
        } catch (\Throwable $exception) {
            $existing = Db::table('cex_wallet_withdrawal_addresses')
                ->where('account_id', $accountId)
                ->where('asset_network_id', $assetNetworkId)
                ->where('address_hash', $destination['address_hash'])
                ->where('memo_hash', $destination['memo_hash'])
                ->find();
            if (!$existing) throw $exception;
            return $existing;
        }
    }

    private function assertSameRequest(array $existing, array $route, array $destination, string $receiveAmount, string $fee, string $gross): void
    {
        if ((int) $existing['asset_network_id'] !== (int) $route['asset_network_id']
            || (string) $existing['destination_address'] !== (string) $destination['address']
            || (string) ($existing['destination_memo'] ?? '') !== (string) ($destination['memo'] ?? '')
            || Decimal18::compare((string) $existing['receive_amount'], $receiveAmount) !== 0
            || Decimal18::compare((string) $existing['platform_fee'], $fee) !== 0
            || Decimal18::compare((string) $existing['gross_debit_amount'], $gross) !== 0) {
            throw new AssetException('该请求编号已用于另一笔提币参数', 409, 'WITHDRAW_IDEMPOTENCY_CONFLICT');
        }
    }

    private function withdrawalSummary(array $row, array $route, bool $existing): array
    {
        return [
            'withdrawal_no' => (string) $row['withdrawal_no'],
            'asset_code' => (string) $route['asset_code'],
            'route_code' => (string) $route['route_code'],
            'network_name' => (string) $route['network_name'],
            'receive_amount' => Decimal18::trim((string) $row['receive_amount'], (int) $route['display_decimals']),
            'platform_fee' => Decimal18::trim((string) $row['platform_fee'], (int) $route['display_decimals']),
            'gross_debit_amount' => Decimal18::trim((string) $row['gross_debit_amount'], (int) $route['display_decimals']),
            'status' => (int) $row['status'],
            'status_label' => $this->statusLabel((int) $row['status']),
            'requested_at' => (string) $row['requested_at'],
            'duplicate' => $existing,
            'message' => '提币申请已提交，处理期间相关资金保持锁定。',
        ];
    }

    private function historyRow(array $row): array
    {
        $displayDecimals = isset($row['display_decimals']) ? (int) $row['display_decimals'] : 8;
        $status = (int) $row['status'];
        $networkCode = strtoupper((string) ($row['network_code'] ?? ''));
        $txHash = trim((string) ($row['tx_hash'] ?? ''));
        $row['status'] = $status;
        $row['status_label'] = $this->statusLabel($status);
        $row['receive_amount'] = Decimal18::trim((string) $row['receive_amount'], $displayDecimals);
        $row['platform_fee'] = Decimal18::trim((string) $row['platform_fee'], $displayDecimals);
        $row['gross_debit_amount'] = Decimal18::trim((string) $row['gross_debit_amount'], $displayDecimals);
        $row['can_cancel'] = $status === self::STATUS_PENDING_REVIEW;
        $row['destination_short'] = $this->shortAddress((string) $row['destination_address']);
        $row['explorer_tx_url'] = $txHash !== '' ? $this->explorerTxUrl($networkCode, $txHash, $row['explorer_tx_url'] ?? null) : null;
        return $row;
    }

    private function refundLockedWithdrawal(array $withdrawal, int $targetStatus, string $failureCode, string $note, int $actorType, string $actorRef): void
    {
        if (empty($withdrawal['hold_id']) || empty($withdrawal['freeze_ledger_transaction_id'])) {
            throw new AssetException('提币冻结记录不完整，已停止自动退回', 500, 'WITHDRAW_HOLD_MISSING');
        }
        if (!empty($withdrawal['settle_ledger_transaction_id']) || !empty($withdrawal['chain_transaction_id'])) {
            throw new AssetException('链上打款已经开始，不能自动退回资金', 409, 'WITHDRAW_ALREADY_BROADCAST');
        }

        $hold = Db::table('cex_asset_holds')->where('id', (int) $withdrawal['hold_id'])->lock(true)->find();
        if (!$hold) throw new AssetException('提币冻结记录不存在', 500, 'WITHDRAW_HOLD_NOT_FOUND');

        $refund = $this->ledger->postWithinTransaction([
            'business_type' => 'WALLET_WITHDRAW_REFUND',
            'business_id' => (string) $withdrawal['withdrawal_no'],
            'idempotency_key' => 'withdraw-refund:' . (string) $withdrawal['withdrawal_no'],
            'reversed_transaction_id' => (int) $withdrawal['freeze_ledger_transaction_id'],
            'request_id' => Ulid::generate(),
            'description' => 'Withdrawal balance refund',
            'metadata' => ['reason' => $failureCode],
            'occurred_at' => UtcClock::now(),
        ], [
            [
                'ledger_account_id' => (int) $hold['locked_ledger_account_id'],
                'asset_id' => (int) $hold['asset_id'],
                'direction' => LedgerService::DIRECTION_DECREASE,
                'amount' => (string) $withdrawal['gross_debit_amount'],
            ],
            [
                'ledger_account_id' => (int) $hold['available_ledger_account_id'],
                'asset_id' => (int) $hold['asset_id'],
                'direction' => LedgerService::DIRECTION_INCREASE,
                'amount' => (string) $withdrawal['gross_debit_amount'],
            ],
        ]);

        $now = UtcClock::now();
        Db::table('cex_asset_holds')->where('id', (int) $hold['id'])->update([
            'remaining_amount' => Decimal18::zero(),
            'status' => 4,
            'released_at' => $now,
            'updated_at' => $now,
        ]);
        Db::table('cex_wallet_withdrawals')->where('id', (int) $withdrawal['id'])->update([
            'status' => $targetStatus,
            'refund_ledger_transaction_id' => (int) $refund['id'],
            'failure_code' => $failureCode,
            'completed_at' => $now,
            'updated_at' => $now,
        ]);
        $this->recordAction((int) $withdrawal['id'], $targetStatus === self::STATUS_CANCELLED ? 'CANCELLED' : 'REJECTED', $actorType, $actorRef, Ulid::generate(), $this->clientIp(), $note, ['failure_code' => $failureCode]);
    }

    private function statusLabel(int $status): string
    {
        $labels = [
            self::STATUS_PENDING_REVIEW => '处理中',
            self::STATUS_APPROVED => '已受理',
            self::STATUS_PAYOUT_PROCESSING => '链上处理中',
            self::STATUS_BROADCASTED => '已提交链上',
            self::STATUS_COMPLETED => '已完成',
            self::STATUS_REJECTED => '未通过',
            self::STATUS_FAILED => '处理失败',
            self::STATUS_CANCELLED => '已取消',
            self::STATUS_REFUNDED => '已退回',
        ];
        return $labels[$status] ?? '未知';
    }

    private function explorerTxUrl(string $networkCode, string $txHash, $databaseTemplate): ?string
    {
        $template = trim((string) ($databaseTemplate ?? ''));
        if ($template === '') {
            $templates = (array) config('wallet.explorer_tx_urls', []);
            $template = trim((string) ($templates[$networkCode] ?? ''));
        }
        if ($template === '' || strpos($template, '{tx}') === false) return null;
        $url = str_replace('{tx}', rawurlencode($txHash), $template);
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || strtolower((string) $parts['scheme']) !== 'https') return null;
        return $url;
    }

    private function shortAddress(string $address): string
    {
        if (strlen($address) <= 18) return $address;
        return substr($address, 0, 9) . '…' . substr($address, -7);
    }

    private function clientRequestId(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 80 || !preg_match('/^[A-Za-z0-9_-]{16,80}$/', $value)) {
            throw new AssetException('请求标识无效，请刷新页面后重试', 422, 'WITHDRAW_CLIENT_REQUEST_ID_INVALID');
        }
        return $value;
    }

    private function withdrawalNo(string $value): string
    {
        $value = strtoupper(trim($value));
        if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value)) {
            throw new AssetException('提币编号无效', 422, 'WITHDRAW_NO_INVALID');
        }
        return $value;
    }

    private function totpReplayKey(int $userId, string $code): string
    {
        return 'withdraw:totp-replay:' . $userId . ':' . $code;
    }

    private function markTotpReplay(int $userId, string $code): void
    {
        $code = trim($code);
        if (preg_match('/^\d{6}$/', $code)) Cache::set($this->totpReplayKey($userId, $code), 1, 90);
    }

    private function recordAction(int $withdrawalId, string $actionType, int $actorType, string $actorRef, string $requestId, string $remoteIp, ?string $note, array $metadata = []): void
    {
        Db::table('cex_wallet_withdrawal_actions')->insert([
            'action_no' => Ulid::generate(),
            'withdrawal_id' => $withdrawalId,
            'action_type' => $actionType,
            'actor_type' => $actorType,
            'actor_ref' => $actorRef !== '' ? substr($actorRef, 0, 64) : null,
            'request_id' => $requestId,
            'remote_ip' => $remoteIp !== '' ? substr($remoteIp, 0, 45) : null,
            'note' => $note !== null ? substr($note, 0, 512) : null,
            'metadata_json' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => UtcClock::now(),
        ]);
    }

    private function authContext(): array
    {
        if ($this->authContext !== null) return $this->authContext;
        $auth = new AuthService($this->request);
        $cookie = (string) $this->request->cookie($auth->cookieName(), '');
        $this->authContext = $auth->authenticatedSession($cookie, true);
        return $this->authContext;
    }

    private function businessAccount(): array
    {
        if ($this->businessAccount !== null) return $this->businessAccount;
        $auth = $this->authContext();
        $userId = (int) $auth['user_id'];
        $row = Db::table('cex_account_accounts')
            ->where('user_id', $userId)
            ->where('account_kind', 1)
            ->field('id,public_id,status,user_id')
            ->find();
        if (!$row) {
            BusinessAccountService::createForUser($userId, (string) $auth['uid']);
            $row = Db::table('cex_account_accounts')
                ->where('user_id', $userId)
                ->where('account_kind', 1)
                ->field('id,public_id,status,user_id')
                ->find();
        }
        if (!$row || (int) $row['status'] !== 1) {
            throw new AssetException('资产账户当前不可用', 409, 'ASSET_ACCOUNT_UNAVAILABLE');
        }
        $this->businessAccount = $row;
        return $row;
    }

    private function rateLimit(string $key, int $limit, int $windowSeconds): void
    {
        $cacheKey = 'withdraw:rl:' . hash('sha256', $key);
        $state = Cache::get($cacheKey);
        $now = time();
        if (!is_array($state) || (int) ($state['reset_at'] ?? 0) <= $now) {
            Cache::set($cacheKey, ['count' => 1, 'reset_at' => $now + $windowSeconds], $windowSeconds);
            return;
        }
        $count = (int) ($state['count'] ?? 0);
        if ($count >= $limit) {
            throw new AssetException('提币请求过于频繁，请稍后再试', 429, 'WITHDRAW_RATE_LIMITED');
        }
        $state['count'] = $count + 1;
        Cache::set($cacheKey, $state, max(1, (int) $state['reset_at'] - $now));
    }

    private function clientIp(): string
    {
        return trim((string) $this->request->server('REMOTE_ADDR', ''));
    }
}
