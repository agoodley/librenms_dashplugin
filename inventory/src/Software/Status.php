<?php

namespace Agoodley\LibrenmsInventory\Software;

/**
 * Where a device's software stands against the policies (Software\Policies): blocked, below the minimum or not approved
 * (not compliant, findings), approved or allowed (compliant), its version unknown, or no policy for it. Pure PHP.
 */
final class Status
{
    public const BLOCKED = 'blocked';

    public const BELOW_MINIMUM = 'below_minimum';

    public const NOT_APPROVED = 'not_approved';

    public const APPROVED = 'approved';

    public const ALLOWED = 'allowed';

    public const UNKNOWN = 'unknown';

    public const NO_POLICY = 'no_policy';

    /** Every status, the worst first: label, severity of a finding (null: none), what it means. */
    public const ALL = [
        self::BLOCKED => ['blocked', 'critical', 'the version is one a policy blocks'],
        self::BELOW_MINIMUM => ['below the minimum', 'warning', 'the version is older than its policy\'s minimum'],
        self::NOT_APPROVED => ['not approved', 'warning', 'the version is none of its policy\'s approved versions'],
        self::APPROVED => ['approved', null, 'the version is one of its policy\'s approved versions'],
        self::ALLOWED => ['allowed', null, 'its policy approves no list of versions, and the version is neither blocked nor below the minimum'],
        self::UNKNOWN => ['version unknown', null, 'a policy applies, but LibreNMS has no software version for the device'],
        self::NO_POLICY => ['no policy', null, 'no policy applies to the device'],
    ];

    /** Filters of the software page that stand for several statuses. */
    public const GROUPS = [
        'not_compliant' => [self::BLOCKED, self::BELOW_MINIMUM, self::NOT_APPROVED],
        'compliant' => [self::APPROVED, self::ALLOWED],
    ];

    public static function label(string $status): string
    {
        return self::ALL[$status][0] ?? $status;
    }

    /**
     * The severity of a finding of this status (critical, warning), null for a status that is none.
     */
    public static function severity(string $status): ?string
    {
        return self::ALL[$status][1] ?? null;
    }

    public static function compliant(string $status): bool
    {
        return in_array($status, self::GROUPS['compliant'], true);
    }

    public static function failing(string $status): bool
    {
        return in_array($status, self::GROUPS['not_compliant'], true);
    }

    /**
     * The statuses a filter of the software page stands for (a status, a group of them), [] for none.
     *
     * @return string[]
     */
    public static function filter(string $filter): array
    {
        return self::GROUPS[$filter] ?? (isset(self::ALL[$filter]) ? [$filter] : []);
    }

    /**
     * Statuses in order, the worst first.
     */
    public static function rank(string $status): int
    {
        $rank = array_search($status, array_keys(self::ALL), true);

        return $rank === false ? count(self::ALL) : $rank;
    }
}
