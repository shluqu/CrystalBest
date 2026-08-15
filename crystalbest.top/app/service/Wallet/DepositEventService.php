<?php

namespace app\service\Wallet;

use app\controller\Auth\Ulid;
use app\controller\Auth\UtcClock;
use app\service\Asset\AssetException;
use app\service\Asset\Decimal18;
use app\service\Asset\LedgerService;
use think\facade\Db;

/**
 * Ingests public chain-observation events from 10.0.0.1.
 * 10.0.0.2 remains authoritative for:
 * - route/minimum/confirmation policy;
 * - user/account/address ownership;
 * - deposit lifecycle state;
 * - final Ledger credit/reversal.
 */
final class DepositEventService
{
    public const EVENT_RECEIVED = 1;
    public const EVENT_PROCESSED = 2;
    public const EVENT_REJECTED = 3;
    public const EVENT_PROCESSING = 4;

    public const DEPOSIT_DETECTED = 1;
    public const DEPOSIT_CONFIRMING = 2;
    public const DEPOSIT_CREDITED = 3;
    public const DEPOSIT_REVERSED = 4;
    public const DEPOSIT_BELOW_MINIMUM = 5;
    public const DEPOSIT_MANUAL_REVIEW = 6;

    private $ledger;
    private $accounting;

    public function __construct()
    {
        $this->ledger = new LedgerService();
        $this->accounting = new DepositAccountingService();
    }

    public function handle(array $payload, array $sourceMeta): array
    {
        $event = $this->validatePayload($payload, $sourceMeta);
        $incomingHash = hex2bin((string) $sourceMeta['body_hash_hex']);
        if ($incomingHash === false || strlen($incomingHash) !== 32) {
            throw new AssetException('Wallet callback body hash 无效', 422, 'WALLET_CALLBACK_BODY_HASH_INVALID');
        }

        $claim = $this->claimEvent($event, $sourceMeta, $incomingHash);
        if (($claim['duplicate'] ?? false) === true) {
            return [
                'event_id' => $event['event_id'],
                'duplicate' => true,
                'status' => self::EVENT_PROCESSED,
                'result_code' => (string) ($claim['result_code'] ?? 'ALREADY_PROCESSED'),
            ];
        }

        $eventDbId = (int) $claim['event_db_id'];

        try {
            $result = Db::transaction(function () use ($event) {
                return $event['event_type'] === 'DEPOSIT_REORGED'
                    ? $this->handleReorg($event)
                    : $this->handleObservation($event);
            });

            Db::table('cex_wallet_custody_events')->where('id', $eventDbId)->update([
                'status' => self::EVENT_PROCESSED,
                'result_code' => (string) ($result['result_code'] ?? 'PROCESSED'),
                'error_message' => null,
                'processed_at' => UtcClock::now(),
                'processing_started_at' => null,
                'last_attempt_at' => UtcClock::now(),
            ]);

            $result['event_id'] = $event['event_id'];
            $result['duplicate'] = false;
            return $result;
        } catch (\Throwable $exception) {
            // Event receipt/attempt state is intentionally persisted outside the
            // business transaction so failed callbacks remain visible and can retry.
            Db::table('cex_wallet_custody_events')->where('id', $eventDbId)->update([
                'status' => self::EVENT_REJECTED,
                'result_code' => $exception instanceof AssetException ? $exception->getErrorCode() : 'INTERNAL_ERROR',
                'error_message' => substr($exception->getMessage(), 0, 512),
                'processed_at' => UtcClock::now(),
                'processing_started_at' => null,
                'last_attempt_at' => UtcClock::now(),
            ]);
            throw $exception;
        }
    }

    /**
     * Claim one immutable event payload for processing.
     *
     * Fixes a closure hole in the old implementation: if PHP died after the
     * receipt row was inserted with status=RECEIVED, all retries were treated
     * as duplicates forever. RECEIVED/REJECTED/stale PROCESSING rows are now
     * safely reclaimable; only PROCESSED is terminal.
     */
    private function claimEvent(array $event, array $sourceMeta, string $incomingHash): array
    {
        return Db::transaction(function () use ($event, $sourceMeta, $incomingHash) {
            $row = Db::table('cex_wallet_custody_events')
                ->where('event_id', $event['event_id'])
                ->field('id,event_id,event_type,source_service,idempotency_key,payload_hash,status,result_code,processed_at,processing_started_at,attempt_count')
                ->lock(true)
                ->find();

            if (!$row) {
                try {
                    $id = (int) Db::table('cex_wallet_custody_events')->insertGetId([
                        'event_id' => $event['event_id'],
                        'event_type' => $event['event_type'],
                        'source_service' => (string) ($sourceMeta['source_service'] ?? 'wallet-monitor'),
                        'idempotency_key' => (string) $sourceMeta['idempotency_key'],
                        'payload_hash' => $incomingHash,
                        'status' => self::EVENT_RECEIVED,
                        'attempt_count' => 0,
                        'last_remote_ip' => substr((string) ($sourceMeta['remote_ip'] ?? ''), 0, 45),
                        'received_at' => UtcClock::now(),
                    ]);
                    $row = Db::table('cex_wallet_custody_events')
                        ->where('id', $id)
                        ->field('id,event_id,event_type,source_service,idempotency_key,payload_hash,status,result_code,processed_at,processing_started_at,attempt_count')
                        ->lock(true)
                        ->find();
                } catch (\Throwable $exception) {
                    // Concurrent delivery of the same event may win the unique key.
                    $row = Db::table('cex_wallet_custody_events')
                        ->where('event_id', $event['event_id'])
                        ->field('id,event_id,event_type,source_service,idempotency_key,payload_hash,status,result_code,processed_at,processing_started_at,attempt_count')
                        ->lock(true)
                        ->find();
                    if (!$row) {
                        throw $exception;
                    }
                }
            }

            if ((string) $row['idempotency_key'] !== (string) $sourceMeta['idempotency_key']
                || !hash_equals((string) $row['payload_hash'], $incomingHash)) {
                throw new AssetException('相同 wallet event_id 收到了不同内容，已拒绝', 409, 'WALLET_EVENT_REPLAY_CONFLICT');
            }

            if ((int) $row['status'] === self::EVENT_PROCESSED) {
                return [
                    'duplicate' => true,
                    'result_code' => (string) ($row['result_code'] ?? 'ALREADY_PROCESSED'),
                ];
            }

            if ((int) $row['status'] === self::EVENT_PROCESSING && !$this->processingClaimExpired($row['processing_started_at'] ?? null)) {
                throw new AssetException('相同 Wallet event 正在处理中，请稍后重试', 409, 'WALLET_EVENT_PROCESSING');
            }

            $now = UtcClock::now();
            Db::table('cex_wallet_custody_events')->where('id', (int) $row['id'])->update([
                'status' => self::EVENT_PROCESSING,
                'result_code' => null,
                'error_message' => null,
                'processed_at' => null,
                'processing_started_at' => $now,
                'last_attempt_at' => $now,
                'last_remote_ip' => substr((string) ($sourceMeta['remote_ip'] ?? ''), 0, 45),
                'attempt_count' => (int) ($row['attempt_count'] ?? 0) + 1,
            ]);

            return [
                'duplicate' => false,
                'event_db_id' => (int) $row['id'],
            ];
        });
    }

    private function processingClaimExpired($startedAt): bool
    {
        if ($startedAt === null || trim((string) $startedAt) === '') {
            return true;
        }

        try {
            $started = new \DateTimeImmutable((string) $startedAt, new \DateTimeZone('UTC'));
        } catch (\Throwable $exception) {
            return true;
        }

        $timeout = (int) config('wallet.ops.event_processing_timeout_seconds', 120);
        return (time() - $started->getTimestamp()) >= $timeout;
    }

    private function handleObservation(array $event): array
    {
        $context = $this->resolveContext($event);
        $route = $context['route'];
        $address = $context['address'];
        $amount = $event['amount'];
        $this->assertOnChainPrecision($amount, (int) $route['asset_decimals_on_chain']);

        $chainTxId = $this->upsertChainTransaction($event, $route, $address);
        $deposit = $this->upsertDeposit($event, $route, $address, $chainTxId);

        $requiredConfirmations = isset($deposit['required_confirmations_snapshot'])
            ? (int) $deposit['required_confirmations_snapshot']
            : (int) $route['effective_required_confirmations'];
        $minimumDeposit = isset($deposit['min_deposit_snapshot'])
            ? Decimal18::normalize((string) $deposit['min_deposit_snapshot'])
            : Decimal18::normalize((string) $route['effective_min_deposit']);
        $effectiveConfirmations = max((int) $deposit['confirmations'], $event['confirmations']);

        if ((int) $deposit['status'] === self::DEPOSIT_REVERSED) {
            throw new AssetException('已冲正充值收到新的确认事件，需要人工复核', 409, 'DEPOSIT_REVERSED_EVENT_CONFLICT');
        }

        if ((int) $deposit['status'] === self::DEPOSIT_MANUAL_REVIEW) {
            return [
                'deposit_no' => (string) $deposit['deposit_no'],
                'status' => self::DEPOSIT_MANUAL_REVIEW,
                'result_code' => 'MANUAL_REVIEW_REQUIRED',
                'credited' => !empty($deposit['credit_ledger_transaction_id']),
                'required_confirmations' => $requiredConfirmations,
                'confirmations' => $effectiveConfirmations,
            ];
        }

        if (Decimal18::compare($amount, $minimumDeposit) < 0) {
            Db::table('cex_wallet_deposits')->where('id', (int) $deposit['id'])->update([
                'status' => self::DEPOSIT_BELOW_MINIMUM,
                'confirmations' => $effectiveConfirmations,
                'last_event_id' => $event['event_id'],
                'last_event_type' => $event['event_type'],
                'last_event_at' => UtcClock::now(),
                'updated_at' => UtcClock::now(),
            ]);
            return [
                'deposit_no' => (string) $deposit['deposit_no'],
                'status' => self::DEPOSIT_BELOW_MINIMUM,
                'result_code' => 'BELOW_MINIMUM',
                'credited' => false,
                'required_confirmations' => $requiredConfirmations,
                'confirmations' => $effectiveConfirmations,
            ];
        }

        if ($effectiveConfirmations < $requiredConfirmations) {
            if ((int) $deposit['status'] !== self::DEPOSIT_CREDITED) {
                Db::table('cex_wallet_deposits')->where('id', (int) $deposit['id'])->update([
                    'status' => self::DEPOSIT_CONFIRMING,
                    'confirmations' => $effectiveConfirmations,
                    'last_event_id' => $event['event_id'],
                    'last_event_type' => $event['event_type'],
                    'last_event_at' => UtcClock::now(),
                    'updated_at' => UtcClock::now(),
                ]);
            }
            return [
                'deposit_no' => (string) $deposit['deposit_no'],
                'status' => (int) $deposit['status'] === self::DEPOSIT_CREDITED ? self::DEPOSIT_CREDITED : self::DEPOSIT_CONFIRMING,
                'result_code' => 'AWAITING_CONFIRMATIONS',
                'credited' => (int) $deposit['status'] === self::DEPOSIT_CREDITED,
                'required_confirmations' => $requiredConfirmations,
                'confirmations' => $effectiveConfirmations,
            ];
        }

        if ((int) $deposit['status'] === self::DEPOSIT_CREDITED && !empty($deposit['credit_ledger_transaction_id'])) {
            return [
                'deposit_no' => (string) $deposit['deposit_no'],
                'status' => self::DEPOSIT_CREDITED,
                'result_code' => 'ALREADY_CREDITED',
                'credited' => true,
                'required_confirmations' => $requiredConfirmations,
                'confirmations' => $effectiveConfirmations,
            ];
        }

        if ((int) $deposit['status'] === self::DEPOSIT_CREDITED && empty($deposit['credit_ledger_transaction_id'])) {
            Db::table('cex_wallet_deposits')->where('id', (int) $deposit['id'])->update([
                'status' => self::DEPOSIT_MANUAL_REVIEW,
                'last_event_id' => $event['event_id'],
                'last_event_type' => $event['event_type'],
                'last_event_at' => UtcClock::now(),
                'updated_at' => UtcClock::now(),
            ]);
            return [
                'deposit_no' => (string) $deposit['deposit_no'],
                'status' => self::DEPOSIT_MANUAL_REVIEW,
                'result_code' => 'CREDITED_STATUS_LEDGER_LINK_MISSING',
                'credited' => false,
            ];
        }

        if (!(bool) config('wallet.deposit.auto_credit_confirmed', true)) {
            Db::table('cex_wallet_deposits')->where('id', (int) $deposit['id'])->update([
                'status' => self::DEPOSIT_CONFIRMING,
                'confirmations' => $effectiveConfirmations,
                'last_event_id' => $event['event_id'],
                'last_event_type' => $event['event_type'],
                'last_event_at' => UtcClock::now(),
                'updated_at' => UtcClock::now(),
            ]);
            return [
                'deposit_no' => (string) $deposit['deposit_no'],
                'status' => self::DEPOSIT_CONFIRMING,
                'result_code' => 'CONFIRMED_AUTO_CREDIT_DISABLED',
                'credited' => false,
                'required_confirmations' => $requiredConfirmations,
                'confirmations' => $effectiveConfirmations,
            ];
        }

        $ledgerTx = $this->accounting->credit($deposit, $route);
        Db::table('cex_wallet_deposits')->where('id', (int) $deposit['id'])->update([
            'status' => self::DEPOSIT_CREDITED,
            'confirmations' => $effectiveConfirmations,
            'credit_ledger_transaction_id' => (int) $ledgerTx['id'],
            'credited_at' => UtcClock::now(),
            'last_event_id' => $event['event_id'],
            'last_event_type' => $event['event_type'],
            'last_event_at' => UtcClock::now(),
            'updated_at' => UtcClock::now(),
        ]);

        return [
            'deposit_no' => (string) $deposit['deposit_no'],
            'status' => self::DEPOSIT_CREDITED,
            'result_code' => $ledgerTx['existing'] ? 'CREDIT_LINK_REPAIRED' : 'CREDITED',
            'credited' => true,
            'journal_no' => (string) $ledgerTx['journal_no'],
            'required_confirmations' => $requiredConfirmations,
            'confirmations' => $effectiveConfirmations,
        ];
    }

    private function upsertChainTransaction(array $event, array $route, array $address): int
    {
        $query = function () use ($route, $event) {
            return Db::table('cex_wallet_chain_transactions')
                ->where('network_id', (int) $route['network_id'])
                ->where('tx_hash', $event['tx_hash'])
                ->field('id,status,confirmations,block_height,block_hash,confirmed_at')
                ->lock(true)
                ->find();
        };

        $chainTx = $query();
        $chainStatus = $event['confirmations'] >= (int) $route['effective_required_confirmations'] ? 3 : 2;

        if (!$chainTx) {
            try {
                $chainTxId = (int) Db::table('cex_wallet_chain_transactions')->insertGetId([
                    'network_id' => (int) $route['network_id'],
                    'tx_hash' => $event['tx_hash'],
                    'direction' => 1,
                    'status' => $chainStatus,
                    'from_address' => $event['from_address'],
                    'to_address' => $event['to_address'] ?: (string) $address['address'],
                    'block_height' => $event['block_height'],
                    'block_hash' => $event['block_hash'],
                    'confirmations' => $event['confirmations'],
                    'first_seen_at' => $event['occurred_at'],
                    'confirmed_at' => $chainStatus === 3 ? UtcClock::now() : null,
                    'raw_metadata_json' => json_encode([
                        'source' => 'PRIVATE_WALLET_MONITOR',
                        'event_id' => $event['event_id'],
                        'event_index' => $event['event_index'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                return $chainTxId;
            } catch (\Throwable $exception) {
                $chainTx = $query();
                if (!$chainTx) {
                    throw $exception;
                }
            }
        }

        $chainTxId = (int) $chainTx['id'];
        $nextStatus = (int) $chainTx['status'] === 3 ? 3 : $chainStatus;
        $confirmedAt = $chainTx['confirmed_at'] ?? null;
        if ($nextStatus === 3 && ($confirmedAt === null || trim((string) $confirmedAt) === '')) {
            $confirmedAt = UtcClock::now();
        }

        Db::table('cex_wallet_chain_transactions')->where('id', $chainTxId)->update([
            'status' => $nextStatus,
            'confirmations' => max((int) $chainTx['confirmations'], $event['confirmations']),
            'block_height' => $event['block_height'] ?? $chainTx['block_height'],
            'block_hash' => $event['block_hash'] ?? $chainTx['block_hash'],
            'confirmed_at' => $confirmedAt,
            'updated_at' => UtcClock::now(),
        ]);

        return $chainTxId;
    }

    private function upsertDeposit(array $event, array $route, array $address, int $chainTxId): array
    {
        $query = function () use ($route, $event) {
            return Db::table('cex_wallet_deposits')
                ->where('asset_network_id', (int) $route['asset_network_id'])
                ->where('tx_hash', $event['tx_hash'])
                ->where('event_index', $event['event_index'])
                ->field('id,deposit_no,account_id,address_id,asset_network_id,chain_transaction_id,tx_hash,event_index,amount,confirmations,status,credit_ledger_transaction_id,reversal_ledger_transaction_id,required_confirmations_snapshot,min_deposit_snapshot,last_event_id,last_event_type,last_event_at')
                ->lock(true)
                ->find();
        };

        $deposit = $query();

        if (!$deposit) {
            $depositNo = Ulid::generate();
            $initialStatus = self::DEPOSIT_DETECTED;
            if (Decimal18::compare($event['amount'], (string) $route['effective_min_deposit']) < 0) {
                $initialStatus = self::DEPOSIT_BELOW_MINIMUM;
            } elseif ($event['confirmations'] > 0) {
                $initialStatus = self::DEPOSIT_CONFIRMING;
            }

            try {
                $depositId = (int) Db::table('cex_wallet_deposits')->insertGetId([
                    'deposit_no' => $depositNo,
                    'account_id' => (int) $address['account_id'],
                    'asset_network_id' => (int) $route['asset_network_id'],
                    'address_id' => (int) $address['id'],
                    'chain_transaction_id' => $chainTxId,
                    'tx_hash' => $event['tx_hash'],
                    'event_index' => $event['event_index'],
                    'amount' => $event['amount'],
                    'confirmations' => $event['confirmations'],
                    'required_confirmations_snapshot' => (int) $route['effective_required_confirmations'],
                    'min_deposit_snapshot' => (string) $route['effective_min_deposit'],
                    'status' => $initialStatus,
                    'detected_at' => $event['occurred_at'],
                    'last_event_id' => $event['event_id'],
                    'last_event_type' => $event['event_type'],
                    'last_event_at' => UtcClock::now(),
                ]);
                $deposit = Db::table('cex_wallet_deposits')
                    ->where('id', $depositId)
                    ->field('id,deposit_no,account_id,address_id,asset_network_id,chain_transaction_id,tx_hash,event_index,amount,confirmations,status,credit_ledger_transaction_id,reversal_ledger_transaction_id,required_confirmations_snapshot,min_deposit_snapshot,last_event_id,last_event_type,last_event_at')
                    ->lock(true)
                    ->find();
            } catch (\Throwable $exception) {
                // Database unique key is the final idempotency barrier.
                $deposit = $query();
                if (!$deposit) {
                    throw $exception;
                }
            }
        }

        if (!$deposit) {
            throw new AssetException('充值记录创建失败', 500, 'DEPOSIT_CREATE_FAILED');
        }

        if ((int) $deposit['account_id'] !== (int) $address['account_id']
            || (int) $deposit['address_id'] !== (int) $address['id']) {
            throw new AssetException('充值事件与历史归属冲突，已拒绝处理', 409, 'DEPOSIT_OWNERSHIP_CONFLICT');
        }

        if (Decimal18::compare((string) $deposit['amount'], $event['amount']) !== 0) {
            Db::table('cex_wallet_deposits')->where('id', (int) $deposit['id'])->update([
                'status' => self::DEPOSIT_MANUAL_REVIEW,
                'last_event_id' => $event['event_id'],
                'last_event_type' => $event['event_type'],
                'last_event_at' => UtcClock::now(),
                'updated_at' => UtcClock::now(),
            ]);
            $deposit['status'] = self::DEPOSIT_MANUAL_REVIEW;
            return $deposit;
        }

        Db::table('cex_wallet_deposits')->where('id', (int) $deposit['id'])->update([
            'chain_transaction_id' => $chainTxId,
            'confirmations' => max((int) $deposit['confirmations'], $event['confirmations']),
            'last_event_id' => $event['event_id'],
            'last_event_type' => $event['event_type'],
            'last_event_at' => UtcClock::now(),
            'updated_at' => UtcClock::now(),
        ]);
        $deposit['chain_transaction_id'] = $chainTxId;
        $deposit['confirmations'] = max((int) $deposit['confirmations'], $event['confirmations']);
        $deposit['last_event_id'] = $event['event_id'];
        $deposit['last_event_type'] = $event['event_type'];
        $deposit['last_event_at'] = UtcClock::now();

        return $deposit;
    }

    private function handleReorg(array $event): array
    {
        $context = $this->resolveContext($event);
        $route = $context['route'];
        $address = $context['address'];

        $chainTx = Db::table('cex_wallet_chain_transactions')
            ->where('network_id', (int) $route['network_id'])
            ->where('tx_hash', $event['tx_hash'])
            ->field('id,status')
            ->lock(true)
            ->find();
        if ($chainTx) {
            Db::table('cex_wallet_chain_transactions')->where('id', (int) $chainTx['id'])->update([
                'status' => 5,
                'confirmations' => $event['confirmations'],
                'block_height' => $event['block_height'],
                'block_hash' => $event['block_hash'],
                'updated_at' => UtcClock::now(),
            ]);
        }

        $deposit = Db::table('cex_wallet_deposits')
            ->where('asset_network_id', (int) $route['asset_network_id'])
            ->where('tx_hash', $event['tx_hash'])
            ->where('event_index', $event['event_index'])
            ->field('id,deposit_no,account_id,address_id,asset_network_id,tx_hash,event_index,amount,confirmations,status,credit_ledger_transaction_id,reversal_ledger_transaction_id,required_confirmations_snapshot,min_deposit_snapshot,last_event_id,last_event_type,last_event_at')
            ->lock(true)
            ->find();
        if (!$deposit) {
            return [
                'status' => self::DEPOSIT_MANUAL_REVIEW,
                'result_code' => 'REORG_WITHOUT_LOCAL_DEPOSIT',
                'credited' => false,
            ];
        }
        if ((int) $deposit['account_id'] !== (int) $address['account_id']) {
            throw new AssetException('Reorg 事件账户归属冲突', 409, 'DEPOSIT_REORG_OWNERSHIP_CONFLICT');
        }
        if (Decimal18::compare((string) $deposit['amount'], (string) $event['amount']) !== 0) {
            Db::table('cex_wallet_deposits')->where('id', (int) $deposit['id'])->update([
                'status' => self::DEPOSIT_MANUAL_REVIEW,
                'last_event_id' => $event['event_id'],
                'last_event_type' => $event['event_type'],
                'last_event_at' => UtcClock::now(),
                'updated_at' => UtcClock::now(),
            ]);
            return [
                'deposit_no' => (string) $deposit['deposit_no'],
                'status' => self::DEPOSIT_MANUAL_REVIEW,
                'result_code' => 'REORG_AMOUNT_CONFLICT',
                'credited' => !empty($deposit['credit_ledger_transaction_id']),
            ];
        }
        if ((int) $deposit['status'] === self::DEPOSIT_REVERSED) {
            return [
                'deposit_no' => (string) $deposit['deposit_no'],
                'status' => self::DEPOSIT_REVERSED,
                'result_code' => 'ALREADY_REVERSED',
                'credited' => false,
            ];
        }

        if ((int) $deposit['status'] === self::DEPOSIT_CREDITED && !empty($deposit['credit_ledger_transaction_id'])) {
            $userLedger = $this->ledger->ensureLedgerAccount(
                (int) $deposit['account_id'],
                (int) $route['asset_id'],
                LedgerService::SCOPE_SPOT,
                LedgerService::BUCKET_AVAILABLE,
                false
            );
            $lockedUserBalance = $this->ledger->lockBalanceForDimensions(
                (int) $deposit['account_id'],
                (int) $route['asset_id'],
                LedgerService::SCOPE_SPOT,
                LedgerService::BUCKET_AVAILABLE
            );
            $available = (string) $lockedUserBalance['balance'];
            if (Decimal18::compare($available, (string) $deposit['amount']) < 0) {
                Db::table('cex_wallet_deposits')->where('id', (int) $deposit['id'])->update([
                    'status' => self::DEPOSIT_MANUAL_REVIEW,
                    'last_event_id' => $event['event_id'],
                    'last_event_type' => $event['event_type'],
                    'last_event_at' => UtcClock::now(),
                    'updated_at' => UtcClock::now(),
                ]);
                return [
                    'deposit_no' => (string) $deposit['deposit_no'],
                    'status' => self::DEPOSIT_MANUAL_REVIEW,
                    'result_code' => 'REORG_INSUFFICIENT_BALANCE_MANUAL_REVIEW',
                    'credited' => true,
                ];
            }

            $systemAccount = $this->systemDepositClearingAccount();
            $systemLedger = $this->ledger->ensureLedgerAccount(
                (int) $systemAccount['id'],
                (int) $route['asset_id'],
                LedgerService::SCOPE_SPOT,
                LedgerService::BUCKET_AVAILABLE,
                true
            );
            $reversal = $this->ledger->postWithinTransaction([
                'business_type' => 'WALLET_DEPOSIT_REVERSAL',
                'business_id' => (string) $deposit['deposit_no'],
                'idempotency_key' => 'deposit-reversal:' . (string) $deposit['deposit_no'],
                'reversed_transaction_id' => (int) $deposit['credit_ledger_transaction_id'],
                'description' => 'Blockchain reorg deposit reversal',
                'metadata' => [
                    'route_code' => (string) $route['route_code'],
                    'tx_hash' => $event['tx_hash'],
                    'event_index' => $event['event_index'],
                ],
            ], [
                [
                    'ledger_account_id' => (int) $userLedger['id'],
                    'asset_id' => (int) $route['asset_id'],
                    'direction' => LedgerService::DIRECTION_DECREASE,
                    'amount' => (string) $deposit['amount'],
                ],
                [
                    'ledger_account_id' => (int) $systemLedger['id'],
                    'asset_id' => (int) $route['asset_id'],
                    'direction' => LedgerService::DIRECTION_INCREASE,
                    'amount' => (string) $deposit['amount'],
                ],
            ]);

            Db::table('cex_wallet_deposits')->where('id', (int) $deposit['id'])->update([
                'status' => self::DEPOSIT_REVERSED,
                'reversal_ledger_transaction_id' => (int) $reversal['id'],
                'reversed_at' => UtcClock::now(),
                'last_event_id' => $event['event_id'],
                'last_event_type' => $event['event_type'],
                'last_event_at' => UtcClock::now(),
                'updated_at' => UtcClock::now(),
            ]);
            return [
                'deposit_no' => (string) $deposit['deposit_no'],
                'status' => self::DEPOSIT_REVERSED,
                'result_code' => 'REVERSED',
                'credited' => false,
                'journal_no' => (string) $reversal['journal_no'],
            ];
        }

        Db::table('cex_wallet_deposits')->where('id', (int) $deposit['id'])->update([
            'status' => self::DEPOSIT_MANUAL_REVIEW,
            'last_event_id' => $event['event_id'],
            'last_event_type' => $event['event_type'],
            'last_event_at' => UtcClock::now(),
            'updated_at' => UtcClock::now(),
        ]);
        return [
            'deposit_no' => (string) $deposit['deposit_no'],
            'status' => self::DEPOSIT_MANUAL_REVIEW,
            'result_code' => 'REORG_BEFORE_CREDIT',
            'credited' => false,
        ];
    }

    private function creditDeposit(array $deposit, array $route): array
    {
        return $this->accounting->credit($deposit, $route);
    }

    private function resolveContext(array $event): array
    {
        $route = $this->routeByCode($event['route_code']);
        if ((string) $route['network_code'] !== $event['network_code']) {
            throw new AssetException('充值事件网络与 route_code 不匹配', 409, 'DEPOSIT_EVENT_ROUTE_NETWORK_MISMATCH');
        }
        if ((string) $route['asset_code'] !== $event['asset_code']) {
            throw new AssetException('充值事件资产与 route_code 不匹配', 409, 'DEPOSIT_EVENT_ROUTE_ASSET_MISMATCH');
        }
        $this->assertTokenContract($route, $event['token_contract']);
        $address = Db::table('cex_wallet_addresses')->alias('wa')
            ->join('cex_wallet_custody_bundles b', 'b.id = wa.custody_bundle_id')
            ->join('cex_asset_networks n', 'n.id = wa.network_id')
            ->where('wa.custody_address_ref', $event['address_ref'])
            ->where('wa.status', 1)
            ->where('wa.address_type', WalletBundleService::ADDRESS_TYPE_USER_DEPOSIT)
            ->field('wa.id,wa.account_id,wa.network_id,wa.address,wa.address_hash,b.external_bundle_id,b.status AS bundle_status,n.code AS network_code')
            ->lock(true)
            ->find();
        if (!$address || (int) $address['bundle_status'] !== WalletBundleService::BUNDLE_ACTIVE) {
            throw new AssetException('充值事件引用的钱包地址不存在或已停用', 404, 'DEPOSIT_EVENT_ADDRESS_NOT_FOUND');
        }
        if ((string) $address['external_bundle_id'] !== $event['bundle_id']) {
            throw new AssetException('充值事件 bundle_id 与本地分配关系不匹配', 409, 'DEPOSIT_EVENT_BUNDLE_MISMATCH');
        }
        if ((string) $address['network_code'] !== $event['network_code'] || (int) $address['network_id'] !== (int) $route['network_id']) {
            throw new AssetException('充值事件地址网络不匹配', 409, 'DEPOSIT_EVENT_ADDRESS_NETWORK_MISMATCH');
        }
        if ($event['to_address'] !== null) {
            $eventHash = WalletAddressNormalizer::hash($event['network_code'], $event['to_address']);
            if (!hash_equals((string) $address['address_hash'], $eventHash)) {
                throw new AssetException('充值事件目标地址与已分配地址不匹配', 409, 'DEPOSIT_EVENT_TO_ADDRESS_MISMATCH');
            }
        }
        return ['route' => $route, 'address' => $address];
    }

    private function routeByCode(string $routeCode): array
    {
        $row = Db::table('cex_asset_asset_networks')->alias('an')
            ->join('cex_asset_assets a', 'a.id = an.asset_id')
            ->join('cex_asset_networks n', 'n.id = an.network_id')
            ->where('an.route_code', $routeCode)
            ->field('an.id AS asset_network_id,an.asset_id,an.network_id,an.route_code,an.token_standard,an.contract_address,an.asset_decimals_on_chain,an.required_confirmations AS local_required_confirmations,an.min_deposit_amount AS local_min_deposit,a.code AS asset_code,n.code AS network_code')
            ->find();
        if (!$row) {
            throw new AssetException('充值事件 route_code 不存在', 404, 'DEPOSIT_EVENT_ROUTE_NOT_FOUND');
        }

        // OKX is reference-data only. Deposit credit policy is always the
        // CrystalBest local route configuration because the wallets/assets are
        // fully isolated from OKX.
        $row['effective_min_deposit'] = Decimal18::normalize((string) $row['local_min_deposit']);
        $row['effective_required_confirmations'] = (int) $row['local_required_confirmations'];
        return $row;
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

    private function validatePayload(array $payload, array $sourceMeta): array
    {
        $required = ['event_id', 'event_type', 'network_code', 'route_code', 'asset_code', 'bundle_id', 'address_ref', 'tx_hash', 'event_index', 'amount', 'confirmations', 'occurred_at'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new AssetException('Wallet event 缺少字段: ' . $field, 422, 'WALLET_EVENT_FIELD_MISSING');
            }
        }
        $eventId = $this->ascii((string) $payload['event_id'], 64, 'event_id');
        $expectedIdempotency = 'wallet-event:' . $eventId;
        if ((string) ($sourceMeta['idempotency_key'] ?? '') !== $expectedIdempotency) {
            throw new AssetException('Wallet event 幂等头必须与 event_id 绑定', 401, 'WALLET_EVENT_IDEMPOTENCY_MISMATCH');
        }
        $eventType = strtoupper($this->ascii((string) $payload['event_type'], 32, 'event_type'));
        if (!in_array($eventType, ['DEPOSIT_OBSERVED', 'DEPOSIT_UPDATED', 'DEPOSIT_REORGED'], true)) {
            throw new AssetException('Wallet event_type 不受支持', 422, 'WALLET_EVENT_TYPE_UNSUPPORTED');
        }
        $networkCode = strtoupper($this->ascii((string) $payload['network_code'], 32, 'network_code'));
        if (!in_array($networkCode, array_values((array) config('wallet.bundle_networks', [])), true)) {
            throw new AssetException('Wallet event 网络不受支持', 422, 'WALLET_EVENT_NETWORK_UNSUPPORTED');
        }
        $routeCode = strtoupper($this->ascii((string) $payload['route_code'], 48, 'route_code'));
        $assetCode = strtoupper($this->ascii((string) $payload['asset_code'], 16, 'asset_code'));
        $bundleId = $this->ascii((string) $payload['bundle_id'], 128, 'bundle_id');
        $addressRef = $this->ascii((string) $payload['address_ref'], 128, 'address_ref');
        $txHash = trim((string) $payload['tx_hash']);
        if ($txHash === '' || strlen($txHash) > 255 || preg_match('/\s/', $txHash)) {
            throw new AssetException('Wallet event tx_hash 无效', 422, 'WALLET_EVENT_TX_HASH_INVALID');
        }
        $eventIndex = filter_var($payload['event_index'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $confirmations = filter_var($payload['confirmations'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($eventIndex === false || $confirmations === false) {
            throw new AssetException('Wallet event index/confirmations 无效', 422, 'WALLET_EVENT_NUMBER_INVALID');
        }
        $amount = Decimal18::positive((string) $payload['amount']);

        $occurredAt = trim((string) $payload['occurred_at']);
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $occurredAt, new \DateTimeZone('UTC'));
        if (!$dt) {
            throw new AssetException('Wallet event occurred_at 必须是 UTC datetime(6)', 422, 'WALLET_EVENT_TIME_INVALID');
        }

        $blockHeight = $payload['block_height'] ?? null;
        if ($blockHeight !== null) {
            $blockHeight = filter_var($blockHeight, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($blockHeight === false) throw new AssetException('Wallet event block_height 无效', 422, 'WALLET_EVENT_BLOCK_HEIGHT_INVALID');
        }

        return [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'network_code' => $networkCode,
            'route_code' => $routeCode,
            'asset_code' => $assetCode,
            'bundle_id' => $bundleId,
            'address_ref' => $addressRef,
            'tx_hash' => $txHash,
            'event_index' => (int) $eventIndex,
            'amount' => $amount,
            'confirmations' => (int) $confirmations,
            'block_height' => $blockHeight === null ? null : (int) $blockHeight,
            'block_hash' => $this->optionalText($payload['block_hash'] ?? null, 255),
            'from_address' => $this->optionalText($payload['from_address'] ?? null, 255),
            'to_address' => $this->optionalText($payload['to_address'] ?? null, 255),
            'token_contract' => $this->optionalText($payload['token_contract'] ?? null, 255),
            'occurred_at' => $occurredAt,
        ];
    }


    private function assertTokenContract(array $route, ?string $reportedContract): void
    {
        $standard = strtoupper((string) ($route['token_standard'] ?? 'NATIVE'));
        $configured = trim((string) ($route['contract_address'] ?? ''));
        if ($standard === 'NATIVE') {
            if ($reportedContract !== null && trim($reportedContract) !== '') {
                throw new AssetException('Native 充值事件不应包含 token_contract', 409, 'DEPOSIT_NATIVE_CONTRACT_CONFLICT');
            }
            return;
        }
        if ($configured === '' || $reportedContract === null || trim($reportedContract) === '') {
            throw new AssetException('Token 充值事件缺少可验证的合约地址', 409, 'DEPOSIT_TOKEN_CONTRACT_MISSING');
        }
        $reported = trim($reportedContract);
        if ((string) $route['network_code'] === 'ETHEREUM') {
            $configured = strtolower($configured);
            $reported = strtolower($reported);
        }
        if (!hash_equals($configured, $reported)) {
            throw new AssetException('Token 合约地址与 CrystalBest route 配置不匹配', 409, 'DEPOSIT_TOKEN_CONTRACT_MISMATCH');
        }
    }

    private function assertOnChainPrecision(string $amount, int $decimals): void
    {
        $decimals = max(0, min(18, $decimals));
        $normalized = Decimal18::normalize($amount);
        $fraction = explode('.', ltrim($normalized, '-'), 2)[1] ?? '';
        if ($decimals < 18 && trim(substr($fraction, $decimals), '0') !== '') {
            throw new AssetException('充值金额精度超过该链资产 decimals', 422, 'DEPOSIT_AMOUNT_CHAIN_PRECISION');
        }
    }

    private function ascii(string $value, int $maxLength, string $field): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength || !preg_match('/^[A-Za-z0-9:_\-.]+$/', $value)) {
            throw new AssetException('Wallet event ' . $field . ' 无效', 422, 'WALLET_EVENT_ASCII_INVALID');
        }
        return $value;
    }

    private function optionalText($value, int $maxLength): ?string
    {
        if ($value === null || trim((string) $value) === '') return null;
        $value = trim((string) $value);
        if (strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new AssetException('Wallet event 文本字段无效', 422, 'WALLET_EVENT_TEXT_INVALID');
        }
        return $value;
    }
}
