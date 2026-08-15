<?php

namespace app\controller\Auth;

use think\facade\Db;

class PublicUid
{
    // 32-character alphabet: uppercase letters without I/O + digits 2-9.
    // 16 characters => 80 bits of entropy and avoids visually ambiguous characters.
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const LENGTH = 16;

    public static function generate(): string
    {
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $bytes = random_bytes(self::LENGTH);
            $uid = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                // Alphabet length is exactly 32; 256 % 32 = 0, so this is unbiased.
                $uid .= self::ALPHABET[ord($bytes[$i]) & 31];
            }

            $exists = Db::table('cex_user_users')->where('uid', $uid)->field('id')->find();
            if (!$exists) {
                return $uid;
            }
        }

        throw new AuthException('无法生成唯一用户 UID，请稍后重试', 500, 'UID_GENERATION_FAILED');
    }

    public static function isNewFormat(string $uid): bool
    {
        return (bool) preg_match('/^[A-HJ-NP-Z2-9]{16}$/', $uid);
    }
}
