<?php

namespace Agoodley\LibrenmsTopology\Sharing;

use Agoodley\LibrenmsTopology\Links\LinkAnalyzer;
use Agoodley\LibrenmsTopology\Support\DeviceRole;

/**
 * A LibreNMS Custom Map (custom_maps, custom_map_nodes, custom_map_edges) of an L2 or L3 map: one node
 * per map node where it is on the plugin's map, one edge per link with the port LibreNMS reads its
 * status and utilization from. Plain arrays; Data\CustomMapRepository writes them.
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

    /**
     * Font Awesome icons (the custom map editor's icon style) by role, all from the icon list of LibreNMS'
     * node dialog (resources/views/map/custom-node-modal.blade.php): a node with another icon loses it
     * when it is edited there. LibreNMS colors the icon of a monitored device by its status, blue when up
     * and red when down; unmanaged neighbors are grey.
     */
    public const ICONS = [
        DeviceRole::SWITCH => 'f796', // ethernet
        DeviceRole::L3SWITCH => 'f6ff', // network-wired
        DeviceRole::ROUTER => 'f4d7', // route: the editor's "router"
        DeviceRole::FIREWALL => 'f3ed', // shield
        DeviceRole::WIRELESS => 'f1eb', // wifi
        DeviceRole::SERVER => 'f233', // server
        DeviceRole::PHONE => 'f3ce', // mobile
        DeviceRole::OTHER => 'f2db', // microchip
        'cloud' => 'f0c2', // cloud: an external AS on the L3 map
        DeviceRole::UNKNOWN => 'f2db', // microchip
    ];

    /** Color of the icons of unmanaged neighbors and AS clouds, which have no status. */
    public const UNMANAGED_COLOR = '#8B9CAD';

    /**
     * Characters that mean something in HTML and their look-alikes: LibreNMS' map editor puts labels
     * into HTML (the options of its edge dialog), and names come from devices and their LLDP/CDP
     * neighbors, which anyone on an access port can name. vis.js shows labels as text, so no entities.
     */
    private const UNSAFE = ['<' => '‹', '>' => '›', '"' => '″', "'" => '’', '&' => '＆', '`' => 'ˋ'];

    /** Labels of the L3 links that have no address of their own. */
    private const KINDS = ['ospfv3' => 'OSPFv3', 'isis' => 'IS-IS', 'ipsec' => 'IPsec', 'mpls' => 'MPLS LSP', 'pw' => 'Pseudowire'];

    /**
     * @param  array{nodes: array, edges: array}  $map  api/l2 or api/l3 data (L2Builder, L3Builder)
     * @param  array<string, array{0: float, 1: float}>  $positions  map node id => [x, y] on the plugin's map; ids
     *     that are not nodes of $map are ignored
     * @param  array{text_face: string, text_size: int, text_colour: string, node_size: int, edge_text_face: string,
     *     edge_text_size: int, edge_text_colour: string, edge_text_align: string}  $style  LibreNMS' defaults for new
     *     nodes and edges
     * @return array{nodes: array<string, array>, edges: array<int, array>, width: int, height: int,
     *     stats: array{devices: int, unmanaged: int, subnets: int, clouds: int, edges: int, ports: int, unplaced: int}}
     *     nodes by map node id; edges name their nodes by map node id (from, to)
     */
    public static function build(array $map, array $positions, array $style): array
    {
        $nodes = [];
        $placed = [];
        foreach ($map['nodes'] ?? [] as $node) {
            $id = (string) $node['id'];
            $nodes[$id] = self::node($node, $style);
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
                'label' => self::label(self::edgeLabel($edge), 255),
                'text_face' => $style['edge_text_face'],
                'text_size' => $style['edge_text_size'],
                'text_colour' => $style['edge_text_colour'],
                'text_align' => $style['edge_text_align'],
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
     * A label written to LibreNMS: the characters that matter in HTML become look-alikes (UNSAFE),
     * control characters other than line breaks become spaces (one between two words: "Gi1/0/1\tuplink"
     * is "Gi1/0/1 uplink"), and it is cut to the column's length.
     */
    public static function label(string $text, int $length): string
    {
        $text = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', strtr(mb_scrub($text, 'UTF-8'), self::UNSAFE));
        $text = (string) preg_replace(['/ {2,}/', '/ ?\n ?/'], [' ', "\n"], $text);

        return rtrim(mb_substr(trim($text), 0, $length));
    }

    /**
     * A map name or menu group as LibreNMS' map editor can show it: it puts the name into a JavaScript
     * string, which a line break ends and a backslash escapes (a trailing one takes its closing quote,
     * "\u" or "\x" are errors), so control characters become spaces and backslashes their look-alike.
     * Anything but a string or number is no name ('').
     */
    public static function mapName(mixed $input): string
    {
        if (! is_scalar($input)) {
            return '';
        }

        return trim(str_replace('\\', '＼', (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', mb_scrub((string) $input, 'UTF-8'))));
    }

    /**
     * Node of the custom map for a map node: monitored devices by device id, unmanaged neighbors and
     * AS clouds, each as the icon of its role (LibreNMS colors a monitored device's icon by status);
     * subnets as boxes.
     */
    private static function node(array $node, array $style): array
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
            'label' => self::label((string) ($node['label'] ?? $node['name'] ?? $node['id']), 50),
            'style' => 'icon',
            'icon' => self::ICONS[$role] ?? self::ICONS[DeviceRole::UNKNOWN],
            'image' => '',
            'size' => $managed || $type === 'subnet' ? $style['node_size'] : (int) round($style['node_size'] * 0.7),
            'border_width' => 1,
            'text_face' => $style['text_face'],
            'text_size' => $managed || $type === 'subnet' ? $style['text_size'] : max(8, $style['text_size'] - 2),
            'text_colour' => $style['text_colour'],
            'colour_bg' => null,
            // no color for a monitored device: LibreNMS colors its icon by status
            'colour_bdr' => $managed ? null : self::UNMANAGED_COLOR,
            'x_pos' => 0,
            'y_pos' => 0,
        ];

        if ($type === 'subnet') {
            return ['style' => 'box', 'icon' => null, 'colour_bg' => '#FFF6D5', 'colour_bdr' => '#C9A227'] + $row;
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
     * The port LibreNMS takes a link's status and utilization from, and whether it is on the far side
     * (reverse). On the L2 map: a port-channel only when it is wholly this link (L2Builder's a_whole /
     * b_whole: not one split over two switches, an MLAG), else the port of the cable when the link is
     * one cable, else none: the port of one of several cables would show that cable only. On the L3
     * map: the port of the near end's address, else the far end's.
     *
     * @return array{0: int|null, 1: bool}
     */
    private static function port(array $edge): array
    {
        $id = fn (mixed $port): ?int => is_numeric($port) && (int) $port > 0 ? (int) $port : null;

        if (! array_key_exists('members', $edge)) {
            foreach (['a' => false, 'b' => true] as $side => $reverse) {
                if (($port = $id($edge[$side]['port_id'] ?? null)) !== null) {
                    return [$port, $reverse];
                }
            }

            return [null, false];
        }

        $lag = $edge['lag'] ?? null;
        foreach (['a' => false, 'b' => true] as $side => $reverse) {
            if (! empty($lag[$side . '_whole']) && ($port = $id($edge[$side]['port_id'] ?? null)) !== null) {
                return [$port, $reverse];
            }
        }
        $members = $edge['members'] ?? [];
        if (count($members) === 1) {
            foreach (['a' => false, 'b' => true] as $side => $reverse) {
                if (($port = $id($members[0][$side . '_port_id'] ?? null)) !== null) {
                    return [$port, $reverse];
                }
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
