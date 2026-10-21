<?php

namespace Agoodley\LibrenmsTopology\Path;

use Agoodley\LibrenmsTopology\Stp\Finding;

/**
 * The notes and findings of one path trace: PathService::trace() starts a new one for every trace.
 */
class PathLog
{
    /** @var array<int, array{level: string, text: string}> */
    private array $notes = [];

    /** @var Finding[] */
    private array $findings = [];

    /**
     * A note shown above the hops (the same text once).
     */
    public function note(string $level, string $text): void
    {
        foreach ($this->notes as $note) {
            if ($note['text'] === $text) {
                return;
            }
        }
        $this->notes[] = ['level' => $level, 'text' => $text];
    }

    /**
     * @param  int[]  $devices
     * @param  int[]  $ports
     */
    public function add(string $severity, string $code, string $title, string $detail, string $recommendation, array $devices = [], array $ports = [], ?int $vlan = null): void
    {
        $this->findings[] = new Finding($severity, $code, $title, $detail, $recommendation, $vlan, $devices, $ports);
    }

    /**
     * @return array<int, array{level: string, text: string}>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * Findings as arrays, the most severe first.
     *
     * @return array<int, array>
     */
    public function findings(): array
    {
        $findings = array_map(fn (Finding $finding) => $finding->toArray(), $this->findings);
        usort($findings, fn ($x, $y) => (Finding::SEVERITY_ORDER[$x['severity']] ?? 9) <=> (Finding::SEVERITY_ORDER[$y['severity']] ?? 9));

        return $findings;
    }
}
