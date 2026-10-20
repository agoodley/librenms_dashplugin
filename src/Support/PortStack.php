<?php

namespace Agoodley\LibrenmsTopology\Support;

/**
 * Port-channel membership from interface stack pairs. LibreNMS' ports_stack does not say which side
 * of a pair is the aggregate in a stable way: before 24.8 the higher layer (IF-MIB ifStackHigherLayer)
 * was stored as port_id_high, since then the discovery module stores it in low_port_id. So the
 * aggregate is told apart by the ports themselves.
 */
class PortStack
{
    /** ifTypes that are never a member of a port-channel. */
    private const NOT_MEMBERS = ['ieee8023adLag', 'l2vlan', 'l3ipvlan', 'propVirtual', 'softwareLoopback', 'tunnel', 'mplsTunnel'];

    /**
     * @param  array<int, array{0: int, 1: int}>  $pairs  port id pairs of either orientation (stack rows, PAgP)
     * @param  array<int, array>  $ports  port_id => port row (ifType, ifName, ifDescr)
     * @return array<int, int> member port_id => aggregate port_id (the first pair wins)
     */
    public static function lagMembers(array $pairs, array $ports): array
    {
        $result = [];
        foreach ($pairs as [$a, $b]) {
            $a = (int) $a;
            $b = (int) $b;
            if ($a === $b || ! isset($ports[$a], $ports[$b])) {
                continue;
            }
            if (self::isAggregate($ports[$a]) && self::isMember($ports[$b])) {
                $result[$b] ??= $a;
            } elseif (self::isAggregate($ports[$b]) && self::isMember($ports[$a])) {
                $result[$a] ??= $b;
            }
        }

        return $result;
    }

    /**
     * A port-channel itself: its LAG ifType or name, not one of its sub-interfaces (Po1.100, ae0.0).
     */
    public static function isAggregate(array $port): bool
    {
        $name = self::name($port);

        return ! self::isSubInterface($name)
            && (($port['ifType'] ?? '') === 'ieee8023adLag' || InterfaceName::isLagName($name));
    }

    /**
     * A physical port that can be bundled: no aggregate, sub-interface, VLAN or virtual interface.
     */
    private static function isMember(array $port): bool
    {
        $name = self::name($port);

        return ! self::isAggregate($port) && ! self::isSubInterface($name) && ! in_array($port['ifType'] ?? '', self::NOT_MEMBERS, true);
    }

    private static function isSubInterface(string $name): bool
    {
        return (bool) preg_match('/\.\d+$/', $name);
    }

    private static function name(array $port): string
    {
        return trim((string) (($port['ifName'] ?? '') !== '' ? $port['ifName'] : ($port['ifDescr'] ?? '')));
    }
}
