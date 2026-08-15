<?php

namespace app\service\Spot;

use app\controller\Auth\AuditLog;
use app\controller\Auth\AuthService;
use app\controller\Auth\BusinessAccountService;
use app\controller\Auth\Ulid;
use app\controller\Auth\UtcClock;
use app\service\Asset\AssetException;
use app\service\Asset\Decimal18;
use app\service\Asset\LedgerService;
use think\facade\Cache;
use think\facade\Db;
use think\Request;

final class SpotTradingService
{
    private const SIDE_BUY = 1;
    private const SIDE_SELL = 2;

    private const TYPE_LIMIT = 1;
    private const TYPE_MARKET = 2;

    private const STATUS_OPEN = 2;
    private const STATUS_PARTIALLY_FILLED = 3;
    private const STATUS_FILLED = 4;
    private const STATUS_CANCELLED = 5;
    private const STATUS_REJECTED = 6;
    private const STATUS_EXPIRED = 7;

    private $request;
    private $ledger;
    private $authContext;
    private $businessAccount;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->ledger = new LedgerService();
    }

    public function accountContext(string $symbol): array
    {
        $auth = $this->authContext();
        $account = $this->businessAccount();
        $market = $this->market($symbol, true);

        $base = $this->balancesForAsset((int) $account['id'], (int) $market['base_asset_id']);
        $quote = $this->balancesForAsset((int) $account['id'], (int) $market['quote_asset_id']);
        $eligible = $this->eligibility($auth, false);

        return [
            'authenticated' => true,
            'uid' => (string) $auth['uid'],
            'business_account' => [
                'public_id' => (string) $account['public_id'],
                'status' => (int) $account['status'],
            ],
            'market' => $this->formatMarket($market),
            'balances' => [
                'base' => $this->formatBalance($market, 'base', $base),
                'quote' => $this->formatBalance($market, 'quote', $quote),
            ],
            'trading' => [
                'eligible' => (bool) $eligible['eligible'],
                'reason_code' => $eligible['reason_code'],
                'reason' => $eligible['reason'],
                'limit_orders_enabled' => (bool) config('spot.limit_orders_enabled', true),
                'market_orders_enabled' => (bool) config('spot.market_orders_enabled', false),
            ],
            'open_order_count' => $this->openOrderCount((int) $account['id'], (int) $market['id']),
        ];
    }

    public function orders(string $scope, string $symbol = '', int $limit = 20): array
    {
        $account = $this->businessAccount();
        $limit = max(1, min((int) config('spot.history_limit', 50), $limit));
        $scope = strtolower(trim($scope));
        if (!in_array($scope, ['open', 'history'], true)) {
            throw new AssetException('委托查询范围无效', 422, 'SPOT_ORDER_SCOPE_INVALID');
        }

        $query = Db::table('cex_spot_orders')->alias('o')
            ->join('cex_market_spot_symbols m', 'm.id = o.symbol_id')
            ->join('cex_asset_assets ba', 'ba.id = m.base_asset_id')
            ->join('cex_asset_assets qa', 'qa.id = m.quote_asset_id')
            ->where('o.account_id', (int) $account['id']);

        $symbol = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($symbol)) ?: '');
        if ($symbol !== '') {
            $query->where('m.symbol', $symbol);
        }
        if ($scope === 'open') {
            $query->whereIn('o.status', [self::STATUS_OPEN, self::STATUS_PARTIALLY_FILLED]);
        } else {
            $query->whereIn('o.status', [self::STATUS_FILLED, self::STATUS_CANCELLED, self::STATUS_REJECTED, self::STATUS_EXPIRED]);
        }

        $rows = $query
            ->field('o.id,o.order_no,o.side,o.order_type,o.price,o.original_quantity,o.executed_quantity,o.cumulative_quote_amount,o.average_price,o.reserved_amount,o.status,o.reject_code,o.created_at,o.opened_at,o.completed_at,m.symbol,ba.code AS base_code,ba.display_decimals AS base_decimals,qa.code AS quote_code,qa.display_decimals AS quote_decimals')
            ->order('o.id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        return [
            'scope' => $scope,
            'items' => array_map([$this, 'formatOrderRow'], $rows),
        ];
    }

    public function createOrder(array $payload): array
    {
        $auth = $this->authContext();
        $account = $this->businessAccount();
        $this->rateLimit('create:user:' . (int) $auth['user_id'], (int) config('spot.rate_limit.create_per_minute', 30), 60);
        $this->assertEligible($auth);

        $symbol = (string) ($payload['symbol'] ?? '');
        $side = $this->parseSide((string) ($payload['side'] ?? ''));
        $type = strtoupper(trim((string) ($payload['type'] ?? 'LIMIT')));
        if ($type !== 'LIMIT') {
            throw new AssetException('市价委托将在撮合执行层接入后开放', 409, 'SPOT_MARKET_ORDER_NOT_ENABLED');
        }
        if (!(bool) config('spot.limit_orders_enabled', true)) {
            throw new AssetException('限价委托当前维护中', 409, 'SPOT_LIMIT_ORDER_DISABLED');
        }

        if (!isset($payload['price']) || !is_string($payload['price']) || !isset($payload['quantity']) || !is_string($payload['quantity'])) {
            throw new AssetException('价格和数量必须以十进制字符串提交', 422, 'SPOT_DECIMAL_STRING_REQUIRED');
        }
        $price = Decimal18::positive($payload['price']);
        $quantity = Decimal18::positive($payload['quantity']);
        $clientOrderId = $this->clientOrderId((string) ($payload['client_order_id'] ?? ''));
        $market = $this->market($symbol, true);

        $this->assertStepAligned($price, (string) $market['price_tick'], 'SPOT_PRICE_TICK_INVALID', '价格不符合当前交易对最小变动单位');
        $this->assertStepAligned($quantity, (string) $market['quantity_step'], 'SPOT_QUANTITY_STEP_INVALID', '数量不符合当前交易对最小变动单位');
        if (Decimal18::compare($quantity, (string) $market['min_quantity']) < 0) {
            throw new AssetException('委托数量低于最小下单数量', 422, 'SPOT_BELOW_MIN_QUANTITY');
        }
        if ($market['max_quantity'] !== null && Decimal18::compare($quantity, (string) $market['max_quantity']) > 0) {
            throw new AssetException('委托数量超过单笔最大数量', 422, 'SPOT_ABOVE_MAX_QUANTITY');
        }
        $notional = $this->multiply($price, $quantity);
        if (Decimal18::compare($notional, (string) $market['min_notional']) < 0) {
            throw new AssetException('委托金额低于最小交易金额', 422, 'SPOT_BELOW_MIN_NOTIONAL');
        }
        if ($market['max_notional'] !== null && Decimal18::compare($notional, (string) $market['max_notional']) > 0) {
            throw new AssetException('委托金额超过单笔最大交易金额', 422, 'SPOT_ABOVE_MAX_NOTIONAL');
        }

        $result = Db::transaction(function () use ($auth, $account, $market, $side, $price, $quantity, $notional, $clientOrderId) {
            $lockedAccount = Db::table('cex_account_accounts')
                ->where('id', (int) $account['id'])
                ->field('id,status')
                ->lock(true)
                ->find();
            if (!$lockedAccount || (int) $lockedAccount['status'] !== 1) {
                throw new AssetException('现货账户当前不可用', 409, 'SPOT_ACCOUNT_UNAVAILABLE');
            }

            $existing = Db::table('cex_spot_orders')
                ->where('account_id', (int) $account['id'])
                ->where('client_order_id', $clientOrderId)
                ->lock(true)
                ->find();
            if ($existing) {
                $this->assertSameOrderRequest($existing, $market, $side, $price, $quantity);
                return ['row' => $existing, 'existing' => true];
            }

            $symbolOpen = (int) Db::table('cex_spot_orders')
                ->where('account_id', (int) $account['id'])
                ->where('symbol_id', (int) $market['id'])
                ->whereIn('status', [self::STATUS_OPEN, self::STATUS_PARTIALLY_FILLED])
                ->count();
            if ($symbolOpen >= (int) config('spot.max_open_orders_per_symbol', 50)) {
                throw new AssetException('当前交易对未完成委托过多，请先撤销部分委托', 409, 'SPOT_SYMBOL_OPEN_ORDER_LIMIT');
            }
            $accountOpen = (int) Db::table('cex_spot_orders')
                ->where('account_id', (int) $account['id'])
                ->whereIn('status', [self::STATUS_OPEN, self::STATUS_PARTIALLY_FILLED])
                ->count();
            if ($accountOpen >= (int) config('spot.max_open_orders_per_account', 200)) {
                throw new AssetException('账户未完成委托数量已达到上限', 409, 'SPOT_ACCOUNT_OPEN_ORDER_LIMIT');
            }

            $reserveAssetId = $side === self::SIDE_BUY ? (int) $market['quote_asset_id'] : (int) $market['base_asset_id'];
            $reserveAmount = $side === self::SIDE_BUY ? $notional : $quantity;
            $available = $this->ledger->ensureLedgerAccount((int) $account['id'], $reserveAssetId, LedgerService::SCOPE_SPOT, LedgerService::BUCKET_AVAILABLE, false);
            $locked = $this->ledger->ensureLedgerAccount((int) $account['id'], $reserveAssetId, LedgerService::SCOPE_SPOT, LedgerService::BUCKET_LOCKED, false);
            $balance = $this->ledger->lockBalanceForDimensions((int) $account['id'], $reserveAssetId, LedgerService::SCOPE_SPOT, LedgerService::BUCKET_AVAILABLE);
            if (Decimal18::compare((string) $balance['balance'], $reserveAmount) < 0) {
                throw new AssetException('现货可用余额不足', 422, 'SPOT_INSUFFICIENT_BALANCE');
            }

            $now = UtcClock::now();
            $orderNo = Ulid::generate();
            $requestId = Ulid::generate();
            // IMPORTANT: all DECIMAL(38,18) order/hold values must bypass ThinkORM's
            // numeric field binder. In this ThinkORM version numeric strings can be coerced
            // through PHP float, which changes large 18-decimal values (for example a
            // 2,453,932 USDT reserve) by a few 1e-8 units. The ledger path already uses
            // raw SQL + CAST for the same reason; keep order + hold metadata bit-identical
            // to the amount actually posted to the ledger.
            Db::execute(
                'INSERT INTO `cex_spot_orders` '
                . '(`order_no`,`account_id`,`symbol_id`,`client_order_id`,`side`,`order_type`,`time_in_force`,`quantity_mode`,'
                . '`price`,`original_quantity`,`executed_quantity`,`cumulative_quote_amount`,`reserved_amount`,`status`,`request_id`,`created_at`,`opened_at`,`updated_at`) '
                . 'VALUES (?,?,?,?,?,1,1,1,CAST(? AS DECIMAL(38,18)),CAST(? AS DECIMAL(38,18)),'
                . 'CAST(? AS DECIMAL(38,18)),CAST(? AS DECIMAL(38,18)),CAST(? AS DECIMAL(38,18)),?,?,?,?,?)',
                [
                    $orderNo,
                    (int) $account['id'],
                    (int) $market['id'],
                    $clientOrderId,
                    $side,
                    $price,
                    $quantity,
                    Decimal18::zero(),
                    Decimal18::zero(),
                    $reserveAmount,
                    self::STATUS_OPEN,
                    $requestId,
                    $now,
                    $now,
                    $now,
                ]
            );
            $lastOrderInsert = Db::query('SELECT LAST_INSERT_ID() AS id');
            if (!isset($lastOrderInsert[0]['id']) || (int) $lastOrderInsert[0]['id'] <= 0) {
                throw new AssetException('现货委托编号获取失败', 500, 'SPOT_ORDER_ID_MISSING');
            }
            $orderId = (int) $lastOrderInsert[0]['id'];

            $holdNo = Ulid::generate();
            Db::execute(
                'INSERT INTO `cex_asset_holds` '
                . '(`hold_no`,`account_id`,`asset_id`,`hold_type`,`business_type`,`business_id`,`available_ledger_account_id`,`locked_ledger_account_id`,'
                . '`original_amount`,`remaining_amount`,`status`,`created_at`,`updated_at`) '
                . 'VALUES (?,?,?,?,?,?,?,?,CAST(? AS DECIMAL(38,18)),CAST(? AS DECIMAL(38,18)),?,?,?)',
                [
                    $holdNo,
                    (int) $account['id'],
                    $reserveAssetId,
                    1,
                    'SPOT_ORDER',
                    $orderNo,
                    (int) $available['id'],
                    (int) $locked['id'],
                    $reserveAmount,
                    $reserveAmount,
                    1,
                    $now,
                    $now,
                ]
            );
            $lastHoldInsert = Db::query('SELECT LAST_INSERT_ID() AS id');
            if (!isset($lastHoldInsert[0]['id']) || (int) $lastHoldInsert[0]['id'] <= 0) {
                throw new AssetException('现货冻结记录编号获取失败', 500, 'SPOT_HOLD_ID_MISSING');
            }
            $holdId = (int) $lastHoldInsert[0]['id'];

            $freeze = $this->ledger->postWithinTransaction([
                'business_type' => 'SPOT_ORDER_FREEZE',
                'business_id' => $orderNo,
                'idempotency_key' => 'spot-order-freeze:' . $orderNo,
                'request_id' => $requestId,
                'description' => 'Spot limit order balance reservation',
                'metadata' => [
                    'order_no' => $orderNo,
                    'symbol' => (string) $market['symbol'],
                    'side' => $side === self::SIDE_BUY ? 'BUY' : 'SELL',
                    'price' => Decimal18::trim($price),
                    'quantity' => Decimal18::trim($quantity),
                ],
                'occurred_at' => $now,
            ], [
                [
                    'ledger_account_id' => (int) $available['id'],
                    'asset_id' => $reserveAssetId,
                    'direction' => LedgerService::DIRECTION_DECREASE,
                    'amount' => $reserveAmount,
                ],
                [
                    'ledger_account_id' => (int) $locked['id'],
                    'asset_id' => $reserveAssetId,
                    'direction' => LedgerService::DIRECTION_INCREASE,
                    'amount' => $reserveAmount,
                ],
            ]);

            Db::table('cex_spot_orders')->where('id', $orderId)->update([
                'hold_id' => $holdId,
                'updated_at' => $now,
            ]);
            Db::table('cex_spot_order_events')->insert([
                'order_id' => $orderId,
                'event_sequence' => 1,
                'event_type' => 'ACCEPTED',
                'previous_status' => null,
                'new_status' => self::STATUS_OPEN,
                'request_id' => $requestId,
                'occurred_at' => $now,
                'created_at' => $now,
            ]);

            AuditLog::record($this->request, 'SPOT_ORDER_CREATED', (int) $auth['user_id'], 1, 'spot_order', $orderNo, [
                'symbol' => (string) $market['symbol'],
                'side' => $side === self::SIDE_BUY ? 'BUY' : 'SELL',
                'reserve_amount' => Decimal18::trim($reserveAmount),
                'ledger_transaction_id' => (int) $freeze['id'],
            ]);

            return ['row' => Db::table('cex_spot_orders')->where('id', $orderId)->find(), 'existing' => false];
        });

        $row = $this->orderWithMarket((int) $result['row']['id']);
        return [
            'order' => $this->formatOrderRow($row),
            'duplicate' => (bool) $result['existing'],
            'message' => (bool) $result['existing'] ? '相同委托已存在' : '限价委托已提交，相关资金已锁定',
            'account' => $this->accountContext((string) $market['symbol']),
        ];
    }

    public function cancel(string $orderNo): array
    {
        $auth = $this->authContext();
        $account = $this->businessAccount();
        $this->rateLimit('cancel:user:' . (int) $auth['user_id'], (int) config('spot.rate_limit.cancel_per_minute', 60), 60);
        $orderNo = strtoupper(trim($orderNo));
        if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $orderNo)) {
            throw new AssetException('委托编号无效', 422, 'SPOT_ORDER_NO_INVALID');
        }

        $row = Db::transaction(function () use ($auth, $account, $orderNo) {
            $order = Db::table('cex_spot_orders')
                ->where('order_no', $orderNo)
                ->where('account_id', (int) $account['id'])
                ->lock(true)
                ->find();
            if (!$order) {
                throw new AssetException('未找到该现货委托', 404, 'SPOT_ORDER_NOT_FOUND');
            }
            $status = (int) $order['status'];
            if ($status === self::STATUS_CANCELLED) {
                return $order;
            }
            if (!in_array($status, [self::STATUS_OPEN, self::STATUS_PARTIALLY_FILLED], true)) {
                throw new AssetException('当前委托状态不可撤销', 409, 'SPOT_ORDER_NOT_CANCELLABLE');
            }
            if (empty($order['hold_id'])) {
                throw new AssetException('委托冻结记录不完整，已停止自动撤单', 500, 'SPOT_ORDER_HOLD_MISSING');
            }

            $hold = Db::table('cex_asset_holds')->where('id', (int) $order['hold_id'])->lock(true)->find();
            if (!$hold) {
                throw new AssetException('委托冻结记录不存在', 500, 'SPOT_ORDER_HOLD_NOT_FOUND');
            }
            $releaseAmount = Decimal18::normalize((string) $hold['remaining_amount']);
            $now = UtcClock::now();
            if (Decimal18::compare($releaseAmount, '0') > 0) {
                $this->ledger->postWithinTransaction([
                    'business_type' => 'SPOT_ORDER_RELEASE',
                    'business_id' => (string) $order['order_no'],
                    'idempotency_key' => 'spot-order-release:' . (string) $order['order_no'],
                    'request_id' => Ulid::generate(),
                    'description' => 'Spot order cancellation release',
                    'metadata' => ['order_no' => (string) $order['order_no']],
                    'occurred_at' => $now,
                ], [
                    [
                        'ledger_account_id' => (int) $hold['locked_ledger_account_id'],
                        'asset_id' => (int) $hold['asset_id'],
                        'direction' => LedgerService::DIRECTION_DECREASE,
                        'amount' => $releaseAmount,
                    ],
                    [
                        'ledger_account_id' => (int) $hold['available_ledger_account_id'],
                        'asset_id' => (int) $hold['asset_id'],
                        'direction' => LedgerService::DIRECTION_INCREASE,
                        'amount' => $releaseAmount,
                    ],
                ]);
            }

            Db::table('cex_asset_holds')->where('id', (int) $hold['id'])->update([
                'remaining_amount' => Decimal18::zero(),
                'status' => 4,
                'released_at' => $now,
                'updated_at' => $now,
            ]);
            Db::table('cex_spot_orders')->where('id', (int) $order['id'])->update([
                'status' => self::STATUS_CANCELLED,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);
            $lastSequence = (int) Db::table('cex_spot_order_events')->where('order_id', (int) $order['id'])->max('event_sequence');
            Db::table('cex_spot_order_events')->insert([
                'order_id' => (int) $order['id'],
                'event_sequence' => $lastSequence + 1,
                'event_type' => 'CANCELLED',
                'previous_status' => $status,
                'new_status' => self::STATUS_CANCELLED,
                'request_id' => Ulid::generate(),
                'occurred_at' => $now,
                'created_at' => $now,
            ]);
            AuditLog::record($this->request, 'SPOT_ORDER_CANCELLED', (int) $auth['user_id'], 1, 'spot_order', (string) $order['order_no'], [
                'released_amount' => Decimal18::trim($releaseAmount),
            ]);
            return Db::table('cex_spot_orders')->where('id', (int) $order['id'])->find();
        });

        $full = $this->orderWithMarket((int) $row['id']);
        return [
            'order' => $this->formatOrderRow($full),
            'message' => '委托已撤销，未成交资金已返回现货可用余额',
            'account' => $this->accountContext((string) $full['symbol']),
        ];
    }

    private function market(string $symbol, bool $mustTrade): array
    {
        $symbol = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($symbol)) ?: '');
        if ($symbol === '') {
            throw new AssetException('请选择现货交易对', 422, 'SPOT_SYMBOL_REQUIRED');
        }
        $row = Db::table('cex_market_spot_symbols')->alias('m')
            ->join('cex_asset_assets ba', 'ba.id = m.base_asset_id')
            ->join('cex_asset_assets qa', 'qa.id = m.quote_asset_id')
            ->where('m.symbol', $symbol)
            ->field('m.id,m.symbol,m.base_asset_id,m.quote_asset_id,m.price_tick,m.quantity_step,m.min_quantity,m.max_quantity,m.min_notional,m.max_notional,m.maker_fee_rate,m.taker_fee_rate,m.status,ba.code AS base_code,ba.name AS base_name,ba.display_decimals AS base_decimals,ba.status AS base_status,ba.spot_enabled AS base_spot_enabled,qa.code AS quote_code,qa.name AS quote_name,qa.display_decimals AS quote_decimals,qa.status AS quote_status')
            ->find();
        if (!$row) {
            throw new AssetException('现货交易对不存在', 404, 'SPOT_SYMBOL_NOT_FOUND');
        }
        if ($mustTrade && ((int) $row['status'] !== 1 || (int) $row['base_status'] !== 1 || !(bool) $row['base_spot_enabled'] || (int) $row['quote_status'] !== 1)) {
            throw new AssetException('该现货交易对当前不可交易', 409, 'SPOT_SYMBOL_UNAVAILABLE');
        }
        return $row;
    }

    private function eligibility(array $auth, bool $throw): array
    {
        $reasonCode = null;
        $reason = null;
        if ((int) $auth['user_status'] !== 1) {
            $reasonCode = 'SPOT_USER_UNAVAILABLE';
            $reason = '账户当前不可进行现货交易';
        } elseif (empty($auth['email_verified_at'])) {
            $reasonCode = 'SPOT_EMAIL_REQUIRED';
            $reason = '请先完成安全邮箱验证';
        } elseif ((bool) config('spot.require_kyc', true) && (int) $auth['kyc_level'] < 1) {
            $reasonCode = 'SPOT_KYC_REQUIRED';
            $reason = '请先完成身份认证';
        } elseif ((bool) config('spot.require_kyc', true)) {
            $approved = Db::table('cex_user_kyc')
                ->where('user_id', (int) $auth['user_id'])
                ->where('status', 3)
                ->whereNotNull('approved_at')
                ->whereRaw('(expires_at IS NULL OR expires_at > UTC_TIMESTAMP(6))')
                ->field('id')
                ->find();
            if (!$approved) {
                $reasonCode = 'SPOT_KYC_NOT_APPROVED';
                $reason = '身份认证当前不可用于交易，请重新完成认证';
            }
        }

        if ($reasonCode === null) {
            $restriction = Db::table('cex_user_restrictions')
                ->where('user_id', (int) $auth['user_id'])
                ->where('restriction_type', (int) config('spot.trading_restriction_type', 2))
                ->where('status', 1)
                ->where('starts_at', '<=', UtcClock::now())
                ->whereRaw('(expires_at IS NULL OR expires_at > UTC_TIMESTAMP(6))')
                ->field('id,reason_code')
                ->find();
            if ($restriction) {
                $reasonCode = 'SPOT_TRADING_RESTRICTED';
                $reason = '账户现货交易功能当前受限，请联系支持';
            }
        }

        if ($throw && $reasonCode !== null) {
            throw new AssetException($reason, $reasonCode === 'SPOT_TRADING_RESTRICTED' ? 403 : 409, $reasonCode);
        }
        return ['eligible' => $reasonCode === null, 'reason_code' => $reasonCode, 'reason' => $reason];
    }

    private function assertEligible(array $auth): void
    {
        $this->eligibility($auth, true);
    }

    private function balancesForAsset(int $accountId, int $assetId): array
    {
        // Read-only market browsing must not create zero-balance ledger rows for every
        // trade-only asset a user clicks. Ledger accounts remain lazy and are created
        // only when a real reservation/settlement path needs them.
        $available = $this->ledger->balanceForDimensions($accountId, $assetId, LedgerService::SCOPE_SPOT, LedgerService::BUCKET_AVAILABLE);
        $locked = $this->ledger->balanceForDimensions($accountId, $assetId, LedgerService::SCOPE_SPOT, LedgerService::BUCKET_LOCKED);
        return [
            'available' => $available,
            'locked' => $locked,
            'total' => Decimal18::add($available, $locked),
        ];
    }

    private function formatBalance(array $market, string $side, array $balance): array
    {
        $isBase = $side === 'base';
        $code = (string) $market[$isBase ? 'base_code' : 'quote_code'];
        $decimals = (int) $market[$isBase ? 'base_decimals' : 'quote_decimals'];
        return [
            'asset_id' => (int) $market[$isBase ? 'base_asset_id' : 'quote_asset_id'],
            'code' => $code,
            'display_decimals' => $decimals,
            'available' => Decimal18::trim((string) $balance['available'], $decimals),
            'locked' => Decimal18::trim((string) $balance['locked'], $decimals),
            'total' => Decimal18::trim((string) $balance['total'], $decimals),
        ];
    }

    private function formatMarket(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'symbol' => (string) $row['symbol'],
            'pair' => (string) $row['base_code'] . '/' . (string) $row['quote_code'],
            'base_code' => (string) $row['base_code'],
            'quote_code' => (string) $row['quote_code'],
            'price_tick' => Decimal18::trim((string) $row['price_tick']),
            'quantity_step' => Decimal18::trim((string) $row['quantity_step']),
            'min_quantity' => Decimal18::trim((string) $row['min_quantity']),
            'max_quantity' => $row['max_quantity'] !== null ? Decimal18::trim((string) $row['max_quantity']) : null,
            'min_notional' => Decimal18::trim((string) $row['min_notional']),
            'max_notional' => $row['max_notional'] !== null ? Decimal18::trim((string) $row['max_notional']) : null,
            'maker_fee_rate' => (string) $row['maker_fee_rate'],
            'taker_fee_rate' => (string) $row['taker_fee_rate'],
            'status' => (int) $row['status'],
        ];
    }

    private function formatOrderRow(array $row): array
    {
        $baseDecimals = (int) ($row['base_decimals'] ?? 8);
        $quoteDecimals = (int) ($row['quote_decimals'] ?? 6);
        $status = (int) $row['status'];
        return [
            'order_no' => (string) $row['order_no'],
            'symbol' => (string) $row['symbol'],
            'pair' => (string) $row['base_code'] . '/' . (string) $row['quote_code'],
            'base_code' => (string) $row['base_code'],
            'quote_code' => (string) $row['quote_code'],
            'side' => (int) $row['side'] === self::SIDE_BUY ? 'BUY' : 'SELL',
            'side_label' => (int) $row['side'] === self::SIDE_BUY ? '买入' : '卖出',
            'type' => (int) $row['order_type'] === self::TYPE_MARKET ? 'MARKET' : 'LIMIT',
            'type_label' => (int) $row['order_type'] === self::TYPE_MARKET ? '市价' : '限价',
            'price' => $row['price'] !== null ? Decimal18::trim((string) $row['price'], $quoteDecimals) : null,
            'average_price' => $row['average_price'] !== null ? Decimal18::trim((string) $row['average_price'], $quoteDecimals) : null,
            'original_quantity' => $row['original_quantity'] !== null ? Decimal18::trim((string) $row['original_quantity'], $baseDecimals) : null,
            'executed_quantity' => Decimal18::trim((string) $row['executed_quantity'], $baseDecimals),
            'cumulative_quote_amount' => Decimal18::trim((string) $row['cumulative_quote_amount'], $quoteDecimals),
            'reserved_amount' => Decimal18::trim((string) $row['reserved_amount'], (int) $row['side'] === self::SIDE_BUY ? $quoteDecimals : $baseDecimals),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'can_cancel' => in_array($status, [self::STATUS_OPEN, self::STATUS_PARTIALLY_FILLED], true),
            'reject_code' => $row['reject_code'] !== null ? (string) $row['reject_code'] : null,
            'created_at' => (string) $row['created_at'],
            'opened_at' => $row['opened_at'] !== null ? (string) $row['opened_at'] : null,
            'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
        ];
    }

    private function orderWithMarket(int $id): array
    {
        $row = Db::table('cex_spot_orders')->alias('o')
            ->join('cex_market_spot_symbols m', 'm.id = o.symbol_id')
            ->join('cex_asset_assets ba', 'ba.id = m.base_asset_id')
            ->join('cex_asset_assets qa', 'qa.id = m.quote_asset_id')
            ->where('o.id', $id)
            ->field('o.*,m.symbol,ba.code AS base_code,ba.display_decimals AS base_decimals,qa.code AS quote_code,qa.display_decimals AS quote_decimals')
            ->find();
        if (!$row) throw new AssetException('现货委托不存在', 404, 'SPOT_ORDER_NOT_FOUND');
        return $row;
    }

    private function openOrderCount(int $accountId, int $symbolId): int
    {
        return (int) Db::table('cex_spot_orders')
            ->where('account_id', $accountId)
            ->where('symbol_id', $symbolId)
            ->whereIn('status', [self::STATUS_OPEN, self::STATUS_PARTIALLY_FILLED])
            ->count();
    }

    private function statusLabel(int $status): string
    {
        $map = [
            self::STATUS_OPEN => '待成交',
            self::STATUS_PARTIALLY_FILLED => '部分成交',
            self::STATUS_FILLED => '完全成交',
            self::STATUS_CANCELLED => '已撤销',
            self::STATUS_REJECTED => '已拒绝',
            self::STATUS_EXPIRED => '已失效',
        ];
        return $map[$status] ?? '处理中';
    }

    private function parseSide(string $side): int
    {
        $side = strtoupper(trim($side));
        if ($side === 'BUY') return self::SIDE_BUY;
        if ($side === 'SELL') return self::SIDE_SELL;
        throw new AssetException('交易方向无效', 422, 'SPOT_SIDE_INVALID');
    }

    private function clientOrderId(string $value): string
    {
        $value = trim($value);
        if ($value === '') $value = 'web-' . strtolower(Ulid::generate());
        if (!preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $value)) {
            throw new AssetException('客户端委托编号格式无效', 422, 'SPOT_CLIENT_ORDER_ID_INVALID');
        }
        return $value;
    }

    private function assertSameOrderRequest(array $existing, array $market, int $side, string $price, string $quantity): void
    {
        if ((int) $existing['symbol_id'] !== (int) $market['id']
            || (int) $existing['side'] !== $side
            || (int) $existing['order_type'] !== self::TYPE_LIMIT
            || Decimal18::compare((string) $existing['price'], $price) !== 0
            || Decimal18::compare((string) $existing['original_quantity'], $quantity) !== 0) {
            throw new AssetException('客户端委托编号与原请求不一致', 409, 'SPOT_ORDER_IDEMPOTENCY_CONFLICT');
        }
    }

    private function assertStepAligned(string $value, string $step, string $code, string $message): void
    {
        $rows = Db::query(
            'SELECT CAST(MOD(CAST(? AS DECIMAL(38,18)), CAST(? AS DECIMAL(38,18))) AS CHAR) AS remainder_value',
            [$value, $step]
        );
        $remainder = isset($rows[0]['remainder_value']) ? Decimal18::normalize((string) $rows[0]['remainder_value']) : null;
        if ($remainder === null || Decimal18::compare($remainder, '0') !== 0) {
            throw new AssetException($message, 422, $code);
        }
    }

    private function multiply(string $left, string $right): string
    {
        $rows = Db::query(
            'SELECT CAST(CAST(? AS DECIMAL(38,18)) * CAST(? AS DECIMAL(38,18)) AS DECIMAL(38,18)) AS value',
            [$left, $right]
        );
        if (!isset($rows[0]['value'])) {
            throw new AssetException('交易金额计算失败', 500, 'SPOT_NOTIONAL_CALCULATION_FAILED');
        }
        return Decimal18::normalize((string) $rows[0]['value']);
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
        $row = Db::table('cex_account_accounts')
            ->where('user_id', (int) $auth['user_id'])
            ->where('account_kind', 1)
            ->field('id,public_id,status,user_id')
            ->find();
        if (!$row) {
            BusinessAccountService::createForUser((int) $auth['user_id'], (string) $auth['uid']);
            $row = Db::table('cex_account_accounts')
                ->where('user_id', (int) $auth['user_id'])
                ->where('account_kind', 1)
                ->field('id,public_id,status,user_id')
                ->find();
        }
        if (!$row || (int) $row['status'] !== 1) {
            throw new AssetException('现货账户当前不可用', 409, 'SPOT_ACCOUNT_UNAVAILABLE');
        }
        $this->businessAccount = $row;
        return $row;
    }

    private function rateLimit(string $key, int $limit, int $seconds): void
    {
        if ($limit <= 0) return;
        $cacheKey = 'spot:rl:' . hash('sha256', $key);
        $state = Cache::get($cacheKey);
        $now = time();
        if (!is_array($state) || (int) ($state['reset_at'] ?? 0) <= $now) {
            Cache::set($cacheKey, ['count' => 1, 'reset_at' => $now + $seconds], $seconds);
            return;
        }
        if ((int) ($state['count'] ?? 0) >= $limit) {
            throw new AssetException('现货交易请求过于频繁，请稍后再试', 429, 'SPOT_RATE_LIMITED');
        }
        $state['count'] = (int) $state['count'] + 1;
        Cache::set($cacheKey, $state, max(1, (int) $state['reset_at'] - $now));
    }
}
