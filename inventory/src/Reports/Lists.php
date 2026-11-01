<?php

namespace Agoodley\LibrenmsInventory\Reports;

use Agoodley\LibrenmsInventory\Software\Status;

/**
 * The lists of the pages: the optics of the filters, the software of the devices and the pages of a list. Pure PHP.
 */
final class Lists
{
    /** Rows of a list on one page (the exports have all). */
    public const PAGE = 500;

    /**
     * The optics of the filters type, vendor (any case), origin (genuine, third-party, unknown) and q (a text of the
     * serial number, model, vendor, type or port, any case).
     *
     * @param  list<array>  $optics
     * @return list<array>
     */
    public static function optics(array $optics, array $filters): array
    {
        $q = mb_strtolower($filters['q'] ?? '');

        return array_values(array_filter($optics, function (array $optic) use ($filters, $q): bool {
            if (($filters['type'] ?? '') !== '' && strcasecmp((string) $optic['type'], $filters['type']) !== 0) {
                return false;
            }
            if (($filters['vendor'] ?? '') !== '' && strcasecmp((string) $optic['vendor'], $filters['vendor']) !== 0) {
                return false;
            }
            if (($filters['origin'] ?? '') !== '' && (($optic['origin'] ?? null) ?: 'unknown') !== $filters['origin']) {
                return false;
            }
            if ($q === '') {
                return true;
            }
            $text = mb_strtolower(implode(' ', [$optic['serial'] ?? '', $optic['model'] ?? '', $optic['vendor'] ?? '', $optic['type'] ?? '',
                $optic['port'] ?? '', $optic['port_alias'] ?? '']));

            return str_contains($text, $q);
        }));
    }

    /**
     * The software page's rows: each device of the filters with its row of the last software check (a device the check
     * has not seen is left out), with its name and location; the worst status first, then by name. Filtered by status (a
     * status or a group of them, Software\Status::filter()).
     *
     * @param  array<int, array>  $devices  InventoryRepository::devices()
     * @param  array<int, array>  $stored  SoftwareRepository::rows()
     * @return list<array>
     */
    public static function software(array $devices, array $stored, string $status = ''): array
    {
        $statuses = $status !== '' ? Status::filter($status) : null;
        $rows = [];
        foreach ($devices as $id => $device) {
            $row = $stored[(int) $id] ?? null;
            if ($row === null || ($statuses !== null && ! in_array((string) $row['status'], $statuses, true))) {
                continue;
            }
            $rows[] = ['device_id' => (int) $id, 'name' => (string) $device['name'], 'location' => (string) ($device['location'] ?? ''), 'url' => $device['url'] ?? null] + $row;
        }
        usort($rows, fn (array $a, array $b) => [Status::rank((string) $a['status']), mb_strtolower($a['name'])] <=> [Status::rank((string) $b['status']), mb_strtolower($b['name'])]);

        return $rows;
    }

    /**
     * How many pages a list of $total rows takes (one at least).
     */
    public static function pages(int $total): int
    {
        return max(1, (int) ceil($total / self::PAGE));
    }

    /**
     * The page asked for, within the pages there are.
     */
    public static function page(int $asked, int $total): int
    {
        return max(1, min($asked, self::pages($total)));
    }
}
