<?php

namespace Agoodley\LibrenmsCompliance\Rules;

/**
 * A device's check: each rule of the settings (the plugin's on, and the admin's) on its configuration, and what its
 * switch ports are (for its page and for other plugins: Api\Compliance::ports()).
 */
final class Checker
{
    /** Most ports a check keeps the facts of. */
    public const MAX_PORTS = 2000;

    /**
     * @param  array<string, Rule>  $rules  the rules to check, by id
     */
    public function __construct(private readonly array $rules, private readonly BuiltinRules $builtin = new BuiltinRules())
    {
    }

    /**
     * Each rule's outcome on the device, by rule id. A rule that throws ends as an error, never stopping the others.
     *
     * @return array<string, Outcome>
     */
    public function check(Context $context): array
    {
        $outcomes = [];
        foreach ($this->rules as $id => $rule) {
            try {
                $outcomes[$id] = $rule->custom ? CustomRules::evaluate($rule, $context) : $this->builtin->evaluate($id, $context);
            } catch (\Throwable $e) {
                $outcomes[$id] = Outcome::error('the rule failed: ' . $e->getMessage());
            }
        }

        return $outcomes;
    }

    /**
     * The device's switch ports: what the configuration says of each (mode, shut down, port-channel, portfast, BPDU
     * guard, root guard), its LibreNMS port and what it faces.
     *
     * @return array<int, array{name: string, port_id: ?int, mode: string, shutdown: bool, member: ?string, portfast: string, bpduguard: string,
     *               rootguard: bool, role: string, neighbors: string[]}>
     */
    public static function ports(Context $context): array
    {
        $ports = [];
        foreach ($context->ports as $name => $port) {
            if ($port['mode'] === 'routed') {
                continue;
            }
            $ports[] = ['name' => (string) $name, 'port_id' => $context->portIds[$name] ?? null, 'mode' => $port['mode'], 'shutdown' => $port['shutdown'],
                'member' => $port['member'], 'portfast' => $port['portfast'], 'bpduguard' => $port['bpduguard'], 'rootguard' => $port['rootguard'],
                'role' => $context->role((string) $name), 'neighbors' => $context->neighbors((string) $name)];
            if (count($ports) >= self::MAX_PORTS) {
                break;
            }
        }

        return $ports;
    }
}
