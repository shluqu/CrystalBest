<?php

namespace app\service\Asset;

/**
 * Exact DECIMAL(38,18) arithmetic without float/bcmath dependencies.
 * All ledger mutations use strings; browser-side Number values are display-only.
 */
final class Decimal18
{
    private const SCALE = 18;
    private const MAX_INTEGER_DIGITS = 20;

    public static function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new AssetException('金额不能为空', 422, 'AMOUNT_REQUIRED');
        }
        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $value, $matches)) {
            throw new AssetException('金额格式无效', 422, 'INVALID_AMOUNT');
        }

        $negative = ($matches[1] ?? '') === '-';
        $integer = ltrim((string) $matches[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = (string) ($matches[3] ?? '');

        if (strlen($integer) > self::MAX_INTEGER_DIGITS || strlen($fraction) > self::SCALE) {
            throw new AssetException('金额精度超出系统支持范围', 422, 'AMOUNT_PRECISION_EXCEEDED');
        }

        $fraction = str_pad($fraction, self::SCALE, '0');
        $isZero = $integer === '0' && trim($fraction, '0') === '';
        return ($negative && !$isZero ? '-' : '') . $integer . '.' . $fraction;
    }

    public static function positive(string $value): string
    {
        $normalized = self::normalize($value);
        if (self::compare($normalized, '0') <= 0) {
            throw new AssetException('划转金额必须大于 0', 422, 'AMOUNT_MUST_BE_POSITIVE');
        }
        return $normalized;
    }

    public static function add(string $left, string $right): string
    {
        [$leftSign, $leftDigits] = self::parts($left);
        [$rightSign, $rightDigits] = self::parts($right);

        if ($leftSign === $rightSign) {
            $digits = self::addDigits($leftDigits, $rightDigits);
            return self::fromDigits($leftSign, $digits);
        }

        $cmp = self::compareDigits($leftDigits, $rightDigits);
        if ($cmp === 0) {
            return self::zero();
        }
        if ($cmp > 0) {
            return self::fromDigits($leftSign, self::subtractDigits($leftDigits, $rightDigits));
        }
        return self::fromDigits($rightSign, self::subtractDigits($rightDigits, $leftDigits));
    }

    public static function subtract(string $left, string $right): string
    {
        $right = self::normalize($right);
        return self::add($left, $right[0] === '-' ? substr($right, 1) : '-' . $right);
    }

    public static function compare(string $left, string $right): int
    {
        [$leftSign, $leftDigits] = self::parts($left);
        [$rightSign, $rightDigits] = self::parts($right);

        if ($leftSign !== $rightSign) {
            return $leftSign < $rightSign ? -1 : 1;
        }
        $cmp = self::compareDigits($leftDigits, $rightDigits);
        return $leftSign < 0 ? -$cmp : $cmp;
    }

    public static function trim(string $value, ?int $maxDecimals = null): string
    {
        $normalized = self::normalize($value);
        $negative = $normalized[0] === '-';
        if ($negative) {
            $normalized = substr($normalized, 1);
        }
        [$integer, $fraction] = explode('.', $normalized, 2);
        if ($maxDecimals !== null) {
            $fraction = substr($fraction, 0, max(0, min(self::SCALE, $maxDecimals)));
        }
        $fraction = rtrim($fraction, '0');
        $result = $integer . ($fraction !== '' ? '.' . $fraction : '');
        return $negative && $result !== '0' ? '-' . $result : $result;
    }

    public static function zero(): string
    {
        return '0.000000000000000000';
    }

    private static function parts(string $value): array
    {
        $normalized = self::normalize($value);
        $sign = 1;
        if ($normalized[0] === '-') {
            $sign = -1;
            $normalized = substr($normalized, 1);
        }
        [$integer, $fraction] = explode('.', $normalized, 2);
        $digits = ltrim($integer . $fraction, '0');
        return [$sign, $digits === '' ? '0' : $digits];
    }

    private static function fromDigits(int $sign, string $digits): string
    {
        $digits = ltrim($digits, '0');
        $digits = $digits === '' ? '0' : $digits;
        if (strlen($digits) <= self::SCALE) {
            $digits = str_pad($digits, self::SCALE + 1, '0', STR_PAD_LEFT);
        }
        $integer = substr($digits, 0, -self::SCALE);
        $fraction = substr($digits, -self::SCALE);
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        if (strlen($integer) > self::MAX_INTEGER_DIGITS) {
            throw new AssetException('账本金额超出 DECIMAL(38,18) 范围', 500, 'LEDGER_DECIMAL_OVERFLOW');
        }
        $isZero = $integer === '0' && trim($fraction, '0') === '';
        return ($sign < 0 && !$isZero ? '-' : '') . $integer . '.' . $fraction;
    }

    private static function compareDigits(string $left, string $right): int
    {
        $left = ltrim($left, '0');
        $right = ltrim($right, '0');
        $left = $left === '' ? '0' : $left;
        $right = $right === '' ? '0' : $right;
        if (strlen($left) !== strlen($right)) {
            return strlen($left) < strlen($right) ? -1 : 1;
        }
        $cmp = strcmp($left, $right);
        return $cmp < 0 ? -1 : ($cmp > 0 ? 1 : 0);
    }

    private static function addDigits(string $left, string $right): string
    {
        $i = strlen($left) - 1;
        $j = strlen($right) - 1;
        $carry = 0;
        $out = '';
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = $carry;
            if ($i >= 0) $sum += ord($left[$i--]) - 48;
            if ($j >= 0) $sum += ord($right[$j--]) - 48;
            $out = chr(48 + ($sum % 10)) . $out;
            $carry = intdiv($sum, 10);
        }
        return $out;
    }

    /** left >= right */
    private static function subtractDigits(string $left, string $right): string
    {
        $i = strlen($left) - 1;
        $j = strlen($right) - 1;
        $borrow = 0;
        $out = '';
        while ($i >= 0) {
            $digit = (ord($left[$i--]) - 48) - $borrow;
            $sub = $j >= 0 ? ord($right[$j--]) - 48 : 0;
            if ($digit < $sub) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $out = chr(48 + ($digit - $sub)) . $out;
        }
        $out = ltrim($out, '0');
        return $out === '' ? '0' : $out;
    }
}
