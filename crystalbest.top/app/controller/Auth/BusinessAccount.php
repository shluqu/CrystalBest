<?php

namespace app\controller\Auth;

use think\facade\Db;

final class BusinessAccount
{
    public static function createForNewUser(int $userId, string $uid): void
    {
        Db::table('cex_account_accounts')->insert([
            'public_id' => 'USR_' . $uid,
            'account_kind' => 1,
            'user_id' => $userId,
            'system_code' => null,
            'status' => 1,
            'opened_at' => Clock::now(),
        ]);
    }
}
