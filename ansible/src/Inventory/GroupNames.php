<?php

namespace Agoodley\LibrenmsAnsible\Inventory;

/**
 * Names of the inventory's groups. Ansible accepts letters, digits and "_" in a group name; the plugin uses
 * lowercase ones with a prefix per kind (os_, platform_, group_, location_, type_), so a group name never looks
 * like a host name or another kind's group. Device group and location names come from LibreNMS users and the
 * network: they are reduced to [a-z0-9_], and labels that end up with the same name are numbered.
 */
final class GroupNames
{
    /** Longest name part taken from a label, before a number is added. */
    private const MAX = 64;

    /**
     * prefix_label in [a-z0-9_]: accents are dropped where PHP's intl extension is available, every other run
     * of characters becomes "_"; an empty result uses $fallback.
     */
    public static function slug(string $prefix, string $label, string $fallback = ''): string
    {
        $text = mb_strtolower(mb_scrub($label, 'UTF-8'));
        if (class_exists(\Normalizer::class)) {
            $text = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($text, \Normalizer::FORM_D));
        }
        $text = trim((string) preg_replace('/[^a-z0-9]+/', '_', $text), '_');
        $text = rtrim(substr($text, 0, self::MAX), '_');

        return $prefix . '_' . ($text !== '' ? $text : $fallback);
    }

    /**
     * Group names for labels of one kind, in id order: the slug, or the slug with _2, _3... when an earlier
     * label (or a name in $taken) already has it.
     *
     * @param  array<int|string, string>  $labels  id => label (device group or location name, os, ...)
     * @param  array<string, true>  $taken  names already given, updated with the new ones
     * @return array<int|string, string> id => group name
     */
    public static function assign(string $prefix, array $labels, array &$taken = []): array
    {
        ksort($labels);
        $names = [];
        foreach ($labels as $id => $label) {
            $base = self::slug($prefix, (string) $label, (string) $id);
            $name = $base;
            for ($n = 2; isset($taken[$name]); $n++) {
                $name = $base . '_' . $n;
            }
            $taken[$name] = true;
            $names[$id] = $name;
        }

        return $names;
    }
}
