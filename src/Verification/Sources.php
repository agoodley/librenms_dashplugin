<?php

namespace Agoodley\LibrenmsTopology\Verification;

/**
 * What the verification API reads (Api\Verification), as one LibreNMS user sees the network (or the system scope of
 * console commands): LibrenmsSources over the plugin's scoped repositories and its own checks; a fixture in the tests.
 * Every device id it answers with is one the scope may see; the API filters by visible() all the same.
 */
interface Sources
{
    /**
     * The devices among $ids the scope may see, in their order, each once.
     *
     * @param  int[]  $ids
     * @return int[]
     */
    public function visible(array $ids): array;

    /**
     * The monitored LLDP/CDP neighbors of the devices (the far ends they report, and the devices that report one of
     * them), without the devices themselves.
     *
     * @param  int[]  $ids
     * @return int[]
     */
    public function neighbors(array $ids): array;

    /**
     * The devices whose path to the core depends on these devices: what goes down with them (their blast radius, as the
     * planning API's blastRadius() computes it).
     *
     * @param  int[]  $ids
     * @return int[]
     */
    public function dependents(array $ids): array;

    /**
     * The spanning tree switches of the L2 domains of the devices: the switches with spanning tree data connected to
     * those of $ids through LLDP/CDP links, those of $ids included.
     *
     * @param  int[]  $ids
     * @return int[]
     */
    public function stpDomain(array $ids): array;

    /**
     * The history's facts (FactCollector) of the devices $ids, and the root bridges the switches $rootIds see, naming
     * the devices of $nameIds (null: every device the scope sees); the kinds that could not be read.
     *
     * @param  int[]  $ids
     * @param  int[]  $rootIds
     * @param  int[]|null  $nameIds
     * @return array{facts: array<string, array>, failed: string[]}
     */
    public function facts(array $ids, array $rootIds, ?array $nameIds): array;

    /**
     * The findings of the background checks the settings enable (HistoryService::enabledChecks()), as their pages find
     * them: the link check of the devices $ids, the spanning tree check of the switches $stpIds (one VLAN at a time),
     * the redundancy and routing checks over the whole network the scope sees.
     *
     * @param  int[]  $ids
     * @param  int[]  $stpIds
     * @return array{findings: array<string, list<array>>, failed: array<string, string>, enabled: string[]} findings: analysis => its findings;
     *     failed: analysis => why it could not run
     */
    public function findings(array $ids, array $stpIds): array;

    /**
     * The link check's findings of these devices, every device the scope sees when null.
     *
     * @param  int[]|null  $ids
     * @return list<array>
     */
    public function linkFindings(?array $ids): array;

    /**
     * The spanning tree check of these switches (every one the scope sees when null): its findings and the domains of
     * each VLAN.
     *
     * @param  int[]|null  $ids
     * @return array{findings: list<array>, domains: array<int, list<int[]>>}
     */
    public function stpAnalysis(?array $ids): array;

    /**
     * The spanning tree of these switches: the VLAN keys of their instances, and for the keys asked the bridge MAC,
     * priority and protocol of each instance.
     *
     * @param  array<int, int[]>  $keys  device_id => VLAN keys
     * @return array<int, array{keys: int[], instances: array<int, array{mac: string, priority: int, protocol: string}>}>
     */
    public function bridges(array $keys): array;

    /**
     * Status and freshness of the devices (VerificationRepository::deviceStates()).
     *
     * @param  int[]  $ids
     * @return array<int, array{up: bool, down_since: ?int, discovered_at: ?int, polled_at: ?int, discovery_pending: bool}>
     */
    public function deviceStates(array $ids): array;

    /**
     * Names and descriptions of ports (VerificationRepository::portNames()).
     *
     * @param  int[]  $portIds
     * @return array<int, array{device_id: int, ifName: string, ifDescr: string, ifAlias: string}>
     */
    public function portNames(array $portIds): array;

    /**
     * Display names of the devices (only those the scope may see).
     *
     * @param  int[]  $ids
     * @return array<int, string>
     */
    public function names(array $ids): array;

    /**
     * The devices named by a setting that lists devices (stp_preferred_roots, redundancy_core), in the setting's order,
     * among those the scope may see.
     *
     * @return int[]
     */
    public function devicesOfSetting(string $key): array;

    /**
     * The checks the topology plugin keeps out of the eventlog (its setting, FindingHistory::skipList()): codes, or
     * code:severity.
     *
     * @return string[]
     */
    public function quietChecks(): array;

    /**
     * What the scope's LibreNMS role may not view of the data a state reads (with any, the state is incomplete).
     *
     * @return string[]
     */
    public function roleHidden(): array;
}
