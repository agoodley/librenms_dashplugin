<?php

namespace Agoodley\LibrenmsCompliance\Rules;

/**
 * A rule a device's configuration is checked against: one of the plugin's (BuiltinRules) or of the admin's
 * (CustomRules), with how much a failure matters, what it checks and how to fix a failure.
 */
final class Rule
{
    public const CRITICAL = 'critical';

    public const WARNING = 'warning';

    public const INFO = 'info';

    /** From the worst. */
    public const SEVERITIES = [self::CRITICAL, self::WARNING, self::INFO];

    /** How a rule ended on a device: passed, failed, not applicable (nothing it checks), or could not be checked. */
    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const NA = 'na';

    public const ERROR = 'error';

    public const STATUSES = [self::FAIL, self::ERROR, self::PASS, self::NA];

    /**
     * @param  array<string, mixed>  $spec  a custom rule's definition, its patterns compiled (CustomRules)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $severity,
        public readonly string $description,
        public readonly string $fix,
        public readonly bool $custom = false,
        public readonly array $spec = [],
    ) {
    }

    /**
     * The rank of a severity, the worst first (0).
     */
    public static function rank(string $severity): int
    {
        $rank = array_search($severity, self::SEVERITIES, true);

        return $rank === false ? count(self::SEVERITIES) : (int) $rank;
    }
}
