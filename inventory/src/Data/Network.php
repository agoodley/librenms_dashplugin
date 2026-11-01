<?php

namespace Agoodley\LibrenmsInventory\Data;

/**
 * What the software check reads of LibreNMS: the devices it covers with their os, model, software version and their
 * chassis' models (a stack's members), every device LibreNMS has, its device groups. LibrenmsNetwork reads LibreNMS'
 * tables; the tests give their own.
 */
interface Network
{
    /**
     * The devices the check covers: enabled, of the device group (every device for ''), by id.
     *
     * @return array<int, array{name: string, os: string, hardware: string, version: string, models: string[]}>
     *
     * @throws \InvalidArgumentException a device group LibreNMS does not have
     */
    public function devices(string $group = ''): array;

    /**
     * The ids of every device LibreNMS has, enabled or not.
     *
     * @return int[]
     */
    public function deviceIds(): array;

    /**
     * LibreNMS' device groups, by name.
     *
     * @return string[]
     */
    public function groups(): array;
}
