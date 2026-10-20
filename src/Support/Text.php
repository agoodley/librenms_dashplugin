<?php

namespace Agoodley\LibrenmsTopology\Support;

/**
 * Small text helpers for the pages: counts whose noun agrees with the number.
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
}
