<?php

namespace App\Support;

/**
 * Exact money arithmetic. The database and the API carry amounts in taka with two decimals, which a PHP float
 * cannot add or subtract exactly (0.1 + 0.2 is 0.30000000000000004). Every calculation therefore goes through
 * whole paisa (integers, which are exact) and only the result is turned back into taka.
 *
 * Comparing two stored amounts needs nothing from here; adding, subtracting, summing, splitting and taking shares does.
 */
final class Money
{
    /** Taka, as a model attribute, request field or SQL SUM gives it, to whole paisa. */
    public static function paisa(int|float|string|null $taka): int
    {
        return (int) round((float) $taka * 100);
    }

    /** Whole paisa back to taka, for saving and for the API. */
    public static function taka(int $paisa): float
    {
        return $paisa / 100;
    }

    /** The exact total of some taka amounts; pass a negative amount to subtract it. */
    public static function add(int|float|string|null ...$taka): float
    {
        return self::taka(array_sum(array_map(self::paisa(...), $taka)));
    }

    /**
     * The exact total over a list: of each item itself, of one of its fields, or of what a callback returns for it.
     *
     * @param  iterable<mixed>  $items
     */
    public static function sum(iterable $items, string|callable|null $value = null): float
    {
        $total = 0;

        foreach ($items as $item) {
            $total += self::paisa(match (true) {
                $value === null => $item,
                is_string($value) => data_get($item, $value),
                default => $value($item),
            });
        }

        return self::taka($total);
    }

    /** The part of an amount that $part is of $whole (e.g. the principal's share of a payment), to the nearest paisa. */
    public static function share(int $paisa, float $part, float $whole): int
    {
        return $whole == 0 ? 0 : (int) round($paisa * ($part / $whole));
    }

    /** A percentage of an amount, to the nearest paisa. */
    public static function percent(int $paisa, float $percent): int
    {
        return (int) round($paisa * $percent / 100);
    }

    /**
     * An amount split into equal payments, the rounding left over going on the last one, so the payments always add up exactly.
     *
     * @return list<int>
     */
    public static function split(int $paisa, int $parts): array
    {
        $each = (int) round($paisa / $parts);

        return [...array_fill(0, $parts - 1, $each), $paisa - $each * ($parts - 1)];
    }

    /** Paisa as taka for a message, e.g. "1,000.30". */
    public static function format(int $paisa): string
    {
        return number_format(self::taka($paisa), 2);
    }
}
