<?php

namespace app\service\Perp;

use app\service\Asset\AssetException;
use app\service\Asset\Decimal18;

/**
 * Pure read-model calculator for historical perpetual order profit.
 *
 * It never writes trading tables. The authoritative inputs are committed rows
 * from cex_perp_fills: realized_pnl, fee_amount and before/after net position.
 * Opening/add fees are carried in-memory and allocated pro-rata when exposure
 * is reduced. A reversing fill charges only its close-side fee to the old
 * position; the remainder becomes the opening-fee pool for the new direction.
 */
final class PerpOrderProfitCalculator
{
    /**
     * @param array<int,array<string,mixed>> $fills ascending by fill id
     * @return array<int,array<string,mixed>> keyed by order_id
     */
    public function calculate(array $fills): array
    {
        $openFeePool = Decimal18::zero();
        $results = [];

        foreach ($fills as $fill) {
            $orderId = (int) ($fill['order_id'] ?? 0);
            if ($orderId <= 0) continue;

            $before = Decimal18::normalize((string) ($fill['position_quantity_before'] ?? '0'));
            $after = Decimal18::normalize((string) ($fill['position_quantity_after'] ?? '0'));
            $fillQty = $this->abs((string) ($fill['quantity'] ?? '0'));
            $fee = Decimal18::normalize((string) ($fill['fee_amount'] ?? '0'));
            $realized = Decimal18::normalize((string) ($fill['realized_pnl'] ?? '0'));

            $beforeSign = Decimal18::compare($before, '0');
            $afterSign = Decimal18::compare($after, '0');
            $absBefore = $this->abs($before);
            $absAfter = $this->abs($after);

            $piece = [
                'role' => 'UNKNOWN',
                'order_profit' => null,
                'realized_pnl' => Decimal18::zero(),
                'allocated_open_fee' => Decimal18::zero(),
                'close_fee_amount' => Decimal18::zero(),
                'flip_close_quantity' => null,
                'flip_open_quantity' => null,
                'position_quantity_before' => $before,
                'position_quantity_after' => $after,
            ];

            if ($beforeSign === 0 && $afterSign !== 0) {
                // Fresh exposure. The whole fill fee belongs to the new position.
                $openFeePool = Decimal18::add($openFeePool, $fee);
                $piece['role'] = 'OPEN';
            } elseif ($beforeSign !== 0 && $afterSign === $beforeSign) {
                $absCmp = Decimal18::compare($absAfter, $absBefore);
                if ($absCmp > 0) {
                    // Same-direction add.
                    $openFeePool = Decimal18::add($openFeePool, $fee);
                    $piece['role'] = 'ADD';
                } elseif ($absCmp < 0) {
                    // Partial close. Allocate the still-unallocated opening fees
                    // according to the fraction of the pre-fill exposure closed.
                    $closeQty = Decimal18::subtract($absBefore, $absAfter);
                    $allocated = $this->proRata($openFeePool, $closeQty, $absBefore);
                    $profit = Decimal18::subtract(Decimal18::subtract($realized, $allocated), $fee);
                    $openFeePool = Decimal18::subtract($openFeePool, $allocated);

                    $piece['role'] = 'REDUCE';
                    $piece['order_profit'] = $profit;
                    $piece['realized_pnl'] = $realized;
                    $piece['allocated_open_fee'] = $allocated;
                    $piece['close_fee_amount'] = $fee;
                } else {
                    // Defensive fallback; no exposure change should not occur for
                    // a committed fill, but do not invent profit if it ever does.
                    $piece['role'] = 'UNCHANGED';
                }
            } elseif ($beforeSign !== 0 && $afterSign === 0) {
                // Full close: consume every remaining opening fee to guarantee
                // exact conservation and avoid a decimal tail.
                $allocated = $openFeePool;
                $profit = Decimal18::subtract(Decimal18::subtract($realized, $allocated), $fee);
                $openFeePool = Decimal18::zero();

                $piece['role'] = 'CLOSE';
                $piece['order_profit'] = $profit;
                $piece['realized_pnl'] = $realized;
                $piece['allocated_open_fee'] = $allocated;
                $piece['close_fee_amount'] = $fee;
            } elseif ($beforeSign !== 0 && $afterSign !== 0 && $beforeSign !== $afterSign) {
                // Reversal. The committed fill is logically split only for this
                // read-model calculation: close old exposure, then open new side.
                $closeQty = $absBefore;
                $openQty = $absAfter;
                $closeFee = $this->proRata($fee, $closeQty, $fillQty);
                $newOpenFee = Decimal18::subtract($fee, $closeFee);
                $allocated = $openFeePool;
                $profit = Decimal18::subtract(Decimal18::subtract($realized, $allocated), $closeFee);
                $openFeePool = $newOpenFee;

                $piece['role'] = 'FLIP';
                $piece['order_profit'] = $profit;
                $piece['realized_pnl'] = $realized;
                $piece['allocated_open_fee'] = $allocated;
                $piece['close_fee_amount'] = $closeFee;
                $piece['flip_close_quantity'] = $closeQty;
                $piece['flip_open_quantity'] = $openQty;
            }

            $this->mergeOrderResult($results, $orderId, $piece);
        }

        return $results;
    }

    /** @param array<int,array<string,mixed>> $results */
    private function mergeOrderResult(array &$results, int $orderId, array $piece): void
    {
        if (!isset($results[$orderId])) {
            $results[$orderId] = $piece;
            return;
        }

        $current = $results[$orderId];
        $current['role'] = $this->strongerRole((string) $current['role'], (string) $piece['role']);
        $current['realized_pnl'] = Decimal18::add((string) $current['realized_pnl'], (string) $piece['realized_pnl']);
        $current['allocated_open_fee'] = Decimal18::add((string) $current['allocated_open_fee'], (string) $piece['allocated_open_fee']);
        $current['close_fee_amount'] = Decimal18::add((string) $current['close_fee_amount'], (string) $piece['close_fee_amount']);
        $current['position_quantity_after'] = $piece['position_quantity_after'];

        if ($piece['order_profit'] !== null) {
            $current['order_profit'] = $current['order_profit'] === null
                ? (string) $piece['order_profit']
                : Decimal18::add((string) $current['order_profit'], (string) $piece['order_profit']);
        }
        if ($piece['flip_close_quantity'] !== null) $current['flip_close_quantity'] = $piece['flip_close_quantity'];
        if ($piece['flip_open_quantity'] !== null) $current['flip_open_quantity'] = $piece['flip_open_quantity'];

        $results[$orderId] = $current;
    }

    private function strongerRole(string $left, string $right): string
    {
        $rank = ['UNKNOWN' => 0, 'UNCHANGED' => 0, 'OPEN' => 1, 'ADD' => 2, 'REDUCE' => 3, 'CLOSE' => 4, 'FLIP' => 5];
        return ($rank[$right] ?? 0) >= ($rank[$left] ?? 0) ? $right : $left;
    }

    private function abs(string $value): string
    {
        $normalized = Decimal18::normalize($value);
        return $normalized[0] === '-' ? substr($normalized, 1) : $normalized;
    }

    /**
     * Exact floor(amount * part / whole) at DECIMAL(38,18) scale.
     * Inputs are non-negative. Any sub-1e-18 remainder stays in the fee pool
     * and is therefore consumed by the final close, preserving total fees.
     */
    private function proRata(string $amount, string $part, string $whole): string
    {
        $amount = $this->abs($amount);
        $part = $this->abs($part);
        $whole = $this->abs($whole);

        if (Decimal18::compare($whole, '0') <= 0) {
            throw new AssetException('合约历史收益分摊分母无效', 500, 'PERP_HISTORY_PROFIT_RATIO_INVALID');
        }
        if (Decimal18::compare($part, '0') <= 0 || Decimal18::compare($amount, '0') === 0) {
            return Decimal18::zero();
        }
        if (Decimal18::compare($part, $whole) >= 0) {
            return Decimal18::normalize($amount);
        }

        $a = $this->scaledDigits($amount);
        $p = $this->scaledDigits($part);
        $w = $this->scaledDigits($whole);
        $product = $this->multiplyDigits($a, $p);
        [$quotient] = $this->divideDigits($product, $w);
        return $this->fromScaledDigits($quotient);
    }

    private function scaledDigits(string $value): string
    {
        $normalized = Decimal18::normalize($value);
        if ($normalized[0] === '-') {
            throw new AssetException('合约历史收益内部数值不能为负', 500, 'PERP_HISTORY_PROFIT_NEGATIVE_RATIO');
        }
        $digits = str_replace('.', '', $normalized);
        $digits = ltrim($digits, '0');
        return $digits === '' ? '0' : $digits;
    }

    private function fromScaledDigits(string $digits): string
    {
        $digits = ltrim($digits, '0');
        $digits = $digits === '' ? '0' : $digits;
        if (strlen($digits) <= 18) $digits = str_pad($digits, 19, '0', STR_PAD_LEFT);
        $integer = substr($digits, 0, -18);
        $fraction = substr($digits, -18);
        return Decimal18::normalize($integer . '.' . $fraction);
    }

    private function multiplyDigits(string $left, string $right): string
    {
        $left = ltrim($left, '0');
        $right = ltrim($right, '0');
        if ($left === '' || $right === '') return '0';

        $out = array_fill(0, strlen($left) + strlen($right), 0);
        for ($i = strlen($left) - 1; $i >= 0; $i--) {
            $a = ord($left[$i]) - 48;
            for ($j = strlen($right) - 1; $j >= 0; $j--) {
                $b = ord($right[$j]) - 48;
                $k = $i + $j + 1;
                $sum = $out[$k] + ($a * $b);
                $out[$k] = $sum % 10;
                $out[$k - 1] += intdiv($sum, 10);
            }
        }
        for ($k = count($out) - 1; $k > 0; $k--) {
            if ($out[$k] >= 10) {
                $carry = intdiv($out[$k], 10);
                $out[$k] %= 10;
                $out[$k - 1] += $carry;
            }
        }
        $result = ltrim(implode('', $out), '0');
        return $result === '' ? '0' : $result;
    }

    /** @return array{0:string,1:string} quotient,remainder */
    private function divideDigits(string $numerator, string $denominator): array
    {
        $numerator = $this->stripDigits($numerator);
        $denominator = $this->stripDigits($denominator);
        if ($denominator === '0') {
            throw new AssetException('合约历史收益内部除数为零', 500, 'PERP_HISTORY_PROFIT_DIV_ZERO');
        }
        if ($this->compareDigits($numerator, $denominator) < 0) return ['0', $numerator];

        $quotient = '';
        $remainder = '0';
        $length = strlen($numerator);
        for ($i = 0; $i < $length; $i++) {
            $remainder = $this->stripDigits(($remainder === '0' ? '' : $remainder) . $numerator[$i]);
            $digit = 0;
            while ($digit < 9 && $this->compareDigits($remainder, $denominator) >= 0) {
                $remainder = $this->subtractDigits($remainder, $denominator);
                $digit++;
            }
            $quotient .= (string) $digit;
        }
        return [$this->stripDigits($quotient), $this->stripDigits($remainder)];
    }

    private function stripDigits(string $digits): string
    {
        $digits = ltrim($digits, '0');
        return $digits === '' ? '0' : $digits;
    }

    private function compareDigits(string $left, string $right): int
    {
        $left = $this->stripDigits($left);
        $right = $this->stripDigits($right);
        if (strlen($left) !== strlen($right)) return strlen($left) < strlen($right) ? -1 : 1;
        $cmp = strcmp($left, $right);
        return $cmp < 0 ? -1 : ($cmp > 0 ? 1 : 0);
    }

    /** left >= right */
    private function subtractDigits(string $left, string $right): string
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
        return $this->stripDigits($out);
    }
}
