<?php

namespace Agoodley\LibrenmsAnsible\Inventory;

/**
 * Which LibreNMS os is which Ansible network platform: ansible_network_os, ansible_connection and whether the
 * playbook enters privileged mode with "enable" (ansible_become). A device whose os has no entry is left out of
 * the inventory and cannot be a target. The defaults (DEFAULT) always apply; the setting's lines add to them, one
 * line per os, replacing the default line of the same os:
 *
 *     iosxe  cisco.ios.ios  network_cli  become
 *     asa    cisco.asa.asa  network_cli  become
 *     vyos   -
 *
 * (os, network os, optionally the connection, short names meaning ansible.netcommon.*, and "become"; "os -"
 * switches the os off, a default one included).
 */
final class PlatformMap
{
    /**
     * Defaults only for platforms checked on both sides: the os names exist in LibreNMS' os definitions and
     * the collections (with their cliconf and terminal plugins) ship with the Ansible of Semaphore UI 2.19.
     * Junos uses network_cli: its netconf connection needs the ncclient Python library on the runner.
     */
    public const DEFAULT = <<<'TEXT'
        ios          cisco.ios.ios                   network_cli  become
        iosxe        cisco.ios.ios                   network_cli  become
        iosxr        cisco.iosxr.iosxr               network_cli
        nxos         cisco.nxos.nxos                 network_cli
        arista_eos   arista.eos.eos                  network_cli  become
        junos        junipernetworks.junos.junos     network_cli
        vyos         vyos.vyos.vyos                  network_cli
        routeros     community.routeros.routeros     network_cli
        ciscosb      community.ciscosmb.ciscosmb     network_cli
        dell-sonic   dellemc.enterprise_sonic.sonic  network_cli
        TEXT;

    /** Connections that may be written without their collection, and the collection. */
    private const SHORT_CONNECTIONS = ['network_cli' => 'ansible.netcommon', 'netconf' => 'ansible.netcommon', 'httpapi' => 'ansible.netcommon',
        'libssh' => 'ansible.netcommon', 'grpc' => 'ansible.netcommon', 'ssh' => 'ansible.builtin', 'paramiko' => 'ansible.builtin'];

    /** @var array<string, array{network_os: string, connection: string, become: bool}> */
    private array $entries = [];

    /** @var string[] lines that could not be read, with why */
    private array $errors = [];

    /** @var array<string, string> os => where its entry comes from: default, line (the setting's), off (switched off) */
    private array $sources = [];

    private function __construct()
    {
    }

    /**
     * The defaults with the settings text's lines over them; unreadable lines are skipped and listed in errors().
     * A later line for the same os replaces an earlier one.
     */
    public static function parse(?string $text): self
    {
        $map = new self();
        foreach (self::lines(self::DEFAULT) as $line) {
            $map->add($line);
        }
        $map->sources = array_fill_keys(array_keys($map->entries), 'default');
        foreach (self::lines((string) $text) as $number => $line) {
            $error = $map->add($line, true);
            if ($error !== null) {
                $map->errors[] = 'line ' . ($number + 1) . ' (' . UntrustedText::line($line, 80) . "): $error";
            }
        }

        return $map;
    }

    /**
     * The lines of a mapping text without comments, by line number (from 0).
     *
     * @return array<int, string>
     */
    private static function lines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $number => $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line !== '') {
                $lines[$number] = $line;
            }
        }

        return $lines;
    }

    /**
     * Read one line; null when it is valid.
     */
    private function add(string $line, bool $typed = false): ?string
    {
        $tokens = preg_split('/\s+/', $line) ?: [];
        $os = strtolower((string) array_shift($tokens));
        $networkOs = strtolower((string) array_shift($tokens));
        if (! preg_match('/^[a-z0-9][a-z0-9_.-]*$/D', $os)) {
            return 'the LibreNMS os name may only have lowercase letters, digits, ".", "_" and "-"';
        }
        if ($networkOs === '-') {
            if ($tokens) {
                return '"' . $os . ' -" switches the os off and takes nothing after the "-"';
            }
            unset($this->entries[$os]);
            $this->sources[$os] = 'off';

            return null;
        }
        if (! self::isName($networkOs)) {
            return 'the network os must be a name like cisco.ios.ios';
        }
        $connection = 'ansible.netcommon.network_cli';
        $become = false;
        foreach ($tokens as $token) {
            $token = strtolower($token);
            if ($token === 'become') {
                $become = true;
            } elseif (isset(self::SHORT_CONNECTIONS[$token])) {
                $connection = self::SHORT_CONNECTIONS[$token] . '.' . $token;
            } elseif (preg_match('/^[a-z0-9_]+\.[a-z0-9_]+\.[a-z0-9_]+$/D', $token)) {
                $connection = $token;
            } else {
                return "\"$token\" is neither a connection (network_cli, netconf, httpapi or a full name like ansible.netcommon.network_cli) nor \"become\"";
            }
        }
        $this->entries[$os] = ['network_os' => $networkOs, 'connection' => $connection, 'become' => $become];
        if ($typed) {
            $this->sources[$os] = 'line';
        }

        return null;
    }

    /**
     * Every os the defaults or the setting's lines name, with its platform (null when switched off) and where it
     * comes from: "default", "line" (the setting's, added or replacing a default) or "off".
     *
     * @return array<string, array{platform: array{network_os: string, connection: string, become: bool}|null, source: string}>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->sources as $os => $source) {
            $rows[$os] = ['platform' => $this->entries[$os] ?? null, 'source' => $source];
        }
        ksort($rows);

        return $rows;
    }

    /**
     * A plugin or collection name: dot separated parts of lowercase letters, digits and "_".
     */
    private static function isName(string $name): bool
    {
        return preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)*$/D', $name) === 1;
    }

    /**
     * The platform of a LibreNMS os, null without a mapping.
     *
     * @return array{network_os: string, connection: string, become: bool}|null
     */
    public function for(string $os): ?array
    {
        return $this->entries[strtolower($os)] ?? null;
    }

    /**
     * @return array<string, array{network_os: string, connection: string, become: bool}> os => platform
     */
    public function all(): array
    {
        return $this->entries;
    }

    /**
     * @return string[]
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * A platform as a line of the setting writes it, without the os: "cisco.ios.ios network_cli become".
     *
     * @param  array{network_os: string, connection: string, become: bool}  $platform
     */
    public static function describe(array $platform): string
    {
        $connection = $platform['connection'];
        $short = substr($connection, strrpos($connection, '.') + 1);
        if ((self::SHORT_CONNECTIONS[$short] ?? null) . '.' . $short === $connection) {
            $connection = $short;
        }

        return $platform['network_os'] . ' ' . $connection . ($platform['become'] ? ' become' : '');
    }

    /**
     * Inventory variables of a platform.
     *
     * @param  array{network_os: string, connection: string, become: bool}  $platform
     * @return array<string, string|bool>
     */
    public static function hostVars(array $platform): array
    {
        $vars = ['ansible_network_os' => $platform['network_os'], 'ansible_connection' => $platform['connection']];
        if ($platform['become']) {
            $vars['ansible_become'] = true;
            $vars['ansible_become_method'] = 'enable';
        }

        return $vars;
    }
}
