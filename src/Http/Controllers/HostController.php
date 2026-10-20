<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Agoodley\LibrenmsTopology\Data\HostRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
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
        return $this->json('host search', fn () => $this->hosts($request, $this->settings())->search(self::query($request)));
    }

    private function hosts(Request $request, PluginSettings $settings): HostService
    {
        return new HostService(new HostRepository($request->user()), new TopologyRepository($request->user()), $settings);
    }

    private static function query(Request $request): string
    {
        return mb_substr(trim((string) $request->query('q', '')), 0, 255);
    }
}
