<?php

namespace app\service\C2c;

use app\controller\Auth\AuditLog;
use app\controller\Auth\AuthException;
use app\controller\Auth\AuthService;
use app\controller\Auth\BusinessAccountService;
use app\controller\Auth\Crypto;
use app\controller\Auth\Ulid;
use app\controller\Auth\UtcClock;
use app\service\Asset\AssetException;
use app\service\Asset\Decimal18;
use app\service\Asset\LedgerService;
use think\facade\Db;
use think\Request;
use think\file\UploadedFile;

final class C2cService
{
    public const MERCHANT_PENDING_DEPOSIT = 1;
    public const MERCHANT_ACTIVE = 2;
    public const MERCHANT_SUSPENDED = 3;
    public const MERCHANT_EXITING = 4;
    public const MERCHANT_CLOSED = 5;

    public const PAYMENT_ALIPAY = 1;
    public const PAYMENT_WECHAT = 2;
    public const PAYMENT_BANK = 3;

    public const AD_BUY = 1;   // 商家买币：商家付法币、用户卖币
    public const AD_SELL = 2;  // 商家卖币：用户付法币、商家卖币

    public const AD_ONLINE = 1;
    public const AD_OFFLINE = 2;
    public const AD_EXHAUSTED = 3;
    public const AD_CANCELLED = 4;

    public const ORDER_WAITING_PAYMENT = 1;
    public const ORDER_PAID = 2;
    public const ORDER_APPEAL = 3;
    public const ORDER_COMPLETED = 4;
    public const ORDER_CANCELLED = 5;
    public const ORDER_EXPIRED = 6;

    private $request;
    private $authContext;
    private $businessAccount;
    private $ledger;

    public function __construct(?Request $request = null)
    {
        $this->request = $request;
        $this->ledger = new LedgerService();
    }

    public function context(): array
    {
        $auth = $this->optionalAuthContext();
        if (!$auth) {
            return [
                'authenticated' => false,
                'supported_assets' => $this->supportedAssets(),
                'payment_types' => $this->paymentTypeOptions(),
                'merchant_deposit' => $this->merchantDepositConfig(),
            ];
        }

        $account = $this->businessAccount();
        $merchant = $this->merchantForUser((int) $auth['user_id']);
        $paymentMethods = $this->paymentMethodsForUser((int) $auth['user_id']);
        $depositCfg = $this->merchantDepositConfig();
        $depositAsset = $this->assetByCode((string) $depositCfg['asset']);
        $depositAvailable = $this->ledger->balanceForDimensions(
            (int) $account['id'],
            (int) $depositAsset['id'],
            LedgerService::SCOPE_SPOT,
            LedgerService::BUCKET_AVAILABLE
        );
        $kycApproved = (bool) Db::table('cex_user_kyc')
            ->where('user_id', (int) $auth['user_id'])
            ->where('status', 3)
            ->where('kyc_level', '>=', 1)
            ->find();

        return [
            'authenticated' => true,
            'user' => [
                'uid' => (string) $auth['uid'],
                'nickname' => $auth['nickname'],
                'kyc_level' => (int) $auth['kyc_level'],
                'risk_level' => (int) $auth['risk_level'],
                'kyc_approved' => $kycApproved,
            ],
            'account' => [
                'id' => (int) $account['id'],
                'public_id' => (string) $account['public_id'],
            ],
            'merchant' => $merchant ? $this->merchantDto($merchant) : null,
            'payment_methods' => array_map([$this, 'paymentMethodDto'], $paymentMethods),
            'supported_assets' => $this->supportedAssets(),
            'payment_types' => $this->paymentTypeOptions(),
            'merchant_deposit' => array_merge($depositCfg, [
                'available_balance' => Decimal18::trim($depositAvailable, 8),
            ]),
        ];
    }

    public function market(array $filters): array
    {
        $action = strtolower(trim((string) ($filters['action'] ?? 'buy')));
        if (!in_array($action, ['buy', 'sell'], true)) {
            $action = 'buy';
        }
        $adSide = $action === 'buy' ? self::AD_SELL : self::AD_BUY;

        $assetCode = strtoupper(trim((string) ($filters['asset'] ?? 'USDT')));
        if (!in_array($assetCode, $this->supportedAssetCodes(), true)) {
            $assetCode = 'USDT';
        }

        $paymentType = $this->parseOptionalPaymentType($filters['payment_type'] ?? null);
        $fiatAmount = trim((string) ($filters['amount'] ?? ''));
        if ($fiatAmount !== '') {
            $fiatAmount = $this->fiatAmount($fiatAmount);
        }

        $query = Db::table('cex_c2c_ads')->alias('a')
            ->join('cex_c2c_merchants m', 'm.id = a.merchant_id')
            ->join('cex_user_users u', 'u.id = m.user_id')
            ->join('cex_asset_assets x', 'x.id = a.asset_id')
            ->where('a.status', self::AD_ONLINE)
            ->where('m.status', self::MERCHANT_ACTIVE)
            ->where('u.status', 1)
            ->where('a.side', $adSide)
            ->where('x.code', $assetCode)
            ->whereRaw('a.remaining_crypto_amount > 0')
            ->field(
                'a.id,a.ad_no,a.side,a.price,a.remaining_crypto_amount,a.min_fiat_amount,a.max_fiat_amount,' .
                'a.payment_window_minutes,a.terms,a.created_at,' .
                'm.id AS merchant_id,m.merchant_no,m.display_name,m.activated_at,' .
                'u.uid,u.nickname,x.code AS asset_code,x.display_decimals'
            );

        if ($paymentType !== null) {
            $query->join('cex_c2c_ad_payment_types ap', 'ap.ad_id = a.id')
                ->where('ap.payment_type', $paymentType);
        }
        if ($fiatAmount !== '') {
            $query->where('a.min_fiat_amount', '<=', $fiatAmount)
                ->where('a.max_fiat_amount', '>=', $fiatAmount);
        }

        $rows = $query
            ->order('a.price', $action === 'buy' ? 'asc' : 'desc')
            ->order('a.id', 'desc')
            ->limit(100)
            ->select()
            ->toArray();

        $adIds = array_map('intval', array_column($rows, 'id'));
        $paymentsByAd = [];
        if ($adIds) {
            $payRows = Db::table('cex_c2c_ad_payment_types')
                ->whereIn('ad_id', $adIds)
                ->field('ad_id,payment_type')
                ->order('payment_type', 'asc')
                ->select()
                ->toArray();
            foreach ($payRows as $payRow) {
                $paymentsByAd[(int) $payRow['ad_id']][] = (int) $payRow['payment_type'];
            }
        }

        $merchantIds = array_values(array_unique(array_map('intval', array_column($rows, 'merchant_id'))));
        $statsByMerchant = $this->merchantStats($merchantIds);

        $items = [];
        foreach ($rows as $row) {
            $merchantId = (int) $row['merchant_id'];
            $paymentTypes = $paymentsByAd[(int) $row['id']] ?? [];
            $items[] = [
                'ad_no' => (string) $row['ad_no'],
                'action' => $action,
                'ad_side' => (int) $row['side'],
                'asset' => (string) $row['asset_code'],
                'display_decimals' => (int) $row['display_decimals'],
                'price' => Decimal18::trim((string) $row['price'], 8),
                'available_crypto' => Decimal18::trim((string) $row['remaining_crypto_amount'], (int) $row['display_decimals']),
                'min_fiat' => $this->trimFiat((string) $row['min_fiat_amount']),
                'max_fiat' => $this->trimFiat((string) $row['max_fiat_amount']),
                'fiat_currency' => 'CNY',
                'payment_window_minutes' => (int) $row['payment_window_minutes'],
                'payment_types' => array_map(function (int $type) {
                    return [
                        'code' => $type,
                        'label' => $this->paymentTypeLabel($type),
                    ];
                }, $paymentTypes),
                'terms' => (string) ($row['terms'] ?? ''),
                'merchant' => [
                    'merchant_no' => (string) $row['merchant_no'],
                    'name' => (string) ($row['display_name'] ?: $row['nickname'] ?: ('商家' . substr((string) $row['uid'], -4))),
                    'completed_orders' => (int) ($statsByMerchant[$merchantId]['completed'] ?? 0),
                    'completion_rate' => (string) ($statsByMerchant[$merchantId]['completion_rate'] ?? '100.00'),
                    'merchant_since' => $row['activated_at'],
                ],
            ];
        }

        return [
            'action' => $action,
            'asset' => $assetCode,
            'fiat_currency' => 'CNY',
            'items' => $items,
        ];
    }

    public function applyMerchant(): array
    {
        $auth = $this->authContext();
        $userId = (int) $auth['user_id'];
        $account = $this->businessAccount();
        $this->approvedKyc($userId);

        $depositCfg = $this->merchantDepositConfig();
        $depositAsset = $this->assetByCode((string) $depositCfg['asset']);
        $depositAmount = Decimal18::positive((string) $depositCfg['amount']);

        $result = Db::transaction(function () use ($auth, $userId, $account, $depositAsset, $depositAmount) {
            $lockedAccount = Db::table('cex_account_accounts')
                ->where('id', (int) $account['id'])
                ->where('account_kind', 1)
                ->field('id,public_id,status,user_id')
                ->lock(true)
                ->find();
            if (!$lockedAccount || (int) $lockedAccount['status'] !== 1) {
                throw new C2cException('资产账户当前不可用', 409, 'C2C_ACCOUNT_UNAVAILABLE');
            }

            $merchant = Db::table('cex_c2c_merchants')->where('user_id', $userId)->lock(true)->find();
            if ($merchant && (int) $merchant['status'] === self::MERCHANT_ACTIVE) {
                return $merchant;
            }
            if ($merchant && in_array((int) $merchant['status'], [self::MERCHANT_SUSPENDED, self::MERCHANT_EXITING, self::MERCHANT_CLOSED], true)) {
                throw new C2cException('当前 C2C 商家状态不允许重新缴纳保证金', 409, 'C2C_MERCHANT_STATUS_BLOCKED');
            }

            if (!$merchant) {
                $merchantNo = Ulid::generate();
                $displayName = trim((string) ($auth['nickname'] ?? ''));
                if ($displayName === '') {
                    $displayName = '商家' . substr((string) $auth['uid'], -4);
                }
                $merchantId = (int) Db::table('cex_c2c_merchants')->insertGetId([
                    'merchant_no' => $merchantNo,
                    'user_id' => $userId,
                    'account_id' => (int) $account['id'],
                    'display_name' => mb_substr($displayName, 0, 64),
                    'status' => self::MERCHANT_PENDING_DEPOSIT,
                    'deposit_asset_id' => (int) $depositAsset['id'],
                    'deposit_amount' => $depositAmount,
                ]);
                $merchant = Db::table('cex_c2c_merchants')->where('id', $merchantId)->lock(true)->find();
            }

            $existingDeposit = Db::table('cex_c2c_merchant_deposits')
                ->where('merchant_id', (int) $merchant['id'])
                ->where('status', 1)
                ->lock(true)
                ->find();
            if ($existingDeposit) {
                Db::table('cex_c2c_merchants')->where('id', (int) $merchant['id'])->update([
                    'status' => self::MERCHANT_ACTIVE,
                    'activated_at' => $merchant['activated_at'] ?: UtcClock::now(),
                ]);
                return Db::table('cex_c2c_merchants')->where('id', (int) $merchant['id'])->find();
            }

            $system = $this->systemAccount('C2C_MERCHANT_DEPOSIT');
            $userLedger = $this->ledger->ensureLedgerAccount(
                (int) $account['id'],
                (int) $depositAsset['id'],
                LedgerService::SCOPE_SPOT,
                LedgerService::BUCKET_AVAILABLE,
                false
            );
            $systemLedger = $this->ledger->ensureLedgerAccount(
                (int) $system['id'],
                (int) $depositAsset['id'],
                LedgerService::SCOPE_SPOT,
                LedgerService::BUCKET_AVAILABLE,
                false
            );

            $depositNo = Ulid::generate();
            $ledgerTx = $this->ledger->postWithinTransaction([
                'business_type' => 'C2C_MERCHANT_DEPOSIT',
                'business_id' => $depositNo,
                'idempotency_key' => 'c2c-merchant-deposit:' . $depositNo,
                'description' => 'C2C merchant security deposit',
                'metadata' => [
                    'merchant_no' => (string) $merchant['merchant_no'],
                    'asset' => (string) $depositAsset['code'],
                    'amount' => $depositAmount,
                ],
            ], [
                [
                    'ledger_account_id' => (int) $userLedger['id'],
                    'asset_id' => (int) $depositAsset['id'],
                    'direction' => LedgerService::DIRECTION_DECREASE,
                    'amount' => $depositAmount,
                ],
                [
                    'ledger_account_id' => (int) $systemLedger['id'],
                    'asset_id' => (int) $depositAsset['id'],
                    'direction' => LedgerService::DIRECTION_INCREASE,
                    'amount' => $depositAmount,
                ],
            ]);

            Db::table('cex_c2c_merchant_deposits')->insert([
                'deposit_no' => $depositNo,
                'merchant_id' => (int) $merchant['id'],
                'account_id' => (int) $account['id'],
                'asset_id' => (int) $depositAsset['id'],
                'amount' => $depositAmount,
                'ledger_transaction_id' => (int) $ledgerTx['id'],
                'status' => 1,
            ]);

            Db::table('cex_c2c_merchants')->where('id', (int) $merchant['id'])->update([
                'status' => self::MERCHANT_ACTIVE,
                'deposit_asset_id' => (int) $depositAsset['id'],
                'deposit_amount' => $depositAmount,
                'activated_at' => UtcClock::now(),
            ]);

            return Db::table('cex_c2c_merchants')->where('id', (int) $merchant['id'])->find();
        });

        $this->audit('C2C_MERCHANT_ACTIVATED', $userId, 'c2c_merchant', (string) $result['merchant_no'], [
            'deposit_asset' => $depositCfg['asset'],
            'deposit_amount' => $depositCfg['amount'],
        ]);

        return $this->merchantDto($result);
    }

    public function paymentMethods(): array
    {
        $auth = $this->authContext();
        return array_map([$this, 'paymentMethodDto'], $this->paymentMethodsForUser((int) $auth['user_id']));
    }

    public function createPaymentMethod(array $payload, ?UploadedFile $qrFile): array
    {
        $auth = $this->authContext();
        $userId = (int) $auth['user_id'];
        $kyc = $this->approvedKyc($userId);
        $methodType = $this->parsePaymentType($payload['method_type'] ?? null);
        $accountName = trim((string) ($payload['account_name'] ?? ''));
        $accountNo = trim((string) ($payload['account_no'] ?? ''));
        $bankName = trim((string) ($payload['bank_name'] ?? ''));
        $bankBranch = trim((string) ($payload['bank_branch'] ?? ''));

        if ($accountName === '' || mb_strlen($accountName) > 128) {
            throw new C2cException('请输入正确的收款人姓名', 422, 'C2C_PAYMENT_NAME_INVALID');
        }
        $kycName = Crypto::decryptSensitive((string) $kyc['real_name_ciphertext'], 'kyc-real-name');
        if ($this->normalizePersonName($accountName) !== $this->normalizePersonName($kycName)) {
            throw new C2cException('收款人姓名必须与实名认证姓名一致', 422, 'C2C_PAYMENT_NAME_MISMATCH');
        }

        if ($methodType === self::PAYMENT_BANK) {
            if ($accountNo === '' || mb_strlen($accountNo) > 128 || $bankName === '' || mb_strlen($bankName) > 128) {
                throw new C2cException('银行卡收款需要填写银行卡号和银行名称', 422, 'C2C_BANK_INFO_REQUIRED');
            }
        } elseif (!$qrFile) {
            throw new C2cException('支付宝和微信收款方式必须上传收款二维码', 422, 'C2C_QR_REQUIRED');
        }

        if ($accountNo !== '' && mb_strlen($accountNo) > 128) {
            throw new C2cException('收款账号长度无效', 422, 'C2C_PAYMENT_ACCOUNT_INVALID');
        }
        if (mb_strlen($bankBranch) > 255) {
            throw new C2cException('开户支行名称过长', 422, 'C2C_BANK_BRANCH_TOO_LONG');
        }

        $paymentNo = Ulid::generate();
        $qr = null;
        if ($qrFile) {
            $qr = (new C2cPaymentDocumentService())->upload($qrFile, (string) $auth['uid'], $paymentNo);
        }

        try {
            $id = Db::transaction(function () use ($paymentNo, $userId, $methodType, $accountName, $accountNo, $bankName, $bankBranch, $qr) {
                // V1 每个用户每种收款类型只保留一个“当前启用”方式。
                // 旧记录不删除，保证历史订单快照仍可审计。
                Db::table('cex_c2c_payment_methods')
                    ->where('user_id', $userId)
                    ->where('method_type', $methodType)
                    ->where('status', 1)
                    ->update(['status' => 2]);

                return (int) Db::table('cex_c2c_payment_methods')->insertGetId([
                'payment_no' => $paymentNo,
                'user_id' => $userId,
                'method_type' => $methodType,
                'account_name_ciphertext' => Crypto::encryptSensitive($accountName, 'c2c-payment-account-name'),
                'account_name_hash' => Crypto::sensitiveHash($this->normalizePersonName($accountName), 'c2c-payment-account-name'),
                'account_no_ciphertext' => $accountNo !== '' ? Crypto::encryptSensitive($accountNo, 'c2c-payment-account-no') : null,
                'account_no_masked' => $accountNo !== '' ? $this->maskAccountNo($accountNo) : null,
                'bank_name' => $bankName !== '' ? mb_substr($bankName, 0, 128) : null,
                'bank_branch' => $bankBranch !== '' ? mb_substr($bankBranch, 0, 255) : null,
                    'qr_storage_key' => $qr ? (string) $qr['storage_key'] : null,
                    'status' => 1,
                ]);
            });
        } catch (\Throwable $e) {
            if ($qr && !empty($qr['storage_key'])) {
                (new C2cPaymentDocumentService())->delete((string) $qr['storage_key']);
            }
            throw $e;
        }

        $row = Db::table('cex_c2c_payment_methods')->where('id', $id)->find();
        $this->audit('C2C_PAYMENT_METHOD_CREATED', $userId, 'c2c_payment_method', $paymentNo, [
            'method_type' => $methodType,
        ]);
        return $this->paymentMethodDto($row);
    }

    public function disablePaymentMethod(string $paymentNo): array
    {
        $auth = $this->authContext();
        $userId = (int) $auth['user_id'];
        $row = Db::table('cex_c2c_payment_methods')
            ->where('payment_no', $this->publicNo($paymentNo, '收款方式'))
            ->where('user_id', $userId)
            ->find();
        if (!$row) {
            throw new C2cException('收款方式不存在', 404, 'C2C_PAYMENT_NOT_FOUND');
        }
        if ((int) $row['status'] === 1) {
            Db::table('cex_c2c_payment_methods')->where('id', (int) $row['id'])->update(['status' => 2]);
        }
        $row = Db::table('cex_c2c_payment_methods')->where('id', (int) $row['id'])->find();
        $this->audit('C2C_PAYMENT_METHOD_DISABLED', $userId, 'c2c_payment_method', $paymentNo);
        return $this->paymentMethodDto($row);
    }

    public function paymentMethodQr(string $paymentNo): array
    {
        $auth = $this->authContext();
        $row = Db::table('cex_c2c_payment_methods')
            ->where('payment_no', $this->publicNo($paymentNo, '收款方式'))
            ->where('user_id', (int) $auth['user_id'])
            ->find();
        if (!$row || empty($row['qr_storage_key'])) {
            throw new C2cException('收款二维码不存在', 404, 'C2C_QR_NOT_FOUND');
        }
        return (new C2cPaymentDocumentService())->read((string) $row['qr_storage_key']);
    }

    public function merchantAds(): array
    {
        $merchant = $this->requireActiveMerchant();
        $rows = Db::table('cex_c2c_ads')->alias('a')
            ->join('cex_asset_assets x', 'x.id = a.asset_id')
            ->where('a.merchant_id', (int) $merchant['id'])
            ->field('a.*,x.code AS asset_code,x.display_decimals')
            ->order('a.id', 'desc')
            ->limit(100)
            ->select()
            ->toArray();
        return $this->adsWithPayments($rows);
    }

    public function createAd(array $payload): array
    {
        $auth = $this->authContext();
        $merchant = $this->requireActiveMerchant();
        $account = $this->businessAccount();

        $sideText = strtoupper(trim((string) ($payload['side'] ?? '')));
        $side = $sideText === 'BUY' ? self::AD_BUY : ($sideText === 'SELL' ? self::AD_SELL : 0);
        if (!$side) {
            throw new C2cException('请选择商家买币或商家卖币', 422, 'C2C_AD_SIDE_INVALID');
        }

        $assetCode = strtoupper(trim((string) ($payload['asset'] ?? '')));
        if (!in_array($assetCode, $this->supportedAssetCodes(), true)) {
            throw new C2cException('当前仅支持 BTC、USDT、ETH', 422, 'C2C_AD_ASSET_INVALID');
        }
        $asset = $this->assetByCode($assetCode);
        $price = Decimal18::positive((string) ($payload['price'] ?? ''));
        $total = Decimal18::positive((string) ($payload['total_crypto_amount'] ?? ''));
        $minFiat = $this->fiatAmount((string) ($payload['min_fiat_amount'] ?? ''));
        $maxFiat = $this->fiatAmount((string) ($payload['max_fiat_amount'] ?? ''));
        if (Decimal18::compare($minFiat, $maxFiat) > 0) {
            throw new C2cException('单笔最小金额不能大于单笔最大金额', 422, 'C2C_AD_LIMIT_INVALID');
        }

        $totalFiat = $this->decimalMul($total, $price, 2);
        if (Decimal18::compare($maxFiat, $totalFiat) > 0) {
            $maxFiat = $totalFiat;
        }
        if (Decimal18::compare($minFiat, $maxFiat) > 0) {
            throw new C2cException('广告总数量不足以满足单笔最小金额', 422, 'C2C_AD_TOTAL_TOO_SMALL');
        }

        $paymentTypes = $this->paymentTypesFromPayload($payload['payment_types'] ?? []);
        if (!$paymentTypes) {
            throw new C2cException('至少选择一种付款方式', 422, 'C2C_AD_PAYMENT_REQUIRED');
        }

        if ($side === self::AD_SELL) {
            $activeTypes = array_values(array_unique(array_map('intval', array_column(
                Db::table('cex_c2c_payment_methods')
                    ->where('user_id', (int) $auth['user_id'])
                    ->where('status', 1)
                    ->field('method_type')
                    ->select()
                    ->toArray(),
                'method_type'
            ))));
            foreach ($paymentTypes as $type) {
                if (!in_array($type, $activeTypes, true)) {
                    throw new C2cException('商家卖币广告只能选择自己已经添加的收款方式', 422, 'C2C_AD_PAYMENT_METHOD_MISSING');
                }
            }

            $available = $this->ledger->balanceForDimensions(
                (int) $account['id'],
                (int) $asset['id'],
                LedgerService::SCOPE_SPOT,
                LedgerService::BUCKET_AVAILABLE
            );
            if (Decimal18::compare($available, $total) < 0) {
                throw new C2cException('现货可用余额不足，无法发布该卖币广告', 422, 'C2C_AD_BALANCE_INSUFFICIENT');
            }
        }

        $window = (int) ($payload['payment_window_minutes'] ?? config('c2c.payment_window.default_minutes', 15));
        $window = max(
            (int) config('c2c.payment_window.min_minutes', 5),
            min((int) config('c2c.payment_window.max_minutes', 60), $window)
        );
        $terms = trim((string) ($payload['terms'] ?? ''));
        if (mb_strlen($terms) > 1000) {
            throw new C2cException('交易说明最多 1000 个字符', 422, 'C2C_AD_TERMS_TOO_LONG');
        }

        $adNo = Ulid::generate();
        $id = Db::transaction(function () use ($merchant, $asset, $side, $price, $total, $minFiat, $maxFiat, $window, $terms, $paymentTypes, $adNo) {
            $lockedMerchant = Db::table('cex_c2c_merchants')->where('id', (int) $merchant['id'])->lock(true)->find();
            if (!$lockedMerchant || (int) $lockedMerchant['status'] !== self::MERCHANT_ACTIVE) {
                throw new C2cException('C2C 商家当前不可发布广告', 409, 'C2C_MERCHANT_NOT_ACTIVE');
            }
            $id = (int) Db::table('cex_c2c_ads')->insertGetId([
                'ad_no' => $adNo,
                'merchant_id' => (int) $merchant['id'],
                'side' => $side,
                'asset_id' => (int) $asset['id'],
                'fiat_currency' => 'CNY',
                'price' => $price,
                'total_crypto_amount' => $total,
                'remaining_crypto_amount' => $total,
                'min_fiat_amount' => $minFiat,
                'max_fiat_amount' => $maxFiat,
                'payment_window_minutes' => $window,
                'terms' => $terms !== '' ? $terms : null,
                'status' => self::AD_ONLINE,
            ]);
            foreach ($paymentTypes as $type) {
                Db::table('cex_c2c_ad_payment_types')->insert([
                    'ad_id' => $id,
                    'payment_type' => $type,
                ]);
            }
            return $id;
        });

        $row = Db::table('cex_c2c_ads')->alias('a')
            ->join('cex_asset_assets x', 'x.id = a.asset_id')
            ->where('a.id', $id)
            ->field('a.*,x.code AS asset_code,x.display_decimals')
            ->find();
        $dto = $this->adsWithPayments([$row])[0];
        $this->audit('C2C_AD_CREATED', (int) $auth['user_id'], 'c2c_ad', $adNo, [
            'side' => $side,
            'asset' => $assetCode,
        ]);
        return $dto;
    }

    public function setAdStatus(string $adNo, array $payload): array
    {
        $auth = $this->authContext();
        $merchant = $this->requireActiveMerchant();
        $statusText = strtoupper(trim((string) ($payload['status'] ?? '')));
        if (!in_array($statusText, ['ONLINE', 'OFFLINE'], true)) {
            throw new C2cException('广告状态仅支持 ONLINE / OFFLINE', 422, 'C2C_AD_STATUS_INVALID');
        }
        $target = $statusText === 'ONLINE' ? self::AD_ONLINE : self::AD_OFFLINE;
        $adNo = $this->publicNo($adNo, '广告');

        Db::transaction(function () use ($merchant, $adNo, $target) {
            $ad = Db::table('cex_c2c_ads')
                ->where('ad_no', $adNo)
                ->where('merchant_id', (int) $merchant['id'])
                ->lock(true)
                ->find();
            if (!$ad) {
                throw new C2cException('广告不存在', 404, 'C2C_AD_NOT_FOUND');
            }
            if ((int) $ad['status'] === self::AD_CANCELLED) {
                throw new C2cException('已取消广告不能重新上线', 409, 'C2C_AD_CANCELLED');
            }
            if ($target === self::AD_ONLINE && Decimal18::compare((string) $ad['remaining_crypto_amount'], '0') <= 0) {
                throw new C2cException('广告剩余数量为 0，不能重新上线', 409, 'C2C_AD_EMPTY');
            }
            Db::table('cex_c2c_ads')->where('id', (int) $ad['id'])->update(['status' => $target]);
        });

        $row = Db::table('cex_c2c_ads')->alias('a')
            ->join('cex_asset_assets x', 'x.id = a.asset_id')
            ->where('a.ad_no', $adNo)
            ->field('a.*,x.code AS asset_code,x.display_decimals')
            ->find();
        $this->audit('C2C_AD_STATUS_CHANGED', (int) $auth['user_id'], 'c2c_ad', $adNo, ['status' => $target]);
        return $this->adsWithPayments([$row])[0];
    }

    public function createOrder(array $payload): array
    {
        $auth = $this->authContext();
        $userId = (int) $auth['user_id'];
        $takerAccount = $this->businessAccount();
        $adNo = $this->publicNo((string) ($payload['ad_no'] ?? ''), '广告');
        $requestedFiat = $this->fiatAmount((string) ($payload['fiat_amount'] ?? ''));
        $paymentType = $this->parsePaymentType($payload['payment_type'] ?? null);
        $orderNo = Ulid::generate();

        $orderId = Db::transaction(function () use ($userId, $takerAccount, $adNo, $requestedFiat, $paymentType, $orderNo) {
            $ad = Db::table('cex_c2c_ads')->alias('a')
                ->join('cex_c2c_merchants m', 'm.id = a.merchant_id')
                ->where('a.ad_no', $adNo)
                ->field('a.*,m.user_id AS merchant_user_id,m.account_id AS merchant_account_id,m.status AS merchant_status')
                ->lock(true)
                ->find();
            if (!$ad || (int) $ad['status'] !== self::AD_ONLINE || (int) $ad['merchant_status'] !== self::MERCHANT_ACTIVE) {
                throw new C2cException('该广告当前不可交易', 409, 'C2C_AD_UNAVAILABLE');
            }
            if ((int) $ad['merchant_user_id'] === $userId) {
                throw new C2cException('不能与自己的 C2C 广告成交', 422, 'C2C_SELF_TRADE');
            }
            if (Decimal18::compare($requestedFiat, (string) $ad['min_fiat_amount']) < 0
                || Decimal18::compare($requestedFiat, (string) $ad['max_fiat_amount']) > 0) {
                throw new C2cException('下单金额超出广告单笔限额', 422, 'C2C_ORDER_LIMIT');
            }

            $allowed = (bool) Db::table('cex_c2c_ad_payment_types')
                ->where('ad_id', (int) $ad['id'])
                ->where('payment_type', $paymentType)
                ->find();
            if (!$allowed) {
                throw new C2cException('该广告不支持选择的付款方式', 422, 'C2C_ORDER_PAYMENT_UNSUPPORTED');
            }

            $asset = Db::table('cex_asset_assets')->where('id', (int) $ad['asset_id'])->where('status', 1)->find();
            if (!$asset) {
                throw new C2cException('广告币种当前不可用', 409, 'C2C_ASSET_UNAVAILABLE');
            }

            $cryptoAmount = $this->decimalDivTruncate($requestedFiat, (string) $ad['price'], (int) $asset['display_decimals']);
            if (Decimal18::compare($cryptoAmount, '0') <= 0) {
                throw new C2cException('下单金额过小', 422, 'C2C_ORDER_TOO_SMALL');
            }
            if (Decimal18::compare($cryptoAmount, (string) $ad['remaining_crypto_amount']) > 0) {
                throw new C2cException('广告剩余数量不足', 409, 'C2C_AD_REMAINING_INSUFFICIENT');
            }
            $fiatAmount = $this->decimalMul((string) $ad['price'], $cryptoAmount, 2);
            if (Decimal18::compare($fiatAmount, (string) $ad['min_fiat_amount']) < 0) {
                throw new C2cException('按币种精度折算后的订单金额低于广告最小限额', 422, 'C2C_ORDER_ROUNDED_BELOW_MIN');
            }

            if ((int) $ad['side'] === self::AD_SELL) {
                $cryptoSellerAccountId = (int) $ad['merchant_account_id'];
                $cryptoBuyerAccountId = (int) $takerAccount['id'];
                $paymentOwnerUserId = (int) $ad['merchant_user_id'];
            } else {
                $cryptoSellerAccountId = (int) $takerAccount['id'];
                $cryptoBuyerAccountId = (int) $ad['merchant_account_id'];
                $paymentOwnerUserId = $userId;
            }

            $payment = Db::table('cex_c2c_payment_methods')
                ->where('user_id', $paymentOwnerUserId)
                ->where('method_type', $paymentType)
                ->where('status', 1)
                ->order('id', 'asc')
                ->lock(true)
                ->find();
            if (!$payment) {
                throw new C2cException(
                    (int) $ad['side'] === self::AD_SELL
                        ? '商家当前没有可用的该类型收款方式，请选择其他付款方式'
                        : '卖币前请先添加与广告匹配的收款方式',
                    422,
                    'C2C_PAYMENT_METHOD_UNAVAILABLE'
                );
            }

            $snapshot = $this->paymentSnapshot($payment);
            $snapshotCipher = Crypto::encryptSensitive(
                json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'c2c-order-payment-snapshot'
            );

            $sellerAccount = Db::table('cex_account_accounts')
                ->where('id', $cryptoSellerAccountId)
                ->where('account_kind', 1)
                ->field('id,status')
                ->lock(true)
                ->find();
            $buyerAccount = Db::table('cex_account_accounts')
                ->where('id', $cryptoBuyerAccountId)
                ->where('account_kind', 1)
                ->field('id,status')
                ->lock(true)
                ->find();
            if (!$sellerAccount || !$buyerAccount || (int) $sellerAccount['status'] !== 1 || (int) $buyerAccount['status'] !== 1) {
                throw new C2cException('交易账户当前不可用', 409, 'C2C_PARTY_ACCOUNT_UNAVAILABLE');
            }

            $escrowSystem = $this->systemAccount('C2C_ESCROW');
            $sellerLedger = $this->ledger->ensureLedgerAccount(
                $cryptoSellerAccountId,
                (int) $asset['id'],
                LedgerService::SCOPE_SPOT,
                LedgerService::BUCKET_AVAILABLE,
                false
            );
            $escrowLedger = $this->ledger->ensureLedgerAccount(
                (int) $escrowSystem['id'],
                (int) $asset['id'],
                LedgerService::SCOPE_SPOT,
                LedgerService::BUCKET_AVAILABLE,
                false
            );

            $escrowTx = $this->ledger->postWithinTransaction([
                'business_type' => 'C2C_ESCROW_LOCK',
                'business_id' => $orderNo,
                'idempotency_key' => 'c2c-escrow-lock:' . $orderNo,
                'description' => 'C2C crypto escrow lock',
                'metadata' => [
                    'order_no' => $orderNo,
                    'ad_no' => (string) $ad['ad_no'],
                    'asset' => (string) $asset['code'],
                    'crypto_amount' => $cryptoAmount,
                ],
            ], [
                [
                    'ledger_account_id' => (int) $sellerLedger['id'],
                    'asset_id' => (int) $asset['id'],
                    'direction' => LedgerService::DIRECTION_DECREASE,
                    'amount' => $cryptoAmount,
                ],
                [
                    'ledger_account_id' => (int) $escrowLedger['id'],
                    'asset_id' => (int) $asset['id'],
                    'direction' => LedgerService::DIRECTION_INCREASE,
                    'amount' => $cryptoAmount,
                ],
            ]);

            $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+' . (int) $ad['payment_window_minutes'] . ' minutes')
                ->format('Y-m-d H:i:s.u');
            $id = (int) Db::table('cex_c2c_orders')->insertGetId([
                'order_no' => $orderNo,
                'ad_id' => (int) $ad['id'],
                'merchant_id' => (int) $ad['merchant_id'],
                'taker_user_id' => $userId,
                'crypto_seller_account_id' => $cryptoSellerAccountId,
                'crypto_buyer_account_id' => $cryptoBuyerAccountId,
                'asset_id' => (int) $asset['id'],
                'fiat_currency' => 'CNY',
                'ad_side' => (int) $ad['side'],
                'price' => (string) $ad['price'],
                'crypto_amount' => $cryptoAmount,
                'fiat_amount' => $fiatAmount,
                'payment_method_id' => (int) $payment['id'],
                'payment_type' => $paymentType,
                'payment_snapshot_ciphertext' => $snapshotCipher,
                'payment_snapshot_masked' => (string) $snapshot['masked_summary'],
                'escrow_ledger_transaction_id' => (int) $escrowTx['id'],
                'status' => self::ORDER_WAITING_PAYMENT,
                'expires_at' => $expiresAt,
            ]);

            $remaining = Decimal18::subtract((string) $ad['remaining_crypto_amount'], $cryptoAmount);
            Db::execute(
                'UPDATE `cex_c2c_ads` SET `remaining_crypto_amount`=CAST(? AS DECIMAL(38,18)),`status`=?,`updated_at`=CURRENT_TIMESTAMP(6) WHERE `id`=?',
                [
                    $remaining,
                    Decimal18::compare($remaining, '0') <= 0 ? self::AD_EXHAUSTED : self::AD_ONLINE,
                    (int) $ad['id'],
                ]
            );

            return $id;
        });

        $this->audit('C2C_ORDER_CREATED', $userId, 'c2c_order', $orderNo, ['ad_no' => $adNo]);
        return $this->orderDetail($orderNo);
    }

    public function myOrders(int $limit = 100): array
    {
        $auth = $this->authContext();
        $userId = (int) $auth['user_id'];
        $account = $this->businessAccount();
        $this->expireDueOrders(20);

        $rows = Db::table('cex_c2c_orders')->alias('o')
            ->join('cex_asset_assets x', 'x.id = o.asset_id')
            ->join('cex_c2c_ads a', 'a.id = o.ad_id')
            ->join('cex_c2c_merchants m', 'm.id = o.merchant_id')
            ->join('cex_user_users mu', 'mu.id = m.user_id')
            ->join('cex_user_users tu', 'tu.id = o.taker_user_id')
            ->where(function ($q) use ($userId, $account) {
                $q->where('o.taker_user_id', $userId)
                    ->whereOr('m.user_id', $userId)
                    ->whereOr('o.crypto_seller_account_id', (int) $account['id'])
                    ->whereOr('o.crypto_buyer_account_id', (int) $account['id']);
            })
            ->field(
                'o.*,x.code AS asset_code,x.display_decimals,a.ad_no,' .
                'm.user_id AS merchant_user_id,m.display_name AS merchant_name,m.merchant_no,' .
                'mu.uid AS merchant_uid,tu.uid AS taker_uid,tu.nickname AS taker_nickname'
            )
            ->order('o.id', 'desc')
            ->limit(max(1, min(200, $limit)))
            ->select()
            ->toArray();

        return array_map(function (array $row) use ($userId, $account) {
            return $this->orderSummaryDto($row, $userId, (int) $account['id']);
        }, $rows);
    }

    public function orderDetail(string $orderNo): array
    {
        $auth = $this->authContext();
        $userId = (int) $auth['user_id'];
        $account = $this->businessAccount();
        $orderNo = $this->publicNo($orderNo, '订单');
        $this->expireOrderIfDue($orderNo);

        $row = $this->orderRow($orderNo);
        $this->assertOrderParty($row, $userId, (int) $account['id']);

        $snapshot = $this->decryptPaymentSnapshot((string) $row['payment_snapshot_ciphertext']);
        $summary = $this->orderSummaryDto($row, $userId, (int) $account['id']);
        $summary['payment'] = [
            'type' => (int) $row['payment_type'],
            'type_label' => $this->paymentTypeLabel((int) $row['payment_type']),
            'account_name' => (string) ($snapshot['account_name'] ?? ''),
            'account_no' => (string) ($snapshot['account_no'] ?? ''),
            'bank_name' => (string) ($snapshot['bank_name'] ?? ''),
            'bank_branch' => (string) ($snapshot['bank_branch'] ?? ''),
            'has_qr' => !empty($snapshot['qr_storage_key']),
            'qr_url' => !empty($snapshot['qr_storage_key']) ? '/api/c2c/orders/' . rawurlencode($orderNo) . '/payment-qr' : null,
        ];
        $summary['terms'] = (string) ($row['terms'] ?? '');
        $appeal = Db::table('cex_c2c_appeals')->where('order_id', (int) $row['id'])->order('id', 'desc')->find();
        $summary['appeal'] = $appeal ? [
            'appeal_no' => (string) $appeal['appeal_no'],
            'reason_code' => (string) $appeal['reason_code'],
            'description' => (string) ($appeal['description'] ?? ''),
            'status' => (int) $appeal['status'],
            'created_at' => $appeal['created_at'],
        ] : null;
        return $summary;
    }

    public function orderPaymentQr(string $orderNo): array
    {
        $auth = $this->authContext();
        $userId = (int) $auth['user_id'];
        $account = $this->businessAccount();
        $row = $this->orderRow($this->publicNo($orderNo, '订单'));
        $this->assertOrderParty($row, $userId, (int) $account['id']);
        $snapshot = $this->decryptPaymentSnapshot((string) $row['payment_snapshot_ciphertext']);
        $key = trim((string) ($snapshot['qr_storage_key'] ?? ''));
        if ($key === '') {
            throw new C2cException('该订单没有收款二维码', 404, 'C2C_ORDER_QR_NOT_FOUND');
        }
        return (new C2cPaymentDocumentService())->read($key);
    }

    public function markPaid(string $orderNo): array
    {
        $auth = $this->authContext();
        $userId = (int) $auth['user_id'];
        $account = $this->businessAccount();
        $orderNo = $this->publicNo($orderNo, '订单');
        $this->expireOrderIfDue($orderNo);

        Db::transaction(function () use ($orderNo, $userId, $account) {
            $row = $this->orderRowLocked($orderNo);
            $this->assertOrderParty($row, $userId, (int) $account['id']);
            if ((int) $row['status'] !== self::ORDER_WAITING_PAYMENT) {
                throw new C2cException('当前订单状态不能标记已付款', 409, 'C2C_ORDER_STATE_INVALID');
            }
            if (!$this->isFiatPayer($row, $userId)) {
                throw new C2cException('只有法币付款方可以标记已付款', 403, 'C2C_NOT_FIAT_PAYER');
            }
            Db::table('cex_c2c_orders')->where('id', (int) $row['id'])->update([
                'status' => self::ORDER_PAID,
                'paid_at' => UtcClock::now(),
            ]);
        });

        $this->audit('C2C_ORDER_MARK_PAID', $userId, 'c2c_order', $orderNo);
        return $this->orderDetail($orderNo);
    }

    public function confirmReceipt(string $orderNo): array
    {
        $auth = $this->authContext();
        $userId = (int) $auth['user_id'];
        $account = $this->businessAccount();
        $orderNo = $this->publicNo($orderNo, '订单');

        Db::transaction(function () use ($orderNo, $userId, $account) {
            $row = $this->orderRowLocked($orderNo);
            $this->assertOrderParty($row, $userId, (int) $account['id']);
            if ((int) $row['status'] === self::ORDER_COMPLETED) {
                return;
            }
            if ((int) $row['status'] !== self::ORDER_PAID) {
                throw new C2cException('只有已付款订单才能确认收款并放币', 409, 'C2C_ORDER_STATE_INVALID');
            }
            if ((int) $row['crypto_seller_account_id'] !== (int) $account['id']) {
                throw new C2cException('只有加密货币卖方可以确认收款并放币', 403, 'C2C_NOT_CRYPTO_SELLER');
            }

            $this->releaseEscrowWithinTransaction($row);
            Db::table('cex_c2c_orders')->where('id', (int) $row['id'])->update([
                'status' => self::ORDER_COMPLETED,
                'released_at' => UtcClock::now(),
                'completed_at' => UtcClock::now(),
            ]);
        });

        $this->audit('C2C_ORDER_RELEASED', $userId, 'c2c_order', $orderNo);
        return $this->orderDetail($orderNo);
    }

    public function cancelOrder(string $orderNo): array
    {
        $auth = $this->authContext();
        $userId = (int) $auth['user_id'];
        $account = $this->businessAccount();
        $orderNo = $this->publicNo($orderNo, '订单');

        Db::transaction(function () use ($orderNo, $userId, $account) {
            $row = $this->orderRowLocked($orderNo);
            $this->assertOrderParty($row, $userId, (int) $account['id']);
            if ((int) $row['status'] !== self::ORDER_WAITING_PAYMENT) {
                throw new C2cException('只有未付款订单可以取消', 409, 'C2C_ORDER_STATE_INVALID');
            }
            $this->refundEscrowWithinTransaction($row, self::ORDER_CANCELLED);
        });

        $this->audit('C2C_ORDER_CANCELLED', $userId, 'c2c_order', $orderNo);
        return $this->orderDetail($orderNo);
    }

    public function openAppeal(string $orderNo, array $payload): array
    {
        $auth = $this->authContext();
        $userId = (int) $auth['user_id'];
        $account = $this->businessAccount();
        $orderNo = $this->publicNo($orderNo, '订单');
        $reason = strtoupper(trim((string) ($payload['reason_code'] ?? 'PAYMENT_DISPUTE')));
        if (!preg_match('/^[A-Z0-9_-]{3,64}$/', $reason)) {
            throw new C2cException('申诉原因代码无效', 422, 'C2C_APPEAL_REASON_INVALID');
        }
        $description = trim((string) ($payload['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 1000) {
            throw new C2cException('请填写 1-1000 字的申诉说明', 422, 'C2C_APPEAL_DESCRIPTION_INVALID');
        }

        Db::transaction(function () use ($orderNo, $userId, $account, $reason, $description) {
            $row = $this->orderRowLocked($orderNo);
            $this->assertOrderParty($row, $userId, (int) $account['id']);
            if (!in_array((int) $row['status'], [self::ORDER_PAID, self::ORDER_APPEAL], true)) {
                throw new C2cException('当前订单状态不能发起申诉', 409, 'C2C_APPEAL_STATE_INVALID');
            }
            $existing = Db::table('cex_c2c_appeals')->where('order_id', (int) $row['id'])->where('status', 1)->lock(true)->find();
            if (!$existing) {
                Db::table('cex_c2c_appeals')->insert([
                    'appeal_no' => Ulid::generate(),
                    'order_id' => (int) $row['id'],
                    'opened_by_user_id' => $userId,
                    'reason_code' => $reason,
                    'description' => $description,
                    'status' => 1,
                ]);
            }
            Db::table('cex_c2c_orders')->where('id', (int) $row['id'])->update(['status' => self::ORDER_APPEAL]);
        });

        $this->audit('C2C_ORDER_APPEAL_OPENED', $userId, 'c2c_order', $orderNo, ['reason_code' => $reason]);
        return $this->orderDetail($orderNo);
    }

    public function expireDueOrders(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $rows = Db::table('cex_c2c_orders')
            ->where('status', self::ORDER_WAITING_PAYMENT)
            ->where('expires_at', '<=', UtcClock::now())
            ->field('order_no')
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $expired = [];
        foreach ($rows as $row) {
            $orderNo = (string) $row['order_no'];
            try {
                Db::transaction(function () use ($orderNo) {
                    $locked = $this->orderRowLocked($orderNo);
                    if ((int) $locked['status'] !== self::ORDER_WAITING_PAYMENT) {
                        return;
                    }
                    if (strtotime((string) $locked['expires_at']) > time()) {
                        return;
                    }
                    $this->refundEscrowWithinTransaction($locked, self::ORDER_EXPIRED);
                });
                $expired[] = $orderNo;
            } catch (\Throwable $e) {
                // 单笔失败不能阻塞其他订单，由下一轮继续重试。
            }
        }
        return $expired;
    }

    private function expireOrderIfDue(string $orderNo): void
    {
        $row = Db::table('cex_c2c_orders')->where('order_no', $orderNo)->field('status,expires_at')->find();
        if (!$row || (int) $row['status'] !== self::ORDER_WAITING_PAYMENT || strtotime((string) $row['expires_at']) > time()) {
            return;
        }
        Db::transaction(function () use ($orderNo) {
            $locked = $this->orderRowLocked($orderNo);
            if ((int) $locked['status'] === self::ORDER_WAITING_PAYMENT && strtotime((string) $locked['expires_at']) <= time()) {
                $this->refundEscrowWithinTransaction($locked, self::ORDER_EXPIRED);
            }
        });
    }

    private function releaseEscrowWithinTransaction(array $row): void
    {
        if (!empty($row['release_ledger_transaction_id'])) {
            return;
        }
        $assetId = (int) $row['asset_id'];
        $escrowSystem = $this->systemAccount('C2C_ESCROW');
        $escrowLedger = $this->ledger->ensureLedgerAccount(
            (int) $escrowSystem['id'], $assetId, LedgerService::SCOPE_SPOT, LedgerService::BUCKET_AVAILABLE, false
        );
        $buyerLedger = $this->ledger->ensureLedgerAccount(
            (int) $row['crypto_buyer_account_id'], $assetId, LedgerService::SCOPE_SPOT, LedgerService::BUCKET_AVAILABLE, false
        );
        $tx = $this->ledger->postWithinTransaction([
            'business_type' => 'C2C_ESCROW_RELEASE',
            'business_id' => (string) $row['order_no'],
            'idempotency_key' => 'c2c-escrow-release:' . (string) $row['order_no'],
            'description' => 'C2C escrow release to crypto buyer',
            'metadata' => ['order_no' => (string) $row['order_no']],
        ], [
            [
                'ledger_account_id' => (int) $escrowLedger['id'],
                'asset_id' => $assetId,
                'direction' => LedgerService::DIRECTION_DECREASE,
                'amount' => (string) $row['crypto_amount'],
            ],
            [
                'ledger_account_id' => (int) $buyerLedger['id'],
                'asset_id' => $assetId,
                'direction' => LedgerService::DIRECTION_INCREASE,
                'amount' => (string) $row['crypto_amount'],
            ],
        ]);
        Db::table('cex_c2c_orders')->where('id', (int) $row['id'])->update([
            'release_ledger_transaction_id' => (int) $tx['id'],
        ]);
    }

    private function refundEscrowWithinTransaction(array $row, int $targetStatus): void
    {
        if (!empty($row['refund_ledger_transaction_id'])) {
            return;
        }
        $assetId = (int) $row['asset_id'];
        $escrowSystem = $this->systemAccount('C2C_ESCROW');
        $escrowLedger = $this->ledger->ensureLedgerAccount(
            (int) $escrowSystem['id'], $assetId, LedgerService::SCOPE_SPOT, LedgerService::BUCKET_AVAILABLE, false
        );
        $sellerLedger = $this->ledger->ensureLedgerAccount(
            (int) $row['crypto_seller_account_id'], $assetId, LedgerService::SCOPE_SPOT, LedgerService::BUCKET_AVAILABLE, false
        );
        $tx = $this->ledger->postWithinTransaction([
            'business_type' => 'C2C_ESCROW_REFUND',
            'business_id' => (string) $row['order_no'],
            'idempotency_key' => 'c2c-escrow-refund:' . (string) $row['order_no'],
            'description' => 'C2C escrow refund to crypto seller',
            'metadata' => ['order_no' => (string) $row['order_no'], 'target_status' => $targetStatus],
        ], [
            [
                'ledger_account_id' => (int) $escrowLedger['id'],
                'asset_id' => $assetId,
                'direction' => LedgerService::DIRECTION_DECREASE,
                'amount' => (string) $row['crypto_amount'],
            ],
            [
                'ledger_account_id' => (int) $sellerLedger['id'],
                'asset_id' => $assetId,
                'direction' => LedgerService::DIRECTION_INCREASE,
                'amount' => (string) $row['crypto_amount'],
            ],
        ]);

        $ad = Db::table('cex_c2c_ads')->where('id', (int) $row['ad_id'])->lock(true)->find();
        if ($ad && (int) $ad['status'] !== self::AD_CANCELLED) {
            $restored = Decimal18::add((string) $ad['remaining_crypto_amount'], (string) $row['crypto_amount']);
            $nextStatus = (int) $ad['status'] === self::AD_EXHAUSTED ? self::AD_ONLINE : (int) $ad['status'];
            Db::execute(
                'UPDATE `cex_c2c_ads` SET `remaining_crypto_amount`=CAST(? AS DECIMAL(38,18)),`status`=?,`updated_at`=CURRENT_TIMESTAMP(6) WHERE `id`=?',
                [$restored, $nextStatus, (int) $ad['id']]
            );
        }

        Db::table('cex_c2c_orders')->where('id', (int) $row['id'])->update([
            'refund_ledger_transaction_id' => (int) $tx['id'],
            'status' => $targetStatus,
            'cancelled_at' => UtcClock::now(),
            'completed_at' => UtcClock::now(),
        ]);
    }

    private function orderRow(string $orderNo): array
    {
        $row = Db::table('cex_c2c_orders')->alias('o')
            ->join('cex_asset_assets x', 'x.id = o.asset_id')
            ->join('cex_c2c_ads a', 'a.id = o.ad_id')
            ->join('cex_c2c_merchants m', 'm.id = o.merchant_id')
            ->join('cex_user_users mu', 'mu.id = m.user_id')
            ->join('cex_user_users tu', 'tu.id = o.taker_user_id')
            ->where('o.order_no', $orderNo)
            ->field(
                'o.*,x.code AS asset_code,x.display_decimals,a.ad_no,a.terms,' .
                'm.user_id AS merchant_user_id,m.display_name AS merchant_name,m.merchant_no,' .
                'mu.uid AS merchant_uid,tu.uid AS taker_uid,tu.nickname AS taker_nickname'
            )
            ->find();
        if (!$row) {
            throw new C2cException('C2C 订单不存在', 404, 'C2C_ORDER_NOT_FOUND');
        }
        return $row;
    }

    private function orderRowLocked(string $orderNo): array
    {
        $row = Db::table('cex_c2c_orders')->alias('o')
            ->join('cex_asset_assets x', 'x.id = o.asset_id')
            ->join('cex_c2c_ads a', 'a.id = o.ad_id')
            ->join('cex_c2c_merchants m', 'm.id = o.merchant_id')
            ->join('cex_user_users mu', 'mu.id = m.user_id')
            ->join('cex_user_users tu', 'tu.id = o.taker_user_id')
            ->where('o.order_no', $orderNo)
            ->field(
                'o.*,x.code AS asset_code,x.display_decimals,a.ad_no,a.terms,' .
                'm.user_id AS merchant_user_id,m.display_name AS merchant_name,m.merchant_no,' .
                'mu.uid AS merchant_uid,tu.uid AS taker_uid,tu.nickname AS taker_nickname'
            )
            ->lock(true)
            ->find();
        if (!$row) {
            throw new C2cException('C2C 订单不存在', 404, 'C2C_ORDER_NOT_FOUND');
        }
        return $row;
    }

    private function orderSummaryDto(array $row, int $userId, int $accountId): array
    {
        $isMerchant = (int) $row['merchant_user_id'] === $userId;
        $isTaker = (int) $row['taker_user_id'] === $userId;
        $isCryptoSeller = (int) $row['crypto_seller_account_id'] === $accountId;
        $isCryptoBuyer = (int) $row['crypto_buyer_account_id'] === $accountId;
        $isFiatPayer = $this->isFiatPayer($row, $userId);
        return [
            'order_no' => (string) $row['order_no'],
            'ad_no' => isset($row['ad_no']) ? (string) $row['ad_no'] : null,
            'ad_side' => (int) $row['ad_side'],
            'asset' => (string) $row['asset_code'],
            'display_decimals' => (int) $row['display_decimals'],
            'price' => Decimal18::trim((string) $row['price'], 8),
            'crypto_amount' => Decimal18::trim((string) $row['crypto_amount'], (int) $row['display_decimals']),
            'fiat_amount' => $this->trimFiat((string) $row['fiat_amount']),
            'fiat_currency' => (string) $row['fiat_currency'],
            'payment_type' => (int) $row['payment_type'],
            'payment_type_label' => $this->paymentTypeLabel((int) $row['payment_type']),
            'status' => (int) $row['status'],
            'status_label' => $this->orderStatusLabel((int) $row['status']),
            'merchant' => [
                'merchant_no' => (string) $row['merchant_no'],
                'name' => (string) ($row['merchant_name'] ?: ('商家' . substr((string) $row['merchant_uid'], -4))),
            ],
            'taker' => [
                'uid' => (string) $row['taker_uid'],
                'name' => (string) ($row['taker_nickname'] ?: ('用户' . substr((string) $row['taker_uid'], -4))),
            ],
            'role' => [
                'is_merchant' => $isMerchant,
                'is_taker' => $isTaker,
                'is_crypto_seller' => $isCryptoSeller,
                'is_crypto_buyer' => $isCryptoBuyer,
                'is_fiat_payer' => $isFiatPayer,
                'is_fiat_receiver' => !$isFiatPayer,
            ],
            'actions' => [
                'can_mark_paid' => (int) $row['status'] === self::ORDER_WAITING_PAYMENT && $isFiatPayer,
                'can_cancel' => (int) $row['status'] === self::ORDER_WAITING_PAYMENT,
                'can_confirm_receipt' => (int) $row['status'] === self::ORDER_PAID && $isCryptoSeller,
                'can_appeal' => in_array((int) $row['status'], [self::ORDER_PAID, self::ORDER_APPEAL], true),
            ],
            'expires_at' => $row['expires_at'],
            'paid_at' => $row['paid_at'],
            'released_at' => $row['released_at'],
            'completed_at' => $row['completed_at'],
            'created_at' => $row['created_at'],
        ];
    }

    private function isFiatPayer(array $row, int $userId): bool
    {
        // 商家卖币：Taker 是法币付款方；商家买币：商家是法币付款方。
        return (int) $row['ad_side'] === self::AD_SELL
            ? (int) $row['taker_user_id'] === $userId
            : (int) $row['merchant_user_id'] === $userId;
    }

    private function assertOrderParty(array $row, int $userId, int $accountId): void
    {
        if ((int) $row['merchant_user_id'] !== $userId
            && (int) $row['taker_user_id'] !== $userId
            && (int) $row['crypto_seller_account_id'] !== $accountId
            && (int) $row['crypto_buyer_account_id'] !== $accountId) {
            throw new C2cException('无权查看该 C2C 订单', 403, 'C2C_ORDER_FORBIDDEN');
        }
    }

    private function paymentSnapshot(array $payment): array
    {
        $accountName = Crypto::decryptSensitive((string) $payment['account_name_ciphertext'], 'c2c-payment-account-name');
        $accountNo = !empty($payment['account_no_ciphertext'])
            ? Crypto::decryptSensitive((string) $payment['account_no_ciphertext'], 'c2c-payment-account-no')
            : '';
        $type = (int) $payment['method_type'];
        return [
            'payment_no' => (string) $payment['payment_no'],
            'method_type' => $type,
            'method_label' => $this->paymentTypeLabel($type),
            'account_name' => $accountName,
            'account_no' => $accountNo,
            'bank_name' => (string) ($payment['bank_name'] ?? ''),
            'bank_branch' => (string) ($payment['bank_branch'] ?? ''),
            'qr_storage_key' => (string) ($payment['qr_storage_key'] ?? ''),
            'masked_summary' => $this->paymentTypeLabel($type) . ($accountNo !== '' ? ' · ' . $this->maskAccountNo($accountNo) : ''),
        ];
    }

    private function decryptPaymentSnapshot(string $ciphertext): array
    {
        $raw = Crypto::decryptSensitive($ciphertext, 'c2c-order-payment-snapshot');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new C2cException('订单收款快照损坏', 500, 'C2C_PAYMENT_SNAPSHOT_INVALID');
        }
        return $data;
    }

    private function merchantStats(array $merchantIds): array
    {
        if (!$merchantIds) {
            return [];
        }
        $rows = Db::table('cex_c2c_orders')
            ->whereIn('merchant_id', $merchantIds)
            ->group('merchant_id')
            ->field(
                'merchant_id,' .
                'SUM(CASE WHEN status=4 THEN 1 ELSE 0 END) AS completed,' .
                'SUM(CASE WHEN status IN (4,5,6) THEN 1 ELSE 0 END) AS terminal_count'
            )
            ->select()
            ->toArray();
        $out = [];
        foreach ($rows as $row) {
            $completed = (int) $row['completed'];
            $terminal = (int) $row['terminal_count'];
            $rate = $terminal > 0 ? number_format(($completed / $terminal) * 100, 2, '.', '') : '100.00';
            $out[(int) $row['merchant_id']] = [
                'completed' => $completed,
                'completion_rate' => $rate,
            ];
        }
        return $out;
    }

    private function adsWithPayments(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $ids = array_map('intval', array_column($rows, 'id'));
        $paymentRows = Db::table('cex_c2c_ad_payment_types')->whereIn('ad_id', $ids)->field('ad_id,payment_type')->select()->toArray();
        $byAd = [];
        foreach ($paymentRows as $paymentRow) {
            $byAd[(int) $paymentRow['ad_id']][] = (int) $paymentRow['payment_type'];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'ad_no' => (string) $row['ad_no'],
                'side' => (int) $row['side'],
                'side_label' => (int) $row['side'] === self::AD_BUY ? '商家买币' : '商家卖币',
                'asset' => (string) $row['asset_code'],
                'price' => Decimal18::trim((string) $row['price'], 8),
                'total_crypto_amount' => Decimal18::trim((string) $row['total_crypto_amount'], (int) $row['display_decimals']),
                'remaining_crypto_amount' => Decimal18::trim((string) $row['remaining_crypto_amount'], (int) $row['display_decimals']),
                'min_fiat_amount' => $this->trimFiat((string) $row['min_fiat_amount']),
                'max_fiat_amount' => $this->trimFiat((string) $row['max_fiat_amount']),
                'payment_window_minutes' => (int) $row['payment_window_minutes'],
                'payment_types' => array_map(function (int $type) {
                    return ['code' => $type, 'label' => $this->paymentTypeLabel($type)];
                }, $byAd[(int) $row['id']] ?? []),
                'terms' => (string) ($row['terms'] ?? ''),
                'status' => (int) $row['status'],
                'status_label' => $this->adStatusLabel((int) $row['status']),
                'created_at' => $row['created_at'],
            ];
        }
        return $out;
    }

    private function merchantDto(array $merchant): array
    {
        return [
            'merchant_no' => (string) $merchant['merchant_no'],
            'display_name' => (string) $merchant['display_name'],
            'status' => (int) $merchant['status'],
            'status_label' => $this->merchantStatusLabel((int) $merchant['status']),
            'deposit_asset_id' => (int) $merchant['deposit_asset_id'],
            'deposit_amount' => Decimal18::trim((string) $merchant['deposit_amount'], 8),
            'activated_at' => $merchant['activated_at'],
            'created_at' => $merchant['created_at'],
        ];
    }

    private function paymentMethodDto(array $row): array
    {
        return [
            'payment_no' => (string) $row['payment_no'],
            'method_type' => (int) $row['method_type'],
            'method_label' => $this->paymentTypeLabel((int) $row['method_type']),
            'account_no_masked' => (string) ($row['account_no_masked'] ?? ''),
            'bank_name' => (string) ($row['bank_name'] ?? ''),
            'bank_branch' => (string) ($row['bank_branch'] ?? ''),
            'has_qr' => !empty($row['qr_storage_key']),
            'qr_url' => !empty($row['qr_storage_key']) ? '/api/c2c/payment-methods/' . rawurlencode((string) $row['payment_no']) . '/qr' : null,
            'status' => (int) $row['status'],
            'status_label' => (int) $row['status'] === 1 ? '启用' : ((int) $row['status'] === 2 ? '停用' : '已删除'),
            'created_at' => $row['created_at'],
        ];
    }

    private function paymentMethodsForUser(int $userId): array
    {
        return Db::table('cex_c2c_payment_methods')
            ->where('user_id', $userId)
            ->where('status', '<>', 3)
            ->field('id,payment_no,user_id,method_type,account_no_masked,bank_name,bank_branch,qr_storage_key,status,created_at,updated_at')
            ->order('id', 'desc')
            ->select()
            ->toArray();
    }

    private function merchantForUser(int $userId): ?array
    {
        $row = Db::table('cex_c2c_merchants')->where('user_id', $userId)->find();
        return $row ?: null;
    }

    private function requireActiveMerchant(): array
    {
        $auth = $this->authContext();
        $merchant = $this->merchantForUser((int) $auth['user_id']);
        if (!$merchant || (int) $merchant['status'] !== self::MERCHANT_ACTIVE) {
            throw new C2cException('请先缴纳 C2C 商家保证金并激活商家资格', 403, 'C2C_MERCHANT_REQUIRED');
        }
        return $merchant;
    }

    private function approvedKyc(int $userId): array
    {
        $row = Db::table('cex_user_kyc')
            ->where('user_id', $userId)
            ->where('status', 3)
            ->where('kyc_level', '>=', 1)
            ->whereNotNull('real_name_ciphertext')
            ->order('id', 'desc')
            ->find();
        if (!$row) {
            throw new C2cException('C2C 交易需要先完成实名认证', 403, 'C2C_KYC_REQUIRED');
        }
        return $row;
    }

    private function authContext(): array
    {
        if ($this->authContext !== null) {
            return $this->authContext;
        }
        if (!$this->request) {
            throw new C2cException('当前操作需要登录', 401, 'C2C_AUTH_REQUIRED');
        }
        $auth = new AuthService($this->request);
        $cookie = (string) $this->request->cookie($auth->cookieName(), '');
        $this->authContext = $auth->authenticatedSession($cookie, true);
        return $this->authContext;
    }

    private function optionalAuthContext(): ?array
    {
        try {
            return $this->authContext();
        } catch (AuthException $e) {
            return null;
        }
    }

    private function businessAccount(): array
    {
        if ($this->businessAccount !== null) {
            return $this->businessAccount;
        }
        $auth = $this->authContext();
        $userId = (int) $auth['user_id'];
        $row = Db::table('cex_account_accounts')
            ->where('user_id', $userId)
            ->where('account_kind', 1)
            ->field('id,public_id,status,user_id')
            ->find();
        if (!$row) {
            BusinessAccountService::createForUser($userId, (string) $auth['uid']);
            $row = Db::table('cex_account_accounts')
                ->where('user_id', $userId)
                ->where('account_kind', 1)
                ->field('id,public_id,status,user_id')
                ->find();
        }
        if (!$row || (int) $row['status'] !== 1) {
            throw new C2cException('资产账户当前不可用', 409, 'C2C_ACCOUNT_UNAVAILABLE');
        }
        $this->businessAccount = $row;
        return $row;
    }

    private function systemAccount(string $systemCode): array
    {
        $row = Db::table('cex_account_accounts')
            ->where('account_kind', 2)
            ->where('system_code', $systemCode)
            ->where('status', 1)
            ->field('id,public_id,system_code,status')
            ->find();
        if (!$row) {
            throw new C2cException('C2C 系统清算账户缺失：' . $systemCode, 500, 'C2C_SYSTEM_ACCOUNT_MISSING');
        }
        return $row;
    }

    private function assetByCode(string $code): array
    {
        $row = Db::table('cex_asset_assets')
            ->where('code', strtoupper($code))
            ->where('status', 1)
            ->field('id,code,name,display_decimals,ledger_decimals,spot_enabled')
            ->find();
        if (!$row) {
            throw new C2cException('币种当前不可用：' . strtoupper($code), 409, 'C2C_ASSET_UNAVAILABLE');
        }
        return $row;
    }

    private function supportedAssets(): array
    {
        $codes = $this->supportedAssetCodes();
        $rows = Db::table('cex_asset_assets')
            ->whereIn('code', $codes)
            ->where('status', 1)
            ->field('id,code,name,display_decimals')
            ->select()
            ->toArray();
        $byCode = [];
        foreach ($rows as $row) {
            $byCode[(string) $row['code']] = [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'display_decimals' => (int) $row['display_decimals'],
            ];
        }
        $out = [];
        foreach ($codes as $code) {
            if (isset($byCode[$code])) {
                $out[] = $byCode[$code];
            }
        }
        return $out;
    }

    private function supportedAssetCodes(): array
    {
        $codes = array_map('strtoupper', (array) config('c2c.supported_assets', ['BTC', 'USDT', 'ETH']));
        $codes = array_values(array_unique(array_filter($codes, function ($v) {
            return in_array($v, ['BTC', 'USDT', 'ETH'], true);
        })));
        return $codes ?: ['BTC', 'USDT', 'ETH'];
    }

    private function merchantDepositConfig(): array
    {
        return [
            'asset' => strtoupper((string) config('c2c.merchant_deposit.asset', 'USDT')),
            'amount' => Decimal18::trim((string) config('c2c.merchant_deposit.amount', '1000'), 8),
        ];
    }

    private function paymentTypeOptions(): array
    {
        return [
            ['code' => self::PAYMENT_ALIPAY, 'label' => '支付宝'],
            ['code' => self::PAYMENT_WECHAT, 'label' => '微信支付'],
            ['code' => self::PAYMENT_BANK, 'label' => '银行卡'],
        ];
    }

    private function paymentTypeLabel(int $type): string
    {
        return $type === self::PAYMENT_ALIPAY ? '支付宝'
            : ($type === self::PAYMENT_WECHAT ? '微信支付'
                : ($type === self::PAYMENT_BANK ? '银行卡' : '未知方式'));
    }

    private function merchantStatusLabel(int $status): string
    {
        $map = [
            self::MERCHANT_PENDING_DEPOSIT => '待缴保证金',
            self::MERCHANT_ACTIVE => '已激活',
            self::MERCHANT_SUSPENDED => '已暂停',
            self::MERCHANT_EXITING => '退出处理中',
            self::MERCHANT_CLOSED => '已关闭',
        ];
        return $map[$status] ?? '未知状态';
    }

    private function adStatusLabel(int $status): string
    {
        $map = [
            self::AD_ONLINE => '展示中',
            self::AD_OFFLINE => '已下架',
            self::AD_EXHAUSTED => '已售罄',
            self::AD_CANCELLED => '已取消',
        ];
        return $map[$status] ?? '未知状态';
    }

    private function orderStatusLabel(int $status): string
    {
        $map = [
            self::ORDER_WAITING_PAYMENT => '等待付款',
            self::ORDER_PAID => '已付款，等待放币',
            self::ORDER_APPEAL => '申诉中',
            self::ORDER_COMPLETED => '已完成',
            self::ORDER_CANCELLED => '已取消',
            self::ORDER_EXPIRED => '超时取消',
        ];
        return $map[$status] ?? '未知状态';
    }

    private function parsePaymentType($value): int
    {
        $type = (int) $value;
        if (!in_array($type, [self::PAYMENT_ALIPAY, self::PAYMENT_WECHAT, self::PAYMENT_BANK], true)) {
            throw new C2cException('付款方式无效', 422, 'C2C_PAYMENT_TYPE_INVALID');
        }
        return $type;
    }

    private function parseOptionalPaymentType($value): ?int
    {
        if ($value === null || $value === '' || (int) $value === 0) {
            return null;
        }
        return $this->parsePaymentType($value);
    }

    private function paymentTypesFromPayload($value): array
    {
        if (is_string($value)) {
            $value = array_filter(array_map('trim', explode(',', $value)));
        }
        if (!is_array($value)) {
            return [];
        }
        $types = [];
        foreach ($value as $item) {
            $types[] = $this->parsePaymentType($item);
        }
        return array_values(array_unique($types));
    }

    private function fiatAmount(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^\d{1,20}(?:\.\d{1,2})?$/', $value)) {
            throw new C2cException('法币金额最多支持 2 位小数', 422, 'C2C_FIAT_AMOUNT_INVALID');
        }
        return Decimal18::positive($value);
    }

    private function trimFiat(string $value): string
    {
        return Decimal18::trim($value, 2);
    }

    private function decimalMul(string $left, string $right, int $scale): string
    {
        $scale = max(0, min(18, $scale));
        $rows = Db::query(
            'SELECT CAST(ROUND(CAST(? AS DECIMAL(38,18)) * CAST(? AS DECIMAL(38,18)), ?) AS DECIMAL(38,18)) AS v',
            [Decimal18::normalize($left), Decimal18::normalize($right), $scale]
        );
        if (!isset($rows[0]['v'])) {
            throw new C2cException('金额计算失败', 500, 'C2C_DECIMAL_MATH_FAILED');
        }
        return Decimal18::normalize((string) $rows[0]['v']);
    }

    private function decimalDivTruncate(string $left, string $right, int $scale): string
    {
        $scale = max(0, min(18, $scale));
        if (Decimal18::compare($right, '0') <= 0) {
            throw new C2cException('价格必须大于 0', 422, 'C2C_PRICE_INVALID');
        }
        $rows = Db::query(
            'SELECT CAST(TRUNCATE(CAST(? AS DECIMAL(38,18)) / CAST(? AS DECIMAL(38,18)), ?) AS DECIMAL(38,18)) AS v',
            [Decimal18::normalize($left), Decimal18::normalize($right), $scale]
        );
        if (!isset($rows[0]['v'])) {
            throw new C2cException('金额计算失败', 500, 'C2C_DECIMAL_MATH_FAILED');
        }
        return Decimal18::normalize((string) $rows[0]['v']);
    }

    private function normalizePersonName(string $name): string
    {
        $name = preg_replace('/\s+/u', '', trim($name));
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    private function maskAccountNo(string $value): string
    {
        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length <= 4) {
            return str_repeat('*', max(1, $length));
        }
        $tail = function_exists('mb_substr') ? mb_substr($value, -4) : substr($value, -4);
        return str_repeat('*', min(12, $length - 4)) . $tail;
    }

    private function publicNo(string $value, string $label): string
    {
        $value = strtoupper(trim($value));
        if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value)) {
            throw new C2cException($label . '编号无效', 422, 'C2C_PUBLIC_NO_INVALID');
        }
        return $value;
    }

    private function audit(string $action, int $userId, string $resourceType, string $resourceId, array $metadata = []): void
    {
        if (!$this->request) {
            return;
        }
        AuditLog::record($this->request, $action, $userId, 1, $resourceType, $resourceId, $metadata);
    }
}
