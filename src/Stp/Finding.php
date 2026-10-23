<?php

namespace Agoodley\LibrenmsTopology\Stp;

/**
 * One diagnostic result of the spanning tree or link analysis.
 */
class Finding
{
    public const CRITICAL = 'critical';
    public const WARNING = 'warning';
    public const INFO = 'info';

    public const SEVERITY_ORDER = [self::CRITICAL => 0, self::WARNING => 1, self::INFO => 2];

    /**
     * @param  int[]  $devices
     * @param  int[]  $ports
     * @param  string  $config  suggested configuration lines (empty when there are none)
     * @param  array<string, mixed>  $fix  what a fix of the finding would set, as data (empty when it has no clear one): the port
     *                                     descriptions of the description checks (ports: port_id => description), the root bridge of
     *                                     the root placement checks (root, root_priority, root_mac); the verification API's fixes
     */
    public function __construct(
        public readonly string $severity,
        public readonly string $code,
        public readonly string $title,
        public readonly string $detail,
        public readonly string $recommendation = '',
        public readonly ?int $vlan = null,
        public readonly array $devices = [],
        public readonly array $ports = [],
        public readonly string $config = '',
        public readonly array $fix = [],
    ) {
    }

    /**
     * Array form used by the views and the JSON API; "fix" only for a finding that has one.
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'code' => $this->code,
            'title' => $this->title,
            'detail' => $this->detail,
            'recommendation' => $this->recommendation,
            'vlan' => $this->vlan,
            'vlans' => $this->vlan === null ? [] : [$this->vlan],
            'vlan_label' => $this->vlan === null ? null : StpIndex::vlanLabel($this->vlan),
            'devices' => array_values(array_unique($this->devices)),
            'ports' => array_values(array_unique($this->ports)),
            'config' => $this->config,
        ] + ($this->fix ? ['fix' => $this->fix] : []);
    }

    /**
     * Worst of several severities, 'ok' for none.
     *
     * @param  string[]  $severities
     */
    public static function worst(array $severities): string
    {
        $worst = 'ok';
        foreach ($severities as $severity) {
            if ($worst === 'ok' || (self::SEVERITY_ORDER[$severity] ?? 9) < (self::SEVERITY_ORDER[$worst] ?? 9)) {
                $worst = $severity;
            }
        }

        return $worst;
    }
}
