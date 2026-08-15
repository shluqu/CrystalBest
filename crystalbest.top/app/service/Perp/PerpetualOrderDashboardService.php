<?php

namespace app\service\Perp;

use app\controller\Auth\AuthService;
use app\controller\Auth\BusinessAccountService;
use app\service\Asset\AssetException;
use app\service\Asset\Decimal18;
use think\facade\Db;
use think\Request;

/**
 * Read-only perpetual order dashboard.
 *
 * This service never mutates orders/fills/positions/ledger and never uses
 * FOR UPDATE. Order profit is projected from committed cex_perp_fills through
 * PerpOrderProfitService, the same calculator used by the trade-page history.
 */
final class PerpetualOrderDashboardService
{
    private const STATUS_CREATED = 1;
    private const STATUS_OPEN = 2;
    private const STATUS_PARTIALLY_FILLED = 3;
    private const STATUS_FILLED = 4;
    private const STATUS_CANCELLED = 5;
    private const STATUS_REJECTED = 6;
    private const STATUS_EXPIRED = 7;

    private Request $request;
    private ?array $authContext = null;
    private ?array $businessAccount = null;
    private ?array $userTimezone = null;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function context(): array
    {
        $account = $this->businessAccount();
        $accountId = (int) $account['id'];
        $filters = $this->filters();

        $total = (int) $this->ordersQuery($accountId, $filters)->count();
        $totalPages = max(1, (int) ceil($total / $filters['page_size']));
        $page = min($filters['page'], $totalPages);
        $filters['page'] = $page;
        $offset = ($page - 1) * $filters['page_size'];

        $rows = $this->ordersQuery($accountId, $filters)
            ->field(implode(',', [
                'o.id','o.order_no','o.client_order_id','o.side','o.order_type','o.time_in_force','o.price',
                'o.original_quantity','o.executed_quantity','o.average_price','o.reduce_only','o.close_on_trigger',
                'o.requested_leverage','o.reserved_order_margin','o.status','o.reject_code','o.engine_sequence',
                'o.created_at','o.opened_at','o.completed_at','o.updated_at',
                'c.id AS contract_id','c.symbol','c.contract_size','c.maker_fee_rate','c.taker_fee_rate',
                'ba.code AS base_code','ba.display_decimals AS base_decimals',
                'qa.code AS quote_code','qa.display_decimals AS quote_decimals',
            ]))
            ->order('o.id', 'desc')
            ->limit($offset, $filters['page_size'])
            ->select()
            ->toArray();

        $profitService = new PerpOrderProfitService();
        $profitMap = $profitService->forHistory($accountId, $rows);
        $fillMap = $this->fillDetails($accountId, array_column($rows, 'id'));

        $orders = [];
        foreach ($rows as $row) {
            $orders[] = $this->formatOrder(
                $row,
                $profitMap[(int) $row['id']] ?? null,
                $fillMap[(int) $row['id']] ?? null
            );
        }

        return [
            'account' => $account,
            'timezone' => ['name' => $this->timezoneInfo()['name']],
            'filters' => $filters,
            'markets' => $this->markets(),
            'orders' => $orders,
            'pagination' => $this->pagination($filters, $total, $totalPages),
            'stats' => $this->summaryStats($accountId, $filters, $profitService),
        ];
    }

    private function ordersQuery(int $accountId, array $filters)
    {
        $query = Db::table('cex_perp_orders')->alias('o')
            ->join('cex_market_perpetual_contracts c', 'c.id=o.contract_id')
            ->join('cex_asset_assets ba', 'ba.id=c.base_asset_id')
            ->join('cex_asset_assets qa', 'qa.id=c.quote_asset_id')
            ->where('o.account_id', $accountId);

        if ($filters['symbol'] !== '') $query->where('c.symbol', $filters['symbol']);
        if ($filters['side'] === 'BUY') $query->where('o.side', 1);
        elseif ($filters['side'] === 'SELL') $query->where('o.side', 2);

        switch ($filters['status']) {
            case 'open':
                $query->whereIn('o.status', [self::STATUS_CREATED, self::STATUS_OPEN, self::STATUS_PARTIALLY_FILLED]);
                break;
            case 'filled':
                $query->where('o.status', self::STATUS_FILLED);
                break;
            case 'cancelled':
                $query->where('o.status', self::STATUS_CANCELLED);
                break;
            case 'rejected':
                $query->whereIn('o.status', [self::STATUS_REJECTED, self::STATUS_EXPIRED]);
                break;
        }

        if ($filters['start_utc'] !== null) $query->where('o.created_at', '>=', $filters['start_utc']);
        if ($filters['end_utc'] !== null) $query->where('o.created_at', '<', $filters['end_utc']);
        return $query;
    }

    private function filters(): array
    {
        $page = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('page_size', 20);
        if (!in_array($pageSize, [20, 50, 100], true)) $pageSize = 20;

        $status = strtolower(trim((string) $this->request->get('status', 'all')));
        if (!in_array($status, ['all', 'open', 'filled', 'cancelled', 'rejected'], true)) $status = 'all';

        $side = strtoupper(trim((string) $this->request->get('side', 'ALL')));
        if (!in_array($side, ['ALL', 'BUY', 'SELL'], true)) $side = 'ALL';

        $symbol = strtoupper(trim((string) $this->request->get('symbol', '')));
        $symbol = preg_replace('/[^A-Z0-9-]/', '', $symbol) ?: '';
        if ($symbol !== '' && !Db::table('cex_market_perpetual_contracts')->where('symbol', $symbol)->field('id')->find()) {
            $symbol = '';
        }

        $period = strtolower(trim((string) $this->request->get('period', 'all')));
        if (!in_array($period, ['all', 'today', '7d', '30d', '90d', 'custom'], true)) $period = 'all';

        $tzInfo = $this->timezoneInfo();
        $tz = $tzInfo['object'];
        $today = new \DateTimeImmutable('today', $tz);
        $startLocal = null;
        $endLocal = null;
        $startDate = '';
        $endDate = '';

        if ($period === 'today') {
            $startLocal = $today;
            $endLocal = $today->modify('+1 day');
            $startDate = $today->format('Y-m-d');
            $endDate = $today->format('Y-m-d');
        } elseif (in_array($period, ['7d','30d','90d'], true)) {
            $days = (int) substr($period, 0, -1);
            $startLocal = $today->modify('-' . max(0, $days - 1) . ' days');
            $endLocal = $today->modify('+1 day');
            $startDate = $startLocal->format('Y-m-d');
            $endDate = $today->format('Y-m-d');
        } elseif ($period === 'custom') {
            $rawStart = trim((string) $this->request->get('start_date', ''));
            $rawEnd = trim((string) $this->request->get('end_date', ''));
            if ($this->validDate($rawStart) && $this->validDate($rawEnd)) {
                $candidateStart = new \DateTimeImmutable($rawStart . ' 00:00:00', $tz);
                $candidateEndDay = new \DateTimeImmutable($rawEnd . ' 00:00:00', $tz);
                if ($candidateEndDay < $candidateStart) {
                    [$candidateStart, $candidateEndDay] = [$candidateEndDay, $candidateStart];
                }
                // User-initiated reporting can cover a long range, but cap one
                // custom request to two years to prevent accidental huge scans.
                $maxEndDay = $candidateStart->modify('+729 days');
                if ($candidateEndDay > $maxEndDay) $candidateEndDay = $maxEndDay;
                $startLocal = $candidateStart;
                $endLocal = $candidateEndDay->modify('+1 day');
                $startDate = $candidateStart->format('Y-m-d');
                $endDate = $candidateEndDay->format('Y-m-d');
            } else {
                $period = '30d';
                $startLocal = $today->modify('-29 days');
                $endLocal = $today->modify('+1 day');
                $startDate = $startLocal->format('Y-m-d');
                $endDate = $today->format('Y-m-d');
            }
        }

        $utc = new \DateTimeZone('UTC');
        return [
            'page' => $page,
            'page_size' => $pageSize,
            'status' => $status,
            'side' => $side,
            'symbol' => $symbol,
            'period' => $period,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'start_utc' => $startLocal ? $startLocal->setTimezone($utc)->format('Y-m-d H:i:s.u') : null,
            'end_utc' => $endLocal ? $endLocal->setTimezone($utc)->format('Y-m-d H:i:s.u') : null,
        ];
    }

    private function validDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function fillDetails(int $accountId, array $orderIds): array
    {
        $orderIds = array_values(array_filter(array_map('intval', $orderIds), fn($id) => $id > 0));
        if (!$orderIds) return [];

        $fills = Db::table('cex_perp_fills')
            ->where('account_id', $accountId)
            ->whereIn('order_id', $orderIds)
            ->field('id,order_id,price,quantity,notional,fee_amount,realized_pnl,position_quantity_before,position_quantity_after,entry_price_before,entry_price_after,created_at')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $map = [];
        foreach ($fills as $fill) {
            $orderId = (int) $fill['order_id'];
            if (!isset($map[$orderId])) {
                $map[$orderId] = [
                    'fill_count' => 0,
                    'notional' => Decimal18::zero(),
                    'fee_amount' => Decimal18::zero(),
                    'realized_pnl' => Decimal18::zero(),
                    'position_quantity_before' => (string) $fill['position_quantity_before'],
                    'position_quantity_after' => (string) $fill['position_quantity_after'],
                    'entry_price_before' => $fill['entry_price_before'] !== null ? (string) $fill['entry_price_before'] : null,
                    'entry_price_after' => $fill['entry_price_after'] !== null ? (string) $fill['entry_price_after'] : null,
                    'first_fill_at' => (string) $fill['created_at'],
                    'last_fill_at' => (string) $fill['created_at'],
                ];
            }
            $map[$orderId]['fill_count']++;
            $map[$orderId]['notional'] = Decimal18::add($map[$orderId]['notional'], (string) $fill['notional']);
            $map[$orderId]['fee_amount'] = Decimal18::add($map[$orderId]['fee_amount'], (string) $fill['fee_amount']);
            $map[$orderId]['realized_pnl'] = Decimal18::add($map[$orderId]['realized_pnl'], (string) $fill['realized_pnl']);
            $map[$orderId]['position_quantity_after'] = (string) $fill['position_quantity_after'];
            $map[$orderId]['entry_price_after'] = $fill['entry_price_after'] !== null ? (string) $fill['entry_price_after'] : null;
            $map[$orderId]['last_fill_at'] = (string) $fill['created_at'];
        }
        return $map;
    }

    private function summaryStats(int $accountId, array $filters, PerpOrderProfitService $profitService): array
    {
        $filtered = in_array($filters['status'], ['all', 'filled'], true)
            ? $this->profitTotals(
                $accountId,
                $filters,
                $profitService,
                $filters['start_utc'],
                $filters['end_utc']
            )
            : $this->emptyProfitTotals();

        // "今日盈亏" is today's realized order profit after opening-fee
        // allocation and the current closing fee. It follows symbol/side
        // filters, but is independent from the selected period/status.
        $tz = $this->timezoneInfo()['object'];
        $localStart = new \DateTimeImmutable('today', $tz);
        $localEnd = $localStart->modify('+1 day');
        $utc = new \DateTimeZone('UTC');
        $today = $this->profitTotals(
            $accountId,
            $filters,
            $profitService,
            $localStart->setTimezone($utc)->format('Y-m-d H:i:s.u'),
            $localEnd->setTimezone($utc)->format('Y-m-d H:i:s.u')
        );

        $filtered['today_net_profit'] = $today['net_profit'];
        $filtered['today_net_profit_display'] = $today['net_profit_display'];
        $filtered['today_profit_class'] = $today['profit_class'];
        $filtered['today_profit_order_count'] = $today['profit_order_count'];
        return $filtered;
    }

    private function profitTotals(
        int $accountId,
        array $filters,
        PerpOrderProfitService $profitService,
        ?string $startUtc,
        ?string $endUtc
    ): array {
        $query = Db::table('cex_perp_fills')->alias('f')
            ->join('cex_perp_orders o', 'o.id=f.order_id')
            ->join('cex_market_perpetual_contracts c', 'c.id=o.contract_id')
            ->where('f.account_id', $accountId)
            ->where('o.status', self::STATUS_FILLED);

        if ($filters['symbol'] !== '') $query->where('c.symbol', $filters['symbol']);
        if ($filters['side'] === 'BUY') $query->where('o.side', 1);
        elseif ($filters['side'] === 'SELL') $query->where('o.side', 2);
        if ($startUtc !== null) $query->where('f.created_at', '>=', $startUtc);
        if ($endUtc !== null) $query->where('f.created_at', '<', $endUtc);

        $targets = $query
            ->field('o.id,MAX(f.created_at) AS profit_at')
            ->group('o.id')
            ->order('o.id', 'asc')
            ->select()
            ->toArray();

        if (!$targets) return $this->emptyProfitTotals();

        $profitMap = $profitService->forHistory($accountId, $targets);
        $net = Decimal18::zero();
        $count = 0;
        foreach ($targets as $target) {
            $profit = $profitMap[(int) $target['id']] ?? null;
            if (!$profit || $profit['order_profit'] === null) continue;
            $raw = Decimal18::normalize((string) ($profit['order_profit_raw'] ?? $profit['order_profit']));
            $net = Decimal18::add($net, $raw);
            $count++;
        }

        return [
            'profit_order_count' => $count,
            'net_profit' => Decimal18::trim($net, 6),
            'net_profit_display' => $this->signedProfit($net),
            'profit_class' => $this->profitClass($net),
        ];
    }

    private function emptyProfitTotals(): array
    {
        return [
            'profit_order_count' => 0,
            'net_profit' => '0',
            'net_profit_display' => '0',
            'profit_class' => 'flat',
        ];
    }

    private function formatOrder(array $row, ?array $profit, ?array $fill): array
    {
        $baseDecimals = (int) $row['base_decimals'];
        $quoteDecimals = (int) $row['quote_decimals'];
        $side = (int) $row['side'];
        $status = (int) $row['status'];
        $role = $profit['profit_role'] ?? null;
        $profitValue = $profit['order_profit'] ?? null;

        return [
            'id' => (int) $row['id'],
            'order_no' => (string) $row['order_no'],
            'client_order_id' => $row['client_order_id'] !== null ? (string) $row['client_order_id'] : null,
            'symbol' => (string) $row['symbol'],
            'pair' => (string) $row['base_code'] . '/' . (string) $row['quote_code'],
            'base_code' => (string) $row['base_code'],
            'quote_code' => (string) $row['quote_code'],
            'side' => $side === 1 ? 'BUY' : 'SELL',
            'side_label' => $side === 1 ? '买入' : '卖出',
            'side_class' => $side === 1 ? 'buy' : 'sell',
            'type_label' => $this->typeLabel((int) $row['order_type']),
            'price' => $row['price'] !== null ? Decimal18::trim((string) $row['price'], $quoteDecimals) : '—',
            'average_price' => $row['average_price'] !== null ? Decimal18::trim((string) $row['average_price'], $quoteDecimals) : '—',
            'original_quantity' => Decimal18::trim((string) $row['original_quantity'], $baseDecimals),
            'executed_quantity' => Decimal18::trim((string) $row['executed_quantity'], $baseDecimals),
            'requested_leverage' => Decimal18::trim((string) $row['requested_leverage'], 4),
            'reserved_order_margin' => Decimal18::trim((string) $row['reserved_order_margin'], 6),
            'reduce_only' => (bool) $row['reduce_only'],
            'close_on_trigger' => (bool) $row['close_on_trigger'],
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'status_class' => $this->statusClass($status),
            'role' => $role,
            'role_label' => $profit['profit_role_label'] ?? ($status === self::STATUS_FILLED ? '成交' : '—'),
            'order_profit' => $profitValue,
            'order_profit_display' => $profitValue === null ? null : $this->signedProfit((string) $profitValue),
            'profit_class' => $profitValue === null ? 'empty' : $this->profitClass((string) $profitValue),
            'profit_realized_pnl' => $profit['realized_pnl'] ?? '0',
            'profit_allocated_open_fee' => $profit['allocated_open_fee'] ?? '0',
            'profit_close_fee_amount' => $profit['close_fee_amount'] ?? '0',
            'flip_close_quantity' => $profit['flip_close_quantity'] ?? null,
            'flip_open_quantity' => $profit['flip_open_quantity'] ?? null,
            'fill_count' => $fill['fill_count'] ?? 0,
            'fill_notional' => $fill ? Decimal18::trim($fill['notional'], 6) : '0',
            'fill_fee_amount' => $fill ? Decimal18::trim($fill['fee_amount'], 6) : '0',
            'fill_realized_pnl' => $fill ? Decimal18::trim($fill['realized_pnl'], 6) : '0',
            'position_quantity_before' => $fill ? Decimal18::trim($fill['position_quantity_before'], $baseDecimals) : null,
            'position_quantity_after' => $fill ? Decimal18::trim($fill['position_quantity_after'], $baseDecimals) : null,
            'entry_price_before' => $fill && $fill['entry_price_before'] !== null ? Decimal18::trim($fill['entry_price_before'], $quoteDecimals) : null,
            'entry_price_after' => $fill && $fill['entry_price_after'] !== null ? Decimal18::trim($fill['entry_price_after'], $quoteDecimals) : null,
            'first_fill_at' => $fill['first_fill_at'] ?? null,
            'last_fill_at' => $fill['last_fill_at'] ?? null,
            'first_fill_at_local' => $fill && !empty($fill['first_fill_at']) ? $this->localTime((string) $fill['first_fill_at']) : null,
            'last_fill_at_local' => $fill && !empty($fill['last_fill_at']) ? $this->localTime((string) $fill['last_fill_at']) : null,
            'created_at' => (string) $row['created_at'],
            'created_at_local' => $this->localTime((string) $row['created_at']),
            'opened_at' => $row['opened_at'] !== null ? (string) $row['opened_at'] : null,
            'opened_at_local' => $row['opened_at'] !== null ? $this->localTime((string) $row['opened_at']) : null,
            'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
            'completed_at_local' => $row['completed_at'] !== null ? $this->localTime((string) $row['completed_at']) : null,
            'reject_code' => $row['reject_code'] !== null ? (string) $row['reject_code'] : null,
            'engine_sequence' => $row['engine_sequence'] !== null ? (int) $row['engine_sequence'] : null,
            'trade_url' => '/trade-swap/' . strtolower((string) $row['base_code'] . '-' . (string) $row['quote_code'] . '-swap'),
        ];
    }

    private function markets(): array
    {
        return Db::table('cex_market_perpetual_contracts')->alias('c')
            ->join('cex_asset_assets ba', 'ba.id=c.base_asset_id')
            ->join('cex_asset_assets qa', 'qa.id=c.quote_asset_id')
            ->where('c.status', 1)
            ->field('c.symbol,ba.code AS base_code,qa.code AS quote_code')
            ->order('c.id', 'asc')
            ->select()
            ->toArray();
    }

    private function pagination(array $filters, int $total, int $totalPages): array
    {
        $current = $filters['page'];
        $start = max(1, $current - 2);
        $end = min($totalPages, $current + 2);
        if ($end - $start < 4) {
            if ($start === 1) $end = min($totalPages, 5);
            if ($end === $totalPages) $start = max(1, $totalPages - 4);
        }

        $pages = [];
        for ($page = $start; $page <= $end; $page++) {
            $pages[] = ['page' => $page, 'active' => $page === $current, 'url' => $this->pageUrl($filters, $page)];
        }
        return [
            'total' => $total,
            'page' => $current,
            'page_size' => $filters['page_size'],
            'total_pages' => $totalPages,
            'from' => $total === 0 ? 0 : (($current - 1) * $filters['page_size']) + 1,
            'to' => min($total, $current * $filters['page_size']),
            'previous_url' => $current > 1 ? $this->pageUrl($filters, $current - 1) : null,
            'next_url' => $current < $totalPages ? $this->pageUrl($filters, $current + 1) : null,
            'pages' => $pages,
        ];
    }

    private function pageUrl(array $filters, int $page): string
    {
        $query = ['page' => $page, 'page_size' => $filters['page_size']];
        if ($filters['status'] !== 'all') $query['status'] = $filters['status'];
        if ($filters['side'] !== 'ALL') $query['side'] = $filters['side'];
        if ($filters['symbol'] !== '') $query['symbol'] = $filters['symbol'];
        if ($filters['period'] !== 'all') $query['period'] = $filters['period'];
        if ($filters['period'] === 'custom') {
            $query['start_date'] = $filters['start_date'];
            $query['end_date'] = $filters['end_date'];
        }
        return '/dashboard/perpetual-orders?' . http_build_query($query);
    }

    private function typeLabel(int $type): string
    {
        $map = [1 => '限价', 2 => '市价', 3 => '止损限价', 4 => '止损市价', 5 => '止盈限价', 6 => '止盈市价'];
        return $map[$type] ?? '订单';
    }

    private function statusLabel(int $status): string
    {
        $map = [
            self::STATUS_CREATED => '创建中',
            self::STATUS_OPEN => '待成交',
            self::STATUS_PARTIALLY_FILLED => '部分成交',
            self::STATUS_FILLED => '完全成交',
            self::STATUS_CANCELLED => '已撤销',
            self::STATUS_REJECTED => '已拒绝',
            self::STATUS_EXPIRED => '已失效',
        ];
        return $map[$status] ?? '处理中';
    }

    private function statusClass(int $status): string
    {
        if ($status === self::STATUS_FILLED) return 'filled';
        if (in_array($status, [self::STATUS_CREATED, self::STATUS_OPEN, self::STATUS_PARTIALLY_FILLED], true)) return 'open';
        if ($status === self::STATUS_CANCELLED) return 'cancelled';
        return 'rejected';
    }

    private function signedProfit(string $value): string
    {
        $trimmed = Decimal18::trim($value, 6);
        return Decimal18::compare($value, '0') > 0 ? '+' . $trimmed : $trimmed;
    }

    private function profitClass(string $value): string
    {
        $cmp = Decimal18::compare($value, '0');
        return $cmp > 0 ? 'profit' : ($cmp < 0 ? 'loss' : 'flat');
    }

    private function localTime(string $value): string
    {
        try {
            $utc = new \DateTimeZone('UTC');
            $dt = new \DateTimeImmutable($value, $utc);
            return $dt->setTimezone($this->timezoneInfo()['object'])->format('Y-m-d H:i:s');
        } catch (\Throwable $ignored) {
            return $value;
        }
    }

    private function timezoneInfo(): array
    {
        if ($this->userTimezone !== null) return $this->userTimezone;
        $auth = $this->authContext();
        $row = Db::table('cex_user_users')->where('id', (int) $auth['user_id'])->field('timezone')->find();
        $name = trim((string) ($row['timezone'] ?? 'UTC')) ?: 'UTC';
        try {
            $tz = new \DateTimeZone($name);
        } catch (\Throwable $ignored) {
            $name = 'UTC';
            $tz = new \DateTimeZone('UTC');
        }
        $this->userTimezone = ['name' => $name, 'object' => $tz];
        return $this->userTimezone;
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
}
