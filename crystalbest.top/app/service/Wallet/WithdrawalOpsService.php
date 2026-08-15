<?php

namespace app\service\Wallet;

use app\controller\Auth\Ulid;
use app\controller\Auth\UtcClock;
use app\service\Asset\AssetException;
use app\service\Asset\Decimal18;
use app\service\Asset\LedgerService;
use think\facade\Db;

final class WithdrawalOpsService
{
    private $ledger;

    public function __construct()
    {
        $this->ledger = new LedgerService();
    }

    public function pending(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return Db::table('cex_wallet_withdrawals')->alias('w')
            ->join('cex_account_accounts aa', 'aa.id = w.account_id')
            ->join('cex_user_users u', 'u.id = aa.user_id')
            ->join('cex_asset_asset_networks an', 'an.id = w.asset_network_id')
            ->join('cex_asset_assets a', 'a.id = an.asset_id')
            ->join('cex_asset_networks n', 'n.id = an.network_id')
            ->whereIn('w.status', [WithdrawalService::STATUS_PENDING_REVIEW, WithdrawalService::STATUS_APPROVED, WithdrawalService::STATUS_PAYOUT_PROCESSING, WithdrawalService::STATUS_BROADCASTED])
            ->field('w.withdrawal_no,w.status,w.destination_address,w.destination_memo,w.receive_amount,w.platform_fee,w.gross_debit_amount,w.requested_at,w.approved_at,w.broadcast_at,w.chain_transaction_id,w.risk_decision_code,u.uid,u.email_masked,aa.public_id AS account_ref,an.route_code,a.code AS asset_code,n.code AS network_code,n.name AS network_name')
            ->order('w.id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    public function approve(string $withdrawalNo, string $operatorRef, ?string $note, string $remoteIp): array
    {
        return Db::transaction(function () use ($withdrawalNo, $operatorRef, $note, $remoteIp) {
            $w = $this->lockWithdrawal($withdrawalNo);
            if ((int) $w['status'] === WithdrawalService::STATUS_APPROVED) return $this->summary($w, true);
            if ((int) $w['status'] !== WithdrawalService::STATUS_PENDING_REVIEW) {
                throw new AssetException('当前状态不允许审核通过', 409, 'WITHDRAW_APPROVE_NOT_ALLOWED');
            }
            $now = UtcClock::now();
            Db::table('cex_wallet_withdrawals')->where('id', (int) $w['id'])->update([
                'status' => WithdrawalService::STATUS_APPROVED,
                'risk_decision_code' => 'MANUAL_APPROVED',
                'approved_at' => $now,
                'updated_at' => $now,
            ]);
            $this->action((int) $w['id'], 'APPROVED', $operatorRef, $remoteIp, $note, []);
            return $this->summary(Db::table('cex_wallet_withdrawals')->where('id', (int) $w['id'])->find(), false);
        });
    }

    public function reject(string $withdrawalNo, string $operatorRef, string $reasonCode, ?string $note, string $remoteIp): array
    {
        return Db::transaction(function () use ($withdrawalNo, $operatorRef, $reasonCode, $note, $remoteIp) {
            $w = $this->lockWithdrawal($withdrawalNo);
            if ((int) $w['status'] === WithdrawalService::STATUS_REJECTED) return $this->summary($w, true);
            if (!in_array((int) $w['status'], [WithdrawalService::STATUS_PENDING_REVIEW, WithdrawalService::STATUS_APPROVED], true)) {
                throw new AssetException('当前状态不允许审核拒绝', 409, 'WITHDRAW_REJECT_NOT_ALLOWED');
            }
            $this->refundBeforeBroadcast($w, WithdrawalService::STATUS_REJECTED, $reasonCode, $operatorRef, $remoteIp, $note);
            return $this->summary(Db::table('cex_wallet_withdrawals')->where('id', (int) $w['id'])->find(), false);
        });
    }

    public function startPayout(string $withdrawalNo, string $operatorRef, ?string $note, string $remoteIp): array
    {
        return Db::transaction(function () use ($withdrawalNo, $operatorRef, $note, $remoteIp) {
            $w = $this->lockWithdrawal($withdrawalNo);
            if ((int) $w['status'] === WithdrawalService::STATUS_PAYOUT_PROCESSING) return $this->summary($w, true);
            if ((int) $w['status'] !== WithdrawalService::STATUS_APPROVED) {
                throw new AssetException('提币尚未审核通过，不能进入人工打款', 409, 'WITHDRAW_PAYOUT_START_NOT_ALLOWED');
            }
            if (empty($w['hold_id']) || empty($w['freeze_ledger_transaction_id'])) {
                throw new AssetException('提币冻结记录不完整', 500, 'WITHDRAW_HOLD_MISSING');
            }

            $hold = Db::table('cex_asset_holds')->where('id', (int) $w['hold_id'])->lock(true)->find();
            if (!$hold || (int) $hold['status'] !== 1) throw new AssetException('提币冻结状态不正确', 409, 'WITHDRAW_HOLD_NOT_ACTIVE');

            $clearingAccount = Db::table('cex_account_accounts')
                ->where('account_kind', 2)->where('system_code', 'WITHDRAW_CLEARING')->where('status', 1)->field('id')->find();
            if (!$clearingAccount) throw new AssetException('提币清算账户不可用', 500, 'WITHDRAW_CLEARING_MISSING');
            $clearingLedger = $this->ledger->ensureLedgerAccount((int) $clearingAccount['id'], (int) $hold['asset_id'], LedgerService::SCOPE_SPOT, LedgerService::BUCKET_AVAILABLE, true);

            $settle = $this->ledger->postWithinTransaction([
                'business_type' => 'WALLET_WITHDRAW_SETTLE',
                'business_id' => (string) $w['withdrawal_no'],
                'idempotency_key' => 'withdraw-settle:' . (string) $w['withdrawal_no'],
                'request_id' => Ulid::generate(),
                'description' => 'Manual withdrawal payout preparation',
                'metadata' => ['stage' => 'PAYOUT_PROCESSING'],
                'occurred_at' => UtcClock::now(),
            ], [
                [
                    'ledger_account_id' => (int) $hold['locked_ledger_account_id'],
                    'asset_id' => (int) $hold['asset_id'],
                    'direction' => LedgerService::DIRECTION_DECREASE,
                    'amount' => (string) $w['gross_debit_amount'],
                ],
                [
                    'ledger_account_id' => (int) $clearingLedger['id'],
                    'asset_id' => (int) $hold['asset_id'],
                    'direction' => LedgerService::DIRECTION_INCREASE,
                    'amount' => (string) $w['gross_debit_amount'],
                ],
            ]);

            $now = UtcClock::now();
            Db::table('cex_asset_holds')->where('id', (int) $hold['id'])->update([
                'remaining_amount' => Decimal18::zero(),
                'status' => 3,
                'released_at' => $now,
                'updated_at' => $now,
            ]);
            Db::table('cex_wallet_withdrawals')->where('id', (int) $w['id'])->update([
                'status' => WithdrawalService::STATUS_PAYOUT_PROCESSING,
                'settle_ledger_transaction_id' => (int) $settle['id'],
                'updated_at' => $now,
            ]);
            $this->action((int) $w['id'], 'PAYOUT_STARTED', $operatorRef, $remoteIp, $note, []);
            return $this->summary(Db::table('cex_wallet_withdrawals')->where('id', (int) $w['id'])->find(), false);
        });
    }

    public function failPayout(string $withdrawalNo, string $operatorRef, string $reasonCode, ?string $note, string $remoteIp): array
    {
        return Db::transaction(function () use ($withdrawalNo, $operatorRef, $reasonCode, $note, $remoteIp) {
            $w = $this->lockWithdrawal($withdrawalNo);
            if ((int) $w['status'] === WithdrawalService::STATUS_REFUNDED) return $this->summary($w, true);
            if ((int) $w['status'] !== WithdrawalService::STATUS_PAYOUT_PROCESSING || empty($w['settle_ledger_transaction_id']) || !empty($w['chain_transaction_id'])) {
                throw new AssetException('只有尚未登记链上交易的人工打款处理中申请可以失败退回', 409, 'WITHDRAW_PAYOUT_FAIL_NOT_ALLOWED');
            }
            $hold = Db::table('cex_asset_holds')->where('id', (int) $w['hold_id'])->lock(true)->find();
            if (!$hold) throw new AssetException('提币冻结记录不存在', 500, 'WITHDRAW_HOLD_NOT_FOUND');
            $clearingAccount = Db::table('cex_account_accounts')
                ->where('account_kind', 2)->where('system_code', 'WITHDRAW_CLEARING')->where('status', 1)->field('id')->find();
            if (!$clearingAccount) throw new AssetException('提币清算账户不可用', 500, 'WITHDRAW_CLEARING_MISSING');
            $clearingLedger = $this->ledger->ensureLedgerAccount((int) $clearingAccount['id'], (int) $hold['asset_id'], LedgerService::SCOPE_SPOT, LedgerService::BUCKET_AVAILABLE, true);

            $refund = $this->ledger->postWithinTransaction([
                'business_type' => 'WALLET_WITHDRAW_REFUND',
                'business_id' => (string) $w['withdrawal_no'],
                'idempotency_key' => 'withdraw-refund:' . (string) $w['withdrawal_no'],
                'reversed_transaction_id' => (int) $w['settle_ledger_transaction_id'],
                'request_id' => Ulid::generate(),
                'description' => 'Manual payout failed before chain broadcast',
                'metadata' => ['reason_code' => $reasonCode],
                'occurred_at' => UtcClock::now(),
            ], [
                ['ledger_account_id' => (int) $clearingLedger['id'], 'asset_id' => (int) $hold['asset_id'], 'direction' => LedgerService::DIRECTION_DECREASE, 'amount' => (string) $w['gross_debit_amount']],
                ['ledger_account_id' => (int) $hold['available_ledger_account_id'], 'asset_id' => (int) $hold['asset_id'], 'direction' => LedgerService::DIRECTION_INCREASE, 'amount' => (string) $w['gross_debit_amount']],
            ]);

            $now = UtcClock::now();
            Db::table('cex_wallet_withdrawals')->where('id', (int) $w['id'])->update([
                'status' => WithdrawalService::STATUS_REFUNDED,
                'refund_ledger_transaction_id' => (int) $refund['id'],
                'failure_code' => $reasonCode,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);
            $this->action((int) $w['id'], 'PAYOUT_FAILED_REFUNDED', $operatorRef, $remoteIp, $note, ['reason_code' => $reasonCode]);
            return $this->summary(Db::table('cex_wallet_withdrawals')->where('id', (int) $w['id'])->find(), false);
        });
    }

    public function broadcast(string $withdrawalNo, string $operatorRef, string $txHash, ?string $actualNetworkFee, ?string $note, string $remoteIp): array
    {
        return Db::transaction(function () use ($withdrawalNo, $operatorRef, $txHash, $actualNetworkFee, $note, $remoteIp) {
            $w = $this->lockWithdrawal($withdrawalNo);
            if ((int) $w['status'] === WithdrawalService::STATUS_BROADCASTED) {
                $existingTx = Db::table('cex_wallet_chain_transactions')->where('id', (int) $w['chain_transaction_id'])->find();
                if ($existingTx && hash_equals((string) $existingTx['tx_hash'], trim($txHash))) return $this->summary($w, true);
                throw new AssetException('该提币已经绑定另一笔链上交易', 409, 'WITHDRAW_TX_CONFLICT');
            }
            if ((int) $w['status'] !== WithdrawalService::STATUS_PAYOUT_PROCESSING || empty($w['settle_ledger_transaction_id'])) {
                throw new AssetException('请先进入人工打款处理，再登记真实链上交易', 409, 'WITHDRAW_BROADCAST_NOT_ALLOWED');
            }

            $txHash = $this->txHash($txHash);
            $fee = $actualNetworkFee === null || trim($actualNetworkFee) === '' ? null : Decimal18::normalize($actualNetworkFee);
            if ($fee !== null && Decimal18::compare($fee, '0') < 0) throw new AssetException('网络手续费不能为负数', 422, 'WITHDRAW_NETWORK_FEE_INVALID');

            $route = Db::table('cex_asset_asset_networks')->alias('an')
                ->join('cex_asset_networks n', 'n.id = an.network_id')
                ->where('an.id', (int) $w['asset_network_id'])
                ->field('an.asset_id,an.route_code,n.id AS network_id,n.code AS network_code')
                ->find();
            if (!$route) throw new AssetException('提币网络配置不存在', 500, 'WITHDRAW_ROUTE_MISSING');

            $chain = Db::table('cex_wallet_chain_transactions')
                ->where('network_id', (int) $route['network_id'])
                ->where('tx_hash', $txHash)
                ->lock(true)
                ->find();
            if ($chain) {
                $used = Db::table('cex_wallet_withdrawals')->where('chain_transaction_id', (int) $chain['id'])->where('id', '<>', (int) $w['id'])->find();
                if ($used) throw new AssetException('该链上交易哈希已经绑定其他提币', 409, 'WITHDRAW_CHAIN_TX_ALREADY_USED');
            } else {
                $chainId = (int) Db::table('cex_wallet_chain_transactions')->insertGetId([
                    'network_id' => (int) $route['network_id'],
                    'tx_hash' => $txHash,
                    'direction' => 2,
                    'status' => 2,
                    'from_address' => null,
                    'to_address' => (string) $w['destination_address'],
                    'confirmations' => 0,
                    'fee_currency_code' => null,
                    'fee_amount' => $fee,
                    'first_seen_at' => UtcClock::now(),
                    'raw_metadata_json' => json_encode(['source' => 'MANUAL_WITHDRAWAL', 'withdrawal_no' => (string) $w['withdrawal_no']], JSON_UNESCAPED_SLASHES),
                ]);
                $chain = Db::table('cex_wallet_chain_transactions')->where('id', $chainId)->find();
            }

            $now = UtcClock::now();
            Db::table('cex_wallet_withdrawals')->where('id', (int) $w['id'])->update([
                'status' => WithdrawalService::STATUS_BROADCASTED,
                'chain_transaction_id' => (int) $chain['id'],
                'actual_network_fee' => $fee,
                'broadcast_at' => $now,
                'updated_at' => $now,
            ]);
            $this->action((int) $w['id'], 'BROADCASTED', $operatorRef, $remoteIp, $note, ['tx_hash' => $txHash, 'actual_network_fee' => $fee]);
            return $this->summary(Db::table('cex_wallet_withdrawals')->where('id', (int) $w['id'])->find(), false);
        });
    }

    public function confirm(string $withdrawalNo, string $operatorRef, int $confirmations, ?string $note, string $remoteIp): array
    {
        return Db::transaction(function () use ($withdrawalNo, $operatorRef, $confirmations, $note, $remoteIp) {
            $w = $this->lockWithdrawal($withdrawalNo);
            if ((int) $w['status'] === WithdrawalService::STATUS_COMPLETED) return $this->summary($w, true);
            if ((int) $w['status'] !== WithdrawalService::STATUS_BROADCASTED || empty($w['chain_transaction_id']) || empty($w['settle_ledger_transaction_id'])) {
                throw new AssetException('提币尚未登记链上发送，不能确认完成', 409, 'WITHDRAW_CONFIRM_NOT_ALLOWED');
            }
            $confirmations = max(1, min(1000000, $confirmations));
            $now = UtcClock::now();
            Db::table('cex_wallet_chain_transactions')->where('id', (int) $w['chain_transaction_id'])->update([
                'status' => 3,
                'confirmations' => $confirmations,
                'confirmed_at' => $now,
                'updated_at' => $now,
            ]);
            Db::table('cex_wallet_withdrawals')->where('id', (int) $w['id'])->update([
                'status' => WithdrawalService::STATUS_COMPLETED,
                'confirmed_at' => $now,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);
            $this->action((int) $w['id'], 'CONFIRMED', $operatorRef, $remoteIp, $note, ['confirmations' => $confirmations]);
            return $this->summary(Db::table('cex_wallet_withdrawals')->where('id', (int) $w['id'])->find(), false);
        });
    }

    private function refundBeforeBroadcast(array $w, int $targetStatus, string $reasonCode, string $operatorRef, string $remoteIp, ?string $note): void
    {
        if (empty($w['hold_id']) || empty($w['freeze_ledger_transaction_id'])) throw new AssetException('提币冻结记录不完整', 500, 'WITHDRAW_HOLD_MISSING');
        if (!empty($w['settle_ledger_transaction_id']) || !empty($w['chain_transaction_id'])) throw new AssetException('链上打款已经开始，不能直接审核退回', 409, 'WITHDRAW_ALREADY_BROADCAST');
        $hold = Db::table('cex_asset_holds')->where('id', (int) $w['hold_id'])->lock(true)->find();
        if (!$hold) throw new AssetException('提币冻结记录不存在', 500, 'WITHDRAW_HOLD_NOT_FOUND');

        $refund = $this->ledger->postWithinTransaction([
            'business_type' => 'WALLET_WITHDRAW_REFUND',
            'business_id' => (string) $w['withdrawal_no'],
            'idempotency_key' => 'withdraw-refund:' . (string) $w['withdrawal_no'],
            'reversed_transaction_id' => (int) $w['freeze_ledger_transaction_id'],
            'request_id' => Ulid::generate(),
            'description' => 'Manual withdrawal review refund',
            'metadata' => ['reason_code' => $reasonCode],
            'occurred_at' => UtcClock::now(),
        ], [
            ['ledger_account_id' => (int) $hold['locked_ledger_account_id'], 'asset_id' => (int) $hold['asset_id'], 'direction' => LedgerService::DIRECTION_DECREASE, 'amount' => (string) $w['gross_debit_amount']],
            ['ledger_account_id' => (int) $hold['available_ledger_account_id'], 'asset_id' => (int) $hold['asset_id'], 'direction' => LedgerService::DIRECTION_INCREASE, 'amount' => (string) $w['gross_debit_amount']],
        ]);

        $now = UtcClock::now();
        Db::table('cex_asset_holds')->where('id', (int) $hold['id'])->update(['remaining_amount' => Decimal18::zero(), 'status' => 4, 'released_at' => $now, 'updated_at' => $now]);
        Db::table('cex_wallet_withdrawals')->where('id', (int) $w['id'])->update([
            'status' => $targetStatus,
            'refund_ledger_transaction_id' => (int) $refund['id'],
            'failure_code' => $reasonCode,
            'completed_at' => $now,
            'updated_at' => $now,
        ]);
        $this->action((int) $w['id'], 'REJECTED', $operatorRef, $remoteIp, $note, ['reason_code' => $reasonCode]);
    }

    private function lockWithdrawal(string $withdrawalNo): array
    {
        $withdrawalNo = strtoupper(trim($withdrawalNo));
        if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $withdrawalNo)) throw new AssetException('提币编号无效', 422, 'WITHDRAW_NO_INVALID');
        $w = Db::table('cex_wallet_withdrawals')->where('withdrawal_no', $withdrawalNo)->lock(true)->find();
        if (!$w) throw new AssetException('提币申请不存在', 404, 'WITHDRAW_NOT_FOUND');
        return $w;
    }

    private function summary(array $w, bool $duplicate): array
    {
        return [
            'withdrawal_no' => (string) $w['withdrawal_no'],
            'status' => (int) $w['status'],
            'receive_amount' => Decimal18::trim((string) $w['receive_amount'], 18),
            'platform_fee' => Decimal18::trim((string) $w['platform_fee'], 18),
            'gross_debit_amount' => Decimal18::trim((string) $w['gross_debit_amount'], 18),
            'chain_transaction_id' => $w['chain_transaction_id'] === null ? null : (int) $w['chain_transaction_id'],
            'duplicate' => $duplicate,
        ];
    }

    private function action(int $withdrawalId, string $type, string $operatorRef, string $remoteIp, ?string $note, array $metadata): void
    {
        Db::table('cex_wallet_withdrawal_actions')->insert([
            'action_no' => Ulid::generate(),
            'withdrawal_id' => $withdrawalId,
            'action_type' => $type,
            'actor_type' => 2,
            'actor_ref' => substr($operatorRef, 0, 64),
            'request_id' => Ulid::generate(),
            'remote_ip' => substr($remoteIp, 0, 45),
            'note' => $note === null ? null : substr($note, 0, 512),
            'metadata_json' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => UtcClock::now(),
        ]);
    }

    private function txHash(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 255 || !preg_match('/^[A-Za-z0-9:_\-.]+$/', $value)) throw new AssetException('链上交易哈希格式无效', 422, 'WITHDRAW_TX_HASH_INVALID');
        return $value;
    }
}
