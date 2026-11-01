<?php

namespace Agoodley\LibrenmsInventory\Support;

/**
 * The filters of the plugin's pages and exports, from the query string: device, device group and location (ids), os,
 * model, version, class (of a module), type and vendor (of an optic), origin (genuine, third-party, unknown), a text
 * (q) and the page. Anything else, or of the wrong form, is left out. Pure PHP.
 */
final class Filters
{
    /** Filters that are ids. */
    public const IDS = ['device', 'group', 'location'];

    /** Filters that are texts. */
    public const TEXTS = ['os', 'model', 'version', 'class', 'type', 'vendor', 'origin', 'q'];

    /** Longest text filter. */
    public const MAX_LENGTH = 200;

    /** The origins an optic filter takes. */
    public const ORIGINS = [Origin::GENUINE, Origin::THIRD_PARTY, 'unknown'];

    /**
     * @return array{device: ?int, group: ?int, location: ?int, os: string, model: string, version: string, class: string,
     *     type: string, vendor: string, origin: string, q: string, page: int}
     */
    public static function parse(array $query): array
    {
        $filters = [];
        foreach (self::IDS as $key) {
            $value = $query[$key] ?? null;
            $filters[$key] = is_scalar($value) && ctype_digit(trim((string) $value)) && (int) $value > 0 ? (int) $value : null;
        }
        foreach (self::TEXTS as $key) {
            $value = $query[$key] ?? '';
            $filters[$key] = is_scalar($value) ? mb_substr(trim((string) $value), 0, self::MAX_LENGTH) : '';
        }
        if (! in_array($filters['origin'], self::ORIGINS, true)) {
            $filters['origin'] = '';
        }
        $page = $query['page'] ?? 1;
        $filters['page'] = is_scalar($page) && ctype_digit((string) $page) ? max(1, min(100000, (int) $page)) : 1;

        return $filters;
    }

    /**
     * The filters as a query string array without the empty ones and the page (links, exports).
     */
    public static function query(array $filters, array $without = []): array
    {
        return array_filter(array_diff_key($filters, array_flip(['page', ...$without])), fn ($value) => $value !== null && $value !== '');
    }

    /**
     * A text for SQL LIKE that matches it anywhere, its wildcards (% and _) and the escape character taken literally.
     */
    public static function like(string $text): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text) . '%';
    }
}
