<?php

namespace app\service\Perp;

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

final class PerpTradingService
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

    private const MARGIN_MODE_CROSS = 1;
    private const POSITION_MODE_ONE_WAY = 1;

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
        $eligibility = $this->eligibility($auth, false);

        $settlementAssetId = (int) $market['settlement_asset_id'];
        $available = $this->ledger->ensureLedgerAccount(
            (int) $account['id'],
            $settlementAssetId,
            LedgerService::SCOPE_PERPETUAL_CROSS,
            LedgerService::BUCKET_AVAILABLE,
            false
        );
        $locked = $this->ledger->ensureLedgerAccount(
            (int) $account['id'],
            $settlementAssetId,
            LedgerService::SCOPE_PERPETUAL_CROSS,
            LedgerService::BUCKET_LOCKED,
            false
        );

        $availableBalance = $this->ledger->balanceForDimensions(
            (int) $account['id'],
            $settlementAssetId,
            LedgerService::SCOPE_PERPETUAL_CROSS,
            LedgerService::BUCKET_AVAILABLE
        );
        $lockedBalance = $this->ledger->balanceForDimensions(
            (int) $account['id'],
            $settlementAssetId,
            LedgerService::SCOPE_PERPETUAL_CROSS,
            LedgerService::BUCKET_LOCKED
        );
        $walletBalance = Decimal18::add($availableBalance, $lockedBalance);

        $setting = $this->setting((int) $account['id'], $market, true);
        $risk = Db::table('cex_perp_account_risk_states')
            ->where('account_id', (int) $account['id'])
            ->field('wallet_balance,unrealized_pnl,equity,position_initial_margin,order_initial_margin,maintenance_margin,available_margin,margin_ratio,risk_status,mark_price_version,version,updated_at')
            ->find();

        // Ledger balances remain the wallet source of truth. The LIVE Node engine materializes mark-to-market risk after fills/mark updates; before the first materialization we expose a ledger-backed baseline.
        $riskPayload = $risk ? [
            'wallet_balance' => Decimal18::trim((string) $risk['wallet_balance'], 6),
            'unrealized_pnl' => Decimal18::trim((string) $risk['unrealized_pnl'], 6),
            'equity' => Decimal18::trim((string) $risk['equity'], 6),
            'position_initial_margin' => Decimal18::trim((string) $risk['position_initial_margin'], 6),
            'order_initial_margin' => Decimal18::trim((string) $risk['order_initial_margin'], 6),
            'maintenance_margin' => Decimal18::trim((string) $risk['maintenance_margin'], 6),
            'available_margin' => Decimal18::trim((string) $risk['available_margin'], 6),
            'margin_ratio' => $risk['margin_ratio'] !== null ? Decimal18::trim((string) $risk['margin_ratio'], 8) : null,
            'risk_status' => (int) $risk['risk_status'],
            'mark_price_version' => (string) $risk['mark_price_version'],
            'updated_at' => (string) $risk['updated_at'],
            'materialized' => true,
        ] : [
            'wallet_balance' => Decimal18::trim($walletBalance, 6),
            'unrealized_pnl' => '0',
            'equity' => Decimal18::trim($walletBalance, 6),
            'position_initial_margin' => '0',
            'order_initial_margin' => Decimal18::trim($lockedBalance, 6),
            'maintenance_margin' => '0',
            'available_margin' => Decimal18::trim($availableBalance, 6),
            'margin_ratio' => null,
            'risk_status' => 1,
            'mark_price_version' => '0',
            'updated_at' => null,
            'materialized' => false,
        ];

        return [
            'authenticated' => true,
            'uid' => (string) $auth['uid'],
            'business_account' => [
                'id' => (int) $account['id'],
                'public_id' => (string) $account['public_id'],
                'status' => (int) $account['status'],
            ],
            'market' => $this->formatMarket($market),
            'balances' => [
                'settlement' => [
                    'asset_id' => $settlementAssetId,
                    'code' => (string) $market['settlement_code'],
                    'display_decimals' => (int) $market['settlement_decimals'],
                    'available' => Decimal18::trim($availableBalance, (int) $market['settlement_decimals']),
                    'locked' => Decimal18::trim($lockedBalance, (int) $market['settlement_decimals']),
                    'total' => Decimal18::trim($walletBalance, (int) $market['settlement_decimals']),
                    'available_ledger_account_id' => (int) $available['id'],
                    'locked_ledger_account_id' => (int) $locked['id'],
                ],
            ],
            'setting' => $this->formatSetting($setting),
            'risk' => $riskPayload,
            'trading' => [
                'eligible' => (bool) $eligibility['eligible'],
                'reason_code' => $eligibility['reason_code'],
                'reason' => $eligibility['reason'],
                'margin_mode' => 'CROSS',
                'position_mode' => 'ONE_WAY',
                'limit_orders_enabled' => (bool) config('perp.limit_orders_enabled', true),
                'market_orders_enabled' => (bool) config('perp.market_orders_enabled', false),
                'execution_phase' => 'LIVE_REFERENCE_BBO_SETTLEMENT_V1_9_2',
            ],
            'open_order_count' => $this->openOrderCount((int) $account['id'], (int) $market['id']),
        ];
    }

    public function orders(string $scope, string $symbol = '', int $limit = 20): array
    {
        $account = $this->businessAccount();
        $limit = max(1, min((int) config('perp.history_limit', 50), $limit));
        $scope = strtolower(trim($scope));
        if (!in_array($scope, ['open', 'history'], true)) {
            throw new AssetException('委托查询范围无效', 422, 'PERP_ORDER_SCOPE_INVALID');
        }

        $query = Db::table('cex_perp_orders')->alias('o')
            ->join('cex_market_perpetual_contracts c', 'c.id = o.contract_id')
            ->join('cex_asset_assets ba', 'ba.id = c.base_asset_id')
            ->join('cex_asset_assets qa', 'qa.id = c.quote_asset_id')
            ->where('o.account_id', (int) $account['id']);

        $normalized = $this->normalizeSymbol($symbol, false);
        if ($normalized !== '') $query->where('c.symbol', $normalized);

        if ($scope === 'open') {
            $query->whereIn('o.status', [self::STATUS_OPEN, self::STATUS_PARTIALLY_FILLED]);
        } else {
            $query->whereIn('o.status', [self::STATUS_FILLED, self::STATUS_CANCELLED, self::STATUS_REJECTED, self::STATUS_EXPIRED]);
        }

        $rows = $query
            ->field('o.id,o.order_no,o.side,o.order_type,o.price,o.original_quantity,o.executed_quantity,o.average_price,o.reduce_only,o.close_on_trigger,o.requested_leverage,o.reserved_order_margin,o.status,o.reject_code,o.created_at,o.opened_at,o.completed_at,c.symbol,c.contract_size,ba.code AS base_code,ba.display_decimals AS base_decimals,qa.code AS quote_code,qa.display_decimals AS quote_decimals')
            ->order('o.id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        $profitMap = $scope === 'history'
            ? (new PerpOrderProfitService())->forHistory((int) $account['id'], $rows)
            : [];
        $items = [];
        foreach ($rows as $row) {
            $item = $this->formatOrderRow($row);
            if ($scope === 'history') {
                $profit = $profitMap[(int) $row['id']] ?? null;
                $item['profit_role'] = $profit['profit_role'] ?? null;
                $item['profit_role_label'] = $profit['profit_role_label'] ?? null;
                $item['order_profit'] = $profit['order_profit'] ?? null;
                $item['profit_realized_pnl'] = $profit['realized_pnl'] ?? null;
                $item['profit_allocated_open_fee'] = $profit['allocated_open_fee'] ?? null;
                $item['profit_close_fee_amount'] = $profit['close_fee_amount'] ?? null;
                $item['flip_close_quantity'] = $profit['flip_close_quantity'] ?? null;
                $item['flip_open_quantity'] = $profit['flip_open_quantity'] ?? null;
            }
            $items[] = $item;
        }

        return [
            'scope' => $scope,
            'items' => $items,
        ];
    }

    public function positions(string $symbol = '', int $limit = 50): array
    {
        $account = $this->businessAccount();
        $limit = max(1, min(100, $limit));
        $query = Db::table('cex_perp_positions')->alias('p')
            ->join('cex_market_perpetual_contracts c', 'c.id = p.contract_id')
            ->join('cex_asset_assets ba', 'ba.id = c.base_asset_id')
            ->join('cex_asset_assets qa', 'qa.id = c.quote_asset_id')
            ->where('p.account_id', (int) $account['id'])
            ->where('p.position_quantity', '<>', Decimal18::zero());

        $normalized = $this->normalizeSymbol($symbol, false);
        if ($normalized !== '') $query->where('c.symbol', $normalized);

        $rows = $query
            ->field('p.*,c.symbol,c.contract_size,ba.code AS base_code,ba.display_decimals AS base_decimals,qa.code AS quote_code,qa.display_decimals AS quote_decimals')
            ->order('p.updated_at', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $row) {
            $qty = Decimal18::normalize((string) $row['position_quantity']);
            $items[] = [
                'position_id' => (int) $row['id'],
                'symbol' => (string) $row['symbol'],
                'pair' => (string) $row['base_code'] . '/' . (string) $row['quote_code'],
                'side' => Decimal18::compare($qty, '0') > 0 ? 'LONG' : 'SHORT',
                'position_quantity' => Decimal18::trim($this->absDecimal($qty), (int) $row['base_decimals']),
                'signed_quantity' => Decimal18::trim($qty, (int) $row['base_decimals']),
                'entry_price' => $row['entry_price'] !== null ? Decimal18::trim((string) $row['entry_price'], (int) $row['quote_decimals']) : null,
                'break_even_price' => $row['break_even_price'] !== null ? Decimal18::trim((string) $row['break_even_price'], (int) $row['quote_decimals']) : null,
                'mark_price' => $row['last_mark_price'] !== null ? Decimal18::trim((string) $row['last_mark_price'], (int) $row['quote_decimals']) : null,
                'realized_pnl' => Decimal18::trim((string) $row['realized_pnl'], 6),
                'unrealized_pnl' => Decimal18::trim((string) $row['unrealized_pnl'], 6),
                'initial_margin' => Decimal18::trim((string) $row['initial_margin'], 6),
                'maintenance_margin' => Decimal18::trim((string) $row['maintenance_margin'], 6),
                'liquidation_price' => $row['liquidation_price'] !== null ? Decimal18::trim((string) $row['liquidation_price'], (int) $row['quote_decimals']) : null,
                'position_status' => (int) $row['position_status'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return ['items' => $items];
    }

    public function closePosition(int $positionId): array
    {
        $auth = $this->authContext();
        $account = $this->businessAccount();
        $this->rateLimit('close-position:user:' . (int) $auth['user_id'], (int) config('perp.rate_limit.close_position_per_minute', 30), 60);
        $this->assertEligible($auth);
        if ($positionId <= 0) {
            throw new AssetException('持仓编号无效', 422, 'PERP_POSITION_ID_INVALID');
        }

        $position = Db::table('cex_perp_positions')->alias('p')
            ->join('cex_market_perpetual_contracts c', 'c.id = p.contract_id')
            ->where('p.id', $positionId)
            ->where('p.account_id', (int) $account['id'])
            ->field('p.id,p.contract_id,p.position_quantity,p.version,c.symbol,c.price_tick,c.status')
            ->find();
        if (!$position || Decimal18::compare((string) $position['position_quantity'], '0') === 0) {
            throw new AssetException('当前持仓不存在或已平仓', 404, 'PERP_POSITION_NOT_OPEN');
        }

        $existing = Db::table('cex_perp_orders')->alias('o')
            ->join('cex_market_perpetual_contracts c', 'c.id=o.contract_id')
            ->where('o.account_id', (int) $account['id'])
            ->where('o.contract_id', (int) $position['contract_id'])
            ->where('o.reduce_only', 1)
            ->whereIn('o.status', [self::STATUS_OPEN, self::STATUS_PARTIALLY_FILLED])
            ->field('o.id,o.order_no')
            ->order('o.id', 'desc')
            ->find();
        if ($existing) {
            $full = $this->orderWithMarket((int) $existing['id']);
            return [
                'order' => $this->formatOrderRow($full),
                'duplicate' => true,
                'message' => '平仓委托已存在',
            ];
        }

        $market = $this->market((string) $position['symbol'], true);
        $setting = $this->setting((int) $account['id'], $market, true);
        $signedQty = Decimal18::normalize((string) $position['position_quantity']);
        $side = Decimal18::compare($signedQty, '0') > 0 ? self::SIDE_SELL : self::SIDE_BUY;
        $quantity = $this->absDecimal($signedQty);
        $bbo = (new PerpMarketGatewayClient())->bestPrices((string) $market['symbol']);
        $price = $this->marketableClosePrice(
            $side,
            $side === self::SIDE_SELL ? (string) $bbo['best_bid'] : (string) $bbo['best_ask'],
            (string) $market['price_tick'],
            (int) config('perp.close_price_protection_bps', 20)
        );

        $result = $this->createOrder([
            'symbol' => (string) $market['symbol'],
            'side' => $side === self::SIDE_SELL ? 'SELL' : 'BUY',
            'type' => 'LIMIT',
            'price' => $price,
            'quantity' => $quantity,
            'leverage' => Decimal18::trim((string) $setting['leverage'], 4),
            'reduce_only' => true,
            'client_order_id' => 'close-pos-' . $positionId . '-v' . (int) $position['version'],
        ]);
        $result['message'] = '平仓委托已提交';
        $result['close_price_protection_bps'] = (int) config('perp.close_price_protection_bps', 20);
        return $result;
    }

    public function setLeverage(array $payload): array
    {
        $auth = $this->authContext();
        $account = $this->businessAccount();
        $this->rateLimit('leverage:user:' . (int) $auth['user_id'], (int) config('perp.rate_limit.leverage_per_minute', 30), 60);
        $this->assertEligible($auth);

        $market = $this->market((string) ($payload['symbol'] ?? ''), true);
        if (!isset($payload['leverage']) || (!is_string($payload['leverage']) && !is_int($payload['leverage']))) {
            throw new AssetException('杠杆倍数必须提交为字符串或整数', 422, 'PERP_LEVERAGE_REQUIRED');
        }
        $leverage = $this->normalizeLeverage((string) $payload['leverage'], $market);

        return Db::transaction(function () use ($account, $market, $leverage) {
            $locked = Db::table('cex_account_accounts')->where('id', (int) $account['id'])->field('id,status')->lock(true)->find();
            if (!$locked || (int) $locked['status'] !== 1) {
                throw new AssetException('合约账户当前不可用', 409, 'PERP_ACCOUNT_UNAVAILABLE');
            }

            $openOrders = (int) Db::table('cex_perp_orders')
                ->where('account_id', (int) $account['id'])
                ->where('contract_id', (int) $market['id'])
                ->whereIn('status', [self::STATUS_OPEN, self::STATUS_PARTIALLY_FILLED])
                ->count();
            if ($openOrders > 0) {
                throw new AssetException('当前合约存在未完成委托，请撤单后再修改杠杆', 409, 'PERP_LEVERAGE_OPEN_ORDER_CONFLICT');
            }
            $position = Db::table('cex_perp_positions')
                ->where('account_id', (int) $account['id'])
                ->where('contract_id', (int) $market['id'])
                ->field('position_quantity')
                ->lock(true)
                ->find();
            if ($position && Decimal18::compare((string) $position['position_quantity'], '0') !== 0) {
                throw new AssetException('当前合约已有持仓，暂不支持修改杠杆', 409, 'PERP_LEVERAGE_POSITION_CONFLICT');
            }

            $now = UtcClock::now();
            Db::execute(
                'INSERT INTO `cex_perp_account_contract_settings` '
                . '(`account_id`,`contract_id`,`leverage`,`margin_mode`,`position_mode`,`status`,`created_at`,`updated_at`) '
                . 'VALUES (?,?,CAST(? AS DECIMAL(10,4)),?,?,1,?,?) '
                . 'ON DUPLICATE KEY UPDATE `leverage`=VALUES(`leverage`),`margin_mode`=VALUES(`margin_mode`),`position_mode`=VALUES(`position_mode`),`status`=1,`version`=`version`+1,`updated_at`=VALUES(`updated_at`)',
                [(int) $account['id'], (int) $market['id'], $leverage, self::MARGIN_MODE_CROSS, self::POSITION_MODE_ONE_WAY, $now, $now]
            );

            $setting = $this->setting((int) $account['id'], $market, false);
            return [
                'setting' => $this->formatSetting($setting),
                'message' => '杠杆设置已更新',
            ];
        });
    }

    public function createOrder(array $payload): array
    {
        $auth = $this->authContext();
        $account = $this->businessAccount();
        $this->rateLimit('create:user:' . (int) $auth['user_id'], (int) config('perp.rate_limit.create_per_minute', 30), 60);
        $this->assertEligible($auth);

        $market = $this->market((string) ($payload['symbol'] ?? ''), true);
        $side = $this->parseSide((string) ($payload['side'] ?? ''));
        $type = strtoupper(trim((string) ($payload['type'] ?? 'LIMIT')));
        if ($type !== 'LIMIT') {
            throw new AssetException('暂不支持市价委托', 409, 'PERP_MARKET_ORDER_NOT_ENABLED');
        }
        if (!(bool) config('perp.limit_orders_enabled', true)) {
            throw new AssetException('合约限价委托当前维护中', 409, 'PERP_LIMIT_ORDER_DISABLED');
        }

        if (!isset($payload['price']) || !is_string($payload['price']) || !isset($payload['quantity']) || !is_string($payload['quantity'])) {
            throw new AssetException('价格和数量必须以十进制字符串提交', 422, 'PERP_DECIMAL_STRING_REQUIRED');
        }
        $price = Decimal18::positive($payload['price']);
        $quantity = Decimal18::positive($payload['quantity']);
        $reduceOnly = (bool) ($payload['reduce_only'] ?? false);
        $clientOrderId = $this->clientOrderId((string) ($payload['client_order_id'] ?? ''));

        $setting = $this->setting((int) $account['id'], $market, true);
        $requestedLeverage = $this->normalizeLeverage(
            isset($payload['leverage']) ? (string) $payload['leverage'] : (string) $setting['leverage'],
            $market
        );
        if (Decimal18::compare($requestedLeverage, (string) $setting['leverage']) !== 0) {
            throw new AssetException('下单杠杆与当前账户设置不一致，请先更新杠杆设置', 409, 'PERP_LEVERAGE_SETTING_MISMATCH');
        }

        $this->assertStepAligned($price, (string) $market['price_tick'], 'PERP_PRICE_TICK_INVALID', '价格不符合当前合约最小变动单位');
        $this->assertStepAligned($quantity, (string) $market['quantity_step'], 'PERP_QUANTITY_STEP_INVALID', '数量不符合当前合约最小变动单位');
        if (!$reduceOnly && Decimal18::compare($quantity, (string) $market['min_quantity']) < 0) {
            throw new AssetException('委托数量低于最小下单数量', 422, 'PERP_BELOW_MIN_QUANTITY');
        }
        if (!$reduceOnly && $market['max_quantity'] !== null && Decimal18::compare($quantity, (string) $market['max_quantity']) > 0) {
            throw new AssetException('委托数量超过单笔最大数量', 422, 'PERP_ABOVE_MAX_QUANTITY');
        }

        $riskCalc = $this->orderRiskCalculation($price, $quantity, (string) $market['contract_size'], $requestedLeverage, (string) $market['taker_fee_rate'], $reduceOnly);
        if (!$reduceOnly && Decimal18::compare($riskCalc['notional'], (string) $market['min_notional']) < 0) {
            throw new AssetException('委托名义价值低于最小交易金额', 422, 'PERP_BELOW_MIN_NOTIONAL');
        }
        if (!$reduceOnly && $market['max_notional'] !== null && Decimal18::compare($riskCalc['notional'], (string) $market['max_notional']) > 0) {
            throw new AssetException('委托名义价值超过单笔最大交易金额', 422, 'PERP_ABOVE_MAX_NOTIONAL');
        }

        if ($reduceOnly) {
            $this->assertReduceOnly((int) $account['id'], $market, $side, $quantity);
        }

        $result = Db::transaction(function () use ($auth, $account, $market, $side, $price, $quantity, $requestedLeverage, $reduceOnly, $clientOrderId, $riskCalc) {
            $lockedAccount = Db::table('cex_account_accounts')
                ->where('id', (int) $account['id'])
                ->field('id,status')
                ->lock(true)
                ->find();
            if (!$lockedAccount || (int) $lockedAccount['status'] !== 1) {
                throw new AssetException('合约账户当前不可用', 409, 'PERP_ACCOUNT_UNAVAILABLE');
            }

            // Automatic liquidation guard: once the account reaches
            // LIQUIDATION_REQUIRED(4) or LIQUIDATING(5), user-created perpetual orders
            // are fully frozen. The automatic liquidation engine creates its own
            // reduce-only close orders directly in the engine transaction path.
            $riskState = Db::table('cex_perp_account_risk_states')
                ->where('account_id', (int) $account['id'])
                ->where('settlement_asset_id', (int) $market['settlement_asset_id'])
                ->field('risk_status,equity,maintenance_margin,updated_at')
                ->lock(true)
                ->find();
            if ($riskState && in_array((int) $riskState['risk_status'], [4, 5], true)) {
                throw new AssetException('账户已进入自动强平处理，暂不接受新的合约委托', 409, 'PERP_ACCOUNT_LIQUIDATING');
            }

            $existing = Db::table('cex_perp_orders')
                ->where('account_id', (int) $account['id'])
                ->where('client_order_id', $clientOrderId)
                ->lock(true)
                ->find();
            if ($existing) {
                $this->assertSameOrderRequest($existing, $market, $side, $price, $quantity, $requestedLeverage, $reduceOnly);
                return ['row' => $existing, 'existing' => true];
            }

            $contractOpen = (int) Db::table('cex_perp_orders')
                ->where('account_id', (int) $account['id'])
                ->where('contract_id', (int) $market['id'])
                ->whereIn('status', [self::STATUS_OPEN, self::STATUS_PARTIALLY_FILLED])
                ->count();
            if ($contractOpen >= (int) config('perp.max_open_orders_per_contract', 50)) {
                throw new AssetException('当前合约未完成委托过多，请先撤销部分委托', 409, 'PERP_CONTRACT_OPEN_ORDER_LIMIT');
            }
            $accountOpen = (int) Db::table('cex_perp_orders')
                ->where('account_id', (int) $account['id'])
                ->whereIn('status', [self::STATUS_OPEN, self::STATUS_PARTIALLY_FILLED])
                ->count();
            if ($accountOpen >= (int) config('perp.max_open_orders_per_account', 200)) {
                throw new AssetException('账户未完成合约委托数量已达到上限', 409, 'PERP_ACCOUNT_OPEN_ORDER_LIMIT');
            }

            $settlementAssetId = (int) $market['settlement_asset_id'];
            $available = $this->ledger->ensureLedgerAccount((int) $account['id'], $settlementAssetId, LedgerService::SCOPE_PERPETUAL_CROSS, LedgerService::BUCKET_AVAILABLE, false);
            $locked = $this->ledger->ensureLedgerAccount((int) $account['id'], $settlementAssetId, LedgerService::SCOPE_PERPETUAL_CROSS, LedgerService::BUCKET_LOCKED, false);
            $balance = $this->ledger->lockBalanceForDimensions((int) $account['id'], $settlementAssetId, LedgerService::SCOPE_PERPETUAL_CROSS, LedgerService::BUCKET_AVAILABLE);
            if (Decimal18::compare($riskCalc['reserve_amount'], '0') > 0
                && Decimal18::compare((string) $balance['balance'], $riskCalc['reserve_amount']) < 0) {
                throw new AssetException('合约可用保证金不足', 422, 'PERP_INSUFFICIENT_MARGIN');
            }

            $now = UtcClock::now();
            $orderNo = Ulid::generate();
            $requestId = Ulid::generate();
            // Never let ThinkORM convert DECIMAL strings to PHP float here. The order
            // reservation and hold amount must remain byte-for-byte decimal consistent
            // with the exact LedgerService journal that follows.
            Db::execute(
                'INSERT INTO `cex_perp_orders` '
                . '(`order_no`,`account_id`,`contract_id`,`client_order_id`,`side`,`order_type`,`time_in_force`,`price`,`original_quantity`,`executed_quantity`,`reduce_only`,`close_on_trigger`,`requested_leverage`,`reserved_order_margin`,`status`,`request_id`,`created_at`,`opened_at`,`updated_at`) '
                . 'VALUES (?,?,?,?,?,?,1,CAST(? AS DECIMAL(38,18)),CAST(? AS DECIMAL(38,18)),CAST(? AS DECIMAL(38,18)),?,?,CAST(? AS DECIMAL(10,4)),CAST(? AS DECIMAL(38,18)),?,?,?, ?, ?)',
                [
                    $orderNo, (int) $account['id'], (int) $market['id'], $clientOrderId,
                    $side, self::TYPE_LIMIT, $price, $quantity, Decimal18::zero(),
                    $reduceOnly ? 1 : 0, 0, $requestedLeverage, $riskCalc['reserve_amount'],
                    self::STATUS_OPEN, $requestId, $now, $now, $now,
                ]
            );
            $orderIdRow = Db::query('SELECT LAST_INSERT_ID() AS id');
            $orderId = (int) ($orderIdRow[0]['id'] ?? 0);
            if ($orderId <= 0) {
                throw new AssetException('合约委托创建失败', 500, 'PERP_ORDER_INSERT_FAILED');
            }

            $holdId = null;
            $freeze = ['id' => null];
            if (Decimal18::compare($riskCalc['reserve_amount'], '0') > 0) {
                $holdNo = Ulid::generate();
                Db::execute(
                    'INSERT INTO `cex_asset_holds` '
                    . '(`hold_no`,`account_id`,`asset_id`,`hold_type`,`business_type`,`business_id`,`available_ledger_account_id`,`locked_ledger_account_id`,`original_amount`,`remaining_amount`,`status`,`created_at`,`updated_at`) '
                    . 'VALUES (?,?,?,?,?,?,?,?,CAST(? AS DECIMAL(38,18)),CAST(? AS DECIMAL(38,18)),1,?,?)',
                    [
                        $holdNo, (int) $account['id'], $settlementAssetId, 2,
                        'PERP_ORDER', $orderNo, (int) $available['id'], (int) $locked['id'],
                        $riskCalc['reserve_amount'], $riskCalc['reserve_amount'], $now, $now,
                    ]
                );
                $holdIdRow = Db::query('SELECT LAST_INSERT_ID() AS id');
                $holdId = (int) ($holdIdRow[0]['id'] ?? 0);
                if ($holdId <= 0) {
                    throw new AssetException('合约保证金冻结记录创建失败', 500, 'PERP_HOLD_INSERT_FAILED');
                }

                $freeze = $this->ledger->postWithinTransaction([
                    'business_type' => 'PERP_ORDER_FREEZE',
                    'business_id' => $orderNo,
                    'idempotency_key' => 'perp-order-freeze:' . $orderNo,
                    'request_id' => $requestId,
                    'description' => 'Perpetual cross-margin LIMIT order reservation',
                    'metadata' => [
                        'order_no' => $orderNo,
                        'symbol' => (string) $market['symbol'],
                        'side' => $side === self::SIDE_BUY ? 'BUY' : 'SELL',
                        'price' => Decimal18::trim($price),
                        'quantity' => Decimal18::trim($quantity),
                        'notional' => Decimal18::trim($riskCalc['notional']),
                        'leverage' => Decimal18::trim($requestedLeverage, 4),
                        'initial_margin_component' => Decimal18::trim($riskCalc['initial_margin']),
                        'fee_buffer_component' => Decimal18::trim($riskCalc['fee_buffer']),
                        'reduce_only' => $reduceOnly,
                    ],
                    'occurred_at' => $now,
                ], [
                    [
                        'ledger_account_id' => (int) $available['id'],
                        'asset_id' => $settlementAssetId,
                        'direction' => LedgerService::DIRECTION_DECREASE,
                        'amount' => $riskCalc['reserve_amount'],
                    ],
                    [
                        'ledger_account_id' => (int) $locked['id'],
                        'asset_id' => $settlementAssetId,
                        'direction' => LedgerService::DIRECTION_INCREASE,
                        'amount' => $riskCalc['reserve_amount'],
                    ],
                ]);

                Db::table('cex_perp_orders')->where('id', $orderId)->update([
                    'hold_id' => $holdId,
                    'updated_at' => $now,
                ]);
            }

            Db::table('cex_perp_order_events')->insert([
                'order_id' => $orderId,
                'event_sequence' => 1,
                'event_type' => 'ACCEPTED',
                'previous_status' => null,
                'new_status' => self::STATUS_OPEN,
                'executed_quantity_delta' => Decimal18::zero(),
                'request_id' => $requestId,
                'occurred_at' => $now,
                'created_at' => $now,
            ]);

            $this->writeOutbox('PERP_ORDER_CREATED', $orderNo, [
                'order_id' => $orderId,
                'order_no' => $orderNo,
                'account_id' => (int) $account['id'],
                'contract_id' => (int) $market['id'],
                'symbol' => (string) $market['symbol'],
                'side' => $side === self::SIDE_BUY ? 'BUY' : 'SELL',
                'price' => Decimal18::trim($price),
                'quantity' => Decimal18::trim($quantity),
                'requested_leverage' => Decimal18::trim($requestedLeverage, 4),
                'reduce_only' => $reduceOnly,
                'created_at' => $now,
            ], $now);

            AuditLog::record($this->request, 'PERP_ORDER_CREATED', (int) $auth['user_id'], 1, 'perp_order', $orderNo, [
                'symbol' => (string) $market['symbol'],
                'side' => $side === self::SIDE_BUY ? 'BUY' : 'SELL',
                'reserve_amount' => Decimal18::trim($riskCalc['reserve_amount']),
                'notional' => Decimal18::trim($riskCalc['notional']),
                'ledger_transaction_id' => $freeze['id'] !== null ? (int) $freeze['id'] : null,
                'execution_phase' => 'LIVE_REFERENCE_BBO_SETTLEMENT_V1_9_2',
            ]);

            return ['row' => Db::table('cex_perp_orders')->where('id', $orderId)->find(), 'existing' => false];
        });

        $row = $this->orderWithMarket((int) $result['row']['id']);
        return [
            'order' => $this->formatOrderRow($row),
            'duplicate' => (bool) $result['existing'],
            'message' => (bool) $result['existing']
                ? '委托已存在'
                : '委托已提交',
            'account' => $this->accountContext((string) $market['symbol']),
        ];
    }

    public function cancel(string $orderNo): array
    {
        $auth = $this->authContext();
        $account = $this->businessAccount();
        $this->rateLimit('cancel:user:' . (int) $auth['user_id'], (int) config('perp.rate_limit.cancel_per_minute', 60), 60);
        $orderNo = strtoupper(trim($orderNo));
        if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $orderNo)) {
            throw new AssetException('委托编号无效', 422, 'PERP_ORDER_NO_INVALID');
        }

        $row = Db::transaction(function () use ($auth, $account, $orderNo) {
            $order = Db::table('cex_perp_orders')
                ->where('order_no', $orderNo)
                ->where('account_id', (int) $account['id'])
                ->lock(true)
                ->find();
            if (!$order) throw new AssetException('未找到该合约委托', 404, 'PERP_ORDER_NOT_FOUND');
            $status = (int) $order['status'];
            if ($status === self::STATUS_CANCELLED) return $order;
            if (!in_array($status, [self::STATUS_OPEN, self::STATUS_PARTIALLY_FILLED], true)) {
                throw new AssetException('当前委托状态不可撤销', 409, 'PERP_ORDER_NOT_CANCELLABLE');
            }
            $zeroReserveReduceOnly = (bool) $order['reduce_only']
                && Decimal18::compare((string) $order['reserved_order_margin'], '0') === 0
                && empty($order['hold_id']);
            if (empty($order['hold_id']) && !$zeroReserveReduceOnly) {
                throw new AssetException('委托保证金冻结记录不完整，已停止自动撤单', 500, 'PERP_ORDER_HOLD_MISSING');
            }

            $hold = null;
            $releaseAmount = Decimal18::zero();
            if (!$zeroReserveReduceOnly) {
                $hold = Db::table('cex_asset_holds')->where('id', (int) $order['hold_id'])->lock(true)->find();
                if (!$hold) throw new AssetException('委托保证金冻结记录不存在', 500, 'PERP_ORDER_HOLD_NOT_FOUND');
                $releaseAmount = Decimal18::normalize((string) $hold['remaining_amount']);
            }
            $now = UtcClock::now();
            if ($hold && Decimal18::compare($releaseAmount, '0') > 0) {
                $this->ledger->postWithinTransaction([
                    'business_type' => 'PERP_ORDER_RELEASE',
                    'business_id' => (string) $order['order_no'],
                    'idempotency_key' => 'perp-order-release:' . (string) $order['order_no'],
                    'request_id' => Ulid::generate(),
                    'description' => 'Perpetual order cancellation margin release',
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

            if ($hold) {
                Db::table('cex_asset_holds')->where('id', (int) $hold['id'])->update([
                    'remaining_amount' => Decimal18::zero(),
                    'status' => 4,
                    'released_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            Db::table('cex_perp_orders')->where('id', (int) $order['id'])->update([
                'status' => self::STATUS_CANCELLED,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);
            $lastSequence = (int) Db::table('cex_perp_order_events')->where('order_id', (int) $order['id'])->max('event_sequence');
            Db::table('cex_perp_order_events')->insert([
                'order_id' => (int) $order['id'],
                'event_sequence' => $lastSequence + 1,
                'event_type' => 'CANCELLED',
                'previous_status' => $status,
                'new_status' => self::STATUS_CANCELLED,
                'executed_quantity_delta' => Decimal18::zero(),
                'request_id' => Ulid::generate(),
                'occurred_at' => $now,
                'created_at' => $now,
            ]);
            $this->writeOutbox('PERP_ORDER_CANCELLED', (string) $order['order_no'], [
                'order_id' => (int) $order['id'],
                'order_no' => (string) $order['order_no'],
                'account_id' => (int) $account['id'],
                'contract_id' => (int) $order['contract_id'],
                'released_amount' => Decimal18::trim($releaseAmount),
                'cancelled_at' => $now,
            ], $now);

            AuditLog::record($this->request, 'PERP_ORDER_CANCELLED', (int) $auth['user_id'], 1, 'perp_order', (string) $order['order_no'], [
                'released_amount' => Decimal18::trim($releaseAmount),
            ]);
            return Db::table('cex_perp_orders')->where('id', (int) $order['id'])->find();
        });

        $full = $this->orderWithMarket((int) $row['id']);
        return [
            'order' => $this->formatOrderRow($full),
            'message' => '委托已撤销',
            'account' => $this->accountContext((string) $full['symbol']),
        ];
    }

    private function market(string $symbol, bool $mustTrade): array
    {
        $symbol = $this->normalizeSymbol($symbol, true);
        $row = Db::table('cex_market_perpetual_contracts')->alias('c')
            ->join('cex_market_price_indices pi', 'pi.id = c.price_index_id')
            ->join('cex_asset_assets ba', 'ba.id = c.base_asset_id')
            ->join('cex_asset_assets qa', 'qa.id = c.quote_asset_id')
            ->join('cex_asset_assets sa', 'sa.id = c.settlement_asset_id')
            ->where('c.symbol', $symbol)
            ->field('c.id,c.symbol,c.base_asset_id,c.quote_asset_id,c.settlement_asset_id,c.price_index_id,c.contract_size,c.price_tick,c.quantity_step,c.min_quantity,c.max_quantity,c.min_notional,c.max_notional,c.max_leverage,c.initial_margin_rate,c.maintenance_margin_rate,c.liquidation_fee_rate,c.maker_fee_rate,c.taker_fee_rate,c.funding_interval_minutes,c.funding_rate_cap,c.funding_rate_floor,c.status,pi.index_code,pi.status AS index_status,ba.code AS base_code,ba.name AS base_name,ba.display_decimals AS base_decimals,ba.status AS base_status,qa.code AS quote_code,qa.name AS quote_name,qa.display_decimals AS quote_decimals,qa.status AS quote_status,sa.code AS settlement_code,sa.display_decimals AS settlement_decimals,sa.status AS settlement_status,sa.perpetual_margin_enabled')
            ->find();
        if (!$row) throw new AssetException('永续合约不存在', 404, 'PERP_SYMBOL_NOT_FOUND');
        if ($mustTrade && (
            (int) $row['status'] !== 1
            || (int) $row['index_status'] !== 1
            || (int) $row['base_status'] !== 1
            || (int) $row['quote_status'] !== 1
            || (int) $row['settlement_status'] !== 1
            || !(bool) $row['perpetual_margin_enabled']
        )) {
            throw new AssetException('该永续合约当前不可交易', 409, 'PERP_SYMBOL_UNAVAILABLE');
        }
        return $row;
    }

    private function normalizeSymbol(string $symbol, bool $required): string
    {
        $raw = strtoupper(trim($symbol));
        if ($raw === '') {
            if ($required) throw new AssetException('请选择永续合约', 422, 'PERP_SYMBOL_REQUIRED');
            return '';
        }
        if (substr($raw, -5) === '-SWAP') {
            $raw = preg_replace('/[^A-Z0-9]/', '', substr($raw, 0, -5)) . '-PERP';
        } elseif (substr($raw, -5) !== '-PERP') {
            $raw = preg_replace('/[^A-Z0-9]/', '', $raw) . '-PERP';
        } else {
            $raw = preg_replace('/[^A-Z0-9-]/', '', $raw);
        }
        if (!preg_match('/^[A-Z0-9]+-PERP$/', $raw)) {
            throw new AssetException('永续合约代码格式无效', 422, 'PERP_SYMBOL_INVALID');
        }
        return $raw;
    }

    private function setting(int $accountId, array $market, bool $create): array
    {
        $row = Db::table('cex_perp_account_contract_settings')
            ->where('account_id', $accountId)
            ->where('contract_id', (int) $market['id'])
            ->field('account_id,contract_id,leverage,margin_mode,position_mode,status,version,created_at,updated_at')
            ->find();
        if ($row || !$create) {
            if (!$row) throw new AssetException('合约杠杆设置不存在', 500, 'PERP_SETTING_MISSING');
            return $row;
        }

        $default = $this->normalizeLeverage((string) config('perp.default_leverage', '5'), $market);
        $now = UtcClock::now();
        try {
            Db::execute(
                'INSERT INTO `cex_perp_account_contract_settings` '
                . '(`account_id`,`contract_id`,`leverage`,`margin_mode`,`position_mode`,`status`,`version`,`created_at`,`updated_at`) '
                . 'VALUES (?,?,CAST(? AS DECIMAL(10,4)),?,?,1,0,?,?)',
                [$accountId, (int) $market['id'], $default, self::MARGIN_MODE_CROSS, self::POSITION_MODE_ONE_WAY, $now, $now]
            );
        } catch (\Throwable $exception) {
            $row = Db::table('cex_perp_account_contract_settings')
                ->where('account_id', $accountId)
                ->where('contract_id', (int) $market['id'])
                ->find();
            if (!$row) throw $exception;
            return $row;
        }

        return Db::table('cex_perp_account_contract_settings')
            ->where('account_id', $accountId)
            ->where('contract_id', (int) $market['id'])
            ->find();
    }

    private function normalizeLeverage(string $value, array $market): string
    {
        $value = trim($value);
        if (!preg_match('/^\d+(?:\.0{1,4})?$/', $value)) {
            throw new AssetException('杠杆倍数格式无效', 422, 'PERP_LEVERAGE_INVALID');
        }
        $normalized = Decimal18::normalize($value);
        if (Decimal18::compare($normalized, '1') < 0 || Decimal18::compare($normalized, (string) $market['max_leverage']) > 0) {
            throw new AssetException('杠杆倍数超出当前合约允许范围', 422, 'PERP_LEVERAGE_OUT_OF_RANGE');
        }
        $allowed = array_map('strval', (array) config('perp.allowed_leverage', ['1', '2', '3', '5', '10', '20']));
        $allowedMatch = false;
        foreach ($allowed as $candidate) {
            if (Decimal18::compare($normalized, $candidate) === 0) {
                $allowedMatch = true;
                break;
            }
        }
        if (!$allowedMatch) {
            throw new AssetException('请选择平台支持的杠杆倍数', 422, 'PERP_LEVERAGE_NOT_ALLOWED');
        }
        return $normalized;
    }

    private function orderRiskCalculation(string $price, string $quantity, string $contractSize, string $leverage, string $takerFeeRate, bool $reduceOnly): array
    {
        $rows = Db::query(
            'SELECT '
            . 'CAST(CAST(? AS DECIMAL(38,18))*CAST(? AS DECIMAL(38,18))*CAST(? AS DECIMAL(38,18)) AS DECIMAL(38,18)) AS notional_value,'
            . 'CAST((CAST(? AS DECIMAL(38,18))*CAST(? AS DECIMAL(38,18))*CAST(? AS DECIMAL(38,18)))/CAST(? AS DECIMAL(10,4)) AS DECIMAL(38,18)) AS margin_value,'
            . 'CAST((CAST(? AS DECIMAL(38,18))*CAST(? AS DECIMAL(38,18))*CAST(? AS DECIMAL(38,18)))*CAST(? AS DECIMAL(18,10)) AS DECIMAL(38,18)) AS fee_value',
            [
                $price, $quantity, $contractSize,
                $price, $quantity, $contractSize, $leverage,
                $price, $quantity, $contractSize, $takerFeeRate,
            ]
        );
        if (!isset($rows[0]['notional_value'], $rows[0]['margin_value'], $rows[0]['fee_value'])) {
            throw new AssetException('合约保证金计算失败', 500, 'PERP_MARGIN_CALCULATION_FAILED');
        }
        $notional = Decimal18::normalize((string) $rows[0]['notional_value']);
        $initialMargin = $reduceOnly ? Decimal18::zero() : Decimal18::normalize((string) $rows[0]['margin_value']);
        $feeBuffer = $reduceOnly
            ? Decimal18::zero()
            : ((bool) config('perp.reserve_taker_fee_buffer', true)
                ? Decimal18::normalize((string) $rows[0]['fee_value'])
                : Decimal18::zero());
        return [
            'notional' => $notional,
            'initial_margin' => $initialMargin,
            'fee_buffer' => $feeBuffer,
            'reserve_amount' => Decimal18::add($initialMargin, $feeBuffer),
        ];
    }

    private function assertReduceOnly(int $accountId, array $market, int $side, string $quantity): void
    {
        $row = Db::table('cex_perp_positions')
            ->where('account_id', $accountId)
            ->where('contract_id', (int) $market['id'])
            ->field('position_quantity')
            ->find();
        if (!$row || Decimal18::compare((string) $row['position_quantity'], '0') === 0) {
            throw new AssetException('当前没有可供只减仓委托减少的持仓', 409, 'PERP_REDUCE_ONLY_NO_POSITION');
        }
        $positionQty = Decimal18::normalize((string) $row['position_quantity']);
        $isLong = Decimal18::compare($positionQty, '0') > 0;
        if (($isLong && $side !== self::SIDE_SELL) || (!$isLong && $side !== self::SIDE_BUY)) {
            throw new AssetException('只减仓委托方向会增加当前风险敞口', 409, 'PERP_REDUCE_ONLY_SIDE_INVALID');
        }
        if (Decimal18::compare($quantity, $this->absDecimal($positionQty)) > 0) {
            throw new AssetException('只减仓数量不能超过当前持仓', 422, 'PERP_REDUCE_ONLY_TOO_LARGE');
        }
    }

    private function writeOutbox(string $eventType, string $orderNo, array $payload, string $now): void
    {
        if (!(bool) config('perp.outbox_enabled', true)) return;
        $dedupe = strtolower(str_replace('_', '-', $eventType)) . ':' . $orderNo;
        try {
            Db::table('cex_system_outbox_events')->insert([
                'event_no' => Ulid::generate(),
                'aggregate_type' => 'PERP_ORDER',
                'aggregate_id' => $orderNo,
                'event_type' => $eventType,
                'deduplication_key' => $dedupe,
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 1,
                'retry_count' => 0,
                'available_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $exception) {
            $existing = Db::table('cex_system_outbox_events')->where('deduplication_key', $dedupe)->field('id,event_type,aggregate_id')->find();
            if (!$existing || (string) $existing['event_type'] !== $eventType || (string) $existing['aggregate_id'] !== $orderNo) {
                throw $exception;
            }
        }
    }

    private function formatMarket(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'symbol' => (string) $row['symbol'],
            'pair' => (string) $row['base_code'] . '/' . (string) $row['quote_code'],
            'base_code' => (string) $row['base_code'],
            'quote_code' => (string) $row['quote_code'],
            'settlement_code' => (string) $row['settlement_code'],
            'contract_size' => Decimal18::trim((string) $row['contract_size']),
            'price_tick' => Decimal18::trim((string) $row['price_tick']),
            'quantity_step' => Decimal18::trim((string) $row['quantity_step']),
            'min_quantity' => Decimal18::trim((string) $row['min_quantity']),
            'max_quantity' => $row['max_quantity'] !== null ? Decimal18::trim((string) $row['max_quantity']) : null,
            'min_notional' => Decimal18::trim((string) $row['min_notional']),
            'max_notional' => $row['max_notional'] !== null ? Decimal18::trim((string) $row['max_notional']) : null,
            'max_leverage' => Decimal18::trim((string) $row['max_leverage'], 4),
            'initial_margin_rate' => (string) $row['initial_margin_rate'],
            'maintenance_margin_rate' => (string) $row['maintenance_margin_rate'],
            'liquidation_fee_rate' => (string) $row['liquidation_fee_rate'],
            'maker_fee_rate' => (string) $row['maker_fee_rate'],
            'taker_fee_rate' => (string) $row['taker_fee_rate'],
            'funding_interval_minutes' => (int) $row['funding_interval_minutes'],
            'funding_rate_cap' => (string) $row['funding_rate_cap'],
            'funding_rate_floor' => (string) $row['funding_rate_floor'],
            'status' => (int) $row['status'],
            'index_code' => (string) $row['index_code'],
        ];
    }

    private function formatSetting(array $row): array
    {
        return [
            'leverage' => Decimal18::trim((string) $row['leverage'], 4),
            'margin_mode' => (int) $row['margin_mode'] === self::MARGIN_MODE_CROSS ? 'CROSS' : 'UNKNOWN',
            'position_mode' => (int) $row['position_mode'] === self::POSITION_MODE_ONE_WAY ? 'ONE_WAY' : 'UNKNOWN',
            'status' => (int) $row['status'],
            'version' => (int) $row['version'],
        ];
    }

    private function formatOrderRow(array $row): array
    {
        $baseDecimals = (int) ($row['base_decimals'] ?? 8);
        $quoteDecimals = (int) ($row['quote_decimals'] ?? 6);
        $status = (int) $row['status'];
        $quantity = $row['original_quantity'] !== null ? Decimal18::trim((string) $row['original_quantity'], $baseDecimals) : null;
        $price = $row['price'] !== null ? Decimal18::trim((string) $row['price'], $quoteDecimals) : null;
        $notional = null;
        if ($price !== null && $quantity !== null) {
            try {
                $calc = $this->orderRiskCalculation((string) $row['price'], (string) $row['original_quantity'], (string) ($row['contract_size'] ?? '1'), (string) $row['requested_leverage'], '0', true);
                $notional = Decimal18::trim($calc['notional'], $quoteDecimals);
            } catch (\Throwable $ignored) {
                $notional = null;
            }
        }
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
            'price' => $price,
            'average_price' => $row['average_price'] !== null ? Decimal18::trim((string) $row['average_price'], $quoteDecimals) : null,
            'original_quantity' => $quantity,
            'executed_quantity' => Decimal18::trim((string) $row['executed_quantity'], $baseDecimals),
            'notional' => $notional,
            'requested_leverage' => Decimal18::trim((string) $row['requested_leverage'], 4),
            'reserved_order_margin' => Decimal18::trim((string) $row['reserved_order_margin'], $quoteDecimals),
            'reduce_only' => (bool) $row['reduce_only'],
            'close_on_trigger' => (bool) $row['close_on_trigger'],
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
        $row = Db::table('cex_perp_orders')->alias('o')
            ->join('cex_market_perpetual_contracts c', 'c.id = o.contract_id')
            ->join('cex_asset_assets ba', 'ba.id = c.base_asset_id')
            ->join('cex_asset_assets qa', 'qa.id = c.quote_asset_id')
            ->where('o.id', $id)
            ->field('o.*,c.symbol,c.contract_size,ba.code AS base_code,ba.display_decimals AS base_decimals,qa.code AS quote_code,qa.display_decimals AS quote_decimals')
            ->find();
        if (!$row) throw new AssetException('合约委托不存在', 404, 'PERP_ORDER_NOT_FOUND');
        return $row;
    }

    private function openOrderCount(int $accountId, int $contractId): int
    {
        return (int) Db::table('cex_perp_orders')
            ->where('account_id', $accountId)
            ->where('contract_id', $contractId)
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
        throw new AssetException('交易方向无效', 422, 'PERP_SIDE_INVALID');
    }

    private function clientOrderId(string $value): string
    {
        $value = trim($value);
        if ($value === '') $value = 'web-perp-' . strtolower(Ulid::generate());
        if (!preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $value)) {
            throw new AssetException('客户端委托编号格式无效', 422, 'PERP_CLIENT_ORDER_ID_INVALID');
        }
        return $value;
    }

    private function assertSameOrderRequest(array $existing, array $market, int $side, string $price, string $quantity, string $leverage, bool $reduceOnly): void
    {
        if ((int) $existing['contract_id'] !== (int) $market['id']
            || (int) $existing['side'] !== $side
            || (int) $existing['order_type'] !== self::TYPE_LIMIT
            || Decimal18::compare((string) $existing['price'], $price) !== 0
            || Decimal18::compare((string) $existing['original_quantity'], $quantity) !== 0
            || Decimal18::compare((string) $existing['requested_leverage'], $leverage) !== 0
            || (bool) $existing['reduce_only'] !== $reduceOnly) {
            throw new AssetException('客户端委托编号与原请求不一致', 409, 'PERP_ORDER_IDEMPOTENCY_CONFLICT');
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

    private function marketableClosePrice(int $side, string $referencePrice, string $tick, int $protectionBps): string
    {
        $referencePrice = Decimal18::positive($referencePrice);
        $tick = Decimal18::positive($tick);
        $bps = max(0, min(500, $protectionBps));
        $direction = $side === self::SIDE_SELL ? -1 : 1;
        $rows = Db::query(
            'SELECT CAST(CASE WHEN ? < 0 '
            . 'THEN FLOOR(((CAST(? AS DECIMAL(38,18))*(10000-?))/10000)/CAST(? AS DECIMAL(38,18)))*CAST(? AS DECIMAL(38,18)) '
            . 'ELSE CEIL(((CAST(? AS DECIMAL(38,18))*(10000+?))/10000)/CAST(? AS DECIMAL(38,18)))*CAST(? AS DECIMAL(38,18)) END AS CHAR) AS close_price',
            [$direction, $referencePrice, $bps, $tick, $tick, $referencePrice, $bps, $tick, $tick]
        );
        if (!isset($rows[0]['close_price'])) {
            throw new AssetException('平仓保护价格计算失败', 500, 'PERP_CLOSE_PRICE_CALC_FAILED');
        }
        $price = Decimal18::positive((string) $rows[0]['close_price']);
        $this->assertStepAligned($price, $tick, 'PERP_CLOSE_PRICE_TICK_INVALID', '平仓保护价格无效');
        return $price;
    }

    private function absDecimal(string $value): string
    {
        $value = Decimal18::normalize($value);
        return $value[0] === '-' ? substr($value, 1) : $value;
    }

    private function eligibility(array $auth, bool $throw): array
    {
        $reasonCode = null;
        $reason = null;
        if ((int) $auth['user_status'] !== 1) {
            $reasonCode = 'PERP_USER_UNAVAILABLE';
            $reason = '账户当前不可进行合约交易';
        } elseif (empty($auth['email_verified_at'])) {
            $reasonCode = 'PERP_EMAIL_REQUIRED';
            $reason = '请先完成安全邮箱验证';
        } elseif ((bool) config('perp.require_totp', false)) {
            $security = Db::table('cex_user_security')->where('user_id', (int) $auth['user_id'])->field('totp_enabled')->find();
            if (!$security || !(bool) $security['totp_enabled']) {
                $reasonCode = 'PERP_TOTP_REQUIRED';
                $reason = '请先启用身份验证器后再进行合约交易';
            }
        }

        if ($reasonCode === null && (bool) config('perp.require_kyc', true)) {
            if ((int) $auth['kyc_level'] < 1) {
                $reasonCode = 'PERP_KYC_REQUIRED';
                $reason = '请先完成身份认证';
            } else {
                $approved = Db::table('cex_user_kyc')
                    ->where('user_id', (int) $auth['user_id'])
                    ->where('status', 3)
                    ->whereNotNull('approved_at')
                    ->whereRaw('(expires_at IS NULL OR expires_at > UTC_TIMESTAMP(6))')
                    ->field('id')
                    ->find();
                if (!$approved) {
                    $reasonCode = 'PERP_KYC_NOT_APPROVED';
                    $reason = '身份认证当前不可用于交易，请重新完成认证';
                }
            }
        }

        if ($reasonCode === null) {
            $restriction = Db::table('cex_user_restrictions')
                ->where('user_id', (int) $auth['user_id'])
                ->where('restriction_type', (int) config('perp.trading_restriction_type', 2))
                ->where('status', 1)
                ->where('starts_at', '<=', UtcClock::now())
                ->whereRaw('(expires_at IS NULL OR expires_at > UTC_TIMESTAMP(6))')
                ->field('id,reason_code')
                ->find();
            if ($restriction) {
                $reasonCode = 'PERP_TRADING_RESTRICTED';
                $reason = '账户合约交易功能当前受限，请联系支持';
            }
        }

        if ($throw && $reasonCode !== null) throw new AssetException($reason, 403, $reasonCode);
        return ['eligible' => $reasonCode === null, 'reason_code' => $reasonCode, 'reason' => $reason];
    }

    private function assertEligible(array $auth): void
    {
        $this->eligibility($auth, true);
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
            throw new AssetException('合约账户当前不可用', 409, 'PERP_ACCOUNT_UNAVAILABLE');
        }
        $this->businessAccount = $row;
        return $row;
    }

    private function rateLimit(string $key, int $limit, int $seconds): void
    {
        if ($limit <= 0) return;
        $cacheKey = 'perp:rl:' . hash('sha256', $key);
        $state = Cache::get($cacheKey);
        $now = time();
        if (!is_array($state) || (int) ($state['reset_at'] ?? 0) <= $now) {
            Cache::set($cacheKey, ['count' => 1, 'reset_at' => $now + $seconds], $seconds);
            return;
        }
        if ((int) ($state['count'] ?? 0) >= $limit) {
            throw new AssetException('合约交易请求过于频繁，请稍后再试', 429, 'PERP_RATE_LIMITED');
        }
        $state['count'] = (int) $state['count'] + 1;
        Cache::set($cacheKey, $state, max(1, (int) $state['reset_at'] - $now));
    }
}
