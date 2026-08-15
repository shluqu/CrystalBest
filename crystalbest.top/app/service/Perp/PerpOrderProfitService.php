<?php

namespace app\service\Perp;

use app\service\Asset\Decimal18;
use think\facade\Cache;
use think\facade\Db;

/**
 * Read-only historical order-profit projection.
 *
 * No trading table is mutated and no transaction/FOR UPDATE is used. Results
 * are cached by the immutable history-order id window, so normal 3s/10s page
 * polling does not repeatedly replay fills when no new historical order exists.
 */
final class PerpOrderProfitService
{
    private const CACHE_VERSION = 'v2';
    private const CACHE_TTL_SECONDS = 300;

    private $calculator;

    public function __construct()
    {
        $this->calculator = new PerpOrderProfitCalculator();
    }

    /**
     * @param array<int,array<string,mixed>> $historyRows rows from cex_perp_orders
     * @return array<int,array<string,mixed>> keyed by order id
     */
    public function forHistory(int $accountId, array $historyRows): array
    {
        $orderIds = [];
        foreach ($historyRows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) $orderIds[$id] = $id;
        }
        if (!$orderIds) return [];

        $orderIds = array_values($orderIds);
        $cacheKey = 'perp:history-order-profit:' . self::CACHE_VERSION . ':' . $accountId . ':' . sha1(implode(',', $orderIds));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) return $cached;

        // First identify only the committed fills belonging to the requested
        // history window. Cancelled/rejected orders with no fill simply remain --.
        $targets = Db::table('cex_perp_fills')
            ->where('account_id', $accountId)
            ->whereIn('order_id', $orderIds)
            ->field('id,order_id,position_id')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        if (!$targets) {
            Cache::set($cacheKey, [], self::CACHE_TTL_SECONDS);
            return [];
        }

        $byPosition = [];
        foreach ($targets as $target) {
            $positionId = (int) $target['position_id'];
            $fillId = (int) $target['id'];
            if (!isset($byPosition[$positionId])) {
                $byPosition[$positionId] = ['min' => $fillId, 'max' => $fillId];
            } else {
                $byPosition[$positionId]['min'] = min($byPosition[$positionId]['min'], $fillId);
                $byPosition[$positionId]['max'] = max($byPosition[$positionId]['max'], $fillId);
            }
        }

        $wanted = array_fill_keys($orderIds, true);
        $result = [];

        foreach ($byPosition as $positionId => $range) {
            // A one-way net position starts whenever a committed fill has
            // position_quantity_before = 0. Replaying from the latest such fill
            // is sufficient even if later fills reverse direction one or more times.
            $start = Db::table('cex_perp_fills')
                ->where('account_id', $accountId)
                ->where('position_id', (int) $positionId)
                ->where('id', '<=', (int) $range['min'])
                ->where('position_quantity_before', '=', Decimal18::zero())
                ->order('id', 'desc')
                ->field('id')
                ->find();

            $startId = $start ? (int) $start['id'] : 0;
            if ($startId <= 0) {
                $first = Db::table('cex_perp_fills')
                    ->where('account_id', $accountId)
                    ->where('position_id', (int) $positionId)
                    ->where('id', '<=', (int) $range['min'])
                    ->order('id', 'asc')
                    ->field('id')
                    ->find();
                $startId = $first ? (int) $first['id'] : (int) $range['min'];
            }

            $fills = Db::table('cex_perp_fills')
                ->where('account_id', $accountId)
                ->where('position_id', (int) $positionId)
                ->where('id', '>=', $startId)
                ->where('id', '<=', (int) $range['max'])
                ->field('id,order_id,position_id,side,price,quantity,notional,fee_amount,realized_pnl,position_quantity_before,position_quantity_after,entry_price_before,entry_price_after,created_at')
                ->order('id', 'asc')
                ->select()
                ->toArray();

            $positionResult = $this->calculator->calculate($fills);
            foreach ($positionResult as $orderId => $profit) {
                if (isset($wanted[(int) $orderId])) $result[(int) $orderId] = $this->format($profit);
            }
        }

        Cache::set($cacheKey, $result, self::CACHE_TTL_SECONDS);
        return $result;
    }

    private function format(array $row): array
    {
        $role = (string) ($row['role'] ?? 'UNKNOWN');
        $orderProfitRaw = $row['order_profit'] !== null ? Decimal18::normalize((string) $row['order_profit']) : null;
        $realizedRaw = Decimal18::normalize((string) ($row['realized_pnl'] ?? '0'));
        $allocatedOpenFeeRaw = Decimal18::normalize((string) ($row['allocated_open_fee'] ?? '0'));
        $closeFeeRaw = Decimal18::normalize((string) ($row['close_fee_amount'] ?? '0'));

        return [
            'profit_role' => $role,
            'profit_role_label' => $this->roleLabel($role),
            'order_profit' => $orderProfitRaw !== null ? Decimal18::trim($orderProfitRaw, 6) : null,
            'realized_pnl' => Decimal18::trim($realizedRaw, 6),
            'allocated_open_fee' => Decimal18::trim($allocatedOpenFeeRaw, 6),
            'close_fee_amount' => Decimal18::trim($closeFeeRaw, 6),
            // Dashboard/reporting totals use 18-decimal raw values so summing
            // many orders never accumulates six-decimal display truncation.
            'order_profit_raw' => $orderProfitRaw,
            'realized_pnl_raw' => $realizedRaw,
            'allocated_open_fee_raw' => $allocatedOpenFeeRaw,
            'close_fee_amount_raw' => $closeFeeRaw,
            'flip_close_quantity' => $row['flip_close_quantity'] !== null ? Decimal18::trim((string) $row['flip_close_quantity'], 8) : null,
            'flip_open_quantity' => $row['flip_open_quantity'] !== null ? Decimal18::trim((string) $row['flip_open_quantity'], 8) : null,
        ];
    }

    private function roleLabel(string $role): string
    {
        switch ($role) {
            case 'OPEN': return '开仓';
            case 'ADD': return '加仓';
            case 'REDUCE': return '部分平仓';
            case 'CLOSE': return '全部平仓';
            case 'FLIP': return '反手';
            default: return '--';
        }
    }
}
