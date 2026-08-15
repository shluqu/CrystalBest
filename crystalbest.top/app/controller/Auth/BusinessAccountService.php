<?php

namespace app\controller\Auth;

use think\facade\Db;

final class BusinessAccountService
{
    /**
     * Create the one-and-only CEX business account for a user.
     * Call inside the same DB transaction as user creation.
     */
    public static function createForUser(int $userId, string $uid): int
    {
        $existing = Db::table('cex_account_accounts')
            ->where('user_id', $userId)
            ->field('id')
            ->find();
        if ($existing) {
            return (int) $existing['id'];
        }

        if (!PublicUid::isNewFormat($uid)) {
            throw new AuthException('用户 UID 格式无效，无法创建业务账户', 500, 'ACCOUNT_UID_INVALID');
        }

        return (int) Db::table('cex_account_accounts')->insertGetId([
            'public_id' => 'USR_' . $uid,
            'account_kind' => 1,
            'user_id' => $userId,
            'system_code' => null,
            'status' => 1,
            'opened_at' => UtcClock::now(),
        ]);
    }
}
