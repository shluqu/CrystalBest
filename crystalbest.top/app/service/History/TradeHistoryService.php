<?php

namespace app\service\History;

use app\controller\Auth\AuthService;
use app\service\Asset\AssetException;
use app\service\Asset\Decimal18;
use think\facade\Db;
use think\Request;

/**
 * Unified read-only user trade/activity history.
 *
 * Sources:
 * - cex_perp_fills
 * - cex_spot_fills
 * - cex_wallet_deposits
 * - cex_wallet_withdrawals
 * - cex_asset_internal_transfers
 *
 * No INSERT / UPDATE / DELETE / FOR UPDATE is used here.
 */
final class TradeHistoryService
{
    private Request $request;
    private ?array $authContext = null;
    private ?array $businessAccount = null;
    private ?array $timezone = null;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function context(): array
    {
        $account = $this->businessAccount();
        $filters = $this->filters();
        [$unionSql, $unionBind] = $this->unionSql((int) $account['id'], $filters['type']);
        [$whereSql, $whereBind] = $this->outerWhere($filters);
        $bind = array_merge($unionBind, $whereBind);

        $countRows = Db::query(
            'SELECT COUNT(*) AS total FROM (' . $unionSql . ') h WHERE 1=1 ' . $whereSql,
            $bind
        );
        $total = (int) ($countRows[0]['total'] ?? 0);
        $totalPages = max(1, (int) ceil($total / $filters['page_size']));
        $filters['page'] = min($filters['page'], $totalPages);
        $offset = ($filters['page'] - 1) * $filters['page_size'];

        $rows = Db::query(
            'SELECT h.* FROM (' . $unionSql . ') h WHERE 1=1 ' . $whereSql
            . ' ORDER BY h.occurred_at DESC, h.sort_id DESC LIMIT ' . (int) $offset . ',' . (int) $filters['page_size'],
            $bind
        );

        $details = $this->details($rows);
        $records = [];
        foreach ($rows as $row) {
            $key = (string) $row['event_type'] . ':' . (int) $row['source_id'];
            $records[] = $this->formatRecord($row, $details[$key] ?? []);
        }

        return [
            'account' => $account,
            'timezone' => ['name' => $this->timezoneInfo()['name']],
            'filters' => $filters,
            'assets' => $this->assets(),
            'records' => $records,
            'pagination' => $this->pagination($filters, $total, $totalPages),
        ];
    }

    private function unionSql(int $accountId, string $type): array
    {
        $branches = [];
        $bind = [];
        $want = static fn(string $name): bool => $type === 'all' || $type === $name;

        if ($want('perpetual')) {
            $branches[] = "SELECT
                'perpetual' AS event_type,
                f.id AS source_id,
                f.id AS sort_id,
                f.created_at AS occurred_at,
                f.fill_no AS business_no,
                CONCAT(CONVERT(ba.code USING utf8mb4) COLLATE utf8mb4_general_ci,'/',CONVERT(qa.code USING utf8mb4) COLLATE utf8mb4_general_ci) COLLATE utf8mb4_general_ci AS asset_label,
                CONCAT(CONVERT(ba.code USING utf8mb4) COLLATE utf8mb4_general_ci,',',CONVERT(qa.code USING utf8mb4) COLLATE utf8mb4_general_ci) COLLATE utf8mb4_general_ci AS asset_key,
                CASE WHEN f.side=1 THEN '买入' ELSE '卖出' END AS direction_label,
                CASE WHEN f.side=1 THEN 'buy' ELSE 'sell' END AS direction_class,
                f.quantity AS quantity_value,
                ba.code AS quantity_asset,
                ba.display_decimals AS quantity_decimals,
                f.notional AS amount_value,
                qa.code AS amount_asset,
                qa.display_decimals AS amount_decimals,
                f.fee_amount AS fee_value,
                fa.code AS fee_asset,
                fa.display_decimals AS fee_decimals,
                f.realized_pnl AS pnl_value,
                '已成交' AS status_label,
                'completed' AS status_group
            FROM cex_perp_fills f
            JOIN cex_perp_orders o ON o.id=f.order_id
            JOIN cex_market_perpetual_contracts c ON c.id=o.contract_id
            JOIN cex_asset_assets ba ON ba.id=c.base_asset_id
            JOIN cex_asset_assets qa ON qa.id=c.quote_asset_id
            JOIN cex_asset_assets fa ON fa.id=f.fee_asset_id
            WHERE f.account_id=?";
            $bind[] = $accountId;
        }

        if ($want('spot')) {
            $branches[] = "SELECT
                'spot' AS event_type,
                f.id AS source_id,
                f.id AS sort_id,
                f.created_at AS occurred_at,
                f.fill_no AS business_no,
                CONCAT(CONVERT(ba.code USING utf8mb4) COLLATE utf8mb4_general_ci,'/',CONVERT(qa.code USING utf8mb4) COLLATE utf8mb4_general_ci) COLLATE utf8mb4_general_ci AS asset_label,
                CONCAT(CONVERT(ba.code USING utf8mb4) COLLATE utf8mb4_general_ci,',',CONVERT(qa.code USING utf8mb4) COLLATE utf8mb4_general_ci) COLLATE utf8mb4_general_ci AS asset_key,
                CASE WHEN f.side=1 THEN '买入' ELSE '卖出' END AS direction_label,
                CASE WHEN f.side=1 THEN 'buy' ELSE 'sell' END AS direction_class,
                f.quantity AS quantity_value,
                ba.code AS quantity_asset,
                ba.display_decimals AS quantity_decimals,
                f.quote_amount AS amount_value,
                qa.code AS amount_asset,
                qa.display_decimals AS amount_decimals,
                f.fee_amount AS fee_value,
                fa.code AS fee_asset,
                fa.display_decimals AS fee_decimals,
                CAST(NULL AS DECIMAL(38,18)) AS pnl_value,
                '已成交' AS status_label,
                'completed' AS status_group
            FROM cex_spot_fills f
            JOIN cex_spot_orders o ON o.id=f.order_id
            JOIN cex_market_spot_symbols s ON s.id=o.symbol_id
            JOIN cex_asset_assets ba ON ba.id=s.base_asset_id
            JOIN cex_asset_assets qa ON qa.id=s.quote_asset_id
            JOIN cex_asset_assets fa ON fa.id=f.fee_asset_id
            WHERE f.account_id=?";
            $bind[] = $accountId;
        }

        if ($want('deposit')) {
            $branches[] = "SELECT
                'deposit' AS event_type,
                d.id AS source_id,
                d.id AS sort_id,
                COALESCE(d.reversed_at,d.credited_at,d.last_event_at,d.detected_at,d.created_at) AS occurred_at,
                d.deposit_no AS business_no,
                CONCAT(CONVERT(a.code USING utf8mb4) COLLATE utf8mb4_general_ci,' · ',CONVERT(n.code USING utf8mb4) COLLATE utf8mb4_general_ci) COLLATE utf8mb4_general_ci AS asset_label,
                CONVERT(a.code USING utf8mb4) COLLATE utf8mb4_general_ci AS asset_key,
                '充值' AS direction_label,
                'buy' AS direction_class,
                d.amount AS quantity_value,
                a.code AS quantity_asset,
                a.display_decimals AS quantity_decimals,
                CAST(NULL AS DECIMAL(38,18)) AS amount_value,
                a.code AS amount_asset,
                a.display_decimals AS amount_decimals,
                CAST(NULL AS DECIMAL(38,18)) AS fee_value,
                a.code AS fee_asset,
                a.display_decimals AS fee_decimals,
                CAST(NULL AS DECIMAL(38,18)) AS pnl_value,
                CASE d.status WHEN 1 THEN '已检测' WHEN 2 THEN '确认中' WHEN 3 THEN '已到账' WHEN 4 THEN '已冲正' WHEN 5 THEN '低于最小充值' WHEN 6 THEN '人工复核' ELSE '未知' END AS status_label,
                CASE WHEN d.status=3 THEN 'completed' WHEN d.status IN (1,2,6) THEN 'processing' ELSE 'failed' END AS status_group
            FROM cex_wallet_deposits d
            JOIN cex_asset_asset_networks an ON an.id=d.asset_network_id
            JOIN cex_asset_assets a ON a.id=an.asset_id
            JOIN cex_asset_networks n ON n.id=an.network_id
            WHERE d.account_id=?";
            $bind[] = $accountId;
        }

        if ($want('withdrawal')) {
            $branches[] = "SELECT
                'withdrawal' AS event_type,
                w.id AS source_id,
                w.id AS sort_id,
                COALESCE(w.completed_at,w.confirmed_at,w.broadcast_at,w.approved_at,w.requested_at,w.created_at) AS occurred_at,
                w.withdrawal_no AS business_no,
                CONCAT(CONVERT(a.code USING utf8mb4) COLLATE utf8mb4_general_ci,' · ',CONVERT(n.code USING utf8mb4) COLLATE utf8mb4_general_ci) COLLATE utf8mb4_general_ci AS asset_label,
                CONVERT(a.code USING utf8mb4) COLLATE utf8mb4_general_ci AS asset_key,
                '提现' AS direction_label,
                'sell' AS direction_class,
                w.receive_amount AS quantity_value,
                a.code AS quantity_asset,
                a.display_decimals AS quantity_decimals,
                w.gross_debit_amount AS amount_value,
                a.code AS amount_asset,
                a.display_decimals AS amount_decimals,
                w.platform_fee AS fee_value,
                a.code AS fee_asset,
                a.display_decimals AS fee_decimals,
                CAST(NULL AS DECIMAL(38,18)) AS pnl_value,
                CASE w.status WHEN 1 THEN '处理中' WHEN 2 THEN '已受理' WHEN 3 THEN '链上处理中' WHEN 4 THEN '已提交链上' WHEN 5 THEN '已完成' WHEN 6 THEN '未通过' WHEN 7 THEN '处理失败' WHEN 8 THEN '已取消' WHEN 9 THEN '已退回' ELSE '未知' END AS status_label,
                CASE WHEN w.status=5 THEN 'completed' WHEN w.status IN (1,2,3,4) THEN 'processing' WHEN w.status IN (8,9) THEN 'cancelled' ELSE 'failed' END AS status_group
            FROM cex_wallet_withdrawals w
            JOIN cex_asset_asset_networks an ON an.id=w.asset_network_id
            JOIN cex_asset_assets a ON a.id=an.asset_id
            JOIN cex_asset_networks n ON n.id=an.network_id
            WHERE w.account_id=?";
            $bind[] = $accountId;
        }

        if ($want('transfer')) {
            $branches[] = "SELECT
                'transfer' AS event_type,
                t.id AS source_id,
                t.id AS sort_id,
                COALESCE(t.completed_at,t.created_at) AS occurred_at,
                t.transfer_no AS business_no,
                CONVERT(a.code USING utf8mb4) COLLATE utf8mb4_general_ci AS asset_label,
                CONVERT(a.code USING utf8mb4) COLLATE utf8mb4_general_ci AS asset_key,
                CASE WHEN t.direction=1 THEN '现货 → 合约' ELSE '合约 → 现货' END AS direction_label,
                'transfer' AS direction_class,
                t.amount AS quantity_value,
                a.code AS quantity_asset,
                a.display_decimals AS quantity_decimals,
                CAST(NULL AS DECIMAL(38,18)) AS amount_value,
                a.code AS amount_asset,
                a.display_decimals AS amount_decimals,
                CAST(NULL AS DECIMAL(38,18)) AS fee_value,
                a.code AS fee_asset,
                a.display_decimals AS fee_decimals,
                CAST(NULL AS DECIMAL(38,18)) AS pnl_value,
                CASE t.status WHEN 1 THEN '处理中' WHEN 2 THEN '已完成' WHEN 3 THEN '失败' WHEN 4 THEN '已取消' ELSE '未知' END AS status_label,
                CASE WHEN t.status=2 THEN 'completed' WHEN t.status=1 THEN 'processing' WHEN t.status=4 THEN 'cancelled' ELSE 'failed' END AS status_group
            FROM cex_asset_internal_transfers t
            JOIN cex_asset_assets a ON a.id=t.asset_id
            WHERE t.account_id=?";
            $bind[] = $accountId;
        }

        if (!$branches) {
            throw new AssetException('成交历史类型无效', 422, 'TRADE_HISTORY_TYPE_INVALID');
        }
        return [implode(' UNION ALL ', $branches), $bind];
    }

    private function outerWhere(array $filters): array
    {
        $sql = '';
        $bind = [];
        if ($filters['asset'] !== '') {
            $sql .= ' AND FIND_IN_SET(?, h.asset_key) > 0';
            $bind[] = $filters['asset'];
        }
        if ($filters['status'] !== 'all') {
            $sql .= ' AND h.status_group=?';
            $bind[] = $filters['status'];
        }
        if ($filters['start_utc'] !== null) {
            $sql .= ' AND h.occurred_at>=?';
            $bind[] = $filters['start_utc'];
        }
        if ($filters['end_utc'] !== null) {
            $sql .= ' AND h.occurred_at<?';
            $bind[] = $filters['end_utc'];
        }
        return [$sql, $bind];
    }

    private function filters(): array
    {
        $page = max(1, (int) $this->request->get('page', 1));
        $type = strtolower(trim((string) $this->request->get('type', 'all')));
        if (!in_array($type, ['all','perpetual','spot','deposit','withdrawal','transfer'], true)) $type = 'all';

        $asset = strtoupper(trim((string) $this->request->get('asset', '')));
        $asset = preg_replace('/[^A-Z0-9]/', '', $asset) ?: '';
        if ($asset !== '' && !Db::table('cex_asset_assets')->where('code', $asset)->field('id')->find()) $asset = '';

        $status = strtolower(trim((string) $this->request->get('status', 'all')));
        if (!in_array($status, ['all','completed','processing','failed','cancelled'], true)) $status = 'all';

        $period = strtolower(trim((string) $this->request->get('period', 'all')));
        if (!in_array($period, ['all','today','7d','30d','90d','custom'], true)) $period = 'all';

        $tz = $this->timezoneInfo()['object'];
        $today = new \DateTimeImmutable('today', $tz);
        $startLocal = null;
        $endLocal = null;
        $startDate = '';
        $endDate = '';

        if ($period === 'today') {
            $startLocal = $today; $endLocal = $today->modify('+1 day');
            $startDate = $today->format('Y-m-d'); $endDate = $startDate;
        } elseif (in_array($period, ['7d','30d','90d'], true)) {
            $days = (int) substr($period, 0, -1);
            $startLocal = $today->modify('-' . max(0, $days - 1) . ' days');
            $endLocal = $today->modify('+1 day');
            $startDate = $startLocal->format('Y-m-d'); $endDate = $today->format('Y-m-d');
        } elseif ($period === 'custom') {
            $rawStart = trim((string) $this->request->get('start_date', ''));
            $rawEnd = trim((string) $this->request->get('end_date', ''));
            if ($this->validDate($rawStart) && $this->validDate($rawEnd)) {
                $startLocal = new \DateTimeImmutable($rawStart . ' 00:00:00', $tz);
                $endDay = new \DateTimeImmutable($rawEnd . ' 00:00:00', $tz);
                if ($endDay < $startLocal) [$startLocal, $endDay] = [$endDay, $startLocal];
                $endLocal = $endDay->modify('+1 day');
                $startDate = $startLocal->format('Y-m-d');
                $endDate = $endDay->format('Y-m-d');
            } else {
                $period = 'all';
            }
        }

        $utc = new \DateTimeZone('UTC');
        return [
            'page' => $page,
            'page_size' => 20,
            'type' => $type,
            'asset' => $asset,
            'status' => $status,
            'period' => $period,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'start_utc' => $startLocal ? $startLocal->setTimezone($utc)->format('Y-m-d H:i:s.u') : null,
            'end_utc' => $endLocal ? $endLocal->setTimezone($utc)->format('Y-m-d H:i:s.u') : null,
        ];
    }

    private function formatRecord(array $row, array $detail): array
    {
        $quantity = Decimal18::trim((string) $row['quantity_value'], max(0, (int) $row['quantity_decimals']));
        $amount = $row['amount_value'] !== null
            ? Decimal18::trim((string) $row['amount_value'], max(0, (int) $row['amount_decimals']))
            : null;
        $fee = $row['fee_value'] !== null
            ? Decimal18::trim((string) $row['fee_value'], max(0, (int) $row['fee_decimals']))
            : null;
        $pnlRaw = $row['pnl_value'] !== null ? (string) $row['pnl_value'] : null;
        $pnl = $pnlRaw !== null ? Decimal18::trim($pnlRaw, 6) : null;
        $pnlClass = 'flat';
        if ($pnlRaw !== null) {
            $cmp = Decimal18::compare($pnlRaw, '0');
            $pnlClass = $cmp > 0 ? 'profit' : ($cmp < 0 ? 'loss' : 'flat');
        }

        return [
            'event_type' => (string) $row['event_type'],
            'type_label' => $this->typeLabel((string) $row['event_type']),
            'type_class' => (string) $row['event_type'],
            'source_id' => (int) $row['source_id'],
            'occurred_at' => (string) $row['occurred_at'],
            'occurred_at_local' => $this->localTime((string) $row['occurred_at']),
            'business_no' => (string) $row['business_no'],
            'asset_label' => (string) $row['asset_label'],
            'direction_label' => (string) $row['direction_label'],
            'direction_class' => (string) $row['direction_class'],
            'quantity' => $quantity,
            'quantity_asset' => (string) $row['quantity_asset'],
            'amount' => $amount,
            'amount_asset' => (string) $row['amount_asset'],
            'fee' => $fee,
            'fee_asset' => (string) $row['fee_asset'],
            'pnl' => $pnl,
            'pnl_class' => $pnlClass,
            'status_label' => (string) $row['status_label'],
            'status_group' => (string) $row['status_group'],
            'detail' => $detail,
        ];
    }

    private function details(array $rows): array
    {
        $ids = ['perpetual'=>[], 'spot'=>[], 'deposit'=>[], 'withdrawal'=>[], 'transfer'=>[]];
        foreach ($rows as $row) $ids[(string) $row['event_type']][] = (int) $row['source_id'];
        $map = [];

        if ($ids['perpetual']) {
            $items = Db::table('cex_perp_fills')->alias('f')
                ->join('cex_perp_orders o', 'o.id=f.order_id')
                ->join('cex_market_perpetual_contracts c', 'c.id=o.contract_id')
                ->join('cex_asset_assets ba', 'ba.id=c.base_asset_id')
                ->join('cex_asset_assets qa', 'qa.id=c.quote_asset_id')
                ->whereIn('f.id', array_values(array_unique($ids['perpetual'])))
                ->field('f.id,f.fill_no,o.order_no,c.symbol,ba.code AS base_code,qa.code AS quote_code,f.side,f.price,f.quantity,f.notional,f.fee_amount,f.realized_pnl,f.position_quantity_before,f.position_quantity_after,f.entry_price_before,f.entry_price_after,o.requested_leverage,o.reduce_only,f.liquidity_role,f.created_at')
                ->select()->toArray();
            foreach ($items as $x) {
                $map['perpetual:' . (int) $x['id']] = [
                    ['label'=>'订单号','value'=>(string)$x['order_no']],
                    ['label'=>'Fill 编号','value'=>(string)$x['fill_no']],
                    ['label'=>'合约','value'=>(string)$x['base_code'].'/'.(string)$x['quote_code']],
                    ['label'=>'方向','value'=>(int)$x['side']===1?'买入':'卖出'],
                    ['label'=>'成交价','value'=>Decimal18::trim((string)$x['price'], 10).' '.(string)$x['quote_code']],
                    ['label'=>'成交数量','value'=>Decimal18::trim((string)$x['quantity'], 10).' '.(string)$x['base_code']],
                    ['label'=>'成交额','value'=>Decimal18::trim((string)$x['notional'], 8).' '.(string)$x['quote_code']],
                    ['label'=>'手续费','value'=>Decimal18::trim((string)$x['fee_amount'], 8).' '.(string)$x['quote_code']],
                    ['label'=>'已实现盈亏','value'=>Decimal18::trim((string)$x['realized_pnl'], 8).' '.(string)$x['quote_code']],
                    ['label'=>'持仓变化','value'=>Decimal18::trim((string)$x['position_quantity_before'],10).' → '.Decimal18::trim((string)$x['position_quantity_after'],10).' '.(string)$x['base_code']],
                    ['label'=>'开仓均价变化','value'=>($x['entry_price_before']!==null?Decimal18::trim((string)$x['entry_price_before'],10):'--').' → '.($x['entry_price_after']!==null?Decimal18::trim((string)$x['entry_price_after'],10):'--')],
                    ['label'=>'杠杆','value'=>Decimal18::trim((string)$x['requested_leverage'],2).'x'],
                    ['label'=>'只减仓','value'=>(int)$x['reduce_only']===1?'是':'否'],
                    ['label'=>'流动性角色','value'=>(int)$x['liquidity_role']===1?'Maker':'Taker'],
                ];
            }
        }

        if ($ids['spot']) {
            $items = Db::table('cex_spot_fills')->alias('f')
                ->join('cex_spot_orders o', 'o.id=f.order_id')
                ->join('cex_market_spot_symbols s', 's.id=o.symbol_id')
                ->join('cex_asset_assets ba', 'ba.id=s.base_asset_id')
                ->join('cex_asset_assets qa', 'qa.id=s.quote_asset_id')
                ->join('cex_asset_assets fa', 'fa.id=f.fee_asset_id')
                ->whereIn('f.id', array_values(array_unique($ids['spot'])))
                ->field('f.id,f.fill_no,o.order_no,s.symbol,ba.code AS base_code,qa.code AS quote_code,fa.code AS fee_code,f.side,f.price,f.quantity,f.quote_amount,f.fee_amount,f.liquidity_role,f.created_at')
                ->select()->toArray();
            foreach ($items as $x) {
                $map['spot:' . (int) $x['id']] = [
                    ['label'=>'订单号','value'=>(string)$x['order_no']],
                    ['label'=>'Fill 编号','value'=>(string)$x['fill_no']],
                    ['label'=>'交易对','value'=>(string)$x['base_code'].'/'.(string)$x['quote_code']],
                    ['label'=>'方向','value'=>(int)$x['side']===1?'买入':'卖出'],
                    ['label'=>'成交价','value'=>Decimal18::trim((string)$x['price'],10).' '.(string)$x['quote_code']],
                    ['label'=>'成交数量','value'=>Decimal18::trim((string)$x['quantity'],10).' '.(string)$x['base_code']],
                    ['label'=>'成交额','value'=>Decimal18::trim((string)$x['quote_amount'],8).' '.(string)$x['quote_code']],
                    ['label'=>'手续费','value'=>Decimal18::trim((string)$x['fee_amount'],10).' '.(string)$x['fee_code']],
                    ['label'=>'流动性角色','value'=>(int)$x['liquidity_role']===1?'Maker':'Taker'],
                ];
            }
        }

        if ($ids['deposit']) {
            $items = Db::table('cex_wallet_deposits')->alias('d')
                ->join('cex_asset_asset_networks an', 'an.id=d.asset_network_id')
                ->join('cex_asset_assets a', 'a.id=an.asset_id')
                ->join('cex_asset_networks n', 'n.id=an.network_id')
                ->whereIn('d.id', array_values(array_unique($ids['deposit'])))
                ->field('d.id,d.deposit_no,d.tx_hash,d.amount,d.confirmations,d.required_confirmations_snapshot,d.status,d.detected_at,d.credited_at,d.reversed_at,a.code AS asset_code,n.code AS network_code')
                ->select()->toArray();
            foreach ($items as $x) {
                $map['deposit:' . (int) $x['id']] = [
                    ['label'=>'充值编号','value'=>(string)$x['deposit_no']],
                    ['label'=>'资产','value'=>(string)$x['asset_code']],
                    ['label'=>'网络','value'=>(string)$x['network_code']],
                    ['label'=>'数量','value'=>Decimal18::trim((string)$x['amount'],10).' '.(string)$x['asset_code']],
                    ['label'=>'确认数','value'=>(int)$x['confirmations'].' / '.(int)$x['required_confirmations_snapshot']],
                    ['label'=>'Tx Hash','value'=>(string)$x['tx_hash']],
                    ['label'=>'检测时间','value'=>$this->localTime((string)$x['detected_at'])],
                    ['label'=>'到账时间','value'=>$x['credited_at']!==null?$this->localTime((string)$x['credited_at']):'--'],
                    ['label'=>'冲正时间','value'=>$x['reversed_at']!==null?$this->localTime((string)$x['reversed_at']):'--'],
                ];
            }
        }

        if ($ids['withdrawal']) {
            $items = Db::table('cex_wallet_withdrawals')->alias('w')
                ->join('cex_asset_asset_networks an', 'an.id=w.asset_network_id')
                ->join('cex_asset_assets a', 'a.id=an.asset_id')
                ->join('cex_asset_networks n', 'n.id=an.network_id')
                ->whereIn('w.id', array_values(array_unique($ids['withdrawal'])))
                ->field('w.id,w.withdrawal_no,w.destination_address,w.destination_memo,w.receive_amount,w.platform_fee,w.gross_debit_amount,w.estimated_network_fee,w.actual_network_fee,w.failure_code,w.risk_decision_code,w.requested_at,w.approved_at,w.broadcast_at,w.confirmed_at,w.completed_at,a.code AS asset_code,n.code AS network_code')
                ->select()->toArray();
            foreach ($items as $x) {
                $map['withdrawal:' . (int) $x['id']] = [
                    ['label'=>'提现编号','value'=>(string)$x['withdrawal_no']],
                    ['label'=>'资产','value'=>(string)$x['asset_code']],
                    ['label'=>'网络','value'=>(string)$x['network_code']],
                    ['label'=>'到账数量','value'=>Decimal18::trim((string)$x['receive_amount'],10).' '.(string)$x['asset_code']],
                    ['label'=>'平台手续费','value'=>Decimal18::trim((string)$x['platform_fee'],10).' '.(string)$x['asset_code']],
                    ['label'=>'总扣款','value'=>Decimal18::trim((string)$x['gross_debit_amount'],10).' '.(string)$x['asset_code']],
                    ['label'=>'目标地址','value'=>(string)$x['destination_address']],
                    ['label'=>'Memo','value'=>$x['destination_memo']!==null?(string)$x['destination_memo']:'--'],
                    ['label'=>'网络费','value'=>$x['actual_network_fee']!==null?Decimal18::trim((string)$x['actual_network_fee'],10).' '.(string)$x['asset_code']:($x['estimated_network_fee']!==null?'预计 '.Decimal18::trim((string)$x['estimated_network_fee'],10).' '.(string)$x['asset_code']:'--')],
                    ['label'=>'申请时间','value'=>$this->localTime((string)$x['requested_at'])],
                    ['label'=>'完成时间','value'=>$x['completed_at']!==null?$this->localTime((string)$x['completed_at']):'--'],
                    ['label'=>'风险结果','value'=>$x['risk_decision_code']!==null?(string)$x['risk_decision_code']:'--'],
                    ['label'=>'失败代码','value'=>$x['failure_code']!==null?(string)$x['failure_code']:'--'],
                ];
            }
        }

        if ($ids['transfer']) {
            $items = Db::table('cex_asset_internal_transfers')->alias('t')
                ->join('cex_asset_assets a', 'a.id=t.asset_id')
                ->leftJoin('cex_asset_ledger_transactions lt', 'lt.id=t.ledger_transaction_id')
                ->whereIn('t.id', array_values(array_unique($ids['transfer'])))
                ->field('t.id,t.transfer_no,t.direction,t.amount,t.status,t.failure_code,t.created_at,t.completed_at,a.code AS asset_code,lt.journal_no')
                ->select()->toArray();
            foreach ($items as $x) {
                $map['transfer:' . (int) $x['id']] = [
                    ['label'=>'划转编号','value'=>(string)$x['transfer_no']],
                    ['label'=>'方向','value'=>(int)$x['direction']===1?'现货 → 合约':'合约 → 现货'],
                    ['label'=>'数量','value'=>Decimal18::trim((string)$x['amount'],10).' '.(string)$x['asset_code']],
                    ['label'=>'账本流水','value'=>$x['journal_no']!==null?(string)$x['journal_no']:'--'],
                    ['label'=>'创建时间','value'=>$this->localTime((string)$x['created_at'])],
                    ['label'=>'完成时间','value'=>$x['completed_at']!==null?$this->localTime((string)$x['completed_at']):'--'],
                    ['label'=>'失败代码','value'=>$x['failure_code']!==null?(string)$x['failure_code']:'--'],
                ];
            }
        }
        return $map;
    }

    private function assets(): array
    {
        return Db::table('cex_asset_assets')->where('status', 1)->field('code,name')->order('id','asc')->select()->toArray();
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
        for ($p=$start; $p<=$end; $p++) $pages[] = ['page'=>$p,'active'=>$p===$current,'url'=>$this->pageUrl($filters,$p)];
        return [
            'total'=>$total,'page'=>$current,'page_size'=>20,'total_pages'=>$totalPages,
            'from'=>$total===0?0:(($current-1)*20)+1,'to'=>min($total,$current*20),
            'previous_url'=>$current>1?$this->pageUrl($filters,$current-1):null,
            'next_url'=>$current<$totalPages?$this->pageUrl($filters,$current+1):null,
            'pages'=>$pages,
        ];
    }

    private function pageUrl(array $filters, int $page): string
    {
        $query = ['page'=>$page];
        foreach (['type','status','period'] as $key) if ($filters[$key] !== 'all') $query[$key]=$filters[$key];
        if ($filters['asset'] !== '') $query['asset']=$filters['asset'];
        if ($filters['period'] === 'custom') {
            if ($filters['start_date'] !== '') $query['start_date']=$filters['start_date'];
            if ($filters['end_date'] !== '') $query['end_date']=$filters['end_date'];
        }
        return '/dashboard/trade-history?' . http_build_query($query);
    }

    private function typeLabel(string $type): string
    {
        return [
            'perpetual'=>'合约成交','spot'=>'现货成交','deposit'=>'充值','withdrawal'=>'提现','transfer'=>'划转',
        ][$type] ?? '记录';
    }

    private function validDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d && $d->format('Y-m-d') === $value;
    }

    private function localTime(string $value): string
    {
        if ($value === '') return '--';
        try {
            $dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            return $dt->setTimezone($this->timezoneInfo()['object'])->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return $value;
        }
    }

    private function timezoneInfo(): array
    {
        if ($this->timezone !== null) return $this->timezone;
        $auth = $this->authContext();
        $row = Db::table('cex_user_users')->where('id',(int)$auth['user_id'])->field('timezone')->find();
        $name = trim((string)($row['timezone'] ?? 'UTC')) ?: 'UTC';
        try { $object = new \DateTimeZone($name); }
        catch (\Throwable $e) { $name='UTC'; $object=new \DateTimeZone('UTC'); }
        return $this->timezone = ['name'=>$name,'object'=>$object];
    }

    private function authContext(): array
    {
        if ($this->authContext !== null) return $this->authContext;
        $auth = new AuthService($this->request);
        $cookie = (string) $this->request->cookie($auth->cookieName(), '');
        return $this->authContext = $auth->authenticatedSession($cookie, true);
    }

    private function businessAccount(): array
    {
        if ($this->businessAccount !== null) return $this->businessAccount;
        $auth = $this->authContext();
        $row = Db::table('cex_account_accounts')->where('user_id',(int)$auth['user_id'])->where('account_kind',1)->field('id,public_id,status,user_id')->find();
        if (!$row || (int)$row['status'] !== 1) throw new AssetException('账户当前不可用',409,'TRADE_HISTORY_ACCOUNT_UNAVAILABLE');
        return $this->businessAccount = $row;
    }
}