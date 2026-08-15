<?php

namespace app\service\Position;

use app\controller\Auth\AuthService;
use app\service\Asset\Decimal18;
use app\service\Asset\LedgerService;
use think\facade\Db;
use think\Request;

/**
 * Read-only dashboard projection for /dashboard/positions.
 *
 * This service never creates accounts, never locks rows and never writes to
 * orders, positions, balances or ledgers. It only presents already committed
 * spot balances and current perpetual net positions.
 */
final class PositionDashboardService
{
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
        if ($account === null) {
            return [
                'account' => null,
                'summary' => $this->emptySummary(),
                'perpetual_positions' => [],
                'spot_holdings' => [],
                'timezone' => $this->timezoneInfo()['name'],
            ];
        }

        $perpetual = $this->perpetualPositions((int) $account['id']);
        $spot = $this->spotHoldings((int) $account['id']);
        $risk = $this->riskState((int) $account['id']);

        $unrealized = Decimal18::zero();
        $initialMargin = Decimal18::zero();
        $maintenanceMargin = Decimal18::zero();
        foreach ($perpetual as $position) {
            $unrealized = Decimal18::add($unrealized, (string) $position['unrealized_pnl_raw']);
            $initialMargin = Decimal18::add($initialMargin, (string) $position['initial_margin_raw']);
            $maintenanceMargin = Decimal18::add($maintenanceMargin, (string) $position['maintenance_margin_raw']);
        }

        return [
            'account' => [
                'public_id' => (string) $account['public_id'],
                'status' => (int) $account['status'],
            ],
            'summary' => [
                'perpetual_count' => count($perpetual),
                'spot_asset_count' => count($spot),
                'unrealized_pnl' => $this->signed(Decimal18::trim($unrealized, 6)),
                'unrealized_pnl_class' => $this->valueClass($unrealized),
                'position_initial_margin' => Decimal18::trim($initialMargin, 6),
                'maintenance_margin' => Decimal18::trim($maintenanceMargin, 6),
                'equity' => $risk !== null ? Decimal18::trim((string) $risk['equity'], 6) : null,
                'available_margin' => $risk !== null ? Decimal18::trim((string) $risk['available_margin'], 6) : null,
                'wallet_balance' => $risk !== null ? Decimal18::trim((string) $risk['wallet_balance'], 6) : null,
                'risk_updated_at_local' => $risk !== null ? $this->localTime((string) $risk['updated_at']) : null,
            ],
            'perpetual_positions' => $perpetual,
            'spot_holdings' => $spot,
            'timezone' => $this->timezoneInfo()['name'],
        ];
    }

    private function emptySummary(): array
    {
        return [
            'perpetual_count' => 0,
            'spot_asset_count' => 0,
            'unrealized_pnl' => '0',
            'unrealized_pnl_class' => 'flat',
            'position_initial_margin' => '0',
            'maintenance_margin' => '0',
            'equity' => null,
            'available_margin' => null,
            'wallet_balance' => null,
            'risk_updated_at_local' => null,
        ];
    }

    private function perpetualPositions(int $accountId): array
    {
        $rows = Db::table('cex_perp_positions')->alias('p')
            ->join('cex_market_perpetual_contracts c', 'c.id = p.contract_id')
            ->join('cex_asset_assets ba', 'ba.id = c.base_asset_id')
            ->join('cex_asset_assets qa', 'qa.id = c.quote_asset_id')
            ->leftJoin('cex_perp_account_contract_settings s', 's.account_id = p.account_id AND s.contract_id = p.contract_id AND s.status = 1')
            ->where('p.account_id', $accountId)
            ->where('p.position_quantity', '<>', Decimal18::zero())
            ->field(implode(',', [
                'p.id', 'p.contract_id', 'p.position_quantity', 'p.entry_price', 'p.break_even_price',
                'p.realized_pnl', 'p.cumulative_funding', 'p.last_mark_price', 'p.unrealized_pnl',
                'p.initial_margin', 'p.maintenance_margin', 'p.liquidation_price', 'p.position_status',
                'p.opened_at', 'p.updated_at',
                'c.symbol', 'c.contract_size',
                'ba.code AS base_code', 'ba.display_decimals AS base_decimals',
                'qa.code AS quote_code', 'qa.display_decimals AS quote_decimals',
                's.leverage',
                'CAST(ABS(p.position_quantity) * COALESCE(p.last_mark_price,p.entry_price) * c.contract_size AS DECIMAL(38,18)) AS position_notional',
            ]))
            ->order('p.updated_at', 'desc')
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $row) {
            $signedQty = Decimal18::normalize((string) $row['position_quantity']);
            $isLong = Decimal18::compare($signedQty, '0') > 0;
            $baseDecimals = (int) $row['base_decimals'];
            $quoteDecimals = (int) $row['quote_decimals'];
            $unrealizedRaw = Decimal18::normalize((string) $row['unrealized_pnl']);
            $initialMarginRaw = Decimal18::normalize((string) $row['initial_margin']);
            $maintenanceMarginRaw = Decimal18::normalize((string) $row['maintenance_margin']);

            $items[] = [
                'position_id' => (int) $row['id'],
                'symbol' => (string) $row['symbol'],
                'pair' => (string) $row['base_code'] . '/' . (string) $row['quote_code'],
                'base_code' => (string) $row['base_code'],
                'quote_code' => (string) $row['quote_code'],
                'side' => $isLong ? 'LONG' : 'SHORT',
                'side_label' => $isLong ? '多仓' : '空仓',
                'side_class' => $isLong ? 'long' : 'short',
                'position_quantity' => Decimal18::trim($this->absDecimal($signedQty), $baseDecimals),
                'signed_quantity' => Decimal18::trim($signedQty, $baseDecimals),
                'entry_price' => $row['entry_price'] !== null ? Decimal18::trim((string) $row['entry_price'], $quoteDecimals) : '--',
                'break_even_price' => $row['break_even_price'] !== null ? Decimal18::trim((string) $row['break_even_price'], $quoteDecimals) : '--',
                'mark_price' => $row['last_mark_price'] !== null ? Decimal18::trim((string) $row['last_mark_price'], $quoteDecimals) : '--',
                'liquidation_price' => $row['liquidation_price'] !== null ? Decimal18::trim((string) $row['liquidation_price'], $quoteDecimals) : '--',
                'realized_pnl' => $this->signed(Decimal18::trim((string) $row['realized_pnl'], 6)),
                'unrealized_pnl' => $this->signed(Decimal18::trim($unrealizedRaw, 6)),
                'unrealized_pnl_raw' => $unrealizedRaw,
                'pnl_class' => $this->valueClass($unrealizedRaw),
                'initial_margin' => Decimal18::trim($initialMarginRaw, 6),
                'initial_margin_raw' => $initialMarginRaw,
                'maintenance_margin' => Decimal18::trim($maintenanceMarginRaw, 6),
                'maintenance_margin_raw' => $maintenanceMarginRaw,
                'cumulative_funding' => Decimal18::trim((string) $row['cumulative_funding'], 6),
                'position_notional' => Decimal18::trim((string) ($row['position_notional'] ?? '0'), 6),
                'leverage' => $row['leverage'] !== null ? Decimal18::trim((string) $row['leverage'], 2) : '--',
                'opened_at_local' => $row['opened_at'] !== null ? $this->localTime((string) $row['opened_at']) : '--',
                'updated_at_local' => $this->localTime((string) $row['updated_at']),
                'trade_url' => '/trade-swap/' . strtolower((string) $row['base_code'] . '-' . (string) $row['quote_code'] . '-swap'),
            ];
        }

        return $items;
    }

    private function spotHoldings(int $accountId): array
    {
        $rows = Db::table('cex_asset_ledger_accounts')->alias('la')
            ->join('cex_asset_assets a', 'a.id = la.asset_id')
            ->leftJoin('cex_asset_balances b', 'b.ledger_account_id = la.id')
            ->where('la.account_id', $accountId)
            ->where('la.account_scope', LedgerService::SCOPE_SPOT)
            ->where('la.status', 1)
            ->whereIn('la.balance_bucket', [LedgerService::BUCKET_AVAILABLE, LedgerService::BUCKET_LOCKED])
            ->field('la.asset_id,la.balance_bucket,COALESCE(b.balance,0) AS balance,a.code,a.name,a.display_decimals,a.spot_enabled,a.deposit_enabled,a.withdraw_enabled')
            ->order('a.id', 'asc')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $assetId = (int) $row['asset_id'];
            if (!isset($map[$assetId])) {
                $map[$assetId] = [
                    'asset_id' => $assetId,
                    'code' => (string) $row['code'],
                    'name' => (string) $row['name'],
                    'display_decimals' => (int) $row['display_decimals'],
                    'spot_enabled' => (bool) $row['spot_enabled'],
                    'deposit_enabled' => (bool) $row['deposit_enabled'],
                    'withdraw_enabled' => (bool) $row['withdraw_enabled'],
                    'available_raw' => Decimal18::zero(),
                    'locked_raw' => Decimal18::zero(),
                ];
            }
            $balance = Decimal18::normalize((string) $row['balance']);
            if ((int) $row['balance_bucket'] === LedgerService::BUCKET_AVAILABLE) {
                $map[$assetId]['available_raw'] = $balance;
            } elseif ((int) $row['balance_bucket'] === LedgerService::BUCKET_LOCKED) {
                $map[$assetId]['locked_raw'] = $balance;
            }
        }

        $ownedIds = [];
        foreach ($map as $assetId => $item) {
            $total = Decimal18::add($item['available_raw'], $item['locked_raw']);
            if (Decimal18::compare($total, '0') !== 0) $ownedIds[] = (int) $assetId;
        }
        $markets = $this->spotMarketsByBaseAsset($ownedIds);

        $items = [];
        foreach ($map as $assetId => $item) {
            $totalRaw = Decimal18::add($item['available_raw'], $item['locked_raw']);
            if (Decimal18::compare($totalRaw, '0') === 0) continue;
            $decimals = (int) $item['display_decimals'];
            $market = $markets[$assetId] ?? null;
            $items[] = [
                'asset_id' => $assetId,
                'code' => $item['code'],
                'name' => $item['name'],
                'available' => Decimal18::trim($item['available_raw'], $decimals),
                'locked' => Decimal18::trim($item['locked_raw'], $decimals),
                'total' => Decimal18::trim($totalRaw, $decimals),
                'spot_enabled' => $item['spot_enabled'],
                'deposit_enabled' => $item['deposit_enabled'],
                'withdraw_enabled' => $item['withdraw_enabled'],
                'market_pair' => $market !== null ? $market['pair'] : null,
                'trade_url' => $market !== null ? $market['trade_url'] : null,
            ];
        }

        usort($items, function (array $left, array $right) {
            if ($left['code'] === 'USDT') return -1;
            if ($right['code'] === 'USDT') return 1;
            return strcmp((string) $left['code'], (string) $right['code']);
        });
        return $items;
    }

    private function spotMarketsByBaseAsset(array $assetIds): array
    {
        if ($assetIds === []) return [];
        $rows = Db::table('cex_market_spot_symbols')->alias('m')
            ->join('cex_asset_assets ba', 'ba.id = m.base_asset_id')
            ->join('cex_asset_assets qa', 'qa.id = m.quote_asset_id')
            ->whereIn('m.base_asset_id', $assetIds)
            ->where('m.status', 1)
            ->where('qa.code', 'USDT')
            ->field('m.base_asset_id,ba.code AS base_code,qa.code AS quote_code')
            ->order('m.id', 'asc')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $assetId = (int) $row['base_asset_id'];
            if (isset($map[$assetId])) continue;
            $base = (string) $row['base_code'];
            $quote = (string) $row['quote_code'];
            $map[$assetId] = [
                'pair' => $base . '/' . $quote,
                'trade_url' => '/trade-spot/' . strtolower($base . '-' . $quote),
            ];
        }
        return $map;
    }

    private function riskState(int $accountId): ?array
    {
        $row = Db::table('cex_perp_account_risk_states')
            ->where('account_id', $accountId)
            ->field('wallet_balance,unrealized_pnl,equity,position_initial_margin,order_initial_margin,maintenance_margin,available_margin,margin_ratio,risk_status,updated_at')
            ->find();
        return $row ?: null;
    }

    private function businessAccount(): ?array
    {
        if ($this->businessAccount !== null) return $this->businessAccount;
        $auth = $this->authContext();
        $row = Db::table('cex_account_accounts')
            ->where('user_id', (int) $auth['user_id'])
            ->where('account_kind', 1)
            ->field('id,public_id,status,user_id')
            ->find();
        if (!$row) return null;
        $this->businessAccount = $row;
        return $row;
    }

    private function authContext(): array
    {
        if ($this->authContext !== null) return $this->authContext;
        $auth = new AuthService($this->request);
        $cookie = (string) $this->request->cookie($auth->cookieName(), '');
        $this->authContext = $auth->authenticatedSession($cookie, true);
        return $this->authContext;
    }

    private function timezoneInfo(): array
    {
        if ($this->userTimezone !== null) return $this->userTimezone;
        $auth = $this->authContext();
        $row = Db::table('cex_user_users')->where('id', (int) $auth['user_id'])->field('timezone')->find();
        $name = trim((string) ($row['timezone'] ?? 'UTC')) ?: 'UTC';
        try {
            $timezone = new \DateTimeZone($name);
        } catch (\Throwable $ignored) {
            $name = 'UTC';
            $timezone = new \DateTimeZone('UTC');
        }
        $this->userTimezone = ['name' => $name, 'object' => $timezone];
        return $this->userTimezone;
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

    private function valueClass(string $value): string
    {
        $cmp = Decimal18::compare($value, '0');
        return $cmp > 0 ? 'profit' : ($cmp < 0 ? 'loss' : 'flat');
    }

    private function signed(string $value): string
    {
        return Decimal18::compare($value, '0') > 0 ? '+' . $value : $value;
    }

    private function absDecimal(string $value): string
    {
        $normalized = Decimal18::normalize($value);
        return $normalized[0] === '-' ? substr($normalized, 1) : $normalized;
    }
}
