<?php

namespace Agoodley\LibrenmsTopology\Support;

/**
 * Small text helpers for the pages: counts whose noun agrees with the number, lists in words.
 */
class Text
{
    /**
     * "1 warning", "2 warnings", "1,242 switches" (the plural defaults to the singular plus "s").
     */
    public static function count(int|float|string|null $count, string $singular, ?string $plural = null): string
    {
        $count = (int) $count;

        return number_format($count) . ' ' . ($count === 1 ? $singular : ($plural ?? $singular . 's'));
    }

    /**
     * "ports", "ports and routes", "ports, routes and syslog".
     *
     * @param  string[]  $items
     */
    public static function join(array $items): string
    {
        $items = array_values($items);
        $last = array_pop($items);

        return $items ? implode(', ', $items) . ' and ' . $last : (string) $last;
    }
}
