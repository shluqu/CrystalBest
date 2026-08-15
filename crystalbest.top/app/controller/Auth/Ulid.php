<?php

namespace app\controller\Auth;

class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(): string
    {
        $milliseconds = (int) floor(microtime(true) * 1000);
        $time = '';
        for ($i = 0; $i < 10; $i++) {
            $time = self::ALPHABET[$milliseconds % 32] . $time;
            $milliseconds = intdiv($milliseconds, 32);
        }

        $random = random_bytes(10);
        $bits = '';
        for ($i = 0; $i < strlen($random); $i++) {
            $bits .= str_pad(decbin(ord($random[$i])), 8, '0', STR_PAD_LEFT);
        }
        $bits = str_pad($bits, 80, '0', STR_PAD_RIGHT);

        $randomPart = '';
        for ($i = 0; $i < 16; $i++) {
            $chunk = substr($bits, $i * 5, 5);
            $randomPart .= self::ALPHABET[bindec($chunk)];
        }

        return $time . $randomPart;
    }
}
