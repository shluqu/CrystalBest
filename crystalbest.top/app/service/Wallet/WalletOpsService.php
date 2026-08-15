<?php

namespace app\service\Wallet;

use app\controller\Auth\UtcClock;
use think\facade\Db;

final class WalletOpsService
{
    public function health(): array
    {
        $monitor = null;
        $monitorError = null;
        try {
            $monitor = (new WalletMonitorStatusClient())->fetch();
        } catch (\Throwable $exception) {
            $monitorError = [
                'code' => method_exists($exception, 'getErrorCode') ? $exception->getErrorCode() : 'WALLET_MONITOR_STATUS_ERROR',
                'message' => $exception->getMessage(),
            ];
        }

        $mainWatchCount = (int) Db::table('v_cex_wallet_assigned_watch_addresses')->count();
        $depositCounts = $this->countByStatus('cex_wallet_deposits');
        $eventCounts = $this->countByStatus('cex_wallet_custody_events');
        $withdrawalCounts = $this->countByStatus('cex_wallet_withdrawals');
        $staleMinutes = (int) config('wallet.ops.stale_confirming_minutes', 30);
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . max(5, $staleMinutes) . ' minutes')
            ->format('Y-m-d H:i:s.u');

        $staleConfirming = (int) Db::table('cex_wallet_deposits')
            ->whereIn('status', [
                DepositEventService::DEPOSIT_DETECTED,
                DepositEventService::DEPOSIT_CONFIRMING,
            ])
            ->where('updated_at', '<', $cutoff)
            ->count();

        $confirmedNotCredited = (int) Db::table('cex_wallet_deposits')->alias('d')
            ->join('cex_asset_asset_networks an', 'an.id = d.asset_network_id')
            ->whereIn('d.status', [
                DepositEventService::DEPOSIT_DETECTED,
                DepositEventService::DEPOSIT_CONFIRMING,
            ])
            ->whereRaw('d.confirmations >= COALESCE(d.required_confirmations_snapshot, an.required_confirmations)')
            ->whereRaw('d.amount >= COALESCE(d.min_deposit_snapshot, an.min_deposit_amount)')
            ->whereNull('d.credit_ledger_transaction_id')
            ->count();

        $rejectedRecent = (int) Db::table('cex_wallet_custody_events')
            ->where('status', DepositEventService::EVENT_REJECTED)
            ->where('last_attempt_at', '>=', (new \DateTimeImmutable('-24 hours', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'))
            ->count();

        $eventTimeout = (int) config('wallet.ops.event_processing_timeout_seconds', 120);
        $eventCutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . max(30, $eventTimeout) . ' seconds')
            ->format('Y-m-d H:i:s.u');
        $stuckReceived = (int) Db::table('cex_wallet_custody_events')
            ->where('status', DepositEventService::EVENT_RECEIVED)
            ->where('received_at', '<', $eventCutoff)
            ->count();
        $staleProcessing = (int) Db::table('cex_wallet_custody_events')
            ->where('status', DepositEventService::EVENT_PROCESSING)
            ->where('processing_started_at', '<', $eventCutoff)
            ->count();

        $withdrawReviewCutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . max(15, (int) config('wallet.withdrawal.stale_review_minutes', 120)) . ' minutes')
            ->format('Y-m-d H:i:s.u');
        $withdrawPayoutCutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . max(15, (int) config('wallet.withdrawal.stale_payout_minutes', 120)) . ' minutes')
            ->format('Y-m-d H:i:s.u');
        $staleWithdrawReview = (int) Db::table('cex_wallet_withdrawals')
            ->where('status', WithdrawalService::STATUS_PENDING_REVIEW)
            ->where('requested_at', '<', $withdrawReviewCutoff)
            ->count();
        $staleWithdrawPayout = (int) Db::table('cex_wallet_withdrawals')
            ->whereIn('status', [WithdrawalService::STATUS_APPROVED, WithdrawalService::STATUS_PAYOUT_PROCESSING])
            ->whereRaw('COALESCE(approved_at, updated_at) < ?', [$withdrawPayoutCutoff])
            ->count();

        $manualReview = (int) ($depositCounts[DepositEventService::DEPOSIT_MANUAL_REVIEW] ?? 0);
        $latestProcessedEvent = Db::table('cex_wallet_custody_events')
            ->where('status', DepositEventService::EVENT_PROCESSED)
            ->order('processed_at', 'desc')
            ->field('event_id,event_type,result_code,attempt_count,processed_at')
            ->find();
        $latestWithdrawal = Db::table('cex_wallet_withdrawals')->alias('w')
            ->join('cex_asset_asset_networks an', 'an.id = w.asset_network_id')
            ->join('cex_asset_assets a', 'a.id = an.asset_id')
            ->order('w.id', 'desc')
            ->field('w.withdrawal_no,w.status,w.receive_amount,w.platform_fee,w.gross_debit_amount,w.requested_at,w.approved_at,w.broadcast_at,w.confirmed_at,an.route_code,a.code AS asset_code')
            ->find();
        $latestCredit = Db::table('cex_wallet_deposits')
            ->where('status', DepositEventService::DEPOSIT_CREDITED)
            ->order('credited_at', 'desc')
            ->field('deposit_no,tx_hash,event_index,amount,confirmations,required_confirmations_snapshot,credited_at')
            ->find();
        $lastReconciliation = Db::table('cex_audit_reconciliation_runs')
            ->where('reconciliation_type', 'WALLET_DEPOSIT_LEDGER')
            ->order('id', 'desc')
            ->field('run_no,status,checked_count,difference_count,summary_json,started_at,completed_at')
            ->find();

        $networkErrors = [];
        $networkLag = [];
        $configuredNetworkCount = 0;
        if (is_array($monitor)) {
            foreach ((array) ($monitor['networks'] ?? []) as $code => $network) {
                if (!is_array($network) || !($network['configured'] ?? false)) continue;
                $configuredNetworkCount++;
                if (($network['last_error'] ?? null) !== null) {
                    $networkErrors[(string) $code] = (string) $network['last_error'];
                }
                if (isset($network['tip'], $network['checkpoint'])
                    && $network['tip'] !== null && $network['checkpoint'] !== null) {
                    $networkLag[(string) $code] = max(0, (int) $network['tip'] - (int) $network['checkpoint']);
                }
            }
        }

        $watchCountsMatch = is_array($monitor)
            && (int) ($monitor['watch_address_count'] ?? -1) === $mainWatchCount;
        $monitorHealthy = is_array($monitor)
            && ($monitor['ok'] ?? false) === true
            && ($monitor['stopping'] ?? true) === false
            && ($monitor['last_watch_refresh_error'] ?? null) === null
            && ($monitor['last_delivery_error'] ?? null) === null
            && $configuredNetworkCount > 0
            && $networkErrors === []
            && $watchCountsMatch;

        $hardAnomalies = $confirmedNotCredited + $rejectedRecent + $stuckReceived + $staleProcessing;
        $state = !$monitorHealthy || $hardAnomalies > 0
            ? 'DEGRADED'
            : ($manualReview > 0 || $staleConfirming > 0 || $staleWithdrawReview > 0 || $staleWithdrawPayout > 0 ? 'ATTENTION' : 'HEALTHY');

        return [
            'ok' => $state !== 'DEGRADED',
            'state' => $state,
            'time_utc' => UtcClock::now(),
            'main' => [
                'watch_address_count' => $mainWatchCount,
                'monitor_watch_count_matches_main' => $watchCountsMatch,
                'deposit_status_counts' => $depositCounts,
                'event_status_counts' => $eventCounts,
                'withdrawal_status_counts' => $withdrawalCounts,
                'confirmed_not_credited' => $confirmedNotCredited,
            'stale_withdrawals' => $staleWithdrawals,
                'stale_detected_or_confirming' => $staleConfirming,
                'manual_review' => $manualReview,
                'rejected_events_last_24h' => $rejectedRecent,
                'stuck_received_events' => $stuckReceived,
                'stale_processing_events' => $staleProcessing,
                'stale_withdraw_review' => $staleWithdrawReview,
                'stale_withdraw_payout' => $staleWithdrawPayout,
                'latest_processed_event' => $latestProcessedEvent ?: null,
                'latest_credit' => $latestCredit ?: null,
                'latest_withdrawal' => $latestWithdrawal ?: null,
                'last_reconciliation' => $this->decodeSummary($lastReconciliation),
            ],
            'monitor_summary' => [
                'healthy' => $monitorHealthy,
                'configured_network_count' => $configuredNetworkCount,
                'network_errors' => $networkErrors,
                'checkpoint_lag' => $networkLag,
            ],
            'monitor' => $monitor,
            'monitor_error' => $monitorError,
        ];
    }

    public function anomalies(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $eventTimeout = (int) config('wallet.ops.event_processing_timeout_seconds', 120);
        $eventCutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . max(30, $eventTimeout) . ' seconds')
            ->format('Y-m-d H:i:s.u');

        $rejected = Db::table('cex_wallet_custody_events')
            ->where('status', DepositEventService::EVENT_REJECTED)
            ->field('event_id,event_type,result_code,error_message,attempt_count,last_remote_ip,received_at,last_attempt_at,processed_at')
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        $stuckEvents = Db::table('cex_wallet_custody_events')
            ->whereRaw(
                '(`status` = ? AND `received_at` < ?) OR (`status` = ? AND `processing_started_at` < ?)',
                [
                    DepositEventService::EVENT_RECEIVED,
                    $eventCutoff,
                    DepositEventService::EVENT_PROCESSING,
                    $eventCutoff,
                ]
            )
            ->field('event_id,event_type,status,result_code,error_message,attempt_count,last_remote_ip,received_at,processing_started_at,last_attempt_at')
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $manual = Db::table('cex_wallet_deposits')->alias('d')
            ->join('cex_asset_asset_networks an', 'an.id = d.asset_network_id')
            ->join('cex_asset_assets a', 'a.id = an.asset_id')
            ->join('cex_asset_networks n', 'n.id = an.network_id')
            ->where('d.status', DepositEventService::DEPOSIT_MANUAL_REVIEW)
            ->field('d.deposit_no,d.tx_hash,d.event_index,d.amount,d.confirmations,d.required_confirmations_snapshot,d.last_event_id,d.last_event_type,d.last_event_at,d.updated_at,an.route_code,a.code AS asset_code,n.code AS network_code')
            ->order('d.id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        $confirmedNotCredited = Db::table('cex_wallet_deposits')->alias('d')
            ->join('cex_asset_asset_networks an', 'an.id = d.asset_network_id')
            ->join('cex_asset_assets a', 'a.id = an.asset_id')
            ->join('cex_asset_networks n', 'n.id = an.network_id')
            ->whereIn('d.status', [
                DepositEventService::DEPOSIT_DETECTED,
                DepositEventService::DEPOSIT_CONFIRMING,
            ])
            ->whereRaw('d.confirmations >= COALESCE(d.required_confirmations_snapshot, an.required_confirmations)')
            ->whereRaw('d.amount >= COALESCE(d.min_deposit_snapshot, an.min_deposit_amount)')
            ->whereNull('d.credit_ledger_transaction_id')
            ->field('d.deposit_no,d.tx_hash,d.event_index,d.amount,d.confirmations,d.required_confirmations_snapshot,d.min_deposit_snapshot,d.detected_at,d.updated_at,an.route_code,a.code AS asset_code,n.code AS network_code')
            ->order('d.id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $reviewCutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . max(15, (int) config('wallet.withdrawal.stale_review_minutes', 120)) . ' minutes')
            ->format('Y-m-d H:i:s.u');
        $payoutCutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . max(15, (int) config('wallet.withdrawal.stale_payout_minutes', 120)) . ' minutes')
            ->format('Y-m-d H:i:s.u');
        $staleWithdrawals = Db::table('cex_wallet_withdrawals')->alias('w')
            ->join('cex_asset_asset_networks an', 'an.id = w.asset_network_id')
            ->join('cex_asset_assets a', 'a.id = an.asset_id')
            ->whereRaw('(w.status = ? AND w.requested_at < ?) OR (w.status IN (?,?) AND COALESCE(w.approved_at,w.updated_at) < ?)', [
                WithdrawalService::STATUS_PENDING_REVIEW, $reviewCutoff,
                WithdrawalService::STATUS_APPROVED, WithdrawalService::STATUS_PAYOUT_PROCESSING, $payoutCutoff,
            ])
            ->field('w.withdrawal_no,w.status,w.receive_amount,w.platform_fee,w.gross_debit_amount,w.destination_address,w.requested_at,w.approved_at,an.route_code,a.code AS asset_code')
            ->order('w.id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $openReconciliation = Db::table('cex_audit_reconciliation_items')->alias('i')
            ->join('cex_audit_reconciliation_runs r', 'r.id = i.run_id')
            ->where('r.reconciliation_type', 'WALLET_DEPOSIT_LEDGER')
            ->whereIn('i.resolution_status', [1, 2])
            ->field('r.run_no,i.entity_type,i.entity_id,i.reason_code,i.details_json,i.created_at')
            ->order('i.id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        foreach ($openReconciliation as &$row) {
            if (isset($row['details_json']) && is_string($row['details_json'])) {
                $decoded = json_decode($row['details_json'], true);
                $row['details'] = is_array($decoded) ? $decoded : null;
                unset($row['details_json']);
            }
        }
        unset($row);

        return [
            'rejected_events' => $rejected,
            'stuck_callback_events' => $stuckEvents,
            'manual_review_deposits' => $manual,
            'confirmed_not_credited' => $confirmedNotCredited,
            'stale_withdrawals' => $staleWithdrawals,
            'open_reconciliation_items' => $openReconciliation,
        ];
    }

    private function countByStatus(string $table): array
    {
        $rows = Db::table($table)
            ->field('status,COUNT(*) AS total')
            ->group('status')
            ->select()
            ->toArray();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['status']] = (int) $row['total'];
        }
        return $counts;
    }

    private function decodeSummary($row): ?array
    {
        if (!$row) return null;
        $summary = $row['summary_json'] ?? null;
        if (is_string($summary)) {
            $decoded = json_decode($summary, true);
            $row['summary'] = is_array($decoded) ? $decoded : null;
        } else {
            $row['summary'] = is_array($summary) ? $summary : null;
        }
        unset($row['summary_json']);
        return $row;
    }
}
