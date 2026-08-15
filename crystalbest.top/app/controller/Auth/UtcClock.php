<?php

namespace app\controller\Auth;

final class UtcClock
{
    private const FORMAT = 'Y-m-d H:i:s.u';

    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(self::FORMAT);
    }

    public static function afterSeconds(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $seconds . ' seconds')
            ->format(self::FORMAT);
    }

    public static function label(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s') . ' UTC';
    }
}
