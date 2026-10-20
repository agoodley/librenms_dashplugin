<?php

namespace Agoodley\LibrenmsTopology\Data;

use App\Models\Eventlog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LibreNMS\Enum\Severity;

/**
 * LibreNMS Custom Maps made from the plugin's maps (Sharing\CustomMapLayout): whether this LibreNMS has a
 * custom map schema the plugin knows, the maps the plugin created (the only ones it replaces) and
 * writing one. The caller checks that the user may (LibreNMS admins).
 */
class CustomMapRepository extends ScopedRepository
{
    /** Columns the plugin fills, by table; a column that must be set and is not one of these is a schema it does not know. */
    private const COLUMNS = [
        'custom_maps' => ['custom_map_id', 'name', 'menu_group', 'width', 'height', 'node_align', 'reverse_arrows', 'edge_separation',
            'legend_x', 'legend_y', 'legend_steps', 'legend_font_size', 'legend_hide_invalid', 'legend_hide_overspeed', 'legend_colours',
            'options', 'newnodeconfig', 'newedgeconfig', 'background_type', 'background_data', 'background_suffix', 'background_version',
            'created_at', 'updated_at'],
        'custom_map_nodes' => ['custom_map_node_id', 'custom_map_id', 'device_id', 'linked_custom_map_id', 'label', 'style', 'icon', 'image',
            'node_image_id', 'size', 'border_width', 'text_face', 'text_size', 'text_colour', 'colour_bg', 'colour_bdr', 'x_pos', 'y_pos',
            'created_at', 'updated_at'],
        'custom_map_edges' => ['custom_map_edge_id', 'custom_map_id', 'custom_map_node1_id', 'custom_map_node2_id', 'port_id', 'reverse',
            'style', 'showpct', 'showbps', 'label', 'fixed_width', 'text_face', 'text_size', 'text_colour', 'text_align', 'mid_x', 'mid_y',
            'created_at', 'updated_at'],
    ];

    /** Columns every LibreNMS with custom maps has (24.1 and later). */
    private const REQUIRED = [
        'custom_maps' => ['custom_map_id', 'name', 'width', 'height', 'options', 'newnodeconfig', 'newedgeconfig'],
        'custom_map_nodes' => ['custom_map_node_id', 'custom_map_id', 'device_id', 'label', 'style', 'icon', 'size', 'border_width',
            'text_face', 'text_size', 'text_colour', 'colour_bg', 'colour_bdr', 'x_pos', 'y_pos'],
        'custom_map_edges' => ['custom_map_edge_id', 'custom_map_id', 'custom_map_node1_id', 'custom_map_node2_id', 'port_id', 'reverse',
            'style', 'showpct', 'text_face', 'text_size', 'text_colour', 'mid_x', 'mid_y'],
    ];

    /** @var array<string, string[]> columns of the custom map tables */
    private array $columns = [];

    /**
     * Why custom maps cannot be written on this LibreNMS (none, or a schema the plugin does not know),
     * null when they can.
     */
    public function unsupported(): ?string
    {
        try {
            foreach (self::REQUIRED as $table => $required) {
                if (! Schema::hasTable($table)) {
                    return "This LibreNMS has no custom maps (table $table is missing); they came with LibreNMS 24.1.";
                }
                $missing = array_diff($required, $this->columns($table));
                if ($missing) {
                    return "This LibreNMS has a custom map schema the plugin does not know: $table has no " . implode(', ', $missing) . '.';
                }
                $unknown = array_diff($this->mustBeSet($table), self::COLUMNS[$table]);
                if ($unknown) {
                    return "This LibreNMS has a custom map schema the plugin does not know: $table." . implode(", $table.", $unknown) . ' must be set.';
                }
            }
            if (! Schema::hasTable('topology_custom_maps')) {
                return 'The plugin\'s table topology_custom_maps is missing: run ./lnms migrate.';
            }
        } catch (\Throwable $e) {
            Log::warning('topology plugin: cannot read the custom map schema: ' . $e->getMessage());

            return 'Unable to read the custom map tables, see logs/librenms.log.';
        }

        return null;
    }

    /**
     * The custom maps this plugin created that still exist, newest first: custom_map_id => name, page,
     * created_by, updated_at, nodes and edges. Rows of maps deleted in LibreNMS are removed.
     *
     * @return array<int, array{id: int, name: string, page: string, created_by: ?string, updated_at: ?string, nodes: int, edges: int}>
     */
    public function pluginMaps(): array
    {
        DB::table('topology_custom_maps')->whereNotIn('custom_map_id', DB::table('custom_maps')->select('custom_map_id'))->delete();

        $maps = [];
        foreach (DB::table('topology_custom_maps as t')->join('custom_maps as m', 'm.custom_map_id', '=', 't.custom_map_id')
            ->orderByDesc('t.updated_at')->get(['t.custom_map_id', 'm.name', 't.page', 't.created_by', 't.updated_at']) as $row) {
            $maps[(int) $row->custom_map_id] = ['id' => (int) $row->custom_map_id, 'name' => (string) $row->name, 'page' => (string) $row->page,
                'created_by' => $row->created_by, 'updated_at' => $row->updated_at, 'nodes' => 0, 'edges' => 0];
        }
        if ($maps) {
            foreach (['nodes' => 'custom_map_nodes', 'edges' => 'custom_map_edges'] as $key => $table) {
                foreach (DB::table($table)->whereIntegerInRaw('custom_map_id', array_keys($maps))->groupBy('custom_map_id')
                    ->get(['custom_map_id', DB::raw('count(*) as n')]) as $row) {
                    $maps[(int) $row->custom_map_id][$key] = (int) $row->n;
                }
            }
        }

        return $maps;
    }

    /**
     * The images of LibreNMS' custom map icon set (html/images/custommap/icons) among $files.
     *
     * @param  string[]  $files
     * @return string[]
     */
    public function images(array $files): array
    {
        return array_values(array_filter($files, fn (string $file) => is_file(base_path('html/images/custommap/icons/' . $file))));
    }

    /**
     * LibreNMS' defaults for the text and size of new custom map nodes and edges.
     *
     * @return array{text_face: string, text_size: int, text_colour: string, edge_text_size: int, node_size: int}
     */
    public function style(): array
    {
        return [
            'text_face' => (string) self::config('custom_map.node_font_face', 'arial'),
            'text_size' => (int) self::config('custom_map.node_font_size', 14),
            'text_colour' => (string) self::config('custom_map.node_font_color', '#343434'),
            'edge_text_size' => (int) self::config('custom_map.edge_font_size', 12),
            'node_size' => (int) self::config('custom_map.node_size', 25),
        ];
    }

    /**
     * Write a custom map: a new one, or the nodes and edges of one the plugin created ($replace), whose
     * name, menu group and size change and whose settings, legend and background stay. Noted in the
     * eventlog.
     *
     * @param  array{nodes: array<string, array>, edges: array<int, array>, width: int, height: int}  $layout  CustomMapLayout::build()
     * @param  array  $params  the map parameters it was made from (MapParams)
     * @return int the custom_map_id
     */
    public function write(array $layout, string $name, ?string $group, string $page, array $params, ?int $replace): int
    {
        $username = (string) ($this->user->username ?? 'unknown');
        $now = now();

        $id = DB::transaction(function () use ($layout, $name, $group, $page, $params, $replace, $username, $now): int {
            $map = ['name' => $name, 'menu_group' => $group, 'width' => $layout['width'] . 'px', 'height' => $layout['height'] . 'px', 'updated_at' => $now];
            if ($replace !== null) {
                $id = $replace;
                DB::table('custom_maps')->where('custom_map_id', $id)->update($this->only('custom_maps', $map));
                DB::table('custom_map_edges')->where('custom_map_id', $id)->delete();
                DB::table('custom_map_nodes')->where('custom_map_id', $id)->delete();
            } else {
                $id = (int) DB::table('custom_maps')->insertGetId($this->only('custom_maps', $map + $this->newMap() + ['created_at' => $now]), 'custom_map_id');
            }

            $nodeIds = [];
            foreach ($layout['nodes'] as $key => $node) {
                $nodeIds[$key] = (int) DB::table('custom_map_nodes')->insertGetId(
                    $this->only('custom_map_nodes', ['custom_map_id' => $id, 'created_at' => $now, 'updated_at' => $now] + $node), 'custom_map_node_id');
            }
            $edges = array_map(fn (array $edge) => $this->only('custom_map_edges', [
                'custom_map_id' => $id,
                'custom_map_node1_id' => $nodeIds[$edge['from']],
                'custom_map_node2_id' => $nodeIds[$edge['to']],
                'created_at' => $now,
                'updated_at' => $now,
            ] + $edge), $layout['edges']);
            foreach (array_chunk($edges, 500) as $chunk) {
                DB::table('custom_map_edges')->insert($chunk);
            }

            $record = ['page' => $page, 'query' => json_encode($params), 'created_by' => mb_substr($username, 0, 128), 'updated_at' => $now];
            if (DB::table('topology_custom_maps')->where('custom_map_id', $id)->exists()) {
                DB::table('topology_custom_maps')->where('custom_map_id', $id)->update($record);
            } else {
                DB::table('topology_custom_maps')->insert($record + ['custom_map_id' => $id, 'created_at' => $now]);
            }

            return $id;
        });

        try {
            Eventlog::log(sprintf('Custom map "%s" (#%d) %s from the topology %s map by %s: %d nodes, %d links', $name, $id,
                $replace !== null ? 'replaced' : 'created', strtoupper($page), $username, count($layout['nodes']), count($layout['edges'])),
                null, 'system', Severity::Info);
        } catch (\Throwable $e) {
            Log::info("topology plugin: custom map $id written by $username (" . $e->getMessage() . ')');
        }

        return $id;
    }

    /**
     * Settings of a new map as LibreNMS' editor creates it (CustomMapController::store()).
     */
    private function newMap(): array
    {
        $background = self::config('custom_map.background_data', null);

        return [
            'node_align' => (int) self::config('custom_map.node_align', 10),
            'reverse_arrows' => self::config('custom_map.reverse_arrows', false) ? 1 : 0,
            'edge_separation' => (int) self::config('custom_map.edge_seperation', 10),
            'options' => json_encode([
                'interaction' => ['dragNodes' => false, 'dragView' => true, 'zoomView' => true],
                'manipulation' => ['enabled' => false],
                'physics' => ['enabled' => false],
            ]),
            'newnodeconfig' => json_encode([
                'borderWidth' => 1,
                'color' => [
                    'border' => self::config('custom_map.node_border', '#2B7CE9'),
                    'background' => self::config('custom_map.node_background', '#D2E5FF'),
                ],
                'font' => [
                    'color' => self::config('custom_map.node_font_color', '#343434'),
                    'size' => self::config('custom_map.node_font_size', 14),
                    'face' => self::config('custom_map.node_font_face', 'arial'),
                ],
                'icon' => [],
                'label' => true,
                'shape' => self::config('custom_map.node_type', 'box'),
                'size' => self::config('custom_map.node_size', 25),
            ]),
            'newedgeconfig' => json_encode([
                'arrows' => ['to' => ['enabled' => true]],
                'smooth' => ['type' => 'dynamic'],
                'font' => [
                    'color' => self::config('custom_map.edge_font_color', '#343434'),
                    'size' => self::config('custom_map.edge_font_size', 12),
                    'face' => self::config('custom_map.edge_font_face', 'arial'),
                    'align' => self::config('custom_map.edge_font_align', 'horizontal'),
                ],
                'label' => true,
            ]),
            'background_type' => (string) self::config('custom_map.background_type', 'none'),
            'background_data' => $background === null ? null : json_encode($background),
            'background_version' => 0, // before LibreNMS 24.5 only
        ];
    }

    /**
     * The values of the columns this table has.
     */
    private function only(string $table, array $values): array
    {
        return array_intersect_key($values, array_flip($this->columns($table)));
    }

    /**
     * @return string[]
     */
    private function columns(string $table): array
    {
        return $this->columns[$table] ??= Schema::getColumnListing($table);
    }

    /**
     * Columns of a table that an insert must set: not null, no default, not auto-incremented. None
     * when this Laravel cannot tell.
     *
     * @return string[]
     */
    private function mustBeSet(string $table): array
    {
        if (! method_exists(Schema::getFacadeRoot(), 'getColumns')) {
            return [];
        }

        return array_values(array_map(fn (array $column) => $column['name'], array_filter(Schema::getColumns($table),
            fn (array $column) => ! $column['nullable'] && $column['default'] === null && ! $column['auto_increment'])));
    }

    /**
     * A LibreNMS setting, $default on versions without the LibrenmsConfig facade.
     */
    private static function config(string $key, mixed $default): mixed
    {
        try {
            return class_exists(\App\Facades\LibrenmsConfig::class) ? \App\Facades\LibrenmsConfig::get($key, $default) : $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
