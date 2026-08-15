<?php

namespace app\service\Wallet;

use app\service\Asset\AssetException;
use app\service\Asset\LedgerService;
use think\facade\Db;

/**
 * Single accounting gateway for confirmed wallet deposits.
 *
 * Call only inside a surrounding DB transaction. LedgerService provides the
 * second idempotency barrier; cex_wallet_deposits provides the first.
 */
final class DepositAccountingService
{
    private LedgerService $ledger;

    public function __construct()
    {
        $this->ledger = new LedgerService();
    }

    public function credit(array $deposit, array $route): array
    {
        $systemAccount = $this->systemDepositClearingAccount();
        $systemLedger = $this->ledger->ensureLedgerAccount(
            (int) $systemAccount['id'],
            (int) $route['asset_id'],
            LedgerService::SCOPE_SPOT,
            LedgerService::BUCKET_AVAILABLE,
            true
        );
        $userLedger = $this->ledger->ensureLedgerAccount(
            (int) $deposit['account_id'],
            (int) $route['asset_id'],
            LedgerService::SCOPE_SPOT,
            LedgerService::BUCKET_AVAILABLE,
            false
        );

        return $this->ledger->postWithinTransaction([
            'business_type' => 'WALLET_DEPOSIT',
            'business_id' => (string) $deposit['deposit_no'],
            'idempotency_key' => 'deposit-credit:' . (string) $deposit['deposit_no'],
            'description' => 'Confirmed blockchain deposit credited to spot available balance',
            'metadata' => [
                'route_code' => (string) $route['route_code'],
                'tx_hash' => (string) $deposit['tx_hash'],
                'event_index' => (int) $deposit['event_index'],
                'natural_key' => hash('sha256', implode('|', [
                    (string) $route['network_code'],
                    (string) $route['route_code'],
                    (string) $deposit['tx_hash'],
                    (string) $deposit['event_index'],
                ])),
            ],
        ], [
            [
                'ledger_account_id' => (int) $systemLedger['id'],
                'asset_id' => (int) $route['asset_id'],
                'direction' => LedgerService::DIRECTION_DECREASE,
                'amount' => (string) $deposit['amount'],
            ],
            [
                'ledger_account_id' => (int) $userLedger['id'],
                'asset_id' => (int) $route['asset_id'],
                'direction' => LedgerService::DIRECTION_INCREASE,
                'amount' => (string) $deposit['amount'],
            ],
        ]);
    }

    public function ledger(): LedgerService
    {
        return $this->ledger;
    }

    private function systemDepositClearingAccount(): array
    {
        $row = Db::table('cex_account_accounts')
            ->where('account_kind', 2)
            ->where('system_code', 'DEPOSIT_CLEARING')
            ->where('status', 1)
            ->field('id,public_id,system_code,status')
            ->find();

        if (!$row) {
            throw new AssetException('系统 DEPOSIT_CLEARING 账户不存在', 500, 'DEPOSIT_CLEARING_ACCOUNT_MISSING');
        }
        return $row;
    }
}
