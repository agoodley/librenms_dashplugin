<?php

namespace Agoodley\LibrenmsTopology\Stp;

use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * The syslog patterns of the loop evidence checks (LoopEvidence::SYSLOG), compiled once from the
 * plugin settings, and the messages to ignore: configuration commands echoed to syslog and
 * recoveries. A message counts for the first check whose pattern matches it; a disabled check
 * keeps its messages, so with BPDU guard switched off the err-disable it caused is not reported as
 * err-disable either. Each pattern is matched on its own, so groups and backreferences work as
 * written. Bare patterns are case-insensitive; a pattern with delimiters and flags, as in
 * LibreNMS' syslog hooks (/udld/i), is used as it is.
 */
class SyslogPatterns
{
    public const IGNORE = 'stp_syslog_ignore';

    /** @var array<string, string> code => regular expression, in matching order (disabled checks included) */
    private array $patterns = [];

    /** @var array<string, true> enabled check codes with a pattern */
    private array $enabled = [];

    private ?string $ignore = null;

    /**
     * The check patterns joined into one, to pass over the many messages that match none of them in
     * one step; null when joining could change what a pattern means.
     */
    private ?string $any = null;

    /** @var array<string, string> setting => what it is for, of the patterns that do not compile */
    private array $invalid = [];

    /** @var array<string, array{title: string, count: int, error: string}> setting => messages its pattern failed on */
    private array $failed = [];

    public function __construct(PluginSettings $settings)
    {
        $disabled = array_fill_keys(array_map('strtolower', $settings->list('stp_disabled_checks')), true);
        $values = $settings->all();
        $sources = [];
        foreach (LoopEvidence::SYSLOG as $code => $check) {
            $enabled = ! isset($disabled[$code]) && ! isset($disabled[LoopEvidence::CHECKS[$code]]);
            $pattern = trim((string) ($values[$check['setting']] ?? ''));
            if ($pattern === '') {
                continue;
            }
            $regex = self::compile($pattern);
            if ($regex === null) {
                if ($enabled) {
                    $this->invalid[$check['setting']] = $check['title'];
                }
                continue;
            }
            $this->patterns[$code] = $regex;
            $sources[] = $pattern;
            if ($enabled) {
                $this->enabled[$code] = true;
            }
        }
        // only bare patterns without references to groups (by number or name), quoting or conditions join safely
        if ($this->enabled && ! preg_grep('{^([/#~@!%]).*\1[a-zA-Z]*$|\\\\[1-9gkQ]|\(\?(?:P[=>]|P?<(?![=!])|[\'&R0-9+-]|\()}s', $sources)) {
            $any = '~(?:' . implode(')|(?:', array_map(fn ($pattern) => substr((string) self::compile($pattern), 1, -2), $sources)) . ')~i';
            $this->any = @preg_match($any, '') === false ? null : $any;
        }

        $ignore = trim((string) ($values[self::IGNORE] ?? ''));
        if ($ignore !== '' && $this->enabled) {
            $this->ignore = self::compile($ignore);
            if ($this->ignore === null) {
                $this->invalid[self::IGNORE] = 'messages to ignore';
            }
        }
    }

    /**
     * Is any syslog check enabled and its pattern usable?
     */
    public function usable(): bool
    {
        return (bool) $this->enabled;
    }

    /**
     * The enabled check a message (program and text) is evidence for; null when it is ignored, matches
     * no pattern or first matches the pattern of a disabled check.
     */
    public function classify(string $text): ?string
    {
        if (! $this->enabled || ($this->any !== null && preg_match($this->any, $text) === 0)) {
            return null;
        }
        if ($this->ignore !== null) {
            $ignored = preg_match($this->ignore, $text);
            if ($ignored === 1) {
                return null;
            }
            if ($ignored === false) {
                $this->failed(self::IGNORE, 'messages to ignore');
            }
        }
        foreach ($this->patterns as $code => $regex) {
            $matched = preg_match($regex, $text);
            if ($matched === 1) {
                return isset($this->enabled[$code]) ? $code : null;
            }
            if ($matched === false) {
                $this->failed(LoopEvidence::SYSLOG[$code]['setting'], LoopEvidence::SYSLOG[$code]['title']);
            }
        }

        return null;
    }

    /**
     * Problems with the patterns, shown on the page: invalid ones, and ones that failed on messages
     * (backtracking limit, invalid UTF-8 with the u flag).
     *
     * @return string[]
     */
    public function notices(): array
    {
        $notices = [];
        foreach ($this->invalid as $setting => $title) {
            $notices[] = "The syslog pattern for $title ($setting) is not a valid regular expression; "
                . ($setting === self::IGNORE ? 'no message is ignored.' : 'that check was skipped.');
        }
        foreach ($this->failed as $setting => $failure) {
            $notices[] = "The syslog pattern for {$failure['title']} ($setting) failed on " . number_format($failure['count'])
                . ($failure['count'] === 1 ? ' message' : ' messages') . " ({$failure['error']}), which it did not match.";
        }

        return $notices;
    }

    /**
     * Regular expression of a pattern setting, null when it does not compile.
     */
    public static function compile(string $pattern): ?string
    {
        $regex = preg_match('{^([/#~@!%]).*\1[a-zA-Z]*$}s', $pattern) === 1
            ? $pattern
            : '~' . preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)~/', '$1\\\\~', $pattern) . '~i'; // escape the delimiter unless it is escaped

        return @preg_match($regex, '') === false ? null : $regex;
    }

    /**
     * Note a message a pattern failed on (it compiled, so the cause is the message: backtracking limit,
     * invalid UTF-8 with the u flag).
     */
    private function failed(string $setting, string $title): void
    {
        $this->failed[$setting] ??= ['title' => $title, 'count' => 0, 'error' => ''];
        $this->failed[$setting]['count']++;
        $this->failed[$setting]['error'] = preg_last_error_msg();
    }
}
