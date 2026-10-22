<?php

namespace Agoodley\LibrenmsAnsible\Runner;

/**
 * An inventory of the runner's project, to check that the plugin's one is the LibreNMS inventory script.
 */
final class RunnerInventory
{
    /**
     * @param  string  $source  the script's path for a file inventory (empty for static ones, whose hosts are not shown)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $type,
        public readonly string $source,
    ) {
    }

    /**
     * Is it pulled from a file of the repository (a script that asks LibreNMS), rather than static?
     */
    public function isFile(): bool
    {
        return $this->type === 'file';
    }
}
