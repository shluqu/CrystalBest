<?php

namespace app\command;

use app\controller\Auth\BusinessAccountService;
use app\controller\Auth\PublicUid;
use app\controller\Auth\UtcClock;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

class AuthMaintenance extends Command
{
    protected function configure()
    {
        $this->setName('auth:maintenance')
            ->setDescription('Expire stale login sessions and repair missing user business accounts');
    }

    protected function execute(Input $input, Output $output)
    {
        $now = UtcClock::now();
        $expired = Db::table('cex_user_sessions')
            ->where('status', 1)
            ->where('expires_at', '<=', $now)
            ->update(['status' => 3]);

        $missing = Db::table('cex_user_users')->alias('u')
            ->leftJoin('cex_account_accounts a', 'a.user_id = u.id AND a.account_kind = 1')
            ->whereNull('a.id')
            ->field('u.id,u.uid')
            ->select()
            ->toArray();

        $created = 0;
        $skipped = 0;
        foreach ($missing as $user) {
            $uid = (string) $user['uid'];
            if (!PublicUid::isNewFormat($uid)) {
                $skipped++;
                continue;
            }
            Db::transaction(function () use ($user, $uid, &$created) {
                BusinessAccountService::createForUser((int) $user['id'], $uid);
                $created++;
            });
        }

        $output->writeln('UTC now: ' . $now);
        $output->writeln('Expired sessions marked status=3: ' . (int) $expired);
        $output->writeln('Missing user accounts created: ' . $created);
        $output->writeln('Users skipped because UID is not V8/V9 16-char format: ' . $skipped);
        return 0;
    }
}
