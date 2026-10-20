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
    ) {
    }

    /**
     * Array form used by the views and the JSON API.
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
        ];
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
