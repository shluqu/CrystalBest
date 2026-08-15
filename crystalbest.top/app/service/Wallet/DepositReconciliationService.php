<?php

namespace app\service\Wallet;

use app\controller\Auth\Ulid;
use app\controller\Auth\UtcClock;
use app\service\Asset\Decimal18;
use think\facade\Db;
use think\facade\Log;

/**
 * Main-DB reconciliation for the deposit -> ledger closure.
 *
 * It never scans chains and never invents deposits. It only verifies deposits
 * that already exist on Main. Safe repair is intentionally narrow: only a
 * deposit that is still DETECTED/CONFIRMING, already meets its snapshotted
 * policy and has no credit link may be idempotently credited/re-linked.
 */
final class DepositReconciliationService
{
    private DepositAccountingService $accounting;

    public function __construct()
    {
        $this->accounting = new DepositAccountingService();
    }

    public function run(bool $repairSafe = false, ?int $limit = null): array
    {
        $limit = $limit ?? (int) config('wallet.ops.reconcile_limit', 250);
        $limit = max(10, min(2000, $limit));
        $lookbackHours = (int) config('wallet.ops.reconcile_lookback_hours', 168);

        $end = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $start = $end->modify('-' . max(1, $lookbackHours) . ' hours');
        $runNo = Ulid::generate();
        $runId = (int) Db::table('cex_audit_reconciliation_runs')->insertGetId([
            'run_no' => $runNo,
            'reconciliation_type' => 'WALLET_DEPOSIT_LEDGER',
            'scope_key' => 'MAIN',
            'period_start_at' => $start->format('Y-m-d H:i:s.u'),
            'period_end_at' => $end->format('Y-m-d H:i:s.u'),
            'status' => 1,
            'checked_count' => 0,
            'difference_count' => 0,
            'summary_json' => null,
            'started_at' => UtcClock::now(),
        ]);

        $checked = 0;
        $differenceDeposits = 0;
        $repairedDeposits = 0;
        $reasonCounts = [];

        try {
            $rows = Db::table('cex_wallet_deposits')->alias('d')
                ->join('cex_asset_asset_networks an', 'an.id = d.asset_network_id')
                ->join('cex_asset_assets a', 'a.id = an.asset_id')
                ->join('cex_asset_networks n', 'n.id = an.network_id')
                ->where('d.detected_at', '>=', $start->format('Y-m-d H:i:s.u'))
                ->field('d.id,d.deposit_no,d.account_id,d.asset_network_id,d.address_id,d.chain_transaction_id,d.tx_hash,d.event_index,d.amount,d.confirmations,d.status,d.credit_ledger_transaction_id,d.reversal_ledger_transaction_id,d.required_confirmations_snapshot,d.min_deposit_snapshot,d.detected_at,d.credited_at,d.reversed_at,d.updated_at,an.asset_id,an.route_code,an.required_confirmations AS route_required_confirmations,an.min_deposit_amount AS route_min_deposit,a.code AS asset_code,n.code AS network_code')
                ->order('d.id', 'desc')
                ->limit($limit)
                ->select()
                ->toArray();

            foreach ($rows as $deposit) {
                $checked++;
                $issues = $this->inspectDeposit($deposit);
                if ($issues === []) {
                    continue;
                }

                // The existing audit schema has a unique key per run/entity.
                // Store one aggregate reconciliation item per deposit so a
                // deposit with multiple issues cannot violate that unique key,
                // and difference_count can never exceed checked_count.
                $differenceDeposits++;
                foreach ($issues as $issue) {
                    $code = (string) $issue['reason_code'];
                    $reasonCounts[$code] = ($reasonCounts[$code] ?? 0) + 1;
                }

                $resolutionStatus = 1; // open
                $resolvedAt = null;
                $repair = null;

                // Only the single, explicitly safe closure gap is auto-repaired.
                if ($repairSafe
                    && count($issues) === 1
                    && ($issues[0]['repairable'] ?? false) === true
                    && (string) $issues[0]['reason_code'] === 'DEPOSIT_CONFIRMED_NOT_CREDITED') {
                    $repair = $this->repairConfirmedDeposit((int) $deposit['id']);
                    if (($repair['repaired'] ?? false) === true) {
                        $repairedDeposits++;
                        $resolutionStatus = 3; // resolved
                        $resolvedAt = UtcClock::now();
                    }
                }

                $primary = $issues[0];
                $reasonCode = count($issues) === 1
                    ? (string) $primary['reason_code']
                    : 'MULTIPLE_DEPOSIT_LEDGER_ISSUES';
                $details = [
                    'deposit_no' => (string) $deposit['deposit_no'],
                    'tx_hash' => (string) $deposit['tx_hash'],
                    'event_index' => (int) $deposit['event_index'],
                    'route_code' => (string) $deposit['route_code'],
                    'issues' => $issues,
                ];
                if ($repair !== null) {
                    $details['repair'] = $repair;
                }

                Db::table('cex_audit_reconciliation_items')->insert([
                    'run_id' => $runId,
                    'entity_type' => 'WALLET_DEPOSIT',
                    'entity_id' => (string) $deposit['deposit_no'],
                    'asset_id' => (int) $deposit['asset_id'],
                    'expected_value' => $primary['expected_value'] ?? null,
                    'actual_value' => $primary['actual_value'] ?? null,
                    'difference_value' => $primary['difference_value'] ?? null,
                    'resolution_status' => $resolutionStatus,
                    'reason_code' => $reasonCode,
                    'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'resolved_at' => $resolvedAt,
                ]);
            }

            $summary = [
                'repair_safe' => $repairSafe,
                'limit' => $limit,
                'checked' => $checked,
                'difference_deposits' => $differenceDeposits,
                'repaired_deposits' => $repairedDeposits,
                'open_difference_deposits' => max(0, $differenceDeposits - $repairedDeposits),
                'reason_counts' => $reasonCounts,
            ];

            Db::table('cex_audit_reconciliation_runs')->where('id', $runId)->update([
                'status' => $differenceDeposits === 0 ? 2 : 3,
                'checked_count' => $checked,
                'difference_count' => $differenceDeposits,
                'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'completed_at' => UtcClock::now(),
            ]);

            return [
                'run_no' => $runNo,
                'status' => $differenceDeposits === 0
                    ? 'CLEAN'
                    : ($repairedDeposits === $differenceDeposits ? 'REPAIRED' : 'DIFFERENCES_FOUND'),
                'summary' => $summary,
            ];
        } catch (\Throwable $exception) {
            try {
                Db::table('cex_audit_reconciliation_runs')->where('id', $runId)->update([
                    'status' => 4,
                    'checked_count' => $checked,
                    'difference_count' => min($differenceDeposits, $checked),
                    'summary_json' => json_encode([
                        'repair_safe' => $repairSafe,
                        'checked' => $checked,
                        'difference_deposits' => $differenceDeposits,
                        'repaired_deposits' => $repairedDeposits,
                        'error' => substr($exception->getMessage(), 0, 512),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'completed_at' => UtcClock::now(),
                ]);
            } catch (\Throwable $ignored) {
                Log::error('Wallet reconciliation run failure could not be recorded: ' . $ignored->getMessage());
            }
            throw $exception;
        }
    }

    private function inspectDeposit(array $deposit): array
    {
        $issues = [];
        $status = (int) $deposit['status'];
        $required = $deposit['required_confirmations_snapshot'] !== null
            ? (int) $deposit['required_confirmations_snapshot']
            : (int) $deposit['route_required_confirmations'];
        $minimum = $deposit['min_deposit_snapshot'] !== null
            ? Decimal18::normalize((string) $deposit['min_deposit_snapshot'])
            : Decimal18::normalize((string) $deposit['route_min_deposit']);

        $creditId = (int) ($deposit['credit_ledger_transaction_id'] ?? 0);
        $creditHeaderValid = false;
        if ($creditId > 0) {
            $credit = $this->ledgerTransaction($creditId);
            if (!$credit) {
                $issues[] = [
                    'reason_code' => 'DEPOSIT_CREDIT_LEDGER_TX_MISSING',
                    'repairable' => false,
                    'details' => ['credit_ledger_transaction_id' => $creditId],
                ];
            } else {
                $creditHeaderValid = (string) $credit['business_type'] === 'WALLET_DEPOSIT'
                    && (string) $credit['business_id'] === (string) $deposit['deposit_no']
                    && (int) $credit['status'] === 2;
                if (!$creditHeaderValid) {
                    $issues[] = [
                        'reason_code' => 'DEPOSIT_CREDIT_LEDGER_HEADER_MISMATCH',
                        'repairable' => false,
                        'details' => [
                            'credit_ledger_transaction_id' => $creditId,
                            'business_type' => $credit['business_type'],
                            'business_id' => $credit['business_id'],
                            'ledger_status' => (int) $credit['status'],
                        ],
                    ];
                }

                $entryIssue = $this->inspectCreditEntries(
                    $creditId,
                    (int) $deposit['asset_id'],
                    (int) $deposit['account_id'],
                    (string) $deposit['amount']
                );
                if ($entryIssue !== null) {
                    $issues[] = $entryIssue;
                }
            }
        }

        if (in_array($status, [DepositEventService::DEPOSIT_CREDITED, DepositEventService::DEPOSIT_REVERSED], true) && $creditId <= 0) {
            $issues[] = [
                'reason_code' => 'DEPOSIT_CREDIT_LINK_MISSING',
                'repairable' => false,
                'details' => ['status' => $status],
            ];
        }

        if ($creditId > 0
            && $creditHeaderValid
            && !in_array($status, [DepositEventService::DEPOSIT_CREDITED, DepositEventService::DEPOSIT_REVERSED], true)) {
            $issues[] = [
                'reason_code' => 'DEPOSIT_STATUS_LEDGER_LINK_MISMATCH',
                'repairable' => false,
                'details' => ['status' => $status, 'credit_ledger_transaction_id' => $creditId],
            ];
        }

        if ($status === DepositEventService::DEPOSIT_REVERSED) {
            $reversalId = (int) ($deposit['reversal_ledger_transaction_id'] ?? 0);
            $reversal = $reversalId > 0 ? $this->ledgerTransaction($reversalId) : null;
            if (!$reversal
                || (string) ($reversal['business_type'] ?? '') !== 'WALLET_DEPOSIT_REVERSAL'
                || (string) ($reversal['business_id'] ?? '') !== (string) $deposit['deposit_no']
                || (int) ($reversal['reversed_transaction_id'] ?? 0) !== $creditId
                || (int) ($reversal['status'] ?? 0) !== 2) {
                $issues[] = [
                    'reason_code' => 'DEPOSIT_REVERSAL_LEDGER_MISMATCH',
                    'repairable' => false,
                    'details' => [
                        'credit_ledger_transaction_id' => $creditId ?: null,
                        'reversal_ledger_transaction_id' => $reversalId ?: null,
                    ],
                ];
            } elseif (($entryIssue = $this->inspectReversalEntries(
                $reversalId,
                (int) $deposit['asset_id'],
                (int) $deposit['account_id'],
                (string) $deposit['amount']
            )) !== null) {
                $issues[] = $entryIssue;
            }
        }

        $confirmedEnough = (int) $deposit['confirmations'] >= $required;
        $meetsMinimum = Decimal18::compare((string) $deposit['amount'], $minimum) >= 0;
        if (in_array($status, [DepositEventService::DEPOSIT_DETECTED, DepositEventService::DEPOSIT_CONFIRMING], true)
            && $confirmedEnough && $meetsMinimum && $creditId <= 0) {
            $issues[] = [
                'reason_code' => 'DEPOSIT_CONFIRMED_NOT_CREDITED',
                'repairable' => true,
                'details' => [
                    'confirmations' => (int) $deposit['confirmations'],
                    'required_confirmations' => $required,
                    'minimum' => $minimum,
                ],
            ];
        }

        return $issues;
    }

    private function inspectCreditEntries(int $transactionId, int $assetId, int $userAccountId, string $amount): ?array
    {
        $rows = $this->ledgerEntries($transactionId, $assetId);
        $expected = Decimal18::normalize($amount);
        $decrease = '0';
        $increase = '0';
        $systemDecrease = 0;
        $userIncrease = 0;

        foreach ($rows as $row) {
            if ((int) $row['direction'] === 1) {
                $decrease = Decimal18::add($decrease, (string) $row['amount']);
                if ((int) $row['account_kind'] === 2
                    && (string) $row['system_code'] === 'DEPOSIT_CLEARING'
                    && (int) $row['account_scope'] === 1
                    && (int) $row['balance_bucket'] === 1) {
                    $systemDecrease++;
                }
            } elseif ((int) $row['direction'] === 2) {
                $increase = Decimal18::add($increase, (string) $row['amount']);
                if ((int) $row['account_id'] === $userAccountId
                    && (int) $row['account_scope'] === 1
                    && (int) $row['balance_bucket'] === 1) {
                    $userIncrease++;
                }
            }
        }

        if (count($rows) !== 2
            || Decimal18::compare($decrease, $expected) !== 0
            || Decimal18::compare($increase, $expected) !== 0
            || $systemDecrease !== 1
            || $userIncrease !== 1) {
            return [
                'reason_code' => 'DEPOSIT_CREDIT_LEDGER_ENTRIES_MISMATCH',
                'repairable' => false,
                'expected_value' => $expected,
                'actual_value' => $increase,
                'difference_value' => Decimal18::subtract($increase, $expected),
                'details' => [
                    'transaction_id' => $transactionId,
                    'entry_count' => count($rows),
                    'decrease' => $decrease,
                    'increase' => $increase,
                    'deposit_clearing_decrease_entries' => $systemDecrease,
                    'user_spot_available_increase_entries' => $userIncrease,
                ],
            ];
        }

        return null;
    }

    private function inspectReversalEntries(int $transactionId, int $assetId, int $userAccountId, string $amount): ?array
    {
        $rows = $this->ledgerEntries($transactionId, $assetId);
        $expected = Decimal18::normalize($amount);
        $decrease = '0';
        $increase = '0';
        $userDecrease = 0;
        $systemIncrease = 0;

        foreach ($rows as $row) {
            if ((int) $row['direction'] === 1) {
                $decrease = Decimal18::add($decrease, (string) $row['amount']);
                if ((int) $row['account_id'] === $userAccountId
                    && (int) $row['account_scope'] === 1
                    && (int) $row['balance_bucket'] === 1) {
                    $userDecrease++;
                }
            } elseif ((int) $row['direction'] === 2) {
                $increase = Decimal18::add($increase, (string) $row['amount']);
                if ((int) $row['account_kind'] === 2
                    && (string) $row['system_code'] === 'DEPOSIT_CLEARING'
                    && (int) $row['account_scope'] === 1
                    && (int) $row['balance_bucket'] === 1) {
                    $systemIncrease++;
                }
            }
        }

        if (count($rows) !== 2
            || Decimal18::compare($decrease, $expected) !== 0
            || Decimal18::compare($increase, $expected) !== 0
            || $userDecrease !== 1
            || $systemIncrease !== 1) {
            return [
                'reason_code' => 'DEPOSIT_REVERSAL_LEDGER_ENTRIES_MISMATCH',
                'repairable' => false,
                'expected_value' => $expected,
                'actual_value' => $decrease,
                'difference_value' => Decimal18::subtract($decrease, $expected),
                'details' => [
                    'transaction_id' => $transactionId,
                    'entry_count' => count($rows),
                    'decrease' => $decrease,
                    'increase' => $increase,
                    'user_spot_available_decrease_entries' => $userDecrease,
                    'deposit_clearing_increase_entries' => $systemIncrease,
                ],
            ];
        }

        return null;
    }

    private function ledgerEntries(int $transactionId, int $assetId): array
    {
        return Db::table('cex_asset_ledger_entries')->alias('e')
            ->join('cex_asset_ledger_accounts la', 'la.id = e.ledger_account_id')
            ->join('cex_account_accounts aa', 'aa.id = la.account_id')
            ->where('e.transaction_id', $transactionId)
            ->where('e.asset_id', $assetId)
            ->field('e.direction,e.amount,la.account_id,la.account_scope,la.balance_bucket,aa.account_kind,aa.system_code')
            ->order('e.entry_no', 'asc')
            ->select()
            ->toArray();
    }

    private function repairConfirmedDeposit(int $depositId): array
    {
        return Db::transaction(function () use ($depositId) {
            $deposit = Db::table('cex_wallet_deposits')->alias('d')
                ->join('cex_asset_asset_networks an', 'an.id = d.asset_network_id')
                ->join('cex_asset_networks n', 'n.id = an.network_id')
                ->where('d.id', $depositId)
                ->field('d.id,d.deposit_no,d.account_id,d.asset_network_id,d.address_id,d.chain_transaction_id,d.tx_hash,d.event_index,d.amount,d.confirmations,d.status,d.credit_ledger_transaction_id,d.required_confirmations_snapshot,d.min_deposit_snapshot,an.asset_id,an.route_code,an.required_confirmations AS route_required_confirmations,an.min_deposit_amount AS route_min_deposit,n.code AS network_code')
                ->lock(true)
                ->find();

            if (!$deposit) {
                return ['repaired' => false, 'reason' => 'DEPOSIT_NOT_FOUND'];
            }
            if ((int) $deposit['status'] === DepositEventService::DEPOSIT_CREDITED
                && !empty($deposit['credit_ledger_transaction_id'])) {
                return ['repaired' => false, 'reason' => 'ALREADY_CREDITED'];
            }
            if (!in_array((int) $deposit['status'], [
                DepositEventService::DEPOSIT_DETECTED,
                DepositEventService::DEPOSIT_CONFIRMING,
            ], true)) {
                return ['repaired' => false, 'reason' => 'STATUS_NOT_REPAIRABLE'];
            }
            if (!empty($deposit['credit_ledger_transaction_id'])) {
                return ['repaired' => false, 'reason' => 'CREDIT_LINK_ALREADY_PRESENT'];
            }

            $required = $deposit['required_confirmations_snapshot'] !== null
                ? (int) $deposit['required_confirmations_snapshot']
                : (int) $deposit['route_required_confirmations'];
            $minimum = $deposit['min_deposit_snapshot'] !== null
                ? Decimal18::normalize((string) $deposit['min_deposit_snapshot'])
                : Decimal18::normalize((string) $deposit['route_min_deposit']);

            if ((int) $deposit['confirmations'] < $required
                || Decimal18::compare((string) $deposit['amount'], $minimum) < 0) {
                return ['repaired' => false, 'reason' => 'POLICY_NOT_MET'];
            }

            $ledgerTx = $this->accounting->credit($deposit, $deposit);
            Db::table('cex_wallet_deposits')->where('id', $depositId)->update([
                'status' => DepositEventService::DEPOSIT_CREDITED,
                'credit_ledger_transaction_id' => (int) $ledgerTx['id'],
                'credited_at' => UtcClock::now(),
                'updated_at' => UtcClock::now(),
            ]);

            return [
                'repaired' => true,
                'ledger_transaction_id' => (int) $ledgerTx['id'],
                'journal_no' => (string) $ledgerTx['journal_no'],
                'ledger_existing' => (bool) $ledgerTx['existing'],
            ];
        });
    }

    private function ledgerTransaction(int $id): ?array
    {
        $row = Db::table('cex_asset_ledger_transactions')
            ->where('id', $id)
            ->field('id,journal_no,business_type,business_id,idempotency_key,status,reversed_transaction_id,posted_at')
            ->find();
        return $row ?: null;
    }
}
