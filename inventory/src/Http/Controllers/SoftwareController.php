<?php

namespace Agoodley\LibrenmsInventory\Http\Controllers;

use Agoodley\LibrenmsInventory\Data\InventoryRepository;
use Agoodley\LibrenmsInventory\Data\SettingsRepository;
use Agoodley\LibrenmsInventory\Data\SoftwareRepository;
use Agoodley\LibrenmsInventory\Reports\Lists;
use Agoodley\LibrenmsInventory\Services\SoftwareCheck;
use Agoodley\LibrenmsInventory\Software\Policies;
use Agoodley\LibrenmsInventory\Software\Policy;
use Agoodley\LibrenmsInventory\Software\Status;
use Agoodley\LibrenmsInventory\Support\Filters;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The software compliance pages: each device's software against the policies as the last check saw it, the policies,
 * and the version changes the checks saw; for admins, a check now. Every page shows the devices of its filters the user
 * may see.
 */
class SoftwareController extends Controller
{
    /**
     * plugin/inventory/software: the devices by status (filtered by a status or a group of them), the policies.
     */
    public function software(Request $request): View
    {
        $filters = Filters::parse($request->query());

        return $this->render('software', $filters, function (InventoryRepository $repo) use ($filters): array {
            $software = new SoftwareRepository();
            $settings = (new SettingsRepository())->get();
            $data = ['available' => $software->available(), 'lastRun' => null, 'policies' => [], 'policyErrors' => [], 'perPolicy' => [], 'counts' => Policies::counts([]),
                'rows' => [], 'total' => 0, 'pages' => 1, 'unchecked' => 0, 'osNames' => [], 'settingsError' => $settings->error];
            if (! $data['available']) {
                return $data;
            }
            $devices = $repo->devices($filters);
            $all = Lists::software($devices, $software->rows(array_keys($devices)));
            $statuses = Status::filter($filters['status']);
            $listed = $statuses !== [] ? array_values(array_filter($all, fn (array $row) => in_array((string) $row['status'], $statuses, true))) : $all;
            $pages = Lists::pages(count($listed));
            $parsed = $settings->policies();
            $perPolicy = [];
            foreach ($all as $row) {
                if ($row['policy'] !== null) {
                    $perPolicy[(string) $row['policy']] = ($perPolicy[(string) $row['policy']] ?? 0) + 1;
                }
            }

            return [
                'lastRun' => SoftwareCheck::forLibrenms($settings)->lastRun(),
                'policies' => $parsed['policies'],
                'policyErrors' => $parsed['errors'],
                'perPolicy' => $perPolicy,
                'counts' => Policies::counts($all),
                'rows' => array_slice($listed, (min($filters['page'], $pages) - 1) * Lists::PAGE, Lists::PAGE),
                'total' => count($listed),
                'pages' => $pages,
                'unchecked' => count($devices) - count($all),
                'osNames' => InventoryRepository::osNames(array_column($devices, 'os')),
            ] + $data;
        });
    }

    /**
     * plugin/inventory/upgrades: the version changes the checks saw, the newest first.
     */
    public function upgrades(Request $request): View
    {
        $filters = Filters::parse($request->query());

        return $this->render('upgrades', $filters, function (InventoryRepository $repo) use ($filters): array {
            $software = new SoftwareRepository();
            if (! $software->available()) {
                return ['available' => false, 'rows' => [], 'total' => 0, 'pages' => 1, 'names' => [], 'osNames' => [], 'first' => null];
            }
            $devices = $repo->devices($filters);
            $all = $software->upgrades(array_keys($devices))['rows'];
            $rows = array_slice($all, (Lists::page($filters['page'], count($all)) - 1) * Lists::PAGE, Lists::PAGE);

            return [
                'available' => true,
                'rows' => $rows,
                'total' => count($all),
                'pages' => Lists::pages(count($all)),
                'names' => $repo->names(array_column($rows, 'device_id')),
                'osNames' => InventoryRepository::osNames(array_filter(array_map('strval', array_column($rows, 'os')))),
                'first' => SoftwareCheck::forLibrenms()->firstRun(),
            ];
        });
    }

    /**
     * POST plugin/inventory/software/check (admins): check every device now.
     */
    public function check(Request $request): RedirectResponse
    {
        try {
            $summary = SoftwareCheck::forLibrenms()->run();
            $flash = $summary['error'] !== null ? ['danger', $summary['error']] : ['success', 'Checked: ' . SoftwareCheck::describe($summary) . '.'];
        } catch (\Throwable $e) {
            $flash = ['danger', $this->failure('software check', $e)];
        }

        return redirect()->route('inventory.software', Filters::query(Filters::parse($request->query())))->with('inventory_flash', $flash);
    }

    /**
     * The patterns of a policy's list as the page shows them.
     *
     * @param  string[]  $patterns
     */
    public static function patterns(array $patterns): string
    {
        return $patterns === [] ? '' : implode(', ', $patterns);
    }

    /**
     * Who a policy is for: "os iosxe, model C9300-*".
     */
    public static function scope(Policy $policy): string
    {
        return implode('; ', array_filter([
            $policy->os !== [] ? 'os ' . implode(', ', $policy->os) : '',
            $policy->models !== [] ? 'model ' . implode(', ', $policy->models) : '',
        ]));
    }
}
