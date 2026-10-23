<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\History\FactCollector;
use Agoodley\LibrenmsTopology\Verification\Sources;

/**
 * The verification API's sources on a small network, for its tests: what LibrenmsSources reads from LibreNMS, from
 * plain arrays a test changes between two states (a cable pulled, a root bridge moved, a finding that came). The facts
 * are FactCollector's own, built from the links and spanning tree rows as the history builds them.
 *
 * It answers like a buggy source would if asked: a device the user may not see (SECRET) is among the neighbors, the
 * dependents, the domain, the facts, the findings, the names and the ports it returns, so that the tests see the API
 * filter all of it by visible().
 *
 *     core1 (1) ── core2 (2)          far1 (7) hangs off core1; rtr (8) has no spanning tree
 *       │            │                acc2 (5) is single-homed to dist1: it depends on it
 *     dist1 (3) ─── acc1 (4) ── secret (6), a device the user may not see
 *       │
 *     acc2 (5)
 */
final class VerificationFixture implements Sources
{
    public const CORE1 = 1;
    public const CORE2 = 2;
    public const DIST1 = 3;
    public const ACC1 = 4;
    public const ACC2 = 5;
    public const SECRET = 6;
    public const FAR1 = 7;
    public const RTR = 8;

    public const NAMES = [1 => 'core1', 2 => 'core2', 3 => 'dist1', 4 => 'acc1', 5 => 'acc2', 6 => 'secret', 7 => 'far1', 8 => 'rtr'];

    public const MACS = [1 => '00ab01000001', 2 => '00ab02000001', 3 => '00ab03000001', 4 => '00ab04000001', 5 => '00ab05000001', 6 => '00ab06000001',
        7 => '00ab07000001'];

    /** @var int[] the devices the user may see */
    public array $visible = [1, 2, 3, 4, 5, 7, 8];

    /** @var array<int, array> port_id => port (device_id, ifName, ifDescr, ifAlias) */
    public array $ports = [];

    /** @var list<array> links rows (both directions are not needed: one row per cable) */
    public array $links = [];

    /** @var array<int, int> device => the root bridge (device) it sees (one instance: VLAN 1 / CST, key 0) */
    public array $roots = [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 7 => 1, 6 => 1];

    /** @var list<array> ports_stp rows that block */
    public array $blocked = [];

    /** @var array<string, list<array>> analysis => findings as the analyzers give them */
    public array $findings = ['stp' => [], 'links' => [], 'redundancy' => [], 'routing' => []];

    /** @var array<string, string> analysis => why it failed */
    public array $failedChecks = [];

    /** @var array<string, string> kind of facts (FactCollector::LINK, STP_ROOT, STP_BLOCKED) => why it could not be read */
    public array $failedFacts = [];

    /** @var array<int, array> device => status and freshness */
    public array $states = [];

    /** @var array<int, int[]> device => the devices that depend on it */
    public array $dependentsOf = [3 => [5]];

    /** @var array<int, array{keys: int[], instances: array<int, array{mac: string, priority: int, protocol: string}>}> */
    public array $bridges = [];

    /** @var array<string, int[]> setting => devices */
    public array $settings = ['stp_preferred_roots' => [], 'redundancy_core' => [1, 2]];

    /** @var string[] */
    public array $quiet = [];

    /** @var string[] */
    public array $hidden = [];

    /** @var list<array{string, mixed}> the calls the API made (method, arguments) */
    public array $calls = [];

    public function __construct()
    {
        foreach ([[11, 1, 'Te1/0/1'], [13, 1, 'Te1/0/3'], [17, 1, 'Te1/0/7'], [21, 2, 'Te1/0/1'], [24, 2, 'Te1/0/4'], [31, 3, 'Te1/0/1'], [32, 3, 'Te1/0/2'],
            [39, 3, 'Te1/0/49'], [41, 4, 'Gi1/0/49'], [42, 4, 'Gi1/0/50'], [43, 4, 'Gi1/0/1'], [51, 5, 'Gi1/0/49'], [61, 6, 'Gi0/1'], [71, 7, 'Gi1/0/49']] as [$id, $device, $name]) {
            $this->ports[$id] = ['port_id' => $id, 'device_id' => $device, 'ifName' => $name, 'ifDescr' => $name, 'ifAlias' => ''];
        }
        $this->links = [self::link(1, 11, 2, 21), self::link(3, 39, 1, 13), self::link(4, 41, 3, 31), self::link(4, 42, 2, 24), self::link(5, 51, 3, 32),
            self::link(4, 43, 6, 61), self::link(7, 71, 1, 17)];
        foreach (array_keys(self::NAMES) as $id) {
            $this->states[$id] = ['up' => true, 'down_since' => null, 'discovered_at' => 1_700_000_000, 'polled_at' => 1_700_000_100, 'discovery_pending' => false];
        }
        foreach (array_keys(self::MACS) as $id) {
            $this->bridges[$id] = ['keys' => [0], 'instances' => [0 => ['mac' => self::MACS[$id], 'priority' => 32768, 'protocol' => 'ieee8021d']]];
        }
    }

    public static function link(int $ld, int $lp, int $rd, ?int $rp): array
    {
        return ['local_device_id' => $ld, 'local_port_id' => $lp, 'remote_device_id' => $rd, 'remote_port_id' => $rp, 'remote_hostname' => self::NAMES[$rd] ?? '',
            'remote_port' => '', 'remote_platform' => '', 'remote_version' => '', 'protocol' => 'lldp'];
    }

    /** Pull the cable of a port (either end). */
    public function unplug(int $portId): void
    {
        $this->links = array_values(array_filter($this->links, fn (array $link) => $link['local_port_id'] !== $portId && $link['remote_port_id'] !== $portId));
    }

    /**
     * A finding as an analyzer gives it.
     *
     * @param  int[]  $devices
     */
    public static function finding(string $code, string $severity, string $title, array $devices, ?int $vlan = null, array $extra = []): array
    {
        return ['code' => $code, 'severity' => $severity, 'title' => $title, 'detail' => '', 'devices' => $devices] + ($vlan !== null ? ['vlan' => $vlan] : []) + $extra;
    }

    public function visible(array $ids): array
    {
        return array_values(array_unique(array_map('intval', array_filter($ids, fn ($id) => in_array((int) $id, $this->visible, true)))));
    }

    public function neighbors(array $ids): array
    {
        $this->calls[] = ['neighbors', $ids];
        $found = [];
        foreach ($this->links as $link) {
            if (in_array($link['local_device_id'], $ids, true)) {
                $found[] = $link['remote_device_id'];
            }
            if (in_array($link['remote_device_id'], $ids, true)) {
                $found[] = $link['local_device_id'];
            }
        }

        return array_values(array_diff(array_unique($found), $ids)); // the secret device among them
    }

    public function dependents(array $ids): array
    {
        $this->calls[] = ['dependents', $ids];

        return array_values(array_unique(array_merge([], ...array_map(fn (int $id) => $this->dependentsOf[$id] ?? [], $ids))));
    }

    public function stpDomain(array $ids): array
    {
        $this->calls[] = ['stpDomain', $ids];

        return array_intersect($ids, array_keys(self::MACS)) ? array_keys(self::MACS) : [];
    }

    public function facts(array $ids, array $rootIds, ?array $nameIds): array
    {
        $this->calls[] = ['facts', $ids, $rootIds];
        $collector = new FactCollector(self::NAMES, $this->ports);
        $links = array_values(array_filter($this->links, fn (array $link) => in_array($link['local_device_id'], $ids, true) || in_array($link['remote_device_id'], $ids, true)));
        $stp = [];
        foreach ($this->roots as $device => $root) {
            if (in_array($device, $rootIds, true)) {
                $stp[] = ['device_id' => $device, 'vlan' => 1, 'designatedRoot' => self::MACS[$root], 'bridgeAddress' => self::MACS[$device]];
            }
        }

        $kinds = [FactCollector::LINK => fn () => $collector->links($links), FactCollector::STP_ROOT => fn () => $collector->stpRoots($stp),
            FactCollector::STP_BLOCKED => fn () => $collector->blockedPorts(array_filter($this->blocked, fn (array $row) => in_array($row['device_id'], $ids, true)))];
        $facts = [];
        foreach (array_diff_key($kinds, $this->failedFacts) as $read) {
            $facts += $read();
        }

        return ['facts' => $facts, 'failed' => array_keys($this->failedFacts), 'errors' => $this->failedFacts];
    }

    public function findings(array $ids, array $stpIds): array
    {
        $this->calls[] = ['findings', $ids, $stpIds];

        return ['findings' => array_diff_key($this->findings, $this->failedChecks), 'failed' => $this->failedChecks, 'enabled' => array_keys($this->findings)];
    }

    public function linkFindings(?array $ids): array
    {
        $this->calls[] = ['linkFindings', $ids];

        return $this->findings['links'];
    }

    public function stpAnalysis(?array $ids): array
    {
        $this->calls[] = ['stpAnalysis', $ids];

        return ['findings' => $this->findings['stp'], 'domains' => [0 => [array_keys(self::MACS)]]];
    }

    public function bridges(array $keys): array
    {
        return array_intersect_key($this->bridges, $keys);
    }

    public function deviceStates(array $ids): array
    {
        return array_intersect_key($this->states, array_flip($ids));
    }

    public function portNames(array $portIds): array
    {
        return array_intersect_key($this->ports, array_flip($portIds));
    }

    public function names(array $ids): array
    {
        return array_intersect_key(self::NAMES, array_flip($ids));
    }

    public function devicesOfSetting(string $key): array
    {
        return $this->settings[$key] ?? [];
    }

    public function quietChecks(): array
    {
        return $this->quiet;
    }

    public function roleHidden(): array
    {
        return $this->hidden;
    }
}
