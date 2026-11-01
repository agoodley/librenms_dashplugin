<?php

namespace Agoodley\LibrenmsCompliance\Config;

/**
 * A device's configuration as its backup holds it, read in IOS-style blocks: a line at the left margin and the more
 * indented lines under it (Cisco IOS and IOS-XE, NX-OS, Arista EOS, and most configurations written like them). What
 * is no command is left out, so that it can never pass for one: comment lines (! ..., which also holds the header
 * Oxidized writes), the text of banners (IOS and NX-OS: between their delimiters, ^C or a character of their own; EOS:
 * up to EOF), the bodies of IOS macros (up to @), and "end". Each line keeps its number in the backup, as LibreNMS'
 * Config tab shows it.
 */
final class Config
{
    /** Most lines read: past this, a backup is not a configuration the plugin checks. */
    public const MAX_LINES = 200000;

    /** @var Line[] the lines at the left margin, in order */
    public array $top = [];

    /** How many lines the backup has. */
    public int $lines = 0;

    /**
     * @throws \LengthException a backup of more than MAX_LINES lines
     */
    public static function parse(string $text): self
    {
        $config = new self();
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $config->lines = count($lines);
        if ($config->lines > self::MAX_LINES) {
            throw new \LengthException('the backup has ' . number_format($config->lines) . ' lines: more than the ' . number_format(self::MAX_LINES)
                . ' the plugin reads');
        }
        /** @var Line[] $stack the lines a deeper line may belong to, the innermost last */
        $stack = [];
        $until = null; // what ends the banner or macro being skipped
        foreach ($lines as $index => $raw) {
            $raw = rtrim(str_replace("\t", ' ', $raw));
            $text = ltrim($raw);
            if ($until !== null) {
                if (is_string($until) ? str_contains($text, $until) : preg_match($until[0], $text) === 1) {
                    $until = null;
                }
                continue;
            }
            if ($text === '' || $text[0] === '!' || $text === 'end') {
                continue;
            }
            $indent = strlen($raw) - strlen($text);
            if ($indent === 0 && ($end = self::literalEnd($text)) !== null) {
                // the banner command itself is configuration ("banner motd"), its text is not
                $until = $end === '' ? null : $end;
            }
            $line = new Line($index + 1, $text, $indent);
            while ($stack && end($stack)->indent >= $indent) {
                array_pop($stack);
            }
            if ($stack) {
                end($stack)->children[] = $line;
            } else {
                $config->top[] = $line;
            }
            $stack[] = $line;
            if ($until !== null) {
                $stack = [];
            }
        }

        return $config;
    }

    /**
     * What ends the text a line at the left margin starts: a banner's delimiter (a string to find in a later line),
     * EOS' EOF line or an IOS macro's @ line ([regex]); '' for a banner whose text ends on its own line; null for any
     * other line.
     *
     * @return string|array{0: string}|null
     */
    private static function literalEnd(string $text): string|array|null
    {
        if (preg_match('/^macro name \S+$/i', $text) === 1) {
            return ['/^@$/'];
        }
        if (preg_match('/^banner \S+(?: (.*))?$/i', $text, $m) !== 1) {
            return null;
        }
        $rest = $m[1] ?? '';
        if ($rest === '') {
            return ['/^EOF$/']; // EOS: the text follows, up to a line EOF
        }
        // IOS shows ^C (control-C), NX-OS and older IOS the character the admin chose
        $delimiter = str_starts_with($rest, '^C') ? '^C' : mb_substr($rest, 0, 1);

        return str_contains(substr($rest, strlen($delimiter)), $delimiter) ? '' : $delimiter;
    }

    /**
     * The lines at the left margin whose text matches the regular expression.
     *
     * @return Line[]
     */
    public function sections(string $regex): array
    {
        return array_values(array_filter($this->top, fn (Line $line) => preg_match($regex, $line->text) === 1));
    }

    /**
     * The first line at the left margin whose text matches the regular expression, null when none does.
     */
    public function first(string $regex): ?Line
    {
        foreach ($this->top as $line) {
            if (preg_match($regex, $line->text) === 1) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Every line at the left margin whose text matches the regular expression, with the matches: [line, matches].
     *
     * @return array<int, array{0: Line, 1: string[]}>
     */
    public function matches(string $regex): array
    {
        $found = [];
        foreach ($this->top as $line) {
            if (preg_match($regex, $line->text, $m) === 1) {
                $found[] = [$line, $m];
            }
        }

        return $found;
    }
}
