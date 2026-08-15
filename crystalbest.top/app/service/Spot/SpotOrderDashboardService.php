<?php

namespace app\service\Spot;

use app\controller\Auth\AuthService;
use app\controller\Auth\BusinessAccountService;
use app\service\Asset\AssetException;
use app\service\Asset\Decimal18;
use think\facade\Db;
use think\Request;

final class SpotOrderDashboardService
{
    private const STATUS_OPEN = 2;
    private const STATUS_PARTIALLY_FILLED = 3;
    private const STATUS_FILLED = 4;
    private const STATUS_CANCELLED = 5;
    private const STATUS_REJECTED = 6;
    private const STATUS_EXPIRED = 7;

    private Request $request;
    private ?array $authContext = null;
    private ?array $businessAccount = null;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function context(): array
    {
        $account = $this->businessAccount();
        $filters = $this->filters();
        $total = $this->ordersQuery((int) $account['id'], $filters)->count();
        $total = (int) $total;
        $totalPages = max(1, (int) ceil($total / $filters['page_size']));
        $page = min($filters['page'], $totalPages);
        $offset = ($page - 1) * $filters['page_size'];
        $filters['page'] = $page;

        $rows = $this->ordersQuery((int) $account['id'], $filters)
            ->field([
                'o.id', 'o.order_no', 'o.client_order_id', 'o.side', 'o.order_type', 'o.price',
                'o.original_quantity', 'o.executed_quantity', 'o.cumulative_quote_amount', 'o.average_price',
                'o.reserved_amount', 'o.status', 'o.reject_code', 'o.created_at', 'o.opened_at', 'o.completed_at',
                'm.symbol', 'ba.code AS base_code', 'ba.display_decimals AS base_decimals',
                'qa.code AS quote_code', 'qa.display_decimals AS quote_decimals',
            ])
            ->order('o.id', 'desc')
            ->limit($offset, $filters['page_size'])
            ->select()
            ->toArray();

        $today = $this->todayFlow((int) $account['id']);

        return [
            'account' => $account,
            'filters' => $filters,
            'markets' => $this->markets(),
            'orders' => array_map([$this, 'formatOrder'], $rows),
            'pagination' => $this->pagination($filters, $total, $totalPages),
            'today' => $today,
        ];
    }

    private function ordersQuery(int $accountId, array $filters)
    {
        $query = Db::table('cex_spot_orders')->alias('o')
            ->join('cex_market_spot_symbols m', 'm.id = o.symbol_id')
            ->join('cex_asset_assets ba', 'ba.id = m.base_asset_id')
            ->join('cex_asset_assets qa', 'qa.id = m.quote_asset_id')
            ->where('o.account_id', $accountId)
            ->whereRaw("(o.client_order_id IS NULL OR o.client_order_id NOT LIKE 'LIQREF-%')");

        if ($filters['symbol'] !== '') {
            $query->where('m.symbol', $filters['symbol']);
        }
        if ($filters['side'] === 'BUY') {
            $query->where('o.side', 1);
        } elseif ($filters['side'] === 'SELL') {
            $query->where('o.side', 2);
        }

        switch ($filters['status']) {
            case 'open':
                $query->whereIn('o.status', [self::STATUS_OPEN, self::STATUS_PARTIALLY_FILLED]);
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

        return $query;
    }

    private function filters(): array
    {
        $page = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('page_size', 20);
        if (!in_array($pageSize, [20, 50, 100], true)) {
            $pageSize = 20;
        }

        $status = strtolower(trim((string) $this->request->get('status', 'all')));
        if (!in_array($status, ['all', 'open', 'filled', 'cancelled', 'rejected'], true)) {
            $status = 'all';
        }

        $side = strtoupper(trim((string) $this->request->get('side', 'ALL')));
        if (!in_array($side, ['ALL', 'BUY', 'SELL'], true)) {
            $side = 'ALL';
        }

        $symbol = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim((string) $this->request->get('symbol', ''))) ?: '');
        if ($symbol !== '' && !Db::table('cex_market_spot_symbols')->where('symbol', $symbol)->field('id')->find()) {
            $symbol = '';
        }

        return [
            'page' => $page,
            'page_size' => $pageSize,
            'status' => $status,
            'side' => $side,
            'symbol' => $symbol,
        ];
    }

    private function markets(): array
    {
        return Db::table('cex_market_spot_symbols')
            ->where('status', 1)
            ->field('symbol')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    private function todayFlow(int $accountId): array
    {
        $auth = $this->authContext();
        $user = Db::table('cex_user_users')
            ->where('id', (int) $auth['user_id'])
            ->field('timezone')
            ->find();
        $timezoneName = trim((string) ($user['timezone'] ?? 'UTC')) ?: 'UTC';
        try {
            $timezone = new \DateTimeZone($timezoneName);
        } catch (\Throwable $exception) {
            $timezoneName = 'UTC';
            $timezone = new \DateTimeZone('UTC');
        }

        $localStart = new \DateTimeImmutable('today', $timezone);
        $localEnd = $localStart->modify('+1 day');
        $utc = new \DateTimeZone('UTC');
        $startUtc = $localStart->setTimezone($utc)->format('Y-m-d H:i:s.u');
        $endUtc = $localEnd->setTimezone($utc)->format('Y-m-d H:i:s.u');

        // Only the simple, directly auditable daily execution summary remains.
        // The old mark-to-market "today PnL estimate" is intentionally removed.
        $row = Db::table('cex_spot_fills')->alias('f')
            ->join('cex_spot_orders o', 'o.id = f.order_id')
            ->join('cex_market_spot_symbols m', 'm.id = o.symbol_id')
            ->join('cex_asset_assets qa', 'qa.id = m.quote_asset_id')
            ->where('f.account_id', $accountId)
            ->where('f.created_at', '>=', $startUtc)
            ->where('f.created_at', '<', $endUtc)
            ->field(implode(',', [
                'COUNT(*) AS fill_count',
                "CAST(SUM(CASE WHEN qa.code='USDT' THEN f.quote_amount ELSE 0 END) AS DECIMAL(38,18)) AS turnover_usdt",
            ]))
            ->find();

        return [
            'timezone' => $timezoneName,
            'local_date' => $localStart->format('Y-m-d'),
            'start_utc' => $startUtc,
            'end_utc' => $endUtc,
            'fill_count' => (int) ($row['fill_count'] ?? 0),
            'turnover_usdt' => Decimal18::trim((string) ($row['turnover_usdt'] ?? '0'), 2),
        ];
    }

    private function formatOrder(array $row): array
    {
        $baseDecimals = (int) $row['base_decimals'];
        $quoteDecimals = (int) $row['quote_decimals'];
        $side = (int) $row['side'];
        $status = (int) $row['status'];

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
            'type_label' => (int) $row['order_type'] === 2 ? '市价' : '限价',
            'price' => $row['price'] !== null ? Decimal18::trim((string) $row['price'], $quoteDecimals) : '—',
            'average_price' => $row['average_price'] !== null ? Decimal18::trim((string) $row['average_price'], $quoteDecimals) : '—',
            'original_quantity' => $row['original_quantity'] !== null ? Decimal18::trim((string) $row['original_quantity'], $baseDecimals) : '—',
            'executed_quantity' => Decimal18::trim((string) $row['executed_quantity'], $baseDecimals),
            'cumulative_quote_amount' => Decimal18::trim((string) $row['cumulative_quote_amount'], $quoteDecimals),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'status_class' => $this->statusClass($status),
            'reserved_amount' => Decimal18::trim((string) $row['reserved_amount'], 6),
            'created_at' => (string) $row['created_at'],
            'opened_at' => $row['opened_at'] !== null ? (string) $row['opened_at'] : null,
            'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
            'reject_code' => $row['reject_code'] !== null ? (string) $row['reject_code'] : null,
            'trade_url' => '/trade-spot/' . strtolower((string) $row['base_code'] . '-' . (string) $row['quote_code']),
        ];
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

    private function statusClass(int $status): string
    {
        if ($status === self::STATUS_FILLED) return 'filled';
        if (in_array($status, [self::STATUS_OPEN, self::STATUS_PARTIALLY_FILLED], true)) return 'open';
        if ($status === self::STATUS_CANCELLED) return 'cancelled';
        return 'rejected';
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
            $pages[] = [
                'page' => $page,
                'active' => $page === $current,
                'url' => $this->pageUrl($filters, $page),
            ];
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
        $query = [
            'page' => $page,
            'page_size' => $filters['page_size'],
        ];
        if ($filters['status'] !== 'all') $query['status'] = $filters['status'];
        if ($filters['side'] !== 'ALL') $query['side'] = $filters['side'];
        if ($filters['symbol'] !== '') $query['symbol'] = $filters['symbol'];
        return '/dashboard/spot-orders?' . http_build_query($query);
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
}
