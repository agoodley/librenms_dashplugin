<?php

namespace Agoodley\LibrenmsInventory\Software;

/**
 * One software policy: the devices it is for (LibreNMS os names, models as patterns) and the versions it approves, the
 * minimum and the versions it blocks (patterns). A pattern takes * for any text and ? for one character, ignoring case;
 * anything else is taken as written. Pure PHP.
 */
final class Policy
{
    /**
     * @param  string[]  $os  LibreNMS os names, in lower case; [] for any os
     * @param  string[]  $models  patterns of models (the device's, or one of its chassis'); [] for any model
     * @param  string[]  $approved  patterns of the versions it approves; [] when it approves no list
     * @param  string[]  $blocked  patterns of the versions it blocks
     */
    public function __construct(
        public readonly string $name,
        public readonly array $os = [],
        public readonly array $models = [],
        public readonly array $approved = [],
        public readonly ?string $minimum = null,
        public readonly array $blocked = [],
        public readonly string $note = '',
    ) {
    }

    /**
     * Is the policy for a device of this os and these models (the device's, each of its chassis')?
     *
     * @param  string[]  $models
     */
    public function applies(string $os, array $models): bool
    {
        if ($this->os !== [] && ! in_array(strtolower(trim($os)), $this->os, true)) {
            return false;
        }
        if ($this->models === []) {
            return true;
        }
        foreach ($models as $model) {
            $model = trim((string) $model);
            if ($model !== '' && self::any($this->models, $model)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is it for some models (more specific than a policy for an os)?
     */
    public function forModels(): bool
    {
        return $this->models !== [];
    }

    /**
     * Does one of these patterns match the whole text?
     *
     * @param  string[]  $patterns
     */
    public static function any(array $patterns, string $text): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does a pattern (* any text, ? one character, ignoring case) match the whole text?
     */
    public static function matches(string $pattern, string $text): bool
    {
        $regex = '';
        foreach (mb_str_split($pattern) as $char) {
            $regex .= match ($char) {
                '*' => '.*',
                '?' => '.',
                default => preg_quote($char, '~'),
            };
        }

        return preg_match('~^' . $regex . '$~iu', trim($text)) === 1;
    }
}
