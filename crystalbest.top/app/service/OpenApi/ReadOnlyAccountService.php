<?php

namespace app\service\OpenApi;

use app\service\Asset\Decimal18;
use app\service\Asset\LedgerService;
use think\facade\Db;

/**
 * Read-only OpenAPI projection over committed CrystalBest database state.
 *
 * Intentionally excluded:
 * - ticker / 24h statistics
 * - BBO / order book / depth
 * - K-lines
 * - index price / mark price
 * - live funding rate
 * - unrealized PnL and other values derived from current market prices
 */
final class ReadOnlyAccountService
{
    public function profile(int $userId): array
    {
        $row = Db::table('cex_user_users')
            ->where('id', $userId)
            ->field('uid,email_masked,email_verified_at,phone_masked,phone_verified_at,nickname,country_code,language,timezone,status,kyc_level,risk_level,registration_channel,last_login_at,created_at,updated_at')
            ->find();
        if (!$row) {
            throw new ApiException('用户不存在', 404, 'USER_NOT_FOUND');
        }

        return [
            'uid' => (string) $row['uid'],
            'email_masked' => $row['email_masked'] !== null ? (string) $row['email_masked'] : null,
            'email_verified' => !empty($row['email_verified_at']),
            'email_verified_at' => $row['email_verified_at'],
            'phone_masked' => $row['phone_masked'] !== null ? (string) $row['phone_masked'] : null,
            'phone_verified' => !empty($row['phone_verified_at']),
            'phone_verified_at' => $row['phone_verified_at'],
            'nickname' => $row['nickname'] !== null ? (string) $row['nickname'] : null,
            'country_code' => $row['country_code'] !== null ? (string) $row['country_code'] : null,
            'language' => (string) $row['language'],
            'timezone' => (string) $row['timezone'],
            'status' => (int) $row['status'],
            'kyc_level' => (int) $row['kyc_level'],
            'risk_level' => (int) $row['risk_level'],
            'registration_channel' => (string) $row['registration_channel'],
            'last_login_at' => $row['last_login_at'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    public function perpetualPositions(int $userId): array
    {
        $accountId = $this->accountId($userId);
        if ($accountId === null) {
            return [];
        }

        $rows = Db::table('cex_perp_positions')->alias('p')
            ->join('cex_market_perpetual_contracts c', 'c.id = p.contract_id')
            ->join('cex_asset_assets ba', 'ba.id = c.base_asset_id')
            ->join('cex_asset_assets qa', 'qa.id = c.quote_asset_id')
            ->leftJoin('cex_perp_account_contract_settings s', 's.account_id = p.account_id AND s.contract_id = p.contract_id AND s.status = 1')
            ->where('p.account_id', $accountId)
            ->where('p.position_quantity', '<>', Decimal18::zero())
            ->field('p.id,p.contract_id,p.position_quantity,p.entry_price,p.break_even_price,p.realized_pnl,p.cumulative_funding,p.position_status,p.opened_at,p.updated_at,c.symbol,c.contract_size,c.status AS contract_status,ba.code AS base_code,ba.display_decimals AS base_decimals,qa.code AS quote_code,qa.display_decimals AS quote_decimals,s.leverage,s.margin_mode,s.position_mode')
            ->order('p.updated_at', 'desc')
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $row) {
            $signed = Decimal18::normalize((string) $row['position_quantity']);
            $long = Decimal18::compare($signed, Decimal18::zero()) > 0;
            $baseDecimals = (int) $row['base_decimals'];
            $quoteDecimals = (int) $row['quote_decimals'];

            $items[] = [
                'position_id' => (int) $row['id'],
                'contract_id' => (int) $row['contract_id'],
                'symbol' => (string) $row['symbol'],
                'pair' => (string) $row['base_code'] . '/' . (string) $row['quote_code'],
                'base_asset' => (string) $row['base_code'],
                'quote_asset' => (string) $row['quote_code'],
                'side' => $long ? 'LONG' : 'SHORT',
                'side_label' => $long ? '买入' : '卖出',
                'quantity' => Decimal18::trim($this->absDecimal($signed), $baseDecimals),
                'signed_quantity' => Decimal18::trim($signed, $baseDecimals),
                'contract_size' => Decimal18::trim((string) $row['contract_size'], $baseDecimals),
                'entry_price' => $row['entry_price'] !== null ? Decimal18::trim((string) $row['entry_price'], $quoteDecimals) : null,
                'break_even_price' => $row['break_even_price'] !== null ? Decimal18::trim((string) $row['break_even_price'], $quoteDecimals) : null,
                'realized_pnl' => Decimal18::trim((string) $row['realized_pnl'], 8),
                'cumulative_funding' => Decimal18::trim((string) $row['cumulative_funding'], 8),
                'leverage' => $row['leverage'] !== null ? Decimal18::trim((string) $row['leverage'], 4) : null,
                'margin_mode' => $row['margin_mode'] !== null ? (int) $row['margin_mode'] : null,
                'position_mode' => $row['position_mode'] !== null ? (int) $row['position_mode'] : null,
                'position_status' => (int) $row['position_status'],
                'contract_status' => (int) $row['contract_status'],
                'opened_at' => $row['opened_at'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return $items;
    }

    public function balances(int $userId): array
    {
        $accountId = $this->accountId($userId);
        if ($accountId === null) {
            return [];
        }

        $rows = Db::table('cex_asset_ledger_accounts')->alias('la')
            ->join('cex_asset_assets a', 'a.id = la.asset_id')
            ->leftJoin('cex_asset_balances b', 'b.ledger_account_id = la.id')
            ->where('la.account_id', $accountId)
            ->where('la.status', 1)
            ->whereIn('la.account_scope', [LedgerService::SCOPE_SPOT, LedgerService::SCOPE_PERPETUAL_CROSS])
            ->whereIn('la.balance_bucket', [LedgerService::BUCKET_AVAILABLE, LedgerService::BUCKET_LOCKED])
            ->field('la.asset_id,la.account_scope,la.balance_bucket,COALESCE(b.balance,0) AS balance,a.code,a.name,a.asset_type,a.display_decimals,a.status AS asset_status')
            ->order('a.id', 'asc')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $assetId = (int) $row['asset_id'];
            if (!isset($map[$assetId])) {
                $map[$assetId] = [
                    'asset' => (string) $row['code'],
                    'name' => (string) $row['name'],
                    'asset_type' => (int) $row['asset_type'],
                    'asset_status' => (int) $row['asset_status'],
                    'decimals' => (int) $row['display_decimals'],
                    'spot_available' => Decimal18::zero(),
                    'spot_locked' => Decimal18::zero(),
                    'perpetual_available' => Decimal18::zero(),
                    'perpetual_locked' => Decimal18::zero(),
                ];
            }

            $value = Decimal18::normalize((string) $row['balance']);
            $scope = (int) $row['account_scope'];
            $bucket = (int) $row['balance_bucket'];
            if ($scope === LedgerService::SCOPE_SPOT && $bucket === LedgerService::BUCKET_AVAILABLE) {
                $map[$assetId]['spot_available'] = $value;
            } elseif ($scope === LedgerService::SCOPE_SPOT && $bucket === LedgerService::BUCKET_LOCKED) {
                $map[$assetId]['spot_locked'] = $value;
            } elseif ($scope === LedgerService::SCOPE_PERPETUAL_CROSS && $bucket === LedgerService::BUCKET_AVAILABLE) {
                $map[$assetId]['perpetual_available'] = $value;
            } elseif ($scope === LedgerService::SCOPE_PERPETUAL_CROSS && $bucket === LedgerService::BUCKET_LOCKED) {
                $map[$assetId]['perpetual_locked'] = $value;
            }
        }

        $items = [];
        foreach ($map as $item) {
            $spotTotal = Decimal18::add($item['spot_available'], $item['spot_locked']);
            $perpTotal = Decimal18::add($item['perpetual_available'], $item['perpetual_locked']);
            $total = Decimal18::add($spotTotal, $perpTotal);
            if (Decimal18::compare($total, Decimal18::zero()) === 0) {
                continue;
            }

            $decimals = $item['decimals'];
            $items[] = [
                'asset' => $item['asset'],
                'name' => $item['name'],
                'asset_type' => $item['asset_type'],
                'asset_status' => $item['asset_status'],
                'spot' => [
                    'available' => Decimal18::trim($item['spot_available'], $decimals),
                    'locked' => Decimal18::trim($item['spot_locked'], $decimals),
                    'total' => Decimal18::trim($spotTotal, $decimals),
                ],
                'perpetual' => [
                    'available' => Decimal18::trim($item['perpetual_available'], $decimals),
                    'locked' => Decimal18::trim($item['perpetual_locked'], $decimals),
                    'total' => Decimal18::trim($perpTotal, $decimals),
                ],
                'total' => Decimal18::trim($total, $decimals),
            ];
        }

        usort($items, static function (array $left, array $right): int {
            if ($left['asset'] === 'USDT' && $right['asset'] !== 'USDT') {
                return -1;
            }
            if ($right['asset'] === 'USDT' && $left['asset'] !== 'USDT') {
                return 1;
            }
            return strcmp((string) $left['asset'], (string) $right['asset']);
        });

        return $items;
    }

    public function deposits(int $userId, int $page, int $pageSize): array
    {
        $accountId = $this->accountId($userId);
        if ($accountId === null) {
            return $this->emptyPage($page, $pageSize);
        }

        $base = Db::table('cex_wallet_deposits')->alias('d')
            ->join('cex_asset_asset_networks an', 'an.id = d.asset_network_id')
            ->join('cex_asset_assets a', 'a.id = an.asset_id')
            ->join('cex_asset_networks n', 'n.id = an.network_id')
            ->where('d.account_id', $accountId);

        $total = (int) (clone $base)->count();
        $rows = $base
            ->field('d.deposit_no,d.tx_hash,d.event_index,d.amount,d.confirmations,d.required_confirmations_snapshot,d.status,d.detected_at,d.credited_at,d.reversed_at,d.created_at,d.updated_at,a.code AS asset_code,a.display_decimals,n.code AS network_code,n.name AS network_name,an.route_code')
            ->order('d.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $labels = [
            1 => 'DETECTED',
            2 => 'CONFIRMING',
            3 => 'CREDITED',
            4 => 'REVERSED',
            5 => 'BELOW_MINIMUM',
            6 => 'MANUAL_REVIEW',
        ];

        foreach ($rows as &$row) {
            $decimals = (int) $row['display_decimals'];
            $status = (int) $row['status'];
            $row['event_index'] = (int) $row['event_index'];
            $row['confirmations'] = (int) $row['confirmations'];
            $row['required_confirmations'] = (int) $row['required_confirmations_snapshot'];
            $row['status'] = $status;
            $row['status_label'] = $labels[$status] ?? 'UNKNOWN';
            $row['amount'] = Decimal18::trim((string) $row['amount'], $decimals);
            unset($row['required_confirmations_snapshot'], $row['display_decimals']);
        }
        unset($row);

        return $this->pageResult($rows, $page, $pageSize, $total);
    }

    public function withdrawals(int $userId, int $page, int $pageSize): array
    {
        $accountId = $this->accountId($userId);
        if ($accountId === null) {
            return $this->emptyPage($page, $pageSize);
        }

        $base = Db::table('cex_wallet_withdrawals')->alias('w')
            ->join('cex_asset_asset_networks an', 'an.id = w.asset_network_id')
            ->join('cex_asset_assets a', 'a.id = an.asset_id')
            ->join('cex_asset_networks n', 'n.id = an.network_id')
            ->leftJoin('cex_wallet_chain_transactions ct', 'ct.id = w.chain_transaction_id')
            ->where('w.account_id', $accountId);

        $total = (int) (clone $base)->count();
        $rows = $base
            ->field('w.withdrawal_no,w.destination_address,w.destination_memo,w.receive_amount,w.platform_fee,w.gross_debit_amount,w.estimated_network_fee,w.actual_network_fee,w.status,w.risk_decision_code,w.failure_code,w.requested_at,w.approved_at,w.broadcast_at,w.confirmed_at,w.completed_at,w.created_at,w.updated_at,a.code AS asset_code,a.display_decimals,n.code AS network_code,n.name AS network_name,an.route_code,ct.tx_hash')
            ->order('w.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $labels = [
            1 => 'PENDING_REVIEW',
            2 => 'APPROVED',
            3 => 'PAYOUT_PROCESSING',
            4 => 'BROADCASTED',
            5 => 'COMPLETED',
            6 => 'REJECTED',
            7 => 'FAILED',
            8 => 'CANCELLED',
            9 => 'REFUNDED',
        ];

        foreach ($rows as &$row) {
            $decimals = (int) $row['display_decimals'];
            $status = (int) $row['status'];
            $row['status'] = $status;
            $row['status_label'] = $labels[$status] ?? 'UNKNOWN';
            $row['receive_amount'] = Decimal18::trim((string) $row['receive_amount'], $decimals);
            $row['platform_fee'] = Decimal18::trim((string) $row['platform_fee'], $decimals);
            $row['gross_debit_amount'] = Decimal18::trim((string) $row['gross_debit_amount'], $decimals);
            $row['estimated_network_fee'] = $row['estimated_network_fee'] !== null
                ? Decimal18::trim((string) $row['estimated_network_fee'], $decimals)
                : null;
            $row['actual_network_fee'] = $row['actual_network_fee'] !== null
                ? Decimal18::trim((string) $row['actual_network_fee'], $decimals)
                : null;
            unset($row['display_decimals']);
        }
        unset($row);

        return $this->pageResult($rows, $page, $pageSize, $total);
    }

    public function supportedMarkets(): array
    {
        $assets = Db::table('cex_asset_assets')
            ->where('status', 1)
            ->field('id,code,name,asset_type,display_decimals,ledger_decimals,status,deposit_enabled,withdraw_enabled,spot_enabled,perpetual_margin_enabled')
            ->order('code', 'asc')
            ->select()
            ->toArray();
        foreach ($assets as &$asset) {
            $asset['id'] = (int) $asset['id'];
            $asset['asset_type'] = (int) $asset['asset_type'];
            $asset['display_decimals'] = (int) $asset['display_decimals'];
            $asset['ledger_decimals'] = (int) $asset['ledger_decimals'];
            $asset['status'] = (int) $asset['status'];
            foreach (['deposit_enabled', 'withdraw_enabled', 'spot_enabled', 'perpetual_margin_enabled'] as $flag) {
                $asset[$flag] = (bool) $asset[$flag];
            }
        }
        unset($asset);

        $spot = Db::table('cex_market_spot_symbols')->alias('m')
            ->join('cex_asset_assets b', 'b.id = m.base_asset_id')
            ->join('cex_asset_assets q', 'q.id = m.quote_asset_id')
            ->whereIn('m.status', [1, 2, 3, 4])
            ->field('m.id,m.symbol,m.status,m.price_tick,m.quantity_step,m.min_quantity,m.max_quantity,m.min_notional,m.max_notional,m.maker_fee_rate,m.taker_fee_rate,b.code AS base_asset,q.code AS quote_asset')
            ->order('m.symbol', 'asc')
            ->select()
            ->toArray();
        foreach ($spot as &$item) {
            $item['id'] = (int) $item['id'];
            $item['status'] = (int) $item['status'];
        }
        unset($item);

        $perpetual = Db::table('cex_market_perpetual_contracts')->alias('c')
            ->join('cex_asset_assets b', 'b.id = c.base_asset_id')
            ->join('cex_asset_assets q', 'q.id = c.quote_asset_id')
            ->join('cex_asset_assets s', 's.id = c.settlement_asset_id')
            ->whereIn('c.status', [1, 2, 3, 4])
            ->field('c.id,c.symbol,c.status,c.contract_size,c.price_tick,c.quantity_step,c.min_quantity,c.max_quantity,c.min_notional,c.max_notional,c.max_leverage,c.initial_margin_rate,c.maintenance_margin_rate,c.liquidation_fee_rate,c.maker_fee_rate,c.taker_fee_rate,c.funding_interval_minutes,c.funding_rate_cap,c.funding_rate_floor,b.code AS base_asset,q.code AS quote_asset,s.code AS settlement_asset')
            ->order('c.symbol', 'asc')
            ->select()
            ->toArray();
        foreach ($perpetual as &$item) {
            $item['id'] = (int) $item['id'];
            $item['status'] = (int) $item['status'];
            $item['funding_interval_minutes'] = (int) $item['funding_interval_minutes'];
        }
        unset($item);

        return [
            'market_data_included' => false,
            'assets' => $assets,
            'spot_symbols' => $spot,
            'perpetual_contracts' => $perpetual,
        ];
    }

    private function accountId(int $userId): ?int
    {
        $row = Db::table('cex_account_accounts')
            ->where('user_id', $userId)
            ->where('account_kind', 1)
            ->field('id,status')
            ->find();
        if (!$row || (int) $row['status'] === 3) {
            return null;
        }
        return (int) $row['id'];
    }

    private function pageResult(array $items, int $page, int $pageSize, int $total): array
    {
        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $total > 0 ? (int) ceil($total / $pageSize) : 0,
            ],
        ];
    }

    private function emptyPage(int $page, int $pageSize): array
    {
        return $this->pageResult([], $page, $pageSize, 0);
    }

    private function absDecimal(string $value): string
    {
        return $value !== '' && $value[0] === '-' ? substr($value, 1) : $value;
    }
}
