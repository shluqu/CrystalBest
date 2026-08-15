<?php

namespace app\service\Asset;

use app\controller\Auth\AuditLog;
use app\controller\Auth\AuthException;
use app\controller\Auth\AuthService;
use app\controller\Auth\BusinessAccountService;
use app\controller\Auth\Ulid;
use app\controller\Auth\UtcClock;
use think\facade\Db;
use think\Request;

final class AssetService
{
    private $request;
    private $authContext;
    private $businessAccount;
    private $ledger;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->ledger = new LedgerService();
    }

    public function overview(): array
    {
        $account = $this->businessAccount();
        $codes = array_values((array) config('assets.overview_assets', ['USDT', 'BTC', 'ETH', 'DOGE', 'SOL']));

        // Keep the five custody assets visible at all times, while automatically
        // adding any other active asset the user actually owns after spot trades.
        // This avoids listing dozens of zero-balance trade-only assets by default.
        $ownedAssetIds = Db::table('cex_asset_ledger_accounts')->alias('la')
            ->join('cex_asset_balances b', 'b.ledger_account_id = la.id')
            ->where('la.account_id', (int) $account['id'])
            ->where('la.status', 1)
            ->whereRaw('b.balance <> 0')
            ->column('la.asset_id');
        $ownedAssetIds = array_values(array_unique(array_map('intval', $ownedAssetIds)));

        $assetsQuery = Db::table('cex_asset_assets')->where('status', 1);
        if ($ownedAssetIds !== []) {
            $assetsQuery->where(function ($query) use ($codes, $ownedAssetIds) {
                $query->whereIn('code', $codes)->whereOr('id', 'in', $ownedAssetIds);
            });
        } else {
            $assetsQuery->whereIn('code', $codes);
        }
        $assets = $assetsQuery
            ->field('id,code,name,display_decimals,ledger_decimals,deposit_enabled,withdraw_enabled,spot_enabled,perpetual_margin_enabled')
            ->select()
            ->toArray();

        $order = array_flip($codes);
        usort($assets, function (array $left, array $right) use ($order) {
            $leftRank = $order[$left['code']] ?? 999;
            $rightRank = $order[$right['code']] ?? 999;
            if ($leftRank !== $rightRank) return $leftRank <=> $rightRank;
            return strcmp((string) $left['code'], (string) $right['code']);
        });

        $assetIds = array_map('intval', array_column($assets, 'id'));
        $balanceRows = [];
        if ($assetIds !== []) {
            $balanceRows = Db::table('cex_asset_ledger_accounts')->alias('la')
                ->leftJoin('cex_asset_balances b', 'b.ledger_account_id = la.id')
                ->where('la.account_id', (int) $account['id'])
                ->whereIn('la.asset_id', $assetIds)
                ->where('la.status', 1)
                ->field('la.asset_id,la.account_scope,la.balance_bucket,COALESCE(b.balance,0) AS balance')
                ->select()
                ->toArray();
        }

        $balanceMap = [];
        foreach ($balanceRows as $row) {
            $assetId = (int) $row['asset_id'];
            $scope = (int) $row['account_scope'];
            $bucket = (int) $row['balance_bucket'];
            $balanceMap[$assetId][$scope][$bucket] = Decimal18::normalize((string) $row['balance']);
        }

        $routesByAsset = $this->okxRoutesByAsset($assetIds);
        $items = [];
        foreach ($assets as $asset) {
            $assetId = (int) $asset['id'];
            $spotAvailable = $balanceMap[$assetId][LedgerService::SCOPE_SPOT][LedgerService::BUCKET_AVAILABLE] ?? Decimal18::zero();
            $spotLocked = $balanceMap[$assetId][LedgerService::SCOPE_SPOT][LedgerService::BUCKET_LOCKED] ?? Decimal18::zero();
            $perpAvailable = $balanceMap[$assetId][LedgerService::SCOPE_PERPETUAL_CROSS][LedgerService::BUCKET_AVAILABLE] ?? Decimal18::zero();
            $perpLocked = $balanceMap[$assetId][LedgerService::SCOPE_PERPETUAL_CROSS][LedgerService::BUCKET_LOCKED] ?? Decimal18::zero();
            $spotTotal = Decimal18::add($spotAvailable, $spotLocked);
            $perpTotal = Decimal18::add($perpAvailable, $perpLocked);
            $total = Decimal18::add($spotTotal, $perpTotal);

            $items[] = [
                'id' => $assetId,
                'code' => (string) $asset['code'],
                'name' => (string) $asset['name'],
                'display_decimals' => (int) $asset['display_decimals'],
                'deposit_enabled' => (bool) $asset['deposit_enabled'],
                'withdraw_enabled' => (bool) $asset['withdraw_enabled'],
                'spot_enabled' => (bool) $asset['spot_enabled'],
                'perpetual_margin_enabled' => (bool) $asset['perpetual_margin_enabled'],
                'spot' => [
                    'available' => $spotAvailable,
                    'locked' => $spotLocked,
                    'total' => $spotTotal,
                ],
                'perpetual' => [
                    'available' => $perpAvailable,
                    'locked' => $perpLocked,
                    'total' => $perpTotal,
                ],
                'available_total' => Decimal18::add($spotAvailable, $perpAvailable),
                'locked_total' => Decimal18::add($spotLocked, $perpLocked),
                'total' => $total,
                'okx_routes' => $routesByAsset[$assetId] ?? [],
            ];
        }

        return [
            'custody_provider' => (string) config('assets.custody_provider', 'CrystalBest Wallet'),
            'valuation_asset' => (string) config('assets.valuation_asset', 'USDT'),
            'business_account' => [
                'public_id' => (string) $account['public_id'],
                'status' => (int) $account['status'],
            ],
            'assets' => $items,
            'ledger_policy' => [
                'source_of_truth' => 'cex_asset_ledger_entries',
                'balance_cache' => 'cex_asset_balances',
                'scope_spot' => LedgerService::SCOPE_SPOT,
                'scope_perpetual_cross' => LedgerService::SCOPE_PERPETUAL_CROSS,
                'bucket_available' => LedgerService::BUCKET_AVAILABLE,
                'bucket_locked' => LedgerService::BUCKET_LOCKED,
            ],
        ];
    }

    public function transferContext(int $historyLimit = 20): array
    {
        $account = $this->businessAccount();
        $usdt = $this->transferAsset();
        $spotAvailable = $this->ledger->balanceForDimensions(
            (int) $account['id'], (int) $usdt['id'], LedgerService::SCOPE_SPOT, LedgerService::BUCKET_AVAILABLE
        );
        $perpAvailable = $this->ledger->balanceForDimensions(
            (int) $account['id'], (int) $usdt['id'], LedgerService::SCOPE_PERPETUAL_CROSS, LedgerService::BUCKET_AVAILABLE
        );
        $openPositionCount = $this->openPerpetualPositionCount((int) $account['id']);

        return [
            'asset' => [
                'id' => (int) $usdt['id'],
                'code' => (string) $usdt['code'],
                'name' => (string) $usdt['name'],
                'display_decimals' => (int) $usdt['display_decimals'],
            ],
            'spot_available' => $spotAvailable,
            'perpetual_available' => $perpAvailable,
            'perpetual_transfer_out_enabled' => $openPositionCount === 0,
            'perpetual_transfer_out_reason' => $openPositionCount > 0
                ? '当前存在永续持仓。Phase A 在风险引擎正式接管可划出保证金前，暂时禁止从永续账户划回现货。'
                : null,
            'open_perpetual_positions' => $openPositionCount,
            'history' => $this->transferHistory((int) $account['id'], $historyLimit),
        ];
    }

    public function transfer(array $payload): array
    {
        $context = $this->authContext();
        $userId = (int) $context['user_id'];
        $amountForAudit = isset($payload['amount']) ? (string) $payload['amount'] : '';
        $directionForAudit = isset($payload['direction']) ? (string) $payload['direction'] : '';

        try {
            if (!isset($payload['amount']) || !is_string($payload['amount'])) {
                throw new AssetException('金额必须以十进制字符串提交', 422, 'AMOUNT_STRING_REQUIRED');
            }
            $amount = Decimal18::positive($payload['amount']);
            $direction = $this->parseDirection((string) ($payload['direction'] ?? ''));
            $idempotencyKey = $this->validateIdempotencyKey((string) ($payload['idempotency_key'] ?? ''));
            $account = $this->businessAccount();
            $usdt = $this->transferAsset();

            $result = Db::transaction(function () use ($account, $usdt, $amount, $direction, $idempotencyKey) {
                $accountId = (int) $account['id'];
                $assetId = (int) $usdt['id'];

                // Serialize account-level transfer decisions before locking balances.
                $lockedAccount = Db::table('cex_account_accounts')
                    ->where('id', $accountId)
                    ->field('id,status')
                    ->lock(true)
                    ->find();
                if (!$lockedAccount || (int) $lockedAccount['status'] !== 1) {
                    throw new AssetException('资产账户当前不可用', 409, 'ASSET_ACCOUNT_UNAVAILABLE');
                }

                $existing = Db::table('cex_asset_internal_transfers')
                    ->where('idempotency_key', $idempotencyKey)
                    ->field('id,transfer_no,account_id,asset_id,direction,amount,status,ledger_transaction_id,completed_at')
                    ->lock(true)
                    ->find();
                if ($existing) {
                    if ((int) $existing['account_id'] !== $accountId
                        || (int) $existing['asset_id'] !== $assetId
                        || (int) $existing['direction'] !== $direction
                        || Decimal18::compare((string) $existing['amount'], $amount) !== 0) {
                        throw new AssetException('幂等键与原划转请求不一致', 409, 'TRANSFER_IDEMPOTENCY_CONFLICT');
                    }
                    if ((int) $existing['status'] !== 2) {
                        throw new AssetException('相同划转请求仍在处理中', 409, 'TRANSFER_ALREADY_PROCESSING');
                    }
                    return [
                        'transfer_no' => (string) $existing['transfer_no'],
                        'amount' => Decimal18::normalize((string) $existing['amount']),
                        'direction' => (int) $existing['direction'],
                        'idempotent_replay' => true,
                    ];
                }

                if ($direction === 2 && $this->openPerpetualPositionCount($accountId) > 0) {
                    throw new AssetException(
                        '当前存在永续持仓；风险引擎接管可划出保证金前，暂不允许从永续账户划回现货',
                        409,
                        'PERPETUAL_TRANSFER_OUT_BLOCKED'
                    );
                }

                $fromScope = $direction === 1 ? LedgerService::SCOPE_SPOT : LedgerService::SCOPE_PERPETUAL_CROSS;
                $toScope = $direction === 1 ? LedgerService::SCOPE_PERPETUAL_CROSS : LedgerService::SCOPE_SPOT;
                $fromAccount = $this->ledger->ensureLedgerAccount(
                    $accountId, $assetId, $fromScope, LedgerService::BUCKET_AVAILABLE, false
                );
                $toAccount = $this->ledger->ensureLedgerAccount(
                    $accountId, $assetId, $toScope, LedgerService::BUCKET_AVAILABLE, false
                );

                $transferNo = Ulid::generate();
                $transferId = (int) Db::table('cex_asset_internal_transfers')->insertGetId([
                    'transfer_no' => $transferNo,
                    'account_id' => $accountId,
                    'asset_id' => $assetId,
                    'direction' => $direction,
                    'from_ledger_account_id' => (int) $fromAccount['id'],
                    'to_ledger_account_id' => (int) $toAccount['id'],
                    'amount' => $amount,
                    'status' => 1,
                    'idempotency_key' => $idempotencyKey,
                    'created_at' => UtcClock::now(),
                ]);

                $ledger = $this->ledger->postWithinTransaction([
                    'business_type' => 'INTERNAL_TRANSFER',
                    'business_id' => $transferNo,
                    'idempotency_key' => 'IT:' . hash('sha256', $idempotencyKey),
                    'description' => $direction === 1 ? 'USDT spot to perpetual transfer' : 'USDT perpetual to spot transfer',
                    'metadata' => [
                        'transfer_id' => $transferId,
                        'direction' => $direction,
                    ],
                ], [
                    [
                        'ledger_account_id' => (int) $fromAccount['id'],
                        'asset_id' => $assetId,
                        'direction' => LedgerService::DIRECTION_DECREASE,
                        'amount' => $amount,
                    ],
                    [
                        'ledger_account_id' => (int) $toAccount['id'],
                        'asset_id' => $assetId,
                        'direction' => LedgerService::DIRECTION_INCREASE,
                        'amount' => $amount,
                    ],
                ]);

                $completedAt = UtcClock::now();
                Db::table('cex_asset_internal_transfers')
                    ->where('id', $transferId)
                    ->update([
                        'status' => 2,
                        'ledger_transaction_id' => (int) $ledger['id'],
                        'completed_at' => $completedAt,
                    ]);

                return [
                    'transfer_no' => $transferNo,
                    'amount' => $amount,
                    'direction' => $direction,
                    'ledger_journal_no' => (string) $ledger['journal_no'],
                    'completed_at' => $completedAt,
                    'idempotent_replay' => false,
                ];
            });

            AuditLog::record(
                $this->request,
                'ASSET_INTERNAL_TRANSFER',
                $userId,
                1,
                'internal_transfer',
                (string) $result['transfer_no'],
                ['direction' => $result['direction'], 'amount' => $result['amount'], 'asset' => 'USDT']
            );

            $result['balances'] = $this->transferContext(5);
            return $result;
        } catch (AssetException $exception) {
            AuditLog::record(
                $this->request,
                'ASSET_INTERNAL_TRANSFER',
                $userId,
                2,
                'internal_transfer',
                null,
                ['direction' => $directionForAudit, 'amount' => $amountForAudit, 'error' => $exception->getErrorCode()]
            );
            throw $exception;
        }
    }

    private function authContext(): array
    {
        if ($this->authContext !== null) {
            return $this->authContext;
        }
        $auth = new AuthService($this->request);
        $cookie = (string) $this->request->cookie($auth->cookieName(), '');
        $this->authContext = $auth->authenticatedSession($cookie, true);
        return $this->authContext;
    }

    private function businessAccount(): array
    {
        if ($this->businessAccount !== null) {
            return $this->businessAccount;
        }
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

    private function transferAsset(): array
    {
        $code = (string) config('assets.internal_transfer_asset', 'USDT');
        $row = Db::table('cex_asset_assets')
            ->where('code', $code)
            ->where('status', 1)
            ->field('id,code,name,display_decimals,ledger_decimals,perpetual_margin_enabled')
            ->find();
        if (!$row || !(bool) $row['perpetual_margin_enabled']) {
            throw new AssetException('USDT 资金划转当前不可用', 409, 'TRANSFER_ASSET_UNAVAILABLE');
        }
        return $row;
    }

    private function okxRoutesByAsset(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }
        $provider = (string) config('assets.reference_provider', 'OKX');
        $routes = Db::table('cex_asset_asset_networks')->alias('an')
            ->join('cex_asset_networks n', 'n.id = an.network_id')
            ->whereIn('an.asset_id', $assetIds)
            ->field('an.id,an.asset_id,an.route_code,an.token_standard,an.status AS local_status,n.code AS network_code,n.name AS network_name')
            ->order('an.id', 'asc')
            ->select()
            ->toArray();

        $routeIds = array_map('intval', array_column($routes, 'id'));
        $sourceMap = [];
        if ($routeIds !== []) {
            $sources = Db::table('cex_asset_network_route_sources')
                ->where('provider_code', $provider)
                ->whereIn('asset_network_id', $routeIds)
                ->field('asset_network_id,can_deposit,can_withdraw,remote_status,min_deposit_amount,min_withdraw_amount,withdraw_fee,required_confirmations')
                ->select()
                ->toArray();
            foreach ($sources as $source) {
                $sourceMap[(int) $source['asset_network_id']] = $source;
            }
        }

        $result = [];
        foreach ($routes as $route) {
            $assetId = (int) $route['asset_id'];
            $source = $sourceMap[(int) $route['id']] ?? [];
            $result[$assetId][] = [
                'route_code' => (string) $route['route_code'],
                'network_code' => (string) $route['network_code'],
                'network_name' => (string) $route['network_name'],
                'token_standard' => (string) $route['token_standard'],
                'local_status' => (int) $route['local_status'],
                'provider_status' => isset($source['remote_status']) ? (string) $source['remote_status'] : 'UNKNOWN',
                'can_deposit' => (bool) ($source['can_deposit'] ?? false),
                'can_withdraw' => (bool) ($source['can_withdraw'] ?? false),
                'min_deposit_amount' => $source['min_deposit_amount'] ?? null,
                'min_withdraw_amount' => $source['min_withdraw_amount'] ?? null,
                'withdraw_fee' => $source['withdraw_fee'] ?? null,
                'required_confirmations' => isset($source['required_confirmations']) ? (int) $source['required_confirmations'] : null,
            ];
        }
        return $result;
    }

    private function transferHistory(int $accountId, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $rows = Db::table('cex_asset_internal_transfers')->alias('t')
            ->join('cex_asset_assets a', 'a.id = t.asset_id')
            ->leftJoin('cex_asset_ledger_transactions lt', 'lt.id = t.ledger_transaction_id')
            ->where('t.account_id', $accountId)
            ->field('t.transfer_no,t.direction,t.amount,t.status,t.failure_code,t.created_at,t.completed_at,a.code AS asset_code,lt.journal_no')
            ->order('t.id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['direction'] = (int) $row['direction'];
            $row['direction_label'] = (int) $row['direction'] === 1 ? '现货 → 永续' : '永续 → 现货';
            $row['status'] = (int) $row['status'];
            $row['status_label'] = [1 => '处理中', 2 => '已完成', 3 => '失败', 4 => '已取消'][$row['status']] ?? '未知';
            $row['amount'] = Decimal18::normalize((string) $row['amount']);
        }
        unset($row);
        return $rows;
    }

    private function openPerpetualPositionCount(int $accountId): int
    {
        return (int) Db::table('cex_perp_positions')
            ->where('account_id', $accountId)
            ->where('position_quantity', '<>', 0)
            ->count();
    }

    private function parseDirection(string $direction): int
    {
        $direction = strtolower(trim($direction));
        if ($direction === 'spot_to_perpetual') return 1;
        if ($direction === 'perpetual_to_spot') return 2;
        throw new AssetException('划转方向无效', 422, 'INVALID_TRANSFER_DIRECTION');
    }

    private function validateIdempotencyKey(string $key): string
    {
        $key = trim($key);
        if (strlen($key) < 16 || strlen($key) > 110 || !preg_match('/^[A-Za-z0-9:_\-.]+$/', $key)) {
            throw new AssetException('划转幂等键无效，请刷新页面后重试', 422, 'INVALID_IDEMPOTENCY_KEY');
        }
        return $key;
    }
}
