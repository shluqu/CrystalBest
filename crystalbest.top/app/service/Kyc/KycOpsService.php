<?php

namespace app\service\Kyc;

use app\controller\Auth\AuthException;
use app\controller\Auth\Crypto;
use app\controller\Auth\Ulid;
use app\controller\Auth\UtcClock;
use think\facade\Db;

final class KycOpsService
{
    private $documents;

    public function __construct()
    {
        $this->documents = new KycDocumentService();
    }

    public function pending(int $limit): array
    {
        $rows = Db::table('cex_user_kyc')->alias('k')
            ->join('cex_user_users u', 'u.id=k.user_id')
            ->whereIn('k.status', [KycService::STATUS_SUBMITTED, KycService::STATUS_REVIEWING])
            ->field('k.id,k.public_id,k.user_id,k.kyc_level,k.status,k.real_name_ciphertext,k.nationality,k.residence_country,k.document_type,k.document_number_ciphertext,k.document_front_file_id,k.document_back_file_id,k.submitted_at,k.reviewed_at,u.uid,u.email_masked')
            ->order('k.submitted_at', 'asc')
            ->limit($limit)
            ->select()->toArray();
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->summary($row);
        }
        return ['count' => count($items), 'items' => $items];
    }

    public function detail(string $caseId): array
    {
        $row = $this->caseRow($caseId, false);
        $summary = $this->summary($row);
        $summary['real_name'] = $this->decryptNullable($row['real_name_ciphertext'] ?? null, 'kyc-real-name');
        $summary['birth_date'] = $this->decryptNullable($row['birth_date_ciphertext'] ?? null, 'kyc-birth-date');
        $summary['document_number'] = $this->decryptNullable($row['document_number_ciphertext'] ?? null, 'kyc-document-number');
        $summary['documents'] = [
            'front' => !empty($row['document_front_file_id']) ? '/api/internal/kyc/cases/' . rawurlencode((string) $row['public_id']) . '/document/front' : null,
            'back' => !empty($row['document_back_file_id']) ? '/api/internal/kyc/cases/' . rawurlencode((string) $row['public_id']) . '/document/back' : null,
        ];
        return $summary;
    }

    public function document(string $caseId, string $slot): array
    {
        $row = $this->caseRow($caseId, false);
        $slot = strtolower(trim($slot));
        if ($slot === 'front') {
            $key = trim((string) ($row['document_front_file_id'] ?? ''));
        } elseif ($slot === 'back') {
            $key = trim((string) ($row['document_back_file_id'] ?? ''));
        } else {
            throw new AuthException('证件图片位置无效', 422, 'KYC_OPS_DOCUMENT_SLOT_INVALID');
        }
        if ($key === '') {
            throw new AuthException('证件图片不存在', 404, 'KYC_OPS_DOCUMENT_NOT_FOUND');
        }
        return $this->documents->read($key);
    }

    public function startReview(string $caseId, string $operatorRef, ?string $note, string $remoteIp): array
    {
        return Db::transaction(function () use ($caseId, $operatorRef, $note, $remoteIp) {
            $row = $this->caseRow($caseId, true);
            if ((int) $row['status'] === KycService::STATUS_REVIEWING) {
                return $this->summary($row) + ['duplicate' => true];
            }
            if ((int) $row['status'] !== KycService::STATUS_SUBMITTED) {
                throw new AuthException('当前认证状态不能进入审核', 409, 'KYC_OPS_REVIEW_NOT_ALLOWED');
            }
            $now = UtcClock::now();
            Db::table('cex_user_kyc')->where('id', (int) $row['id'])->update([
                'status' => KycService::STATUS_REVIEWING,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ]);
            $this->action((int) $row['id'], 'REVIEW_STARTED', $operatorRef, $remoteIp, $note, []);
            $updated = $this->caseRow($caseId, false);
            return $this->summary($updated) + ['duplicate' => false];
        });
    }

    public function approve(string $caseId, string $operatorRef, ?string $note, string $remoteIp): array
    {
        return Db::transaction(function () use ($caseId, $operatorRef, $note, $remoteIp) {
            $row = $this->caseRow($caseId, true);
            if ((int) $row['status'] === KycService::STATUS_APPROVED) {
                return $this->summary($row) + ['duplicate' => true];
            }
            if (!in_array((int) $row['status'], [KycService::STATUS_SUBMITTED, KycService::STATUS_REVIEWING], true)) {
                throw new AuthException('当前认证状态不能审核通过', 409, 'KYC_OPS_APPROVE_NOT_ALLOWED');
            }
            $now = UtcClock::now();
            $days = max(30, min(3650, (int) config('kyc.review.approval_valid_days', 365)));
            $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+' . $days . ' days')->format('Y-m-d H:i:s.u');
            Db::table('cex_user_kyc')->where('id', (int) $row['id'])->update([
                'status' => KycService::STATUS_APPROVED,
                'reviewed_at' => $now,
                'approved_at' => $now,
                'expires_at' => $expiresAt,
                'rejection_code' => null,
                'rejection_reason' => null,
                'updated_at' => $now,
            ]);
            Db::table('cex_user_users')->where('id', (int) $row['user_id'])->update([
                'kyc_level' => Db::raw('GREATEST(kyc_level, 1)'),
                'version' => Db::raw('version + 1'),
            ]);
            $this->action((int) $row['id'], 'APPROVED', $operatorRef, $remoteIp, $note, ['expires_at' => $expiresAt]);
            $updated = $this->caseRow($caseId, false);
            return $this->summary($updated) + ['duplicate' => false];
        });
    }

    public function reject(string $caseId, string $operatorRef, string $reasonCode, string $reason, ?string $note, string $remoteIp): array
    {
        return Db::transaction(function () use ($caseId, $operatorRef, $reasonCode, $reason, $note, $remoteIp) {
            $row = $this->caseRow($caseId, true);
            if ((int) $row['status'] === KycService::STATUS_REJECTED) {
                return $this->summary($row) + ['duplicate' => true];
            }
            if (!in_array((int) $row['status'], [KycService::STATUS_SUBMITTED, KycService::STATUS_REVIEWING], true)) {
                throw new AuthException('当前认证状态不能拒绝', 409, 'KYC_OPS_REJECT_NOT_ALLOWED');
            }
            $now = UtcClock::now();
            Db::table('cex_user_kyc')->where('id', (int) $row['id'])->update([
                'status' => KycService::STATUS_REJECTED,
                'reviewed_at' => $now,
                'rejection_code' => $reasonCode,
                'rejection_reason' => $reason,
                'updated_at' => $now,
            ]);
            $this->action((int) $row['id'], 'REJECTED', $operatorRef, $remoteIp, $note, ['reason_code' => $reasonCode]);
            $updated = $this->caseRow($caseId, false);
            return $this->summary($updated) + ['duplicate' => false];
        });
    }

    private function caseRow(string $caseId, bool $lock): array
    {
        $caseId = strtoupper(trim($caseId));
        if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $caseId)) {
            throw new AuthException('KYC case 编号无效', 422, 'KYC_OPS_CASE_INVALID');
        }
        $query = Db::table('cex_user_kyc')->alias('k')
            ->join('cex_user_users u', 'u.id=k.user_id')
            ->where('k.public_id', $caseId)
            ->field('k.*,u.uid,u.email_masked');
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!$row) {
            throw new AuthException('KYC case 不存在', 404, 'KYC_OPS_CASE_NOT_FOUND');
        }
        return $row;
    }

    private function summary(array $row): array
    {
        $realName = $this->decryptNullable($row['real_name_ciphertext'] ?? null, 'kyc-real-name');
        $documentNumber = $this->decryptNullable($row['document_number_ciphertext'] ?? null, 'kyc-document-number');
        return [
            'public_id' => (string) $row['public_id'],
            'user_id' => (int) $row['user_id'],
            'uid' => isset($row['uid']) ? (string) $row['uid'] : null,
            'email_masked' => isset($row['email_masked']) ? (string) $row['email_masked'] : null,
            'status' => (int) $row['status'],
            'kyc_level' => (int) $row['kyc_level'],
            'real_name' => $realName,
            'document_type' => $row['document_type'] === null ? null : (int) $row['document_type'],
            'document_type_label' => (int) ($row['document_type'] ?? 1) === KycService::DOC_PASSPORT ? '护照' : '身份证',
            'document_number_masked' => $this->mask($documentNumber),
            'nationality' => $row['nationality'] ?? null,
            'residence_country' => $row['residence_country'] ?? null,
            'submitted_at' => $row['submitted_at'] ?? null,
            'reviewed_at' => $row['reviewed_at'] ?? null,
            'approved_at' => $row['approved_at'] ?? null,
            'expires_at' => $row['expires_at'] ?? null,
            'rejection_code' => $row['rejection_code'] ?? null,
            'rejection_reason' => $row['rejection_reason'] ?? null,
            'has_front_document' => !empty($row['document_front_file_id']),
            'has_back_document' => !empty($row['document_back_file_id']),
        ];
    }

    private function action(int $kycId, string $type, string $operatorRef, string $remoteIp, ?string $note, array $metadata): void
    {
        Db::table('cex_user_kyc_actions')->insert([
            'action_no' => Ulid::generate(),
            'kyc_id' => $kycId,
            'action_type' => substr($type, 0, 32),
            'actor_type' => 2,
            'actor_ref' => substr($operatorRef, 0, 64),
            'request_id' => Ulid::generate(),
            'remote_ip' => substr($remoteIp, 0, 45),
            'note' => $note === null ? null : substr($note, 0, 512),
            'metadata_json' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => UtcClock::now(),
        ]);
    }

    private function decryptNullable($value, string $purpose): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        return Crypto::decryptSensitive((string) $value, $purpose);
    }

    private function mask(string $value): string
    {
        $value = trim($value);
        $length = strlen($value);
        if ($length <= 4) {
            return $value === '' ? '' : str_repeat('*', max(1, $length - 1)) . substr($value, -1);
        }
        return substr($value, 0, 2) . str_repeat('*', min(10, $length - 4)) . substr($value, -2);
    }
}
