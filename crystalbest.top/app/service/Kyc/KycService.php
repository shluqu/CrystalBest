<?php

namespace app\service\Kyc;

use app\controller\Auth\AuditLog;
use app\controller\Auth\AuthException;
use app\controller\Auth\AuthService;
use app\controller\Auth\ClientContext;
use app\controller\Auth\Clock;
use app\controller\Auth\Crypto;
use app\controller\Auth\Ulid;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\Request;
use think\file\UploadedFile;

final class KycService
{
    public const STATUS_DRAFT = 0;
    public const STATUS_SUBMITTED = 1;
    public const STATUS_REVIEWING = 2;
    public const STATUS_APPROVED = 3;
    public const STATUS_REJECTED = 4;
    public const STATUS_EXPIRED = 5;

    public const DOC_ID_CARD = 1;
    public const DOC_PASSPORT = 2;

    private $request;
    private $authService;
    private $documents;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->authService = new AuthService($request);
        $this->documents = new KycDocumentService();
    }

    public function overview(): array
    {
        $auth = $this->context(true);
        $cases = Db::table('cex_user_kyc')
            ->where('user_id', (int) $auth['user_id'])
            ->field('id,public_id,kyc_level,status,real_name_ciphertext,nationality,residence_country,document_type,document_number_ciphertext,document_front_file_id,document_back_file_id,rejection_code,rejection_reason,submitted_at,reviewed_at,approved_at,expires_at,created_at,updated_at')
            ->order('id', 'desc')
            ->limit(10)
            ->select()
            ->toArray();

        $current = $cases ? $this->caseForUser($cases[0], true) : null;
        $blocking = false;
        foreach ($cases as $case) {
            $status = (int) $case['status'];
            if (in_array($status, [self::STATUS_SUBMITTED, self::STATUS_REVIEWING], true)) {
                $blocking = true;
                break;
            }
            if ($status === self::STATUS_APPROVED && !$this->isExpired($case['expires_at'] ?? null)) {
                $blocking = true;
                break;
            }
        }

        $history = [];
        foreach ($cases as $case) {
            $history[] = $this->caseForUser($case, false);
        }

        return [
            'kyc_level' => (int) $auth['kyc_level'],
            'email_verified' => !empty($auth['email_verified_at']),
            'storage_configured' => $this->documents->isConfigured(),
            'can_submit' => !$blocking,
            'current_case' => $current,
            'history' => $history,
            'document_types' => [
                ['value' => 'id_card', 'code' => self::DOC_ID_CARD, 'label' => '身份证'],
                ['value' => 'passport', 'code' => self::DOC_PASSPORT, 'label' => '护照'],
            ],
        ];
    }

    public function submit(array $payload, ?UploadedFile $front, ?UploadedFile $back): array
    {
        $auth = $this->context(true);
        if ((int) $auth['user_status'] !== 1) {
            throw new AuthException('账户当前不可提交身份认证', 409, 'KYC_USER_UNAVAILABLE');
        }
        if (empty($auth['email_verified_at'])) {
            throw new AuthException('请先完成安全邮箱验证', 409, 'KYC_EMAIL_REQUIRED');
        }
        if (!$this->documents->isConfigured()) {
            throw new AuthException('身份认证服务暂时不可用，请稍后再试', 503, 'KYC_STORAGE_NOT_CONFIGURED');
        }

        $this->rateLimit('kyc:submit:user:' . (int) $auth['user_id'], 5, 86400);
        $this->rateLimit('kyc:submit:ip:' . ClientContext::ip($this->request), 15, 86400);

        $realName = $this->realName((string) ($payload['real_name'] ?? ''));
        $birthDate = $this->birthDate((string) ($payload['birth_date'] ?? ''));
        $nationality = $this->country((string) ($payload['nationality'] ?? ''), '国籍');
        $residence = $this->country((string) ($payload['residence_country'] ?? ''), '居住国家/地区');
        $documentType = $this->documentType($payload['document_type'] ?? '');
        $documentNumber = $this->documentNumber((string) ($payload['document_number'] ?? ''));
        $confirmed = (string) ($payload['confirm'] ?? '') === '1';
        if (!$confirmed) {
            throw new AuthException('请确认提交的身份资料真实有效', 422, 'KYC_CONFIRM_REQUIRED');
        }
        if (!$front) {
            throw new AuthException($documentType === self::DOC_PASSPORT ? '请上传护照资料页' : '请上传身份证正面', 422, 'KYC_DOCUMENT_FRONT_REQUIRED');
        }
        if ($documentType === self::DOC_ID_CARD && !$back) {
            throw new AuthException('请上传身份证反面', 422, 'KYC_DOCUMENT_BACK_REQUIRED');
        }

        $this->assertNoActiveCase((int) $auth['user_id']);
        $publicId = Ulid::generate();
        $uploaded = [];
        try {
            $frontResult = $this->documents->upload($front, (string) $auth['uid'], $publicId, 'front');
            $uploaded[] = (string) $frontResult['storage_key'];
            $backResult = null;
            if ($documentType === self::DOC_ID_CARD && $back) {
                $backResult = $this->documents->upload($back, (string) $auth['uid'], $publicId, 'back');
                $uploaded[] = (string) $backResult['storage_key'];
            }

            $hashInput = strtoupper($nationality) . '|' . $documentType . '|' . preg_replace('/\s+/', '', strtoupper($documentNumber));
            $documentHash = Crypto::sensitiveHash($hashInput, 'kyc-document-number');
            $now = Clock::now();

            $caseId = Db::transaction(function () use (
                $auth, $publicId, $realName, $birthDate, $nationality, $residence,
                $documentType, $documentNumber, $documentHash, $frontResult, $backResult, $now
            ) {
                $user = Db::table('cex_user_users')->where('id', (int) $auth['user_id'])->lock(true)->find();
                if (!$user || (int) $user['status'] !== 1) {
                    throw new AuthException('账户当前不可提交身份认证', 409, 'KYC_USER_UNAVAILABLE');
                }
                $this->assertNoActiveCase((int) $auth['user_id'], true);

                $duplicate = Db::table('cex_user_kyc')
                    ->where('document_number_hash', $documentHash)
                    ->where('user_id', '<>', (int) $auth['user_id'])
                    ->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_REVIEWING, self::STATUS_APPROVED])
                    ->field('id')
                    ->find();
                if ($duplicate) {
                    throw new AuthException('该证件已用于其他账户的身份认证', 409, 'KYC_DOCUMENT_ALREADY_USED');
                }

                $id = Db::table('cex_user_kyc')->insertGetId([
                    'public_id' => $publicId,
                    'user_id' => (int) $auth['user_id'],
                    'kyc_level' => 1,
                    'status' => self::STATUS_SUBMITTED,
                    'real_name_ciphertext' => Crypto::encryptSensitive($realName, 'kyc-real-name'),
                    'birth_date_ciphertext' => Crypto::encryptSensitive($birthDate, 'kyc-birth-date'),
                    'nationality' => $nationality,
                    'residence_country' => $residence,
                    'document_type' => $documentType,
                    'document_number_ciphertext' => Crypto::encryptSensitive($documentNumber, 'kyc-document-number'),
                    'document_number_hash' => $documentHash,
                    'document_front_file_id' => (string) $frontResult['storage_key'],
                    'document_back_file_id' => $backResult ? (string) $backResult['storage_key'] : null,
                    'selfie_file_id' => null,
                    'provider' => 'manual',
                    'provider_case_id' => $publicId,
                    'submitted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->action((int) $id, 'SUBMITTED', 'user:' . (string) $auth['uid'], 1, null, [
                    'document_type' => $this->documentTypeLabel($documentType),
                ]);
                return (int) $id;
            });

            AuditLog::record($this->request, 'KYC_SUBMITTED', (int) $auth['user_id'], 1, 'kyc', $publicId, [
                'document_type' => $this->documentTypeLabel($documentType),
            ]);
            Log::info('KYC submitted uid=' . $auth['uid'] . ' case=' . $publicId . ' ip=' . ClientContext::ip($this->request));

            $case = Db::table('cex_user_kyc')->where('id', $caseId)->find();
            return $this->caseForUser($case, true);
        } catch (\Throwable $exception) {
            foreach ($uploaded as $key) {
                try {
                    $this->documents->delete($key);
                } catch (\Throwable $cleanupException) {
                    Log::warning('KYC orphan cleanup failed key=' . $key . ' message=' . $cleanupException->getMessage());
                }
            }
            throw $exception;
        }
    }

    private function context(bool $touch): array
    {
        $cookie = (string) $this->request->cookie($this->authService->cookieName(), '');
        return $this->authService->authenticatedSession($cookie, $touch);
    }

    private function assertNoActiveCase(int $userId, bool $insideTransaction = false): void
    {
        $query = Db::table('cex_user_kyc')
            ->where('user_id', $userId)
            ->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_REVIEWING, self::STATUS_APPROVED])
            ->field('id,status,expires_at')
            ->order('id', 'desc');
        if ($insideTransaction) {
            $query->lock(true);
        }
        $rows = $query->select()->toArray();
        foreach ($rows as $row) {
            $status = (int) $row['status'];
            if (in_array($status, [self::STATUS_SUBMITTED, self::STATUS_REVIEWING], true)) {
                throw new AuthException('你已有身份认证申请正在处理中', 409, 'KYC_CASE_IN_PROGRESS');
            }
            if ($status === self::STATUS_APPROVED && !$this->isExpired($row['expires_at'] ?? null)) {
                throw new AuthException('当前账户已经完成身份认证', 409, 'KYC_ALREADY_APPROVED');
            }
        }
    }

    private function caseForUser(array $row, bool $includeDetails): array
    {
        $expired = (int) $row['status'] === self::STATUS_APPROVED && $this->isExpired($row['expires_at'] ?? null);
        $status = $expired ? self::STATUS_EXPIRED : (int) $row['status'];
        $result = [
            'public_id' => (string) $row['public_id'],
            'kyc_level' => (int) $row['kyc_level'],
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'document_type' => $row['document_type'] === null ? null : (int) $row['document_type'],
            'document_type_label' => $row['document_type'] === null ? '历史认证' : $this->documentTypeLabel((int) $row['document_type']),
            'submitted_at' => $row['submitted_at'] ?? null,
            'reviewed_at' => $row['reviewed_at'] ?? null,
            'approved_at' => $row['approved_at'] ?? null,
            'expires_at' => $row['expires_at'] ?? null,
            'rejection_code' => $row['rejection_code'] ?? null,
            'rejection_reason' => $row['rejection_reason'] ?? null,
            'has_front_document' => !empty($row['document_front_file_id']),
            'has_back_document' => !empty($row['document_back_file_id']),
        ];
        if ($includeDetails) {
            $realName = !empty($row['real_name_ciphertext'])
                ? Crypto::decryptSensitive((string) $row['real_name_ciphertext'], 'kyc-real-name')
                : '';
            $documentNumber = !empty($row['document_number_ciphertext'])
                ? Crypto::decryptSensitive((string) $row['document_number_ciphertext'], 'kyc-document-number')
                : '';
            $result['real_name'] = $realName;
            $result['document_number_masked'] = $this->maskDocumentNumber($documentNumber);
            $result['nationality'] = $row['nationality'] ?? null;
            $result['residence_country'] = $row['residence_country'] ?? null;
        }
        return $result;
    }

    private function realName(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length < 2 || $length > 80 || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw new AuthException('请输入与证件一致的真实姓名', 422, 'KYC_REAL_NAME_INVALID');
        }
        return $value;
    }

    private function birthDate(string $value): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new AuthException('出生日期格式无效', 422, 'KYC_BIRTH_DATE_INVALID');
        }
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $oldest = $today->modify('-120 years');
        if ($date >= $today || $date < $oldest) {
            throw new AuthException('出生日期无效', 422, 'KYC_BIRTH_DATE_INVALID');
        }
        return $value;
    }

    private function country(string $value, string $label): string
    {
        $value = strtoupper(trim($value));
        if (!preg_match('/^[A-Z]{2}$/', $value)) {
            throw new AuthException('请选择有效的' . $label, 422, 'KYC_COUNTRY_INVALID');
        }
        return $value;
    }

    private function documentType($value): int
    {
        $value = strtolower(trim((string) $value));
        if ($value === 'id_card' || $value === '1') {
            return self::DOC_ID_CARD;
        }
        if ($value === 'passport' || $value === '2') {
            return self::DOC_PASSPORT;
        }
        throw new AuthException('请选择证件类型', 422, 'KYC_DOCUMENT_TYPE_INVALID');
    }

    private function documentNumber(string $value): string
    {
        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length < 4 || $length > 64 || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw new AuthException('请输入有效的证件号码', 422, 'KYC_DOCUMENT_NUMBER_INVALID');
        }
        return $value;
    }

    private function statusLabel(int $status): string
    {
        $labels = [
            self::STATUS_DRAFT => '未提交',
            self::STATUS_SUBMITTED => '审核中',
            self::STATUS_REVIEWING => '审核中',
            self::STATUS_APPROVED => '已认证',
            self::STATUS_REJECTED => '未通过',
            self::STATUS_EXPIRED => '已过期',
        ];
        return $labels[$status] ?? '未知状态';
    }

    private function documentTypeLabel(int $type): string
    {
        return $type === self::DOC_PASSPORT ? '护照' : '身份证';
    }

    private function maskDocumentNumber(string $value): string
    {
        $value = trim($value);
        $length = strlen($value);
        if ($length <= 4) {
            return $value === '' ? '' : str_repeat('*', max(1, $length - 1)) . substr($value, -1);
        }
        return substr($value, 0, 2) . str_repeat('*', min(10, $length - 4)) . substr($value, -2);
    }

    private function isExpired($expiresAt): bool
    {
        $value = trim((string) $expiresAt);
        if ($value === '') {
            return false;
        }
        $timestamp = strtotime($value . ' UTC');
        return $timestamp !== false && $timestamp <= time();
    }

    private function action(int $kycId, string $type, string $actorRef, int $actorType, ?string $note, array $metadata): void
    {
        Db::table('cex_user_kyc_actions')->insert([
            'action_no' => Ulid::generate(),
            'kyc_id' => $kycId,
            'action_type' => substr($type, 0, 32),
            'actor_type' => $actorType,
            'actor_ref' => substr($actorRef, 0, 64),
            'request_id' => Ulid::generate(),
            'remote_ip' => substr(ClientContext::ip($this->request), 0, 45),
            'note' => $note,
            'metadata_json' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => Clock::now(),
        ]);
    }

    private function rateLimit(string $key, int $limit, int $seconds): void
    {
        $cacheKey = 'auth:' . $key;
        $state = Cache::get($cacheKey);
        $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
        $startedAt = is_array($state) ? (int) ($state['started_at'] ?? time()) : time();
        if ((time() - $startedAt) >= $seconds) {
            $count = 0;
            $startedAt = time();
        }
        $count++;
        if ($count > $limit) {
            throw new AuthException('身份认证提交过于频繁，请稍后再试', 429, 'KYC_RATE_LIMITED');
        }
        Cache::set($cacheKey, ['count' => $count, 'started_at' => $startedAt], $seconds);
    }
}
