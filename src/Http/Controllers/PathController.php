<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Agoodley\LibrenmsTopology\Data\HostRepository;
use Agoodley\LibrenmsTopology\Data\PathRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Hosts\DnsResolver;
use Agoodley\LibrenmsTopology\Hosts\HostService;
use Agoodley\LibrenmsTopology\Http\Controllers\Concerns\BuildsTopology;
use Agoodley\LibrenmsTopology\Path\PathService;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Path trace: plugin/topology/path?a=...&b=... (page) and plugin/topology/api/path (JSON). a and b are an IP or MAC
 * address, a hostname or a device name; a_device / a_port (b_device / b_port) give a device and port by id instead;
 * vlan the VLAN of a Layer 2 path.
 */
class PathController extends Controller
{
    use BuildsTopology;

    public function index(Request $request): View
    {
        $query = self::query($request);
        $ready = ($query['a'] !== '' || $query['a_device']) && ($query['b'] !== '' || $query['b_device']);

        return $this->page($request, 'path', 'path trace', fn ($params, PluginSettings $settings) => $ready ? $this->paths($request, $settings)->trace($query) : null,
            ['query' => $query]);
    }

    public function api(Request $request): JsonResponse
    {
        return $this->json('path trace', fn () => $this->paths($request, $this->settings())->trace(self::query($request)));
    }

    private function paths(Request $request, PluginSettings $settings): PathService
    {
        $user = $request->user();
        $topology = new TopologyRepository($user);
        $hosts = new HostService($topology->sibling(HostRepository::class), $topology, $settings, new DnsResolver((string) ($user->user_id ?? $request->ip())));

        return new PathService($topology->sibling(PathRepository::class), $topology, $settings, fn (string $q) => $hosts->search($q));
    }

    /**
     * The ends and the VLAN; anything but a string (a[]=...) counts as empty, ids must be positive integers.
     *
     * @return array{a: string, b: string, a_device: ?int, a_port: ?int, b_device: ?int, b_port: ?int, vlan: ?int}
     */
    private static function query(Request $request): array
    {
        $text = function (string $key) use ($request): string {
            $value = $request->query($key, '');

            return is_string($value) ? mb_substr(trim($value), 0, 255) : '';
        };
        $id = function (string $key, int $max = PHP_INT_MAX) use ($request): ?int {
            $value = $request->query($key);

            return is_string($value) && ctype_digit($value) && (int) $value > 0 && (int) $value <= $max ? (int) $value : null;
        };

        return ['a' => $text('a'), 'b' => $text('b'), 'a_device' => $id('a_device'), 'a_port' => $id('a_port'),
            'b_device' => $id('b_device'), 'b_port' => $id('b_port'), 'vlan' => $id('vlan', 4094)];
    }
}
