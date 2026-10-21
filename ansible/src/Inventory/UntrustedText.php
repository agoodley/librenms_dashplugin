<?php

namespace Agoodley\LibrenmsAnsible\Inventory;

/**
 * Text discovered from the network (sysName, location, hardware, version, display names, device group
 * names) that goes into the Ansible inventory or a message. A device can report anything there, newlines
 * and commands included, and Ansible templates every string it is given: a value with "{{" in it would
 * run as Jinja in the playbook. So only a small character set is kept.
 */
final class UntrustedText
{
    /** Longest value kept, in characters. */
    public const MAX = 128;

    /**
     * Letters, combining marks and digits of any script, space and . , : _ - / ( ) @ + =. Not kept: the
     * Jinja delimiters ({ } % #), quotes, shell and CLI characters (; | & $ ` \ ! ? * ~ < > [ ] ^ ').
     */
    private const DISALLOWED = '/[^\p{L}\p{M}\p{N} .,:_\-\/()@+=]+/u';

    /** Control and format characters (newlines, tabs, escapes, bidi overrides) and line separators. */
    private const CONTROL = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u';

    /**
     * The value cut down to the safe character set: invalid UTF-8 and control characters become spaces,
     * other characters outside the set are dropped, runs of spaces collapse, at most $max characters.
     */
    public static function clean(mixed $value, int $max = self::MAX): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }
        $text = mb_scrub((string) $value, 'UTF-8');
        $text = (string) preg_replace(self::CONTROL, ' ', $text);
        $text = (string) preg_replace(self::DISALLOWED, '', $text);
        $text = trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', $text));

        return trim(mb_substr($text, 0, max(0, $max)));
    }

    /**
     * Text for a log line or eventlog message (template and user names): control characters removed, at most
     * $max characters. It is not put into the inventory, so other characters are kept; pages escape it.
     */
    public static function line(mixed $value, int $max = 200): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }
        $text = mb_scrub((string) $value, 'UTF-8');
        $text = trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', (string) preg_replace(self::CONTROL, ' ', $text)));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }
}
