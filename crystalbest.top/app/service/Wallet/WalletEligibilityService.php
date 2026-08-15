<?php
namespace app\service\Wallet;

use think\facade\Db;

final class WalletEligibilityService
{
    public function forUser(int $userId): array
    {
        $user = Db::table('cex_user_users')
            ->where('id', $userId)
            ->field('id,uid,status,email_verified_at,kyc_level')
            ->find();
        if (!$user) {
            return $this->result(false, 'USER_NOT_FOUND', '用户不存在', [], []);
        }

        $security = Db::table('cex_user_security')
            ->where('user_id', $userId)
            ->field('totp_enabled,totp_verified_at')
            ->find();

        $kyc = Db::table('cex_user_kyc')
            ->where('user_id', $userId)
            ->where('status', 3)
            ->whereNotNull('approved_at')
            ->whereRaw('(expires_at IS NULL OR expires_at > UTC_TIMESTAMP(6))')
            ->field('id,public_id,kyc_level,status,approved_at,expires_at')
            ->order('approved_at', 'desc')
            ->find();

        $account = Db::table('cex_account_accounts')
            ->where('user_id', $userId)
            ->where('account_kind', 1)
            ->field('id,public_id,status')
            ->find();

        $requirements = [
            $this->requirement(
                'USER_NOT_ACTIVE',
                '账户状态',
                (int) $user['status'] === 1,
                '账户需要处于正常状态',
                '/dashboard/profile',
                '查看账户状态'
            ),
            $this->requirement(
                'EMAIL_NOT_VERIFIED',
                '安全邮箱',
                !empty($user['email_verified_at']),
                '请先完成安全邮箱验证',
                '/dashboard/security',
                '去安全中心'
            ),
            $this->requirement(
                'TOTP_REQUIRED',
                'Google Authenticator',
                $security && (bool) $security['totp_enabled'] && !empty($security['totp_verified_at']),
                '请先绑定并验证 Google Authenticator',
                '/dashboard/security',
                '去绑定验证器'
            ),
            $this->requirement(
                'KYC_REQUIRED',
                '实名认证',
                $kyc && (int) $user['kyc_level'] >= 1,
                '请先完成实名认证并等待审核通过',
                '/dashboard/kyc',
                '去实名认证'
            ),
            $this->requirement(
                'ACCOUNT_UNAVAILABLE',
                '资产账户',
                $account && (int) $account['status'] === 1,
                '资产账户当前不可用',
                '/dashboard/assets',
                '查看资产账户'
            ),
        ];

        $missing = array_values(array_filter($requirements, static function (array $item): bool {
            return !(bool) $item['complete'];
        }));

        $bundle = null;
        if ($account && (int) $account['status'] === 1) {
            $bundle = Db::table('cex_wallet_custody_bundles')
                ->where('account_id', (int) $account['id'])
                ->where('status', WalletBundleService::BUNDLE_ACTIVE)
                ->field('id,bundle_no,external_bundle_id,allocated_at')
                ->find();
        }

        if ($missing) {
            $first = $missing[0];
            $labels = array_map(static function (array $item): string {
                return (string) $item['label'];
            }, $missing);
            return $this->result(
                false,
                (string) $first['code'],
                '请先完成：' . implode('、', $labels) . '。满足条件后系统会自动分配充值地址。',
                $requirements,
                $missing,
                [
                    'user_id' => $userId,
                    'uid' => (string) $user['uid'],
                    'account' => $account ?: null,
                    'kyc' => $kyc ? $this->formatKyc($kyc) : null,
                    'bundle' => $bundle ?: null,
                ]
            );
        }

        return [
            'eligible' => true,
            'code' => $bundle ? 'ALREADY_ASSIGNED' : 'ELIGIBLE',
            'message' => $bundle ? '钱包地址已分配' : '已满足条件，系统正在自动分配钱包地址',
            'requirements' => $requirements,
            'missing' => [],
            'user_id' => $userId,
            'uid' => (string) $user['uid'],
            'account' => $account,
            'kyc' => $this->formatKyc($kyc),
            'bundle' => $bundle ?: null,
        ];
    }

    private function requirement(
        string $code,
        string $label,
        bool $complete,
        string $message,
        string $actionUrl,
        string $actionLabel
    ): array {
        return [
            'code' => $code,
            'label' => $label,
            'complete' => $complete,
            'message' => $message,
            'action_url' => $actionUrl,
            'action_label' => $actionLabel,
        ];
    }

    private function formatKyc(array $kyc): array
    {
        return [
            'public_id' => (string) $kyc['public_id'],
            'level' => (int) $kyc['kyc_level'],
            'approved_at' => (string) $kyc['approved_at'],
            'expires_at' => $kyc['expires_at'] !== null ? (string) $kyc['expires_at'] : null,
        ];
    }

    private function result(
        bool $eligible,
        string $code,
        string $message,
        array $requirements,
        array $missing,
        array $extra = []
    ): array {
        return array_merge([
            'eligible' => $eligible,
            'code' => $code,
            'message' => $message,
            'requirements' => $requirements,
            'missing' => $missing,
        ], $extra);
    }
}
