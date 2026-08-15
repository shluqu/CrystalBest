<?php

namespace app\controller\Auth;

final class Clock
{
    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s.u');
    }

    public static function afterSeconds(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $seconds . ' seconds')
            ->format('Y-m-d H:i:s.u');
    }
}
