<?php

namespace Agoodley\LibrenmsAnsible\Runs;

/**
 * ANSI escape codes in the runner's output (Ansible colours its lines). The output comes from devices and may
 * hold anything: the codes are either stripped or turned into a fixed set of CSS class names, and every other
 * escape sequence and control character is dropped. The text itself is escaped by whoever shows it.
 */
final class Ansi
{
    /** Escape sequences: CSI (colours, cursor moves), OSC (titles, links) and the two-character ones. */
    private const SEQUENCES = '/\x1b\[[0-?]*[ -\/]*[@-~]|\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)?|\x1b[@-Z\\\\-_]|\x9b[0-?]*[ -\/]*[@-~]/u';

    /** Colours with a class: 30-37 and their bright versions 90-97. */
    private const COLOURS = [30 => 'black', 31 => 'red', 32 => 'green', 33 => 'yellow', 34 => 'blue', 35 => 'magenta', 36 => 'cyan', 37 => 'white',
        90 => 'grey', 91 => 'red', 92 => 'green', 93 => 'yellow', 94 => 'blue', 95 => 'magenta', 96 => 'cyan', 97 => 'white'];

    /**
     * The line without escape sequences and control characters (tabs become spaces).
     */
    public static function strip(string $line): string
    {
        return self::plain((string) preg_replace(self::SEQUENCES, '', self::utf8($line)));
    }

    /**
     * The line as runs of text with their CSS class (null for none): "ans-red", "ans-green ans-bold", ...
     *
     * @return array<int, array{0: string|null, 1: string}>
     */
    public static function segments(string $line): array
    {
        $line = self::utf8($line);
        $segments = [];
        $colour = null;
        $bold = false;
        $offset = 0;
        preg_match_all(self::SEQUENCES, $line, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$sequence, $position]) {
            self::add($segments, substr($line, $offset, $position - $offset), $colour, $bold);
            $offset = $position + strlen($sequence);
            if (preg_match('/^\x1b\[([0-9;]*)m$/', $sequence, $m)) {
                $codes = $m[1] === '' ? [0] : array_map('intval', explode(';', $m[1]));
                for ($i = 0; $i < count($codes); $i++) {
                    $code = $codes[$i];
                    if ($code === 38 || $code === 48) {
                        // 256-colour and RGB colours (38;5;n, 38;2;r;g;b): no class, and their numbers are no codes
                        $i += ($codes[$i + 1] ?? null) === 5 ? 2 : (($codes[$i + 1] ?? null) === 2 ? 4 : 0);
                    } elseif ($code === 0) {
                        [$colour, $bold] = [null, false];
                    } elseif ($code === 1) {
                        $bold = true;
                    } elseif ($code === 22) {
                        $bold = false;
                    } elseif ($code === 39) {
                        $colour = null;
                    } elseif (isset(self::COLOURS[$code])) {
                        $colour = self::COLOURS[$code];
                    }
                }
            }
        }
        self::add($segments, substr($line, $offset), $colour, $bold);

        return $segments;
    }

    /**
     * The line as HTML: every run of text escaped, in a span with its colour class (from the fixed set above).
     */
    public static function html(string $line): string
    {
        $html = '';
        foreach (self::segments($line) as [$class, $text]) {
            $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= $class === null ? $text : '<span class="' . $class . '">' . $text . '</span>';
        }

        return $html;
    }

    /**
     * Append a run of text with the current style, merged with the previous run of the same style.
     *
     * @param  array<int, array{0: string|null, 1: string}>  $segments
     */
    private static function add(array &$segments, string $text, ?string $colour, bool $bold): void
    {
        $text = self::plain($text);
        if ($text === '') {
            return;
        }
        $class = trim(($colour !== null ? "ans-$colour" : '') . ($bold ? ' ans-bold' : '')) ?: null;
        $last = count($segments) - 1;
        if ($last >= 0 && $segments[$last][0] === $class) {
            $segments[$last][1] .= $text;
        } else {
            $segments[] = [$class, $text];
        }
    }

    /**
     * Tabs become spaces; other control and format characters (stray escapes, bidi overrides that could make
     * the text read differently than it is) go.
     */
    private static function plain(string $text): string
    {
        return (string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', str_replace("\t", '    ', $text));
    }

    /**
     * Invalid UTF-8 replaced, so the line can be matched and encoded as JSON.
     */
    private static function utf8(string $text): string
    {
        return mb_scrub($text, 'UTF-8');
    }
}
