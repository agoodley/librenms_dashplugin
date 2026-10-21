<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Agoodley\LibrenmsTopology\Data\HostRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Hosts\DnsResolver;
use Agoodley\LibrenmsTopology\Hosts\HostService;
use Agoodley\LibrenmsTopology\Http\Controllers\Concerns\BuildsTopology;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Host locator: plugin/topology/hosts?q=... (page) and plugin/topology/api/hosts?q=... (JSON),
 * q being an IPv4/IPv6 address, a MAC address or a hostname.
 */
class HostController extends Controller
{
    use BuildsTopology;

    public function index(Request $request): View
    {
        $q = self::query($request);

        return $this->page($request, 'hosts', 'host search', fn ($params, PluginSettings $settings) => $q === '' ? null : $this->hosts($request, $settings)->search($q), ['q' => $q]);
    }

    public function api(Request $request): JsonResponse
    {
        return $this->json('host search', fn () => $this->hosts($request, $this->settings())->search(self::query($request)), 'hosts');
    }

    private function hosts(Request $request, PluginSettings $settings): HostService
    {
        $user = $request->user();

        return new HostService(new HostRepository($user), new TopologyRepository($user), $settings, new DnsResolver((string) ($user->user_id ?? $request->ip())));
    }

    /**
     * The search text; anything but a string (q[]=...) counts as empty.
     */
    private static function query(Request $request): string
    {
        $q = $request->query('q', '');

        return is_string($q) ? mb_substr(trim($q), 0, 255) : '';
    }
}
