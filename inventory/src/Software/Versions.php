<?php

namespace Agoodley\LibrenmsInventory\Software;

/**
 * Software versions as the plugin compares them: their release (LibreNMS writes some versions with a word first: IOS-XE's
 * "CAT9K_IOSXE 17.9.4" is release 17.9.4), number by number, each number as a number (leading zeros ignored), the text
 * between them ignoring case. 15.2(7)E10 is newer than 15.2(7)E8, 17.12.1 than 17.9.4, 17.09.04a is 17.9.4a (Cisco pads
 * IOS-XE's numbers in some outputs and not in others), and a version that goes on is newer (17.9.4a than 17.9.4,
 * 21.4R3-S5 than 21.4R3). Pure PHP.
 */
final class Versions
{
    /**
     * Two versions in order, by their releases: negative when $a is older, 0 when they are the same release, positive when
     * $a is newer.
     */
    public static function compare(string $a, string $b): int
    {
        $left = self::parts(self::release($a));
        $right = self::parts(self::release($b));
        $count = min(count($left), count($right));
        for ($i = 0; $i < $count; $i++) {
            [$x, $y] = [$left[$i], $right[$i]];
            if (ctype_digit($x) && ctype_digit($y)) {
                // as numbers of any length: the longer number is the larger, else the digits decide
                $x = ltrim($x, '0');
                $y = ltrim($y, '0');
                $order = strlen($x) <=> strlen($y) ?: strcmp($x, $y);
            } else {
                $order = strcasecmp($x, $y) <=> 0;
            }
            if ($order !== 0) {
                return $order;
            }
        }

        return count($left) <=> count($right);
    }

    /**
     * The release in a version as LibreNMS has it: its first word that is a number (or a v before one), the v left out;
     * the whole version when no word is. "CAT9K_IOSXE 17.9.4" (IOS-XE, the image's name first) is 17.9.4,
     * "17.9.4a (Build 123)" 17.9.4a, "v7.2.5" 7.2.5; "W.15.08.0012" (ProCurve) and "ML.10.07.0005" (Aruba CX) stay as they
     * are: their letters are their release train.
     */
    public static function release(string $version): string
    {
        $version = trim($version);
        foreach (preg_split('~\s+~', $version) ?: [] as $word) {
            if (preg_match('~^[vV]?(\d.*)$~', $word, $m) === 1) {
                return $m[1];
            }
        }

        return $version;
    }

    /**
     * A version (or a pattern of versions) with the leading zeros of its numbers dropped: 17.09.04a is 17.9.4a. A pattern
     * and a version match after it as before it otherwise.
     */
    public static function normalize(string $version): string
    {
        return (string) preg_replace_callback('~\d+~', fn (array $m) => ltrim($m[0], '0') ?: '0', trim($version));
    }

    /**
     * The numbers and the texts between them.
     *
     * @return string[]
     */
    private static function parts(string $version): array
    {
        return preg_split('~(\d+)~', trim($version), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
