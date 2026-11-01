<?php

namespace Agoodley\LibrenmsCompliance\Config;

/**
 * A line of a configuration with the lines indented under it (Config): "interface GigabitEthernet1/0/5" and its
 * " switchport mode access", " spanning-tree portfast"... Its text is the line without the indentation.
 */
final class Line
{
    /** @var Line[] */
    public array $children = [];

    public function __construct(
        public readonly int $number,
        public readonly string $text,
        public readonly int $indent,
    ) {
    }

    /**
     * Every line under this one (its children, theirs...), in the configuration's order.
     *
     * @return Line[]
     */
    public function descendants(): array
    {
        $lines = [];
        foreach ($this->children as $child) {
            $lines[] = $child;
            array_push($lines, ...$child->descendants());
        }

        return $lines;
    }

    /**
     * The first line under this one whose text matches the regular expression, null when none does.
     */
    public function find(string $regex): ?Line
    {
        foreach ($this->descendants() as $line) {
            if (preg_match($regex, $line->text) === 1) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Does a line under this one match the regular expression?
     */
    public function has(string $regex): bool
    {
        return $this->find($regex) !== null;
    }
}
