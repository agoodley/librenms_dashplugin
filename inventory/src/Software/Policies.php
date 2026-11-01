<?php

namespace Agoodley\LibrenmsInventory\Software;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The software policies, as an admin writes them in the settings (YAML: a list of policies), and a device's status
 * against them. Pure PHP.
 *
 * A policy names the devices it is for, by os, by model or both (os: iosxe, model: C9300-*), and approved versions,
 * a minimum version, blocked versions or several of them. A device gets the policy for its model (its own, or one of
 * its chassis': a stack's members), else the policy for its os; of several such, the first. Its version is checked
 * against that policy's approved versions and minimum, and against the blocked versions of every policy for it, so a
 * policy for an os can block versions on every model. Versions are compared and matched by their release (IOS-XE's
 * "CAT9K_IOSXE 17.9.4" is 17.9.4), number by number, leading zeros ignored (Software\Versions).
 */
final class Policies
{
    /** The keys a policy takes. */
    public const KEYS = ['name', 'os', 'model', 'approved', 'minimum', 'blocked', 'note'];

    /** Most policies. */
    public const MAX_POLICIES = 500;

    /** Most patterns in one list of a policy. */
    public const MAX_PATTERNS = 200;

    /** Longest name, pattern or version. */
    public const MAX_TEXT = 100;

    /** Longest note. */
    public const MAX_NOTE = 300;

    /** Most versions a message names. */
    private const NAMED = 5;

    /**
     * The policies of a text, and what is wrong with it (a text with errors gives no policies).
     *
     * @return array{policies: list<Policy>, errors: string[]}
     */
    public static function parse(string $yaml): array
    {
        if (trim($yaml) === '') {
            return ['policies' => [], 'errors' => []];
        }
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            return ['policies' => [], 'errors' => ['The policies are not valid YAML: ' . $e->getMessage()]];
        }
        if (! is_array($data) || ! array_is_list($data)) {
            return ['policies' => [], 'errors' => ['The policies must be a YAML list: each policy starts with "- ".']];
        }
        if (count($data) > self::MAX_POLICIES) {
            return ['policies' => [], 'errors' => ['At most ' . self::MAX_POLICIES . ' policies.']];
        }
        $policies = [];
        $errors = [];
        $names = [];
        foreach ($data as $index => $item) {
            $where = 'Policy ' . ($index + 1);
            if (! is_array($item) || array_is_list($item)) {
                $errors[] = "$where: must be a map of keys (name, os, model, approved, minimum, blocked, note).";

                continue;
            }
            $name = is_string($item['name'] ?? null) ? trim($item['name']) : '';
            if ($name !== '') {
                $where .= " ($name)";
            }
            $wrong = [];
            foreach (array_diff(array_map('strval', array_keys($item)), self::KEYS) as $key) {
                $wrong[] = "$where: unknown key \"$key\" (a policy takes " . implode(', ', self::KEYS) . ').';
            }
            $text = function (string $key, int $max) use ($item, $where, &$wrong): string {
                $value = $item[$key] ?? null;
                if ($value === null) {
                    return '';
                }
                if (is_array($value)) {
                    $wrong[] = "$where: $key must be a text, not a list.";

                    return '';
                }
                $value = self::scalar($value, "$where: $key", $wrong);
                if ($value !== null && mb_strlen($value) > $max) {
                    $wrong[] = "$where: $key is longer than $max characters.";
                }

                return (string) $value;
            };
            $list = function (string $key) use ($item, $where, &$wrong): array {
                $value = $item[$key] ?? null;
                if ($value === null) {
                    return [];
                }
                $values = is_array($value) && array_is_list($value) ? $value : [$value];
                $out = [];
                foreach ($values as $one) {
                    $one = self::scalar($one, "$where: $key", $wrong);
                    if ($one === null) {
                        continue;
                    }
                    if ($one === '') {
                        $wrong[] = "$where: $key has an empty entry.";
                    } elseif (mb_strlen($one) > self::MAX_TEXT) {
                        $wrong[] = "$where: an entry of $key is longer than " . self::MAX_TEXT . ' characters.';
                    } else {
                        $out[] = $one;
                    }
                }
                if (count($out) > self::MAX_PATTERNS) {
                    $wrong[] = "$where: $key has more than " . self::MAX_PATTERNS . ' entries.';
                }

                return array_values(array_unique($out));
            };
            $name = $text('name', self::MAX_TEXT);
            $os = array_map('strtolower', $list('os'));
            $models = $list('model');
            $approved = $list('approved');
            $blocked = $list('blocked');
            $minimum = $text('minimum', self::MAX_TEXT);
            $note = $text('note', self::MAX_NOTE);
            // what a policy lacks only counts once what it has is right
            if ($wrong === [] && $os === [] && $models === []) {
                $wrong[] = "$where: names no devices: give an os, a model or both.";
            }
            if ($wrong === [] && $approved === [] && $minimum === '' && $blocked === []) {
                $wrong[] = "$where: checks nothing: give approved versions, a minimum, blocked versions or several of them.";
            }
            $name = $name !== '' ? $name : 'policy ' . ($index + 1);
            if (isset($names[mb_strtolower($name)])) {
                $wrong[] = "$where: another policy has this name.";
            }
            $names[mb_strtolower($name)] = true;
            if ($wrong) {
                array_push($errors, ...$wrong);

                continue;
            }
            $policies[] = new Policy($name, $os, $models, $approved, $minimum !== '' ? $minimum : null, $blocked, $note);
        }

        return $errors ? ['policies' => [], 'errors' => $errors] : ['policies' => $policies, 'errors' => []];
    }

    /**
     * A device's status against the policies: the status, the name of the policy that gave it (null: none), why.
     *
     * @param  list<Policy>  $policies
     * @param  string[]  $models  the device's model and its chassis' models (a stack's members)
     * @return array{status: string, policy: ?string, message: string}
     */
    public static function evaluate(array $policies, string $os, array $models, string $version): array
    {
        $applying = array_values(array_filter($policies, fn (Policy $policy) => $policy->applies($os, $models)));
        if ($applying === []) {
            return ['status' => Status::NO_POLICY, 'policy' => null, 'message' => 'No policy applies to it.'];
        }
        $primary = null;
        foreach ($applying as $policy) {
            if ($policy->forModels()) {
                $primary = $policy;

                break;
            }
        }
        $primary ??= $applying[0];
        $version = trim($version);
        if ($version === '') {
            return ['status' => Status::UNKNOWN, 'policy' => $primary->name, 'message' => 'LibreNMS has no software version for it.'];
        }
        // a version the primary policy blocks is told by it, else by the first other policy that blocks it
        foreach ([$primary, ...array_filter($applying, fn (Policy $policy) => $policy !== $primary)] as $policy) {
            if (self::matches($policy->blocked, $version)) {
                return ['status' => Status::BLOCKED, 'policy' => $policy->name, 'message' => self::note("$version is blocked by the policy {$policy->name}.", $policy)];
            }
        }
        if ($primary->minimum !== null && Versions::compare($version, $primary->minimum) < 0) {
            return ['status' => Status::BELOW_MINIMUM, 'policy' => $primary->name,
                'message' => self::note("$version is older than {$primary->minimum}, the minimum of the policy {$primary->name}.", $primary)];
        }
        if ($primary->approved !== []) {
            if (self::matches($primary->approved, $version)) {
                return ['status' => Status::APPROVED, 'policy' => $primary->name, 'message' => "$version is approved by the policy {$primary->name}."];
            }

            return ['status' => Status::NOT_APPROVED, 'policy' => $primary->name, 'message' => self::note("$version is not approved by the policy {$primary->name} (approved: "
                . self::names($primary->approved) . ').', $primary)];
        }

        return ['status' => Status::ALLOWED, 'policy' => $primary->name, 'message' => "$version is allowed by the policy {$primary->name}: "
            . ($primary->minimum !== null ? "not blocked, and not older than its minimum {$primary->minimum}." : 'not blocked.')];
    }

    /**
     * How many devices of each status (Software\Status, every status counted), and of the groups compliant and
     * not_compliant.
     *
     * @param  iterable<array{status: string}>  $rows
     * @return array<string, int>
     */
    public static function counts(iterable $rows): array
    {
        $counts = array_fill_keys(array_keys(Status::ALL), 0);
        foreach ($rows as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']]++;
            }
        }
        foreach (Status::GROUPS as $group => $statuses) {
            $counts[$group] = array_sum(array_intersect_key($counts, array_flip($statuses)));
        }

        return $counts;
    }

    /**
     * Does a version match one of these patterns: its release (17.* matches CAT9K_IOSXE 17.9.4), or the whole version as
     * LibreNMS has it (CAT9K_IOSXE 17.*), the leading zeros of their numbers ignored (17.9.* matches 17.09.04a)?
     *
     * @param  string[]  $patterns
     */
    public static function matches(array $patterns, string $version): bool
    {
        $patterns = array_map([Versions::class, 'normalize'], $patterns);

        return Policy::any($patterns, Versions::normalize(Versions::release($version))) || Policy::any($patterns, Versions::normalize($version));
    }

    /**
     * A message with the policy's note.
     */
    private static function note(string $message, Policy $policy): string
    {
        return $policy->note !== '' ? $message . ' Note: ' . $policy->note : $message;
    }

    /**
     * "17.9.5, 17.12.* and 3 more".
     *
     * @param  string[]  $names
     */
    public static function names(array $names, int $named = self::NAMED): string
    {
        $shown = array_slice($names, 0, $named);
        $more = count($names) - count($shown);

        return implode(', ', $shown) . ($more > 0 ? " and $more more" : '');
    }

    /**
     * A YAML scalar as text: a string, or a whole number. A number with a fraction is refused: YAML reads 4.30 as 4.3,
     * so a version must be in quotes. Null (with the error) for anything else.
     *
     * @param  string[]  $errors
     */
    private static function scalar(mixed $value, string $where, array &$errors): ?string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            $errors[] = "$where: YAML reads " . var_export($value, true) . ' as a number: write versions in quotes, such as \'4.30\'.';

            return null;
        }
        $errors[] = "$where: must be a text or a list of texts.";

        return null;
    }
}
