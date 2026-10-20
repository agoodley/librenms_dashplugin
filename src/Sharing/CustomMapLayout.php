<?php

namespace Agoodley\LibrenmsTopology\Sharing;

use Agoodley\LibrenmsTopology\Links\LinkAnalyzer;
use Agoodley\LibrenmsTopology\Support\DeviceRole;

/**
 * A LibreNMS Custom Map (custom_maps, custom_map_nodes, custom_map_edges) of an L2 or L3 map: one node
 * per map node where it is on the plugin's map, one edge per link with the port LibreNMS reads its
 * utilization from. Plain arrays; Data\CustomMapRepository writes them.
 */
class CustomMapLayout
{
    /** Space around the map, in pixels. */
    public const MARGIN = 80;

    /** Smallest size of the map, so that a small one is not zoomed in a lot by LibreNMS' viewer. */
    public const MIN_WIDTH = 800;

    public const MIN_HEIGHT = 500;

    /** Distance between the middles of parallel links, so that each one stays visible. */
    public const PARALLEL_GAP = 24;

    /** Grid of the nodes that had no position (new since the page was loaded): cell size in pixels. */
    private const GRID = [120, 100];

    /** Images of LibreNMS' custom map icon set (html/images/custommap/icons) for these roles. */
    public const IMAGES = [
        DeviceRole::SWITCH => 'switch-l2.svg',
        DeviceRole::L3SWITCH => 'switch-l3.svg',
        DeviceRole::ROUTER => 'router.svg',
        DeviceRole::FIREWALL => 'firewall.svg',
    ];

    /** Font Awesome icons of the custom map editor for the roles without an image (or when the image is missing). */
    public const ICONS = [
        DeviceRole::SWITCH => 'f6ff', // network-wired
        DeviceRole::L3SWITCH => 'f6ff',
        DeviceRole::ROUTER => 'f4d7', // the editor's "router"
        DeviceRole::FIREWALL => 'f3ed', // shield
        DeviceRole::WIRELESS => 'f1eb', // wifi
        DeviceRole::SERVER => 'f233', // server
        DeviceRole::PHONE => 'f3ce', // mobile
        DeviceRole::OTHER => 'f2db', // microchip
        'cloud' => 'f0c2', // cloud: an external AS on the L3 map
        DeviceRole::UNKNOWN => 'f6ff',
    ];

    /** Labels of the L3 links that have no address of their own. */
    private const KINDS = ['ospfv3' => 'OSPFv3', 'isis' => 'IS-IS', 'ipsec' => 'IPsec', 'mpls' => 'MPLS LSP', 'pw' => 'Pseudowire'];

    /**
     * @param  array{nodes: array, edges: array}  $map  api/l2 or api/l3 data (L2Builder, L3Builder)
     * @param  array<string, array{0: float, 1: float}>  $positions  map node id => [x, y] on the plugin's map; ids
     *     that are not nodes of $map are ignored
     * @param  string[]  $images  the files of IMAGES this LibreNMS has; other roles get the device's OS icon
     * @param  array{text_face: string, text_size: int, text_colour: string, edge_text_size: int, node_size: int}  $style
     *     LibreNMS' defaults for new nodes and edges
     * @return array{nodes: array<string, array>, edges: array<int, array>, width: int, height: int,
     *     stats: array{devices: int, unmanaged: int, subnets: int, clouds: int, edges: int, ports: int, unplaced: int}}
     *     nodes by map node id; edges name their nodes by map node id (from, to)
     */
    public static function build(array $map, array $positions, array $images, array $style): array
    {
        $nodes = [];
        $placed = [];
        foreach ($map['nodes'] ?? [] as $node) {
            $id = (string) $node['id'];
            $nodes[$id] = self::node($node, $images, $style);
            $position = $positions[$id] ?? null;
            if (is_array($position) && count($position) === 2 && is_numeric($position[0] ?? null) && is_numeric($position[1] ?? null)
                && is_finite((float) $position[0]) && is_finite((float) $position[1])) {
                $placed[$id] = [(float) $position[0], (float) $position[1]];
            }
        }

        [$width, $height] = self::place($nodes, $placed);

        $edges = [];
        $pairs = [];
        $ports = 0;
        foreach ($map['edges'] ?? [] as $edge) {
            $from = (string) $edge['from'];
            $to = (string) $edge['to'];
            if ($from === $to || ! isset($nodes[$from], $nodes[$to])) {
                continue;
            }
            [$portId, $reverse] = self::port($edge);
            $ports += $portId !== null ? 1 : 0;
            $pair = strcmp($from, $to) < 0 ? [$from, $to] : [$to, $from];
            $index = $pairs[$pair[0] . '|' . $pair[1]] = ($pairs[$pair[0] . '|' . $pair[1]] ?? -1) + 1;
            [$midX, $midY] = self::middle($nodes[$pair[0]], $nodes[$pair[1]], $index);
            $edges[] = [
                'from' => $from,
                'to' => $to,
                'port_id' => $portId,
                'reverse' => $reverse,
                'style' => 'dynamic', // LibreNMS' default: a straight line through the middle when nothing moves
                'showpct' => $portId !== null,
                'showbps' => false,
                'label' => mb_substr(self::edgeLabel($edge), 0, 255),
                'text_face' => $style['text_face'],
                'text_size' => $style['edge_text_size'],
                'text_colour' => $style['text_colour'],
                'text_align' => 'horizontal',
                'mid_x' => $midX,
                'mid_y' => $midY,
            ];
        }

        $types = array_count_values(array_column($nodes, 'kind'));

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'width' => $width,
            'height' => $height,
            'stats' => [
                'devices' => $types['device'] ?? 0,
                'unmanaged' => $types['unmanaged'] ?? 0,
                'subnets' => $types['subnet'] ?? 0,
                'clouds' => $types['cloud'] ?? 0,
                'edges' => count($edges),
                'ports' => $ports,
                'unplaced' => count($nodes) - count($placed),
            ],
        ];
    }

    /**
     * Node of the custom map for a map node: monitored devices by device id (LibreNMS shows their
     * live status), unmanaged neighbours and AS clouds, each with LibreNMS' image of its role or
     * else an icon of the role; subnets as boxes.
     */
    private static function node(array $node, array $images, array $style): array
    {
        $type = (string) ($node['type'] ?? 'device');
        $managed = ! empty($node['managed']) && ! empty($node['device_id']);
        $role = $type === 'as' ? 'cloud' : (string) ($node['role'] ?? DeviceRole::UNKNOWN);
        $row = [
            'kind' => match (true) {
                $type === 'subnet' => 'subnet',
                $type === 'as' => 'cloud',
                $managed => 'device',
                default => 'unmanaged',
            },
            'device_id' => $managed ? (int) $node['device_id'] : null,
            'label' => mb_substr(trim((string) ($node['label'] ?? $node['name'] ?? $node['id'])), 0, 50),
            'style' => 'image',
            'icon' => null,
            'image' => '',
            'size' => $managed || $type === 'subnet' ? $style['node_size'] : (int) round($style['node_size'] * 0.7),
            'border_width' => 1,
            'text_face' => $style['text_face'],
            'text_size' => $managed || $type === 'subnet' ? $style['text_size'] : max(8, $style['text_size'] - 2),
            'text_colour' => $style['text_colour'],
            'colour_bg' => null,
            'colour_bdr' => null,
            'x_pos' => 0,
            'y_pos' => 0,
        ];

        if ($type === 'subnet') {
            return ['style' => 'box', 'colour_bg' => '#FFF6D5', 'colour_bdr' => '#C9A227'] + $row;
        }
        $image = self::IMAGES[$role] ?? null;
        if ($image !== null && in_array($image, $images, true)) {
            $row['image'] = $image;
        } else {
            $row['style'] = 'icon';
            $row['icon'] = self::ICONS[$role] ?? self::ICONS[DeviceRole::UNKNOWN];
            // no color for a monitored device: LibreNMS colors its icon by status (blue up, red down)
            $row['colour_bdr'] = $managed ? null : '#8B9CAD';
        }

        return $row;
    }

    /**
     * Pixel positions of the custom map: the plugin's map moved so that it starts at MARGIN, nodes
     * without a position in rows below it.
     *
     * @param  array<string, array>  $nodes  updated with x_pos / y_pos
     * @param  array<string, array{0: float, 1: float}>  $placed
     * @return array{0: int, 1: int} width and height
     */
    private static function place(array &$nodes, array $placed): array
    {
        $minX = $placed ? min(array_column($placed, 0)) : 0.0;
        $minY = $placed ? min(array_column($placed, 1)) : 0.0;
        $right = self::MARGIN;
        $bottom = self::MARGIN;
        foreach ($placed as $id => [$x, $y]) {
            $nodes[$id]['x_pos'] = (int) round($x - $minX) + self::MARGIN;
            $nodes[$id]['y_pos'] = (int) round($y - $minY) + self::MARGIN;
            $right = max($right, $nodes[$id]['x_pos']);
            $bottom = max($bottom, $nodes[$id]['y_pos']);
        }

        $rest = array_keys(array_diff_key($nodes, $placed));
        if ($rest) {
            $perRow = max(1, intdiv(max(self::MIN_WIDTH, $right + self::MARGIN) - self::MARGIN, self::GRID[0]));
            $top = $placed ? $bottom + self::GRID[1] : self::MARGIN;
            foreach ($rest as $index => $id) {
                $nodes[$id]['x_pos'] = self::MARGIN + ($index % $perRow) * self::GRID[0];
                $nodes[$id]['y_pos'] = $top + intdiv($index, $perRow) * self::GRID[1];
                $right = max($right, $nodes[$id]['x_pos']);
                $bottom = max($bottom, $nodes[$id]['y_pos']);
            }
        }

        return [max(self::MIN_WIDTH, $right + self::MARGIN), max(self::MIN_HEIGHT, $bottom + self::MARGIN)];
    }

    /**
     * The port LibreNMS takes the utilization of a link from, and whether it is on the far side
     * (reverse): the near end's port (the aggregate of a collapsed port-channel), else the far end's.
     *
     * @return array{0: int|null, 1: bool}
     */
    private static function port(array $edge): array
    {
        foreach (['a' => false, 'b' => true] as $side => $reverse) {
            $portId = $edge[$side]['port_id'] ?? null;
            if (is_numeric($portId) && (int) $portId > 0) {
                return [(int) $portId, $reverse];
            }
        }

        return [null, false];
    }

    /**
     * Middle of a link (LibreNMS draws an edge in two halves through it): the middle of the line,
     * moved sideways for the second and further links between the same two nodes.
     *
     * @return array{0: int, 1: int}
     */
    private static function middle(array $a, array $b, int $index): array
    {
        $x = ($a['x_pos'] + $b['x_pos']) / 2;
        $y = ($a['y_pos'] + $b['y_pos']) / 2;
        if ($index > 0) {
            $dx = $b['x_pos'] - $a['x_pos'];
            $dy = $b['y_pos'] - $a['y_pos'];
            $length = sqrt($dx * $dx + $dy * $dy) ?: 1.0;
            $offset = (int) ceil($index / 2) * self::PARALLEL_GAP * ($index % 2 ? 1 : -1);
            $x += -$dy / $length * $offset;
            $y += $dx / $length * $offset;
        }

        return [(int) round($x), (int) round($y)];
    }

    /**
     * Label in the middle of a link: its speed (2x 10G for a port-channel) on the L2 map; the network
     * of a point-to-point link or the protocol of an adjacency on the L3 map.
     */
    public static function edgeLabel(array $edge): string
    {
        $kind = $edge['kind'] ?? null;
        if ($kind === null) {
            $speed = (int) ($edge['speed'] ?? 0);
            $members = (int) ($edge['lag']['members'] ?? 0);
            if ($members > 1 && $speed > 0) {
                return $members . 'x ' . LinkAnalyzer::speed(intdiv($speed, $members));
            }

            return $speed > 0 ? LinkAnalyzer::speed($speed) : '';
        }

        return match ($kind) {
            'p2p' => (string) ($edge['network'] ?? ''),
            'member' => '',
            'ospf' => 'OSPF',
            'bgp' => trim(($edge['type'] ?? 'BGP') . (! empty($edge['remote_as']) ? ' AS' . $edge['remote_as'] : '')),
            default => self::KINDS[$kind] ?? (string) $kind,
        };
    }
}
