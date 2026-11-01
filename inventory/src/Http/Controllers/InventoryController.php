<?php

namespace Agoodley\LibrenmsInventory\Http\Controllers;

use Agoodley\LibrenmsInventory\Data\InventoryRepository;
use Agoodley\LibrenmsInventory\Data\SoftwareRepository;
use Agoodley\LibrenmsInventory\Reports\Exports;
use Agoodley\LibrenmsInventory\Reports\Lists;
use Agoodley\LibrenmsInventory\Reports\Summaries;
use Agoodley\LibrenmsInventory\Software\Policies;
use Agoodley\LibrenmsInventory\Support\Csv;
use Agoodley\LibrenmsInventory\Support\Filters;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The inventory's pages: the overview (models and software versions across the network), the devices, the modules
 * with their serial numbers, the optics, a search of serial and part numbers, and the CSV exports (of the software
 * compliance pages too: SoftwareController). Every page reads the devices of its filters the user may see (device,
 * device group, location, os, model, version).
 */
class InventoryController extends Controller
{
    /** Shortest text the search looks for. */
    public const SEARCH_MIN = 3;

    /**
     * plugin/inventory/overview: the cards (the software compliance of the last check too), the models and the software
     * versions.
     */
    public function overview(Request $request): View
    {
        $filters = Filters::parse($request->query());

        return $this->render('overview', $filters, function (InventoryRepository $repo) use ($filters): array {
            $devices = $repo->devices($filters);
            $ids = array_keys($devices);
            $osNames = InventoryRepository::osNames(array_column($devices, 'os'));
            $counts = $repo->counts($ids);
            $optics = $repo->optics($ids, $devices);
            $software = new SoftwareRepository();

            return [
                'software' => $software->available() ? Policies::counts(Lists::software($devices, $software->rows($ids))) : null,
                'devices' => count($devices),
                'models' => Summaries::models($devices, $repo->chassis($ids)),
                'versions' => Summaries::versions($devices, $osNames),
                'serials' => array_sum(array_column($counts, 'serials')),
                'deviceSerials' => count(array_filter($devices, fn ($d) => trim((string) ($d['serial'] ?? '')) !== '')),
                'optics' => count($optics),
                'origins' => Summaries::origins($optics),
                'osNames' => $osNames,
            ];
        });
    }

    /**
     * plugin/inventory/devices: each device's model, software, serial number, stack members and counts.
     */
    public function devices(Request $request): View
    {
        $filters = Filters::parse($request->query());

        return $this->render('devices', $filters, function (InventoryRepository $repo) use ($filters): array {
            $devices = $repo->devices($filters);
            $page = array_slice($devices, (min($filters['page'], Lists::pages(count($devices))) - 1) * Lists::PAGE, Lists::PAGE, true);
            $ids = array_keys($page);

            return [
                'rows' => $page,
                'total' => count($devices),
                'pages' => Lists::pages(count($devices)),
                'counts' => $repo->counts($ids),
                'chassis' => $repo->chassis($ids),
                'osNames' => InventoryRepository::osNames(array_column($page, 'os')),
            ];
        });
    }

    /**
     * plugin/inventory/modules: the hardware inventory's entries with a serial number (or a module's model).
     */
    public function modules(Request $request): View
    {
        $filters = Filters::parse($request->query());

        return $this->render('modules', $filters, function (InventoryRepository $repo) use ($filters): array {
            $devices = $repo->devices(['q' => ''] + $filters);
            $total = $repo->modules(array_keys($devices), $filters, 0)['total'];
            $page = min($filters['page'], Lists::pages($total));
            $modules = $repo->modules(array_keys($devices), $filters, Lists::PAGE, ($page - 1) * Lists::PAGE);

            return [
                'rows' => $modules['rows'],
                'total' => $modules['total'],
                'pages' => Lists::pages($modules['total']),
                'names' => $repo->names(array_column($modules['rows'], 'device_id')),
            ];
        });
    }

    /**
     * plugin/inventory/optics: the optics by kind, and each optic (filtered by type, vendor, origin and a text).
     */
    public function optics(Request $request): View
    {
        $filters = Filters::parse($request->query());

        return $this->render('optics', $filters, function (InventoryRepository $repo) use ($filters): array {
            $devices = $repo->devices(['q' => ''] + $filters);
            $all = $repo->optics(array_keys($devices), $devices);
            $optics = Lists::optics($all, $filters);
            $pages = Lists::pages(count($optics));
            $rows = array_slice($optics, (min($filters['page'], $pages) - 1) * Lists::PAGE, Lists::PAGE);

            return [
                'kinds' => Summaries::optics($optics),
                'rows' => $rows,
                'total' => count($optics),
                'all' => count($all),
                'pages' => $pages,
                'origins' => Summaries::origins($optics),
                'types' => self::distinct($all, 'type'),
                'vendors' => self::distinct($all, 'vendor'),
                'names' => $repo->names(array_column($rows, 'device_id')),
            ];
        });
    }

    /**
     * plugin/inventory/search?q=: serial and part numbers of the devices, their hardware inventory and their optics.
     */
    public function search(Request $request): View
    {
        $filters = Filters::parse($request->query());

        return $this->render('search', $filters, function (InventoryRepository $repo) use ($filters): array {
            if (mb_strlen($filters['q']) < self::SEARCH_MIN) {
                return ['found' => [], 'names' => [], 'ports' => [], 'short' => $filters['q'] !== ''];
            }
            $found = $repo->search($filters['q']);

            return [
                'found' => $found,
                'names' => $repo->names(array_column($found, 'device_id')),
                'ports' => $repo->portNames(array_filter(array_column($found, 'port_id'))),
                'short' => false,
            ];
        });
    }

    /**
     * plugin/inventory/export/{kind}: CSV of the devices, modules, optics, models, software versions, software compliance
     * or upgrades of the filters.
     */
    public function export(Request $request, string $kind): Response
    {
        $filters = Filters::parse($request->query());
        try {
            $repo = new InventoryRepository($request->user());
            $devices = $repo->devices(in_array($kind, ['devices', 'models', 'versions', 'software', 'upgrades'], true) ? $filters : ['q' => ''] + $filters);
            $ids = array_keys($devices);
            $rows = match ($kind) {
                'devices' => Exports::devices($devices, $repo->counts($ids), InventoryRepository::osNames(array_column($devices, 'os')), $repo->chassis($ids)),
                'modules' => (function () use ($repo, $ids, $filters): array {
                    $modules = $repo->modules($ids, $filters)['rows'];

                    return Exports::modules($modules, $repo->names(array_column($modules, 'device_id')));
                })(),
                'optics' => (function () use ($repo, $ids, $devices, $filters): array {
                    $optics = Lists::optics($repo->optics($ids, $devices), $filters);

                    return Exports::optics($optics, $repo->names(array_column($optics, 'device_id')));
                })(),
                'models' => Exports::models(Summaries::models($devices, $repo->chassis($ids)), InventoryRepository::osNames(array_column($devices, 'os'))),
                'software' => Exports::software(Lists::software($devices, (new SoftwareRepository())->rows($ids), $filters['status']),
                    InventoryRepository::osNames(array_column($devices, 'os'))),
                'upgrades' => (function () use ($repo, $ids, $devices): array {
                    $upgrades = (new SoftwareRepository())->upgrades($ids)['rows'];

                    return Exports::upgrades($upgrades, $repo->names(array_column($upgrades, 'device_id')), InventoryRepository::osNames(array_column($devices, 'os')));
                })(),
                default => Exports::versions(Summaries::versions($devices, InventoryRepository::osNames(array_column($devices, 'os')))),
            };
        } catch (\Throwable $e) {
            return response($this->failure("inventory export ($kind)", $e), 500, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $name = 'inventory-' . $kind . '-' . date('Ymd-Hi');

        return response(Csv::of($rows), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => "attachment; filename=\"$name.csv\"",
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * The values of a field among these rows, sorted (the empty one left out).
     *
     * @return string[]
     */
    private static function distinct(array $rows, string $field): array
    {
        $values = array_values(array_unique(array_filter(array_map(fn ($row) => (string) $row[$field], $rows), fn ($value) => $value !== '')));
        natcasesort($values);

        return array_values($values);
    }
}
