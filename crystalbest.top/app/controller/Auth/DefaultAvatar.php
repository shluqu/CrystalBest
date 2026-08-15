<?php

namespace app\controller\Auth;

class DefaultAvatar
{
    private const COUNT = 10;
    private const BASE = '/static/assets/images/avatars/default-';

    public static function randomPath(): string
    {
        return self::path(random_int(1, self::COUNT));
    }

    public static function forUserId(int $userId): string
    {
        $index = (($userId > 0 ? $userId : 1) - 1) % self::COUNT + 1;
        return self::path($index);
    }

    public static function path(int $index): string
    {
        if ($index < 1 || $index > self::COUNT) {
            throw new AuthException('默认头像编号无效', 422, 'INVALID_DEFAULT_AVATAR');
        }
        return self::BASE . str_pad((string) $index, 2, '0', STR_PAD_LEFT) . '.svg';
    }

    public static function all(): array
    {
        $items = [];
        for ($i = 1; $i <= self::COUNT; $i++) {
            $items[] = ['id' => $i, 'url' => self::path($i)];
        }
        return $items;
    }

    public static function isDefault(string $url): bool
    {
        return (bool) preg_match('#^/static/assets/images/avatars/default-(0[1-9]|10)\.svg$#', $url);
    }
}
