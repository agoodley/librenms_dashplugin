<?php

namespace Agoodley\LibrenmsCompliance\Rules;

use Agoodley\LibrenmsCompliance\Config\Line;
use Agoodley\LibrenmsCompliance\Config\Secrets;

/**
 * How a rule ended on a device (Rule::PASS, FAIL, NA, ERROR): for a failure, what is wrong, the lines that show it (their
 * secrets hidden) and the ports it is about; for a rule that does not apply or could not be checked, why.
 */
final class Outcome
{
    /** Most lines of evidence a failure keeps. */
    public const EVIDENCE = 60;

    /**
     * @param  array<int, array{0: int, 1: string}>  $evidence  [line number, text with its secrets hidden]
     * @param  string[]  $ports  the names of the ports it is about, as the configuration names them
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message = '',
        public readonly array $evidence = [],
        public readonly array $ports = [],
    ) {
    }

    public static function pass(): self
    {
        return new self(Rule::PASS);
    }

    public static function na(string $why): self
    {
        return new self(Rule::NA, $why);
    }

    public static function error(string $why): self
    {
        return new self(Rule::ERROR, $why);
    }

    /**
     * A failure: what is wrong, the lines that show it (a section's lines under it too, when it is given with
     * $sections), the ports it is about.
     *
     * @param  Line[]  $lines
     * @param  string[]  $ports
     */
    public static function fail(string $message, array $lines = [], array $ports = [], bool $sections = false): self
    {
        $evidence = [];
        foreach ($lines as $line) {
            foreach ($sections ? array_merge([$line], $line->descendants()) : [$line] as $shown) {
                if (count($evidence) >= self::EVIDENCE) {
                    break 2;
                }
                $evidence[$shown->number] = [$shown->number, str_repeat(' ', min(8, $shown->indent)) . Secrets::mask($shown->text)];
            }
        }
        ksort($evidence);

        return new self(Rule::FAIL, $message, array_values($evidence), array_values(array_unique($ports)));
    }

    /**
     * Names in a list: "Gi1/0/1, Gi1/0/2 and 7 more".
     *
     * @param  string[]  $names
     */
    public static function list(array $names, int $max = 8): string
    {
        $names = array_values($names);
        $shown = array_slice($names, 0, $max);
        if (count($names) <= $max) {
            return count($shown) > 1 ? implode(', ', array_slice($shown, 0, -1)) . ' and ' . end($shown) : implode('', $shown);
        }

        return implode(', ', $shown) . ' and ' . (count($names) - $max) . ' more';
    }

    /**
     * "1 port", "3 ports".
     */
    public static function count(int $count, string $word, ?string $plural = null): string
    {
        return $count . ' ' . ($count === 1 ? $word : ($plural ?? $word . 's'));
    }
}
