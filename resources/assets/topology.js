/*
 * LibreNMS Topology plugin - NetBrain style Layer 2 / Layer 3 maps.
 * Renders the JSON produced by plugin/topology/api/{l2,l3} with vis-network (bundled with LibreNMS).
 */
(function (window, document) {
    'use strict';

    // ------------------------------------------------------------------ helpers

    function esc(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    /**
     * Bits per second to a short speed label: 10000000000 => "10G".
     */
    function formatSpeed(bps) {
        bps = Number(bps) || 0;
        if (!bps) {
            return '';
        }
        var units = [[1e12, 'T'], [1e9, 'G'], [1e6, 'M'], [1e3, 'K']];
        for (var i = 0; i < units.length; i++) {
            if (bps >= units[i][0]) {
                var value = bps / units[i][0];
                return (Math.round(value * 10) / 10) + units[i][1];
            }
        }
        return bps + 'bps';
    }

    /**
     * Traffic rate label ("1.2Gbps").
     */
    function formatRate(bps) {
        var speed = formatSpeed(bps);
        return speed ? speed + 'bps' : '0bps';
    }

    /**
     * Seconds to "95s", "12m", "3.5h" or "2.1d".
     */
    function formatDuration(seconds) {
        if (seconds === null || seconds === undefined) {
            return '-';
        }
        seconds = Number(seconds);
        if (seconds < 120) {
            return seconds + 's';
        }
        if (seconds < 7200) {
            return Math.round(seconds / 60) + 'm';
        }
        if (seconds < 172800) {
            return (Math.round(seconds / 360) / 10) + 'h';
        }
        return (Math.round(seconds / 8640) / 10) + 'd';
    }

    /**
     * LibreNMS toggles the "dark" class on <html> for its dark theme.
     */
    function isDark() {
        return document.documentElement.classList.contains('dark');
    }

    /**
     * Canvas colors for the current LibreNMS theme.
     */
    function theme() {
        return isDark() ? {
            background: '#1d2024', text: '#e6e6e6', textStroke: '#1d2024', labelBg: 'rgba(36,39,44,0.88)',
            labelText: '#dcdcdc', labelBorder: 'rgba(120,120,120,0.6)', subnetBg: '#3b3524', subnetBorder: '#c9a227',
            member: '#7f8c99', muted: 'rgba(140,140,140,0.25)'
        } : {
            background: '#ffffff', text: '#222222', textStroke: '#ffffff', labelBg: 'rgba(255,255,255,0.9)',
            labelText: '#333333', labelBorder: 'rgba(160,160,160,0.7)', subnetBg: '#fff6d5', subnetBorder: '#c9a227',
            member: '#7f8c99', muted: 'rgba(160,160,160,0.25)'
        };
    }

    // ------------------------------------------------------------------ icons (drawn as SVG, Cisco/NetBrain-like)

    var STATUS_COLORS = {up: '#1f6fb2', down: '#c0392b', disabled: '#8a949e', unknown: '#7d8a96', degraded: '#e67e22'};
    var iconCache = {};

    /**
     * Lighten (positive amount) or darken (negative) a #rrggbb color.
     */
    function shade(hex, amount) {
        var num = parseInt(hex.slice(1), 16);
        var r = Math.max(0, Math.min(255, (num >> 16) + amount));
        var g = Math.max(0, Math.min(255, ((num >> 8) & 0xff) + amount));
        var b = Math.max(0, Math.min(255, (num & 0xff) + amount));
        return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
    }

    /**
     * White arrow from (x1,y1) to (x2,y2) used inside the device icons.
     */
    function arrow(x1, y1, x2, y2) {
        var angle = Math.atan2(y2 - y1, x2 - x1);
        var head = 5;
        var hx1 = x2 - head * Math.cos(angle - 0.5), hy1 = y2 - head * Math.sin(angle - 0.5);
        var hx2 = x2 - head * Math.cos(angle + 0.5), hy2 = y2 - head * Math.sin(angle + 0.5);
        return '<line x1="' + x1 + '" y1="' + y1 + '" x2="' + x2 + '" y2="' + y2 + '" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/>' +
            '<polygon points="' + x2 + ',' + y2 + ' ' + hx1.toFixed(1) + ',' + hy1.toFixed(1) + ' ' + hx2.toFixed(1) + ',' + hy2.toFixed(1) + '" fill="#fff"/>';
    }

    /**
     * SVG body (64x64 viewBox) of the icon for a device role, drawn in the given color.
     */
    function svgFor(role, color) {
        var dark = shade(color, -45);
        var light = shade(color, 35);
        switch (role) {
            case 'router':
                return '<ellipse cx="32" cy="40" rx="28" ry="11" fill="' + dark + '"/>' +
                    '<rect x="4" y="24" width="56" height="16" fill="' + dark + '"/>' +
                    '<ellipse cx="32" cy="24" rx="28" ry="11" fill="' + color + '" stroke="' + light + '" stroke-width="1"/>' +
                    arrow(14, 19, 25, 23) + arrow(50, 29, 39, 25) + arrow(28, 27, 17, 30) + arrow(36, 21, 47, 18);
            case 'l3switch':
                return '<polygon points="10,18 22,8 58,8 46,18" fill="' + light + '"/>' +
                    '<polygon points="46,18 58,8 58,46 46,56" fill="' + dark + '"/>' +
                    '<rect x="10" y="18" width="36" height="38" fill="' + color + '"/>' +
                    arrow(28, 37, 18, 27) + arrow(28, 37, 38, 47) + arrow(28, 37, 38, 27) + arrow(28, 37, 18, 47) +
                    '<circle cx="28" cy="37" r="4" fill="#fff"/>';
            case 'switch':
                return '<polygon points="4,26 16,16 60,16 48,26" fill="' + light + '"/>' +
                    '<polygon points="48,26 60,16 60,38 48,48" fill="' + dark + '"/>' +
                    '<rect x="4" y="26" width="44" height="22" fill="' + color + '"/>' +
                    arrow(9, 32, 22, 32) + arrow(43, 32, 30, 32) + arrow(22, 42, 9, 42) + arrow(30, 42, 43, 42);
            case 'firewall':
                var bricks = '';
                for (var row = 0; row < 4; row++) {
                    var y = 14 + row * 10;
                    bricks += '<line x1="6" y1="' + y + '" x2="58" y2="' + y + '" stroke="#fff" stroke-width="1.6"/>';
                    for (var col = (row % 2 ? 12 : 22); col < 58; col += 20) {
                        bricks += '<line x1="' + col + '" y1="' + (y - 10) + '" x2="' + col + '" y2="' + y + '" stroke="#fff" stroke-width="1.6"/>';
                    }
                }
                return '<rect x="6" y="4" width="52" height="50" rx="2" fill="' + (color === STATUS_COLORS.up ? '#c0562b' : color) + '"/>' + bricks;
            case 'wireless':
                return '<rect x="12" y="34" width="40" height="18" rx="5" fill="' + color + '"/>' +
                    '<circle cx="32" cy="43" r="3" fill="#fff"/>' +
                    '<path d="M20 28 Q32 16 44 28" stroke="' + color + '" stroke-width="4" fill="none" stroke-linecap="round"/>' +
                    '<path d="M13 21 Q32 2 51 21" stroke="' + color + '" stroke-width="4" fill="none" stroke-linecap="round"/>';
            case 'server':
                return '<rect x="16" y="4" width="32" height="56" rx="3" fill="' + color + '"/>' +
                    '<rect x="21" y="11" width="22" height="4" fill="' + light + '"/><rect x="21" y="19" width="22" height="4" fill="' + light + '"/>' +
                    '<rect x="21" y="27" width="22" height="4" fill="' + light + '"/><circle cx="32" cy="48" r="4" fill="#fff"/>';
            case 'phone':
                return '<rect x="14" y="26" width="36" height="28" rx="4" fill="' + color + '"/>' +
                    '<path d="M10 22 Q32 6 54 22 L48 28 Q32 18 16 28 Z" fill="' + dark + '"/>' +
                    '<rect x="22" y="33" width="20" height="14" rx="2" fill="' + light + '"/>';
            case 'cloud':
                return '<path d="M18 48 Q6 48 7 38 Q8 29 18 30 Q19 17 32 17 Q44 17 46 28 Q57 27 58 38 Q58 48 46 48 Z" fill="' + light + '" stroke="' + dark + '" stroke-width="2"/>';
            case 'other':
                return '<rect x="10" y="14" width="44" height="36" rx="6" fill="' + color + '"/><circle cx="32" cy="32" r="8" fill="#fff"/>';
            default:
                return '<circle cx="32" cy="32" r="22" fill="' + color + '"/><text x="32" y="40" font-size="24" font-family="Arial" font-weight="bold" text-anchor="middle" fill="#fff">?</text>';
        }
    }

    /**
     * Data URI of a role icon colored by status (blue up, red down, grey disabled/unmanaged), cached.
     */
    function iconUrl(role, status, managed) {
        var color = STATUS_COLORS[status] || STATUS_COLORS.unknown;
        if (managed === false && status === 'unknown') {
            color = '#8b9cad';
        }
        var key = role + '|' + color;
        if (!iconCache[key]) {
            var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">' + svgFor(role, color) + '</svg>';
            iconCache[key] = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
        }
        return iconCache[key];
    }

    // ------------------------------------------------------------------ edge styling

    function speedColor(speed) {
        if (!speed) return '#8e9aa6';
        if (speed >= 100e9) return '#b0279e';
        if (speed >= 40e9) return '#6f42c1';
        if (speed >= 25e9) return '#3949ab';
        if (speed >= 10e9) return '#1f6fb2';
        if (speed >= 2e9) return '#17a2b8';
        if (speed >= 1e9) return '#2e9e44';
        return '#8e9aa6';
    }

    /**
     * Link color for a utilization percentage (green < 30%, yellow, orange, red >= 80%).
     */
    function utilColor(util) {
        if (util === null || util === undefined) return '#8e9aa6';
        if (util >= 80) return '#c0392b';
        if (util >= 60) return '#e67e22';
        if (util >= 30) return '#d4ac0d';
        return '#2e9e44';
    }

    /**
     * Link width grows with speed; aggregates are drawn one pixel wider.
     */
    function speedWidth(speed, lag) {
        var width = 1.5;
        if (speed >= 100e9) width = 5.5;
        else if (speed >= 40e9) width = 4.5;
        else if (speed >= 10e9) width = 3.5;
        else if (speed >= 1e9) width = 2.5;
        return lag ? width + 1 : width;
    }

    var STP_TAGS = {root: 'R', designated: 'D', alternate: 'ALT', backup: 'BK', nondesignated: 'FWD?', broken: 'BRK', disabled: 'DIS'};

    /** Roles that pass traffic between their links (monitored devices). */
    var NETWORK_ROLES = ['switch', 'l3switch', 'router', 'firewall'];

    /** Unmanaged neighbors that do not pass traffic between their links, by the role of their platform. */
    var END_ROLES = ['server', 'phone', 'wireless'];

    /** L3 overlays beyond OSPF and BGP: edge kind => name, color and dash pattern (distinct from OSPF, BGP and subnets). */
    var L3_LAYERS = {
        ospfv3: {name: 'OSPFv3 adjacency', color: '#2e86de', dashes: [2, 4]},
        isis: {name: 'IS-IS adjacency', color: '#795548', dashes: [10, 3, 2, 3]},
        ipsec: {name: 'IPsec tunnel', color: '#c9a20a', dashes: [14, 5]},
        mpls: {name: 'MPLS LSP', color: '#d63384', dashes: [6, 3]},
        pw: {name: 'Pseudowire', color: '#5e60ce', dashes: [2, 2]}
    };

    // ------------------------------------------------------------------ the map

    function TopologyMap(config) {
        this.config = config;
        this.mode = config.mode;
        this.data = null;
        this.network = null;
        this.nodes = new window.vis.DataSet();
        this.edges = new window.vis.DataSet();
        this.view = {
            layout: 'hierarchical',
            interfaceLabels: true,
            linkLabels: true,
            colorBy: 'speed',
            stp: true,
            outages: true
        };
        // failure simulation: references of the failed devices / links (d:12, u:name, p:345, l:a|b, also from
        // the URL: fail=...), what of them is on the map, and the server's answer
        this.sim = {refs: (new URLSearchParams(window.location.search).get('fail') || '').split(',').filter(Boolean)};
        this.simMap = {nodes: {}, edges: {}};
        this.simResult = null;
        this.outages = {top: {}, behind: {}};
        this.container = document.getElementById('topo-canvas');
        this.panel = document.getElementById('topo-details');
        this.status = document.getElementById('topo-status');
        this.loading = document.getElementById('topo-loading');
        this.restoreView();
        this.bindControls();
        this.load();
    }

    /**
     * View options (layout, labels, colors, STP overlay) remembered per map type in this browser.
     */
    TopologyMap.prototype.restoreView = function () {
        try {
            var saved = JSON.parse(window.localStorage.getItem('topology.view.' + this.mode) || '{}');
            for (var key in saved) {
                if (Object.prototype.hasOwnProperty.call(this.view, key)) {
                    this.view[key] = saved[key];
                }
            }
            // first visit of the L3 map: the organic layout suits subnets better
            if (this.mode === 'l3' && !window.localStorage.getItem('topology.view.l3')) {
                this.view.layout = 'organic';
            }
            if (this.mode === 'sites' && !window.localStorage.getItem('topology.view.sites')) {
                this.view.layout = 'geographic';
            }
        } catch (e) {
            // storage unavailable (blocked site data, private mode): keep the defaults
        }
    };

    TopologyMap.prototype.saveView = function () {
        try {
            window.localStorage.setItem('topology.view.' + this.mode, JSON.stringify(this.view));
        } catch (e) {
            // storage unavailable
        }
    };

    /**
     * Wire the filter form (reloads data) and the view bar (redraws only) to the map.
     */
    TopologyMap.prototype.bindControls = function () {
        var self = this;
        var form = document.getElementById('topo-filters');

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            self.load();
        });
        form.addEventListener('change', function (event) {
            // the device picker reloads through its select2 change handler
            if (!event.target || event.target.id !== 'topo-device') {
                self.load();
            }
        });

        var $ = window.jQuery;
        var device = document.getElementById('topo-device');
        if ($ && $.fn && $.fn.select2 && device) {
            $(device).select2({
                theme: 'bootstrap',
                width: '240px',
                allowClear: true,
                placeholder: 'Whole network',
                ajax: {
                    url: this.config.urls.deviceSelect,
                    delay: 200,
                    data: function (params) {
                        return {term: params.term, page: params.page || 1};
                    }
                }
            }).on('change', function () {
                self.load();
            });
        }

        document.querySelectorAll('#topo-viewbar [data-view]').forEach(function (input) {
            var key = input.getAttribute('data-view');
            if (input.type === 'checkbox') {
                input.checked = !!self.view[key];
            } else {
                input.value = self.view[key];
            }
            input.addEventListener('change', function () {
                self.view[key] = input.type === 'checkbox' ? input.checked : input.value;
                self.saveView();
                if (key === 'layout') {
                    self.render(true);
                } else {
                    self.restyle();
                }
            });
        });

        this.on('topo-fit', function () {
            self.network && self.network.fit({animation: {duration: 400}});
        });
        this.on('topo-save-layout', function () {
            self.saveLayout();
        });
        this.on('topo-reset-layout', function () {
            self.resetLayout();
        });
        this.on('topo-export-png', function () {
            self.exportPng();
        });
        this.on('topo-export-csv', function () {
            self.exportCsv();
        });
        this.on('topo-fullscreen', function () {
            var wrapper = document.getElementById('topo-app');
            wrapper.classList.toggle('topo-fullscreen');
            setTimeout(function () {
                self.network && self.network.redraw();
                self.network && self.network.fit();
            }, 50);
        });

        var search = document.getElementById('topo-search');
        if (search) {
            search.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    self.find(search.value);
                }
            });
        }

        window.addEventListener('popstate', function () {
            self.applyQueryToForm(new URLSearchParams(window.location.search));
            self.load(true);
        });
    };

    TopologyMap.prototype.on = function (id, handler) {
        var element = document.getElementById(id);
        if (element) {
            element.addEventListener('click', function (event) {
                event.preventDefault();
                handler();
            });
        }
    };

    /**
     * Current filter form as query parameters; checkboxes are always sent (1/0) so they override the plugin defaults.
     */
    TopologyMap.prototype.query = function () {
        var form = document.getElementById('topo-filters');
        var params = new URLSearchParams();
        Array.prototype.forEach.call(form.elements, function (element) {
            if (!element.name || element.disabled) {
                return;
            }
            if (element.type === 'checkbox') {
                params.set(element.name, element.checked ? '1' : '0');
            } else if (element.value !== '' && element.value !== null) {
                params.set(element.name, element.value);
            }
        });
        return params;
    };

    /**
     * Put query parameters back into the filter form (browser back/forward).
     */
    TopologyMap.prototype.applyQueryToForm = function (params) {
        var form = document.getElementById('topo-filters');
        Array.prototype.forEach.call(form.elements, function (element) {
            if (!element.name || !params.has(element.name)) {
                return;
            }
            if (element.type === 'checkbox') {
                element.checked = params.get(element.name) === '1';
            } else {
                element.value = params.get(element.name);
            }
        });
    };

    /**
     * Identifies what the map shows (center, hops, group, location, VRF), used to store saved layouts.
     */
    TopologyMap.prototype.scopeKey = function () {
        var params = this.query();
        return ['device', 'depth', 'group', 'location', 'vrf'].map(function (key) {
            return key + '=' + (params.get(key) || '');
        }).join('&');
    };

    /**
     * Fetch the map data for the current filters, update the URL (shareable) and redraw.
     * @param {boolean} fromHistory  triggered by back/forward: do not rewrite the URL
     */
    TopologyMap.prototype.load = function (fromHistory) {
        var self = this;
        var params = this.query();
        if (!fromHistory) {
            window.history.replaceState(null, '', window.location.pathname + '?' + params.toString());
        }
        this.updateNavLinks(params);
        this.setLoading(true);

        // only the latest request may update the map: abort the previous one and ignore late answers
        var requestId = this.requestId = (this.requestId || 0) + 1;
        if (this.abort) {
            this.abort.abort();
        }
        this.abort = window.AbortController ? new window.AbortController() : null;

        fetch(this.config.urls.api + '?' + params.toString(), {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            signal: this.abort ? this.abort.signal : undefined
        }).then(function (response) {
            return response.json().catch(function () {
                return null; // HTML error page or login redirect
            }).then(function (body) {
                if (!response.ok || body === null) {
                    throw new Error(body && body.error ? body.error : 'HTTP ' + response.status + (response.redirected ? ': session expired, reload the page' : ''));
                }
                return body;
            });
        }).then(function (data) {
            if (requestId !== self.requestId) {
                return;
            }
            self.data = data;
            self.prepareRedundancy();
            self.populateSelects(data);
            self.updateLayerToggles(data);
            self.render(false);
        }).catch(function (error) {
            if (requestId === self.requestId && error.name !== 'AbortError') {
                self.status.innerHTML = '<span class="text-danger"><i class="fa fa-exclamation-triangle"></i> ' + esc(error.message) + '</span>';
            }
        }).finally(function () {
            if (requestId === self.requestId) {
                self.setLoading(false);
            }
        });
    };

    TopologyMap.prototype.setLoading = function (loading) {
        if (this.loading) {
            this.loading.style.display = loading ? 'flex' : 'none';
        }
    };

    /**
     * Keep the scope (device, hops, group, location, VLAN) when switching between the map and diagnostics tabs.
     */
    TopologyMap.prototype.updateNavLinks = function (params) {
        var keep = new URLSearchParams();
        ['device', 'depth', 'group', 'location', 'vlan'].forEach(function (key) {
            if (params.get(key)) {
                keep.set(key, params.get(key));
            }
        });
        document.querySelectorAll('[data-topo-nav]').forEach(function (link) {
            link.href = link.getAttribute('data-base') + (keep.toString() ? '?' + keep.toString() : '');
        });
    };

    /**
     * Fill the VLAN (L2) or VRF (L3) selector with the values present in the loaded data.
     */
    TopologyMap.prototype.populateSelects = function (data) {
        var fill = function (id, items, allLabel) {
            var select = document.getElementById(id);
            if (!select) {
                return;
            }
            var current = select.value || select.getAttribute('data-selected') || '';
            var html = '<option value="">' + esc(allLabel) + '</option>';
            items.forEach(function (item) {
                html += '<option value="' + esc(item.value) + '"' + (String(item.value) === String(current) ? ' selected' : '') + '>' + esc(item.label) + '</option>';
            });
            select.innerHTML = html;
        };

        if (this.mode === 'l2') {
            var vlans = Object.keys(data.vlans || {}).map(function (vlan) {
                var name = data.vlans[vlan];
                return {value: vlan, label: vlan + (name && name !== 'VLAN' + vlan && name !== 'VLAN' + ('0000' + vlan).slice(-4) ? ' - ' + name : '')};
            });
            fill('topo-vlan', vlans, 'All VLANs');
        } else {
            fill('topo-vrf', (data.vrfs || []).map(function (vrf) {
                return {value: vrf, label: vrf};
            }), 'All VRFs');
        }
    };

    // ------------------------------------------------------------------ rendering

    TopologyMap.prototype.layoutKey = function () {
        return 'topology.layout.' + this.mode + '.' + this.scopeKey();
    };

    /**
     * Device positions saved for this map scope, or null.
     */
    TopologyMap.prototype.savedPositions = function () {
        try {
            return JSON.parse(window.localStorage.getItem(this.layoutKey()) || 'null');
        } catch (e) {
            return null;
        }
    };

    /**
     * (Re)create the vis-network from the loaded data: saved positions win over the hierarchical
     * or organic layout; organic layouts are frozen once stabilized so devices stay where dragged.
     * @param {boolean} relayout  ignore saved positions
     */
    TopologyMap.prototype.render = function (relayout) {
        var self = this;
        if (!this.data) {
            return;
        }

        var saved = relayout ? null : this.savedPositions();
        var geo = !saved && this.mode === 'sites' && this.view.layout === 'geographic' ? this.geoPositions() : null;
        var hierarchical = !saved && !geo && this.view.layout === 'hierarchical';

        this.nodes.clear();
        this.edges.clear();
        this.nodes.add(this.data.nodes.map(function (node) {
            var visNode = self.visNode(node);
            var position = (saved && saved[node.id]) || (geo && geo[node.id]);
            if (position) {
                visNode.x = position.x;
                visNode.y = position.y;
            }
            return visNode;
        }));
        this.edges.add(this.data.edges.map(function (edge) {
            return self.visEdge(edge);
        }));

        if (this.network) {
            this.network.destroy();
        }

        var colors = theme();
        var options = {
            autoResize: true,
            nodes: {
                font: {size: 12, color: colors.text, strokeWidth: 3, strokeColor: colors.textStroke, face: 'Arial'},
                shapeProperties: {useBorderWithImage: true, interpolation: true},
                borderWidth: 0,
                borderWidthSelected: 2,
                color: {border: 'rgba(0,0,0,0)', background: 'rgba(0,0,0,0)', highlight: {border: '#f39c12', background: 'rgba(243,156,18,0.12)'}, hover: {border: 'rgba(243,156,18,0.6)', background: 'rgba(243,156,18,0.06)'}}
            },
            edges: {
                smooth: false,
                selectionWidth: 2,
                hoverWidth: 1,
                font: {size: 10, color: colors.labelText, strokeWidth: 3, strokeColor: colors.background, align: 'middle', face: 'Arial'}
            },
            interaction: {hover: true, tooltipDelay: 200, multiselect: true, hideEdgesOnDrag: this.data.edges.length > 1500},
            layout: hierarchical ? {
                hierarchical: {
                    enabled: true,
                    direction: 'UD',
                    levelSeparation: this.mode === 'l3' ? 170 : 210,
                    nodeSpacing: 210,
                    treeSpacing: 240,
                    blockShifting: true,
                    edgeMinimization: true,
                    parentCentralization: true
                }
            } : {improvedLayout: this.data.nodes.length < 200, randomSeed: 7},
            physics: (hierarchical || saved || geo) ? {enabled: false} : {
                enabled: true,
                solver: 'forceAtlas2Based',
                forceAtlas2Based: {gravitationalConstant: -120, centralGravity: 0.006, springLength: 200, springConstant: 0.04, avoidOverlap: 0.8},
                stabilization: {iterations: Math.min(600, 150 + this.data.nodes.length * 2), fit: true}
            }
        };

        this.network = new window.vis.Network(this.container, {nodes: this.nodes, edges: this.edges}, options);
        this.network.on('beforeDrawing', function (ctx) {
            self.drawBackground(ctx);
        });
        this.network.on('afterDrawing', function (ctx) {
            self.drawOverlays(ctx);
        });
        this.network.on('click', function (params) {
            if (params.nodes.length) {
                self.showNode(params.nodes[0]);
            } else if (params.edges.length) {
                self.showEdge(params.edges[0]);
            } else {
                self.showLegend();
            }
        });
        this.network.on('doubleClick', function (params) {
            if (params.nodes.length) {
                var node = self.nodes.get(params.nodes[0]);
                if (node && node.data.device_id) {
                    self.recenter(node.data.device_id, node.data.name);
                } else if (node && node.data.type === 'site') {
                    self.openSite(node.data, self.config.urls.l2Page);
                }
            }
        });
        this.network.once('stabilizationIterationsDone', function () {
            // freeze the organic layout so devices stay where the user drags them
            self.network.setOptions({physics: {enabled: false}});
            self.network.fit();
        });
        if (hierarchical || saved || geo) {
            this.network.fit();
        }

        this.updateStatus();
        this.showLegend();
        this.updateSearchList();
        this.updateSimBox();
    };

    /**
     * vis-network node for a device, unmanaged neighbour, subnet or AS cloud.
     */
    TopologyMap.prototype.visNode = function (node) {
        var colors = theme();
        var tooltip = document.createElement('div');
        tooltip.className = 'topo-tooltip';
        tooltip.innerHTML = this.nodeTooltip(node);

        var visNode = {id: node.id, label: node.label, title: tooltip, level: node.level, data: node};

        if (node.type === 'site') {
            return Object.assign(visNode, {shape: 'image', image: siteIconUrl(node.status), size: 22 + Math.min(16, Math.round(4 * Math.log2(1 + node.devices)))});
        }
        if (node.type === 'subnet') {
            return Object.assign(visNode, {
                shape: 'box',
                margin: 6,
                borderWidth: 1.5,
                shapeProperties: {borderRadius: 3},
                color: {background: colors.subnetBg, border: colors.subnetBorder, highlight: {background: colors.subnetBg, border: '#f39c12'}},
                font: {size: 11, color: colors.text, strokeWidth: 0, face: 'monospace'}
            });
        }

        var role = node.type === 'as' ? 'cloud' : (node.role || 'unknown');
        var size = node.managed ? 24 : (node.type === 'as' ? 26 : 16);
        visNode.shape = 'image';
        visNode.image = iconUrl(role, node.status, node.managed);
        visNode.size = size;

        if (this.mode === 'l2' && this.data.scope && node.vlanMember === false) {
            visNode.opacity = 0.25;
        }
        if (this.mode === 'l3' && node.outside) {
            visNode.opacity = 0.45; // a device outside this map, drawn as the far end of an overlay
        }
        return visNode;
    };

    /**
     * vis-network edge: color, width, dashes and center label from the link data and the view options
     * (color by speed/utilization/status, STP blocked links, VLAN dimming, parallel link curves).
     */
    TopologyMap.prototype.visEdge = function (edge) {
        if (edge.kind === 'site') {
            return this.visSiteEdge(edge);
        }
        var colors = theme();
        var color;
        var width = 2;
        var dashes = false;
        var label;

        if (this.mode === 'l2') {
            width = speedWidth(edge.speed, edge.lag);
            if (this.view.colorBy === 'util') {
                color = utilColor(edge.util);
            } else if (this.view.colorBy === 'status') {
                color = edge.status === 'up' ? '#2e9e44' : (edge.status === 'degraded' ? '#e67e22' : '#c0392b');
            } else {
                color = speedColor(edge.speed);
            }
            if (edge.status === 'down') {
                color = '#c0392b';
                dashes = [6, 4];
            } else if (edge.status === 'degraded') {
                dashes = [12, 4];
            }
            if (this.view.stp && edge.blocked) {
                color = '#e67e22';
                dashes = [10, 6];
            }
            if (this.view.linkLabels) {
                var parts = [];
                var speed = formatSpeed(edge.speed);
                if (edge.lag && edge.lag.members > 1) {
                    parts.push(edge.lag.members + 'x ' + formatSpeed(edge.speed / edge.lag.members));
                } else if (speed) {
                    parts.push(speed);
                }
                if (this.view.colorBy === 'util' && edge.util !== null) {
                    parts.push(edge.util + '%');
                }
                label = parts.join(' ') || undefined;
            }
        } else {
            if (edge.kind === 'ospf') {
                color = edge.status === 'up' ? '#16a085' : '#c0392b';
                dashes = [4, 4];
                label = this.view.linkLabels ? 'OSPF ' + edge.state : undefined;
            } else if (edge.kind === 'bgp') {
                color = edge.status === 'up' ? '#8e44ad' : '#c0392b';
                dashes = [10, 4];
                label = this.view.linkLabels ? edge.type + (edge.remote_as ? ' AS' + edge.remote_as : '') + (edge.status === 'up' ? '' : ' ' + edge.state) : undefined;
            } else if (L3_LAYERS[edge.kind]) {
                var layer = this.layerEdgeStyle(edge);
                color = layer.color;
                dashes = layer.dashes;
                width = layer.width;
                label = layer.label;
            } else if (edge.kind === 'p2p') {
                color = edge.status === 'up' ? '#1f6fb2' : '#c0392b';
                width = 2.5;
                label = this.view.linkLabels ? edge.network : undefined;
            } else {
                color = edge.status === 'up' ? colors.member : '#c0392b';
                width = 1.8;
            }
            if (edge.status === 'down' && !dashes) {
                dashes = [6, 4];
            }
        }

        var visEdge = {
            id: edge.id,
            from: edge.from,
            to: edge.to,
            width: width,
            dashes: dashes,
            label: label,
            color: {color: color, highlight: '#f39c12', hover: color, opacity: 1},
            data: edge
        };

        if (this.mode === 'l2' && this.data && this.isVlanFiltered() && edge.carriesVlan === false) {
            visEdge.color.opacity = 0.15;
        }
        if (this.mode === 'l2') {
            if (this.simMap.edges[edge.id]) {
                visEdge.color.color = visEdge.color.hover = '#c0392b';
                visEdge.dashes = [3, 5];
                visEdge.width = width + 1;
            } else if (this.simMap.nodes[edge.from] || this.simMap.nodes[edge.to]) {
                visEdge.color.opacity = 0.25;
            }
        }

        if (edge.arrows) {
            visEdge.arrows = edge.arrows;
        }

        var parallel = edge.parallel || {index: 0, count: 1};
        if (parallel.count > 1) {
            var offset = parallel.count % 2 ? parallel.index : parallel.index + 1;
            if (offset === 0) {
                visEdge.smooth = false;
            } else {
                visEdge.smooth = {enabled: true, type: offset % 2 ? 'curvedCW' : 'curvedCCW', roundness: 0.12 * Math.ceil(offset / 2)};
            }
        }

        var tooltip = document.createElement('div');
        tooltip.className = 'topo-tooltip';
        tooltip.innerHTML = this.edgeTooltip(edge);
        visEdge.title = tooltip;

        return visEdge;
    };

    /**
     * Is a VLAN selected on the L2 map?
     */
    TopologyMap.prototype.isVlanFiltered = function () {
        var vlan = document.getElementById('topo-vlan');
        return vlan && vlan.value !== '';
    };

    /**
     * Apply changed view options without reloading or re-laying out the map.
     */
    TopologyMap.prototype.restyle = function () {
        var self = this;
        if (!this.data) {
            return;
        }
        this.edges.update(this.data.edges.map(function (edge) {
            return self.visEdge(edge);
        }));
        this.network && this.network.redraw();
        this.showLegend();
    };

    /**
     * Paint the theme background on the canvas (so PNG exports are not transparent).
     */
    TopologyMap.prototype.drawBackground = function (ctx) {
        ctx.save();
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.fillStyle = theme().background;
        ctx.fillRect(0, 0, ctx.canvas.width, ctx.canvas.height);
        ctx.restore();
    };

    /**
     * Point at fraction t along an edge, following curved (parallel) edges when vis-network exposes the curve.
     */
    TopologyMap.prototype.edgePoint = function (edgeId, from, to, t) {
        var internal = this.network && this.network.body && this.network.body.edges[edgeId];
        if (internal && internal.edgeType && typeof internal.edgeType.getPoint === 'function') {
            try {
                var point = internal.edgeType.getPoint(t);
                if (point && isFinite(point.x) && isFinite(point.y)) {
                    return point;
                }
            } catch (e) {
                // fall back to straight interpolation
            }
        }
        return {x: from.x + (to.x - from.x) * t, y: from.y + (to.y - from.y) * t};
    };

    /**
     * Drawn after vis-network: interface names at both ends of every link (with the STP port role),
     * IP addresses on the L3 map, blocked port markers and root bridge badges.
     */
    TopologyMap.prototype.drawOverlays = function (ctx) {
        var self = this;
        if (!this.data || !this.network) {
            return;
        }
        var scale = this.network.getScale();
        var colors = theme();
        var positions = this.network.getPositions();
        var showLabels = this.view.interfaceLabels && scale >= 0.45;
        var slots = this.labelSlots(positions);

        ctx.save();
        this.edges.forEach(function (visEdge) {
            var edge = visEdge.data;
            var from = positions[visEdge.from];
            var to = positions[visEdge.to];
            if (!from || !to || visEdge.hidden) {
                return;
            }
            var dx = to.x - from.x, dy = to.y - from.y;
            var length = Math.sqrt(dx * dx + dy * dy) || 1;
            var tA = Math.min(0.45, Math.max(0.1, (slots[visEdge.id + '|a'] || 56) / length));
            var tB = Math.min(0.45, Math.max(0.1, (slots[visEdge.id + '|b'] || 56) / length));
            var t = tA;

            var ends = [['a', tA], ['b', 1 - tB]];
            ends.forEach(function (end) {
                var endpoint = edge[end[0]];
                if (!endpoint) {
                    return;
                }
                var point = self.edgePoint(visEdge.id, from, to, end[1]);
                var stp = self.mode === 'l2' && self.view.stp ? endpoint.stp : null;

                if (stp && (stp.state === 'blocking' || stp.state === 'discarding' || stp.state === 'broken')) {
                    t = end[0] === 'a' ? tA : tB;
                    var marker = self.edgePoint(visEdge.id, from, to, end[0] === 'a' ? Math.max(0.08, t * 0.6) : Math.min(0.92, 1 - t * 0.6));
                    self.drawBlockedMarker(ctx, marker, stp.state === 'broken');
                }

                if (!showLabels) {
                    return;
                }
                var lines = [];
                var name = endpoint.short || endpoint.name;
                if (name) {
                    lines.push(name + (stp && STP_TAGS[stp.role] ? ' [' + STP_TAGS[stp.role] + ']' : ''));
                }
                if (self.mode === 'l3' && endpoint.ips && endpoint.ips.length) {
                    lines.push(endpoint.ips[0] + (endpoint.ips.length > 1 ? ' +' + (endpoint.ips.length - 1) : ''));
                }
                if (lines.length) {
                    var accent = stp && (stp.role === 'alternate' || stp.role === 'backup' || stp.role === 'broken') ? '#d35400' : null;
                    self.drawLabel(ctx, point, lines, colors, accent);
                }
            });
        });

        // root bridge badges
        if (this.mode === 'l2' && this.view.stp) {
            this.data.nodes.forEach(function (node) {
                if (node.stp && node.stp.root && positions[node.id]) {
                    self.drawRootBadge(ctx, positions[node.id], node.stp.suspected, node.managed ? 22 : 15);
                }
            });
        }

        if (this.mode === 'l2') {
            if (this.view.outages) {
                Object.keys(this.outages.top).forEach(function (id) {
                    var behind = self.outages.top[id].length;
                    if (positions[id]) {
                        self.drawBadge(ctx, positions[id], behind ? '\u25BC ' + behind + ' behind' : '\u25BC down');
                    }
                });
            }
            this.drawSimulation(ctx, positions);
        }
        ctx.restore();
    };

    /**
     * Red label under a node (top-most down device of an outage).
     */
    TopologyMap.prototype.drawBadge = function (ctx, position, text) {
        ctx.font = 'bold 10px Arial';
        var width = ctx.measureText(text).width + 10;
        var x = position.x + 14, y = position.y + 14;
        ctx.fillStyle = '#c0392b';
        ctx.beginPath();
        if (ctx.roundRect) {
            ctx.roundRect(x, y, width, 15, 7);
        } else {
            ctx.rect(x, y, width, 15);
        }
        ctx.fill();
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillText(text, x + 5, y + 8);
    };

    /**
     * Failure simulation: a red cross on every failed device and link, a dashed red ring around the
     * devices it cuts off the core.
     */
    TopologyMap.prototype.drawSimulation = function (ctx, positions) {
        var self = this;
        ((this.simResult && this.simResult.lost) || []).forEach(function (lost) {
            var position = positions[lost.node];
            if (!position) {
                return;
            }
            ctx.beginPath();
            ctx.arc(position.x, position.y, self.nodeIndex[lost.node].managed ? 30 : 22, 0, Math.PI * 2);
            ctx.strokeStyle = 'rgba(192, 57, 43, 0.9)';
            ctx.lineWidth = 3;
            ctx.setLineDash([5, 4]);
            ctx.stroke();
            ctx.setLineDash([]);
        });
        Object.keys(this.simMap.nodes).forEach(function (id) {
            if (positions[id]) {
                self.drawCross(ctx, positions[id], 13);
            }
        });
        Object.keys(this.simMap.edges).forEach(function (id) {
            var visEdge = self.edges.get(id);
            var from = visEdge && positions[visEdge.from];
            var to = visEdge && positions[visEdge.to];
            if (from && to) {
                self.drawCross(ctx, self.edgePoint(id, from, to, 0.5), 8);
            }
        });
    };

    /**
     * Red disc with a white cross.
     */
    TopologyMap.prototype.drawCross = function (ctx, point, radius) {
        ctx.beginPath();
        ctx.arc(point.x, point.y, radius, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(192, 57, 43, 0.92)';
        ctx.fill();
        var arm = radius * 0.5;
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = Math.max(2, radius / 4);
        ctx.beginPath();
        ctx.moveTo(point.x - arm, point.y - arm);
        ctx.lineTo(point.x + arm, point.y + arm);
        ctx.moveTo(point.x + arm, point.y - arm);
        ctx.lineTo(point.x - arm, point.y + arm);
        ctx.stroke();
    };

    /**
     * Distance of each interface label from its device: edges leaving a device at similar
     * angles get alternating distances so their labels do not stack on top of each other.
     */
    TopologyMap.prototype.labelSlots = function (positions) {
        var around = {};
        this.edges.forEach(function (visEdge) {
            var from = positions[visEdge.from];
            var to = positions[visEdge.to];
            if (!from || !to) {
                return;
            }
            (around[visEdge.from] = around[visEdge.from] || []).push({key: visEdge.id + '|a', angle: Math.atan2(to.y - from.y, to.x - from.x)});
            (around[visEdge.to] = around[visEdge.to] || []).push({key: visEdge.id + '|b', angle: Math.atan2(from.y - to.y, from.x - to.x)});
        });

        var slots = {};
        var distances = [56, 84, 112];
        Object.keys(around).forEach(function (node) {
            var list = around[node].sort(function (x, y) {
                return x.angle - y.angle;
            });
            var step = 0;
            list.forEach(function (item, index) {
                step = index > 0 && item.angle - list[index - 1].angle < 0.42 ? (step + 1) % distances.length : 0;
                // links leaving downwards pass the device name, keep their labels clear of it
                slots[item.key] = distances[step] + (Math.sin(item.angle) > 0.45 ? 22 : 0);
            });
        });
        return slots;
    };

    /**
     * Boxed multi-line label centered on a point; accent colors the border and text (blocked ports).
     */
    TopologyMap.prototype.drawLabel = function (ctx, point, lines, colors, accent) {
        ctx.font = '10px Arial';
        var width = 0;
        lines.forEach(function (line) {
            width = Math.max(width, ctx.measureText(line).width);
        });
        var height = lines.length * 12 + 4;
        var x = point.x - width / 2 - 4;
        var y = point.y - height / 2;
        ctx.fillStyle = colors.labelBg;
        ctx.strokeStyle = accent || colors.labelBorder;
        ctx.lineWidth = accent ? 1.5 : 0.8;
        ctx.beginPath();
        if (ctx.roundRect) {
            ctx.roundRect(x, y, width + 8, height, 3);
        } else {
            ctx.rect(x, y, width + 8, height);
        }
        ctx.fill();
        ctx.stroke();
        ctx.fillStyle = accent || colors.labelText;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        lines.forEach(function (line, index) {
            ctx.fillText(line, point.x, y + 8 + index * 12);
        });
    };

    /**
     * Orange circle with a cross on a blocked port (red when the port is broken).
     */
    TopologyMap.prototype.drawBlockedMarker = function (ctx, point, broken) {
        ctx.beginPath();
        ctx.arc(point.x, point.y, 7, 0, Math.PI * 2);
        ctx.fillStyle = broken ? '#c0392b' : '#e67e22';
        ctx.fill();
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(point.x - 3.5, point.y - 3.5);
        ctx.lineTo(point.x + 3.5, point.y + 3.5);
        ctx.moveTo(point.x + 3.5, point.y - 3.5);
        ctx.lineTo(point.x - 3.5, point.y + 3.5);
        ctx.stroke();
    };

    /**
     * Star next to the root bridge; red with "ROOT?" for an unmanaged neighbour suspected to be the root.
     */
    TopologyMap.prototype.drawRootBadge = function (ctx, position, suspected, offset) {
        var cx = position.x + offset, cy = position.y - offset, outer = 9, inner = 4;
        ctx.beginPath();
        for (var i = 0; i < 10; i++) {
            var radius = i % 2 ? inner : outer;
            var angle = Math.PI / 5 * i - Math.PI / 2;
            ctx[i ? 'lineTo' : 'moveTo'](cx + radius * Math.cos(angle), cy + radius * Math.sin(angle));
        }
        ctx.closePath();
        ctx.fillStyle = suspected ? '#e74c3c' : '#f1c40f';
        ctx.fill();
        ctx.strokeStyle = suspected ? '#922b21' : '#b7950b';
        ctx.lineWidth = 1;
        ctx.stroke();
        ctx.font = 'bold 9px Arial';
        ctx.fillStyle = suspected ? '#c0392b' : '#b7950b';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillText(suspected ? 'ROOT? (unmanaged)' : 'ROOT', cx + 11, cy);
    };

    // ------------------------------------------------------------------ L3 overlay layers

    /**
     * Offer the toggles of the L3 overlay layers (OSPFv3, IS-IS, IPsec, MPLS LSPs, pseudowires) only when
     * the devices drawn have such data: the LibreNMS modules behind them are often off. A shown toggle
     * reflects what the server drew; a hidden one is disabled, so that it stays out of the URL, unless it
     * keeps a choice that differs from the layer's default (switched off, or MPLS switched on).
     */
    TopologyMap.prototype.updateLayerToggles = function (data) {
        var layers = data.layers || {};
        document.querySelectorAll('#topo-filters [data-layer]').forEach(function (label) {
            var layer = layers[label.getAttribute('data-layer')] || {};
            var input = label.querySelector('input');
            var available = layer.available > 0;
            label.style.display = available ? '' : 'none';
            if (available) {
                input.checked = !!layer.shown;
            }
            input.disabled = !available && input.checked === !!layer.default;
        });
    };

    /**
     * Color, dashes, width and label of an overlay edge: red when down, grey when shut down, orange for
     * pseudowires whose two ends have different MTUs (always labelled: the MTU check is the point of
     * that layer).
     */
    TopologyMap.prototype.layerEdgeStyle = function (edge) {
        var layer = L3_LAYERS[edge.kind];
        var style = {color: layer.color, dashes: layer.dashes, width: edge.kind === 'ipsec' ? 2.5 : 2, label: undefined};
        if (edge.status === 'down') {
            style.color = '#c0392b';
        } else if (edge.status === 'disabled') {
            style.color = '#95a5a6';
        } else if (edge.status === 'warning') {
            style.color = '#e67e22';
            style.width = 3;
        }
        if (this.view.linkLabels || edge.mtu_mismatch) {
            style.label = this.layerSummary(edge);
        }
        return style;
    };

    /**
     * One line about an overlay edge: protocol and state, number of tunnels / LSPs / pseudowires, an
     * MTU mismatch.
     */
    TopologyMap.prototype.layerSummary = function (edge) {
        var count = function (n, one, many) {
            return n + ' ' + (n === 1 ? one : many);
        };
        var problem = edge.status === 'up' ? '' : ' ' + edge.state;
        switch (edge.kind) {
            case 'ospfv3':
                return 'OSPFv3 ' + edge.state;
            case 'isis':
                return 'IS-IS' + (edge.levels.length ? ' ' + edge.levels.join('/') : '') + problem;
            case 'ipsec':
                return 'IPsec' + problem;
            case 'mpls':
                return count(edge.lsps.length, 'LSP', 'LSPs') + (edge.status === 'up' ? '' : ', ' + edge.state);
            case 'pw':
                var bad = edge.vcs.filter(function (vc) {
                    return vc.mismatch;
                })[0];
                return count(edge.vcs.length, 'pseudowire', 'pseudowires') + (bad ? ': MTU ' + bad.mtu_a + ' ≠ ' + bad.mtu_b : '');
        }
        return '';
    };

    /**
     * Details panel of an overlay edge: its state and the adjacencies, tunnels, LSPs or pseudowires it
     * stands for (an edge merges both ends and every tunnel / LSP / pseudowire between two devices).
     */
    TopologyMap.prototype.layerDetails = function (edge) {
        var self = this;
        var name = function (deviceId) {
            return esc(self.nodeName('d:' + deviceId));
        };
        var state = function (status, text) {
            return '<span class="topo-status-' + esc(status === 'warning' ? 'degraded' : status) + '">' + esc(text) + '</span>';
        };
        var html = '<table class="table table-condensed topo-kv">' + this.row('Layer', esc(L3_LAYERS[edge.kind].name)) +
            this.row('State', state(edge.status, edge.state) + (edge.kind === 'pw' && !edge.mtu_mismatch ? ' <small>(LibreNMS keeps no pseudowire state)</small>' : '') +
                this.layerSideNote(edge));

        if (edge.kind === 'ospfv3') {
            html += this.row('Router IDs', esc(edge.routerIds.join(', '))) + this.row('Neighbor addresses', esc(edge.addresses.join(', ')));
        } else if (edge.kind === 'isis') {
            html += this.row('Levels', esc(edge.levels.join(', '))) + this.row('Areas', esc(edge.areas.join(', '))) +
                this.row('System IDs', esc(edge.systemIds.join(', '))) + this.row('Addresses', esc(edge.addresses.join(', '))) +
                this.row('Interfaces', edge.interfaces.map(function (i) {
                    return name(i.device_id) + ' ' + esc(i.name);
                }).join(', '));
        }
        html += '</table>';

        if (edge.kind === 'ipsec') {
            html += '<h5>Tunnels</h5><table class="table table-condensed topo-kv topo-layer-list">';
            edge.tunnels.forEach(function (t) {
                html += '<tr><td>' + name(t.device_id) + '</td><td>' + esc(t.name) + '</td><td>' + esc((t.local ? t.local + ' ' : '') + '→ ' + t.peer) +
                    '</td><td>' + state(t.up ? 'up' : 'down', t.status) + '</td></tr>';
            });
            html += '</table>';
        } else if (edge.kind === 'mpls') {
            html += '<h5>LSPs</h5><table class="table table-condensed topo-kv topo-layer-list"><tr><th>Name</th><th>Head → tail</th><th>State</th><th>Paths</th></tr>';
            edge.lsps.forEach(function (lsp) {
                var up = lsp.oper === 'inService';
                var shut = lsp.admin === 'outOfService';
                html += '<tr><td title="' + esc(lsp.type + (lsp.frr ? ', fast reroute' : '') + ', ' + lsp.transitions + ' transitions') + '">' + esc(lsp.name) + '</td><td>' +
                    esc(self.nodeName(lsp.head) + ' → ' + self.nodeName(lsp.tail)) + '</td><td>' +
                    state(shut ? 'disabled' : (up ? 'up' : 'down'), shut ? 'shut down' : lsp.oper) + '</td><td>' + esc(lsp.paths) + '</td></tr>';
            });
            html += '</table>';
        } else if (edge.kind === 'pw') {
            html += '<h5>Pseudowires</h5><table class="table table-condensed topo-kv topo-layer-list"><tr><th>VC</th><th>' + esc(this.nodeName(edge.from)) + '</th><th>' +
                esc(this.nodeName(edge.to)) + '</th><th>MTU</th></tr>';
            edge.vcs.forEach(function (vc) {
                var mtu = (vc.mtu_a || '?') + ' / ' + (vc.mtu_b || '?');
                var signalled = vc.payload_a !== vc.mtu_a || vc.payload_b !== vc.mtu_b ? '<br><small>signals ' + esc((vc.payload_a || '?') + ' / ' + (vc.payload_b || '?')) + '</small>' : '';
                html += '<tr><td title="' + esc(vc.type + ' over ' + vc.psn) + '">' + esc(vc.vc) + (vc.description ? ' <small>' + esc(vc.description) + '</small>' : '') +
                    (vc.inferred ? '<br><small class="text-muted">paired by VC ID (LDP id not stored)</small>' : '') + '</td><td>' +
                    esc(vc.a ? vc.a.port : '') + '</td><td>' + esc(vc.b ? vc.b.port : '') + '</td><td>' + (vc.mismatch ? '<b class="text-danger">' + esc(mtu) + '</b>' : esc(mtu)) + signalled + '</td></tr>';
            });
            html += '</table><p class="text-muted topo-hint">LibreNMS keeps no pseudowire state. MTU: of the two attachment circuits, which must signal the same ' +
                'MTU for a pseudowire to come up; IOS XR counts the Ethernet header in it, and the VLAN tag of a subinterface (1518 there signals 1500). ' +
                'When LibreNMS lost the LDP IDs of both ends, the pseudowire is paired with the one other device that has its VC ID.</p>';
        }
        return html;
    };

    /**
     * Legend lines of the overlay layers drawn on this map.
     */
    TopologyMap.prototype.layerLegend = function () {
        var stats = (this.data && this.data.stats) || {};
        var html = '';
        Object.keys(L3_LAYERS).forEach(function (kind) {
            if (stats[kind]) {
                html += '<div><span class="topo-line topo-line-dashed" style="border-color:' + L3_LAYERS[kind].color + '"></span> ' + esc(L3_LAYERS[kind].name) +
                    (kind === 'mpls' ? ' (arrow toward the tail)' : '') + '</div>';
            }
        });
        if (stats.mtu) {
            html += '<div><span class="topo-line topo-line-dashed" style="border-color:#e67e22"></span> Pseudowire MTU mismatch</div>';
        }
        if (stats.outside) {
            html += '<div><img src="' + iconUrl('router', 'up', true) + '" width="22" height="22" alt="" style="opacity: 0.45"> Device outside this map (double-click to center on it)</div>';
        }
        return html;
    };

    /**
     * Status line parts of the overlay layers.
     */
    TopologyMap.prototype.layerStatus = function (stats) {
        var parts = [];
        var limits = (this.data && this.data.limits) || {};
        if (stats.outside) {
            parts.push(stats.outside + ' outside the map');
        }
        [['ospfv3', 'OSPFv3 adjacencies'], ['isis', 'IS-IS adjacencies'], ['ipsec', 'IPsec peers'], ['mpls', 'LSPs'], ['pw', 'pseudowires']].forEach(function (item) {
            if (stats[item[0]]) {
                parts.push(stats[item[0]] + ' ' + item[1] + (item[0] === 'pw' && stats.mtu ? ' (<span class="topo-text-warning">' + stats.mtu + ' MTU mismatch</span>)' : ''));
            }
        });
        if (limits.mpls) {
            parts.push('<span class="topo-text-warning"><i class="fa fa-exclamation-triangle"></i> LSPs of ' + limits.mpls.shown + ' of ' + limits.mpls.total +
                ' device pairs drawn (those with LSPs down first). Pick a device, group or location to see the others.</span>');
        }
        if (limits.outside) {
            parts.push('<span class="topo-text-warning"><i class="fa fa-exclamation-triangle"></i> ' + limits.outside.shown + ' of ' + limits.outside.total +
                ' devices outside the map drawn (node limit); the others appear as their address or AS.</span>');
        }
        return parts;
    };

    /**
     * State note of an edge to a device outside the map whose own rows were not read (another location or group):
     * only the drawn side reported it.
     */
    TopologyMap.prototype.layerSideNote = function (edge) {
        return edge.oneSide ? ' <small class="text-muted">(as seen from ' + esc(this.nodeName(edge.from)) + ' only: ' + esc(this.nodeName(edge.to)) +
            ' is outside this map)</small>' : '';
    };

    /**
     * Tooltip lines of a device outside this map or an external far end of the overlays.
     */
    TopologyMap.prototype.layerNodeTooltip = function (node) {
        if (node.outside) {
            return '<br><i>Outside this map</i>';
        }
        return node.seen && node.seen.length ? '<br>' + esc(node.seen.join(', ')) : '';
    };

    /**
     * Details panel rows of a device outside this map or an external far end of the overlays: its
     * addresses and what saw it (an unmonitored router can be OSPF neighbor, LSP tail and IPsec peer).
     */
    TopologyMap.prototype.layerNodeRows = function (node) {
        if (node.outside) {
            return this.row('On this map', 'No: far end of an overlay. Double-click it or <i>Center map here</i>.');
        }
        var others = (node.addresses || []).filter(function (address) {
            return address !== node.address;
        });
        return this.row('Address', esc(node.address)) + this.row('Other addresses', esc(others.join(', '))) +
            this.row('Context', esc(node.context)) + this.row('Seen as', esc((node.seen || []).join(', ')));
    };

    // ------------------------------------------------------------------ failure simulation & outages

    /**
     * Does a map node pass traffic between its links? Switches, routers and firewalls, and unmanaged
     * neighbors unless their platform says server, phone or access point.
     */
    TopologyMap.prototype.forwards = function (node) {
        return !!node && (node.managed ? NETWORK_ROLES.indexOf(node.role) !== -1 : END_ROLES.indexOf(node.role) === -1);
    };

    /**
     * Index a freshly loaded L2 map: node lookup, current outages, and the simulated failures (kept
     * from the previous view or requested in the URL) placed on this map and computed again.
     */
    TopologyMap.prototype.prepareRedundancy = function () {
        var self = this;
        this.nodeIndex = {};
        this.data.nodes.forEach(function (node) {
            self.nodeIndex[node.id] = node;
        });
        this.outages = this.mode === 'l2' ? this.computeOutages() : {top: {}, behind: {}};
        this.placeFailures();
        if (this.mode === 'l2' && this.sim.refs.length) {
            this.runSimulation();
        }
    };

    /**
     * Reference of a map edge for the server: its port for a single cable, else its two devices
     * (the cables between them). Not the aggregate port: a port-channel to an MLAG pair also has
     * the cables to the other switch.
     */
    TopologyMap.prototype.edgeRef = function (edge) {
        var members = edge.members || [];
        var port = members.length === 1 && (members[0].a_port_id || members[0].b_port_id);
        return port ? 'p:' + port : 'l:' + edge.from + '|' + edge.to;
    };

    /**
     * Does a link reference (p:<port>, l:<node>|<node>) name this map edge?
     */
    TopologyMap.prototype.refHitsEdge = function (ref, edge) {
        var port = /^p:(\d+)$/.exec(ref);
        var pair = /^l:(.+)\|(.+)$/.exec(ref);
        if (port) {
            var id = Number(port[1]);
            return (edge.a && edge.a.port_id === id) || (edge.b && edge.b.port_id === id) || (edge.members || []).some(function (member) {
                return member.a_port_id === id || member.b_port_id === id;
            });
        }
        return !!pair && ((edge.from === pair[1] && edge.to === pair[2]) || (edge.from === pair[2] && edge.to === pair[1]));
    };

    /**
     * The failure that put a map edge down (it can be another of its ports, e.g. from the URL).
     */
    TopologyMap.prototype.failureOf = function (edge) {
        var self = this;
        return this.sim.refs.find(function (ref) {
            return self.refHitsEdge(ref, edge);
        }) || this.edgeRef(edge);
    };

    /**
     * Find the failed devices and links on the map (to draw them); a failure can be off the map.
     */
    TopologyMap.prototype.placeFailures = function () {
        var self = this;
        this.simMap = {nodes: {}, edges: {}};
        this.sim.refs.forEach(function (ref) {
            if (self.nodeIndex[ref]) {
                self.simMap.nodes[ref] = true;
            }
            self.data.edges.forEach(function (edge) {
                if (self.refHitsEdge(ref, edge)) {
                    self.simMap.edges[edge.id] = true;
                }
            });
        });
    };

    /**
     * Fail or restore a device / link and ask the server what that cuts off.
     * @param {string} ref  d:<id> / u:<name> node id, p:<port>, l:<node>|<node>
     */
    TopologyMap.prototype.toggleFailure = function (ref) {
        var index = this.sim.refs.indexOf(ref);
        if (index === -1) {
            this.sim.refs.push(ref);
        } else {
            this.sim.refs.splice(index, 1);
        }
        this.placeFailures();
        this.runSimulation();
    };

    TopologyMap.prototype.isFailed = function (kind, id) {
        return kind === 'edge' ? !!this.simMap.edges[id] : this.sim.refs.indexOf(id) !== -1;
    };

    TopologyMap.prototype.resetSimulation = function () {
        this.sim.refs = [];
        this.placeFailures();
        this.runSimulation();
    };

    /**
     * The simulation is computed by the server on the whole network as it is now (api/simulate), so
     * it does not depend on what this map shows.
     */
    TopologyMap.prototype.runSimulation = function () {
        var self = this;
        var requestId = this.simRequest = (this.simRequest || 0) + 1;
        var refresh = function () {
            self.edges.update(self.data.edges.map(function (edge) {
                return self.visEdge(edge);
            }));
            self.network && self.network.redraw();
            self.updateSimBox();
        };
        if (!this.sim.refs.length) {
            this.simResult = null;
            refresh();
            return;
        }
        this.simResult = {loading: true, lost: (this.simResult && this.simResult.lost) || []};
        refresh();
        fetch(this.config.urls.simulate + '?fail=' + encodeURIComponent(this.sim.refs.join(',')), {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
        }).then(function (response) {
            return response.json().catch(function () {
                return null;
            }).then(function (body) {
                if (!response.ok || body === null) {
                    throw new Error(body && body.error ? body.error : 'HTTP ' + response.status);
                }
                return body;
            });
        }).then(function (result) {
            if (requestId === self.simRequest) {
                self.simResult = result;
                refresh();
            }
        }).catch(function (error) {
            if (requestId === self.simRequest) {
                self.simResult = {error: error.message, lost: []};
                refresh();
            }
        });
    };

    /**
     * Current outages on this map: the top-most down devices (next to an up switch, router or
     * firewall, possibly through unmanaged switches) and the down devices only reachable through
     * them. Same rules as the redundancy page, limited to what the map shows.
     * @return {{top: Object<string, string[]>, behind: Object<string, string[]>}}
     */
    TopologyMap.prototype.computeOutages = function () {
        var self = this;
        var incident = {};
        this.data.edges.forEach(function (edge) {
            (incident[edge.from] = incident[edge.from] || []).push(edge);
            (incident[edge.to] = incident[edge.to] || []).push(edge);
        });

        var upSide = {};
        var top = {};
        var queue = [];
        this.data.nodes.forEach(function (node) {
            if (node.managed && node.status === 'up' && self.forwards(node)) {
                upSide[node.id] = true;
                queue.push(node.id);
            }
        });
        for (var i = 0; i < queue.length; i++) {
            var id = queue[i];
            (incident[id] || []).forEach(function (edge) {
                var next = edge.from === id ? edge.to : edge.from;
                var other = self.nodeIndex[next];
                if (!other || upSide[next]) {
                    return;
                }
                if (other.managed && other.status === 'down') {
                    top[next] = true;
                } else if (!other.managed && self.forwards(other)) {
                    upSide[next] = true;
                    queue.push(next);
                }
            });
        }

        // devices behind each top-most failure (up to three per device); an end device passes nothing on
        var sources = {};
        queue = Object.keys(top);
        queue.forEach(function (id) {
            sources[id] = {};
            sources[id][id] = true;
        });
        for (i = 0; i < queue.length; i++) {
            id = queue[i];
            if (!self.forwards(self.nodeIndex[id])) {
                continue;
            }
            (incident[id] || []).forEach(function (edge) {
                var next = edge.from === id ? edge.to : edge.from;
                var other = self.nodeIndex[next];
                if (!other || upSide[next] || top[next] || (other.managed && other.status !== 'down')) {
                    return;
                }
                var added = false;
                sources[next] = sources[next] || {};
                Object.keys(sources[id]).forEach(function (source) {
                    if (!sources[next][source] && Object.keys(sources[next]).length < 3) {
                        sources[next][source] = true;
                        added = true;
                    }
                });
                if (added) {
                    queue.push(next);
                }
            });
        }

        var result = {top: {}, behind: {}};
        Object.keys(top).forEach(function (id) {
            result.top[id] = [];
        });
        Object.keys(sources).forEach(function (id) {
            if (top[id] || !self.nodeIndex[id].managed) {
                return;
            }
            result.behind[id] = Object.keys(sources[id]);
            result.behind[id].forEach(function (source) {
                result.top[source].push(id);
            });
        });
        return result;
    };

    /**
     * Box over the map: the simulated failures and every device they cut off the core (also those
     * not on this map).
     */
    TopologyMap.prototype.updateSimBox = function () {
        var self = this;
        var box = document.getElementById('topo-sim');
        if (!box) {
            return;
        }
        if (!this.sim.refs.length) {
            box.style.display = 'none';
            box.innerHTML = '';
            return;
        }

        var result = this.simResult || {};
        var labels = {};
        (result.failed || []).forEach(function (item) {
            labels[item.ref] = item.label;
        });
        var html = '<div class="topo-sim-title"><i class="fa fa-bolt"></i> Simulated failure</div><div>' + this.sim.refs.map(function (ref) {
            var label = labels[ref] || (self.nodeIndex[ref] ? self.nodeIndex[ref].name : ref);
            return esc(label) + '<span class="topo-sim-remove" title="Restore" data-sim-remove="' + esc(ref) + '">&times;</span>';
        }).join(', ') + '</div>';

        if (result.loading) {
            html += '<div class="text-muted" style="margin: 4px 0"><i class="fa fa-spinner fa-spin"></i> Calculating&hellip;</div>';
        } else if (result.error) {
            html += '<div class="text-danger" style="margin: 4px 0">' + esc(result.error) + '</div>';
        } else if (result.lost && result.lost.length) {
            var managed = result.lost.filter(function (lost) {
                return lost.device_id !== null;
            });
            var unmanaged = result.unmanaged || 0;
            html += '<div style="margin-top: 4px"><b>' + managed.length + ' monitored device' + (managed.length === 1 ? '' : 's') +
                (result.network ? ' (' + result.network + (result.network === 1 ? ' switch or router)' : ' switches or routers)') : '') +
                (managed.length === 1 ? ' loses its' : ' lose their') + ' path to the core</b>' +
                (unmanaged ? ', and ' + unmanaged + ' unmanaged neighbor' + (unmanaged === 1 ? '' : 's') : '') + ':</div><ul>';
            managed.slice(0, 40).forEach(function (lost) {
                html += '<li>' + (self.nodeIndex[lost.node]
                    ? '<a data-sim-focus="' + esc(lost.node) + '">' + esc(lost.name) + '</a>'
                    : esc(lost.name) + ' <span class="text-muted">(not on this map)</span>') + '</li>';
            });
            html += managed.length > 40 ? '<li>&hellip;</li></ul>' : '</ul>';
        } else if (result.lost) {
            html += '<div class="topo-sim-ok" style="margin: 4px 0"><i class="fa fa-check"></i> Every device keeps a path to the core.</div>';
        }

        if (result.reference) {
            html += '<div class="text-muted">' + (result.reference === 'core' ? '' : 'No core device is left: the largest remaining part counts as the core. ') +
                'Computed on the whole network as it is now: links and devices that are down already stay down.</div>';
        }
        html += '<div style="margin-top: 6px"><button type="button" class="btn btn-xs btn-default" data-sim-reset><i class="fa fa-undo"></i> Reset</button></div>';

        box.innerHTML = html;
        box.style.display = '';
        box.querySelectorAll('[data-sim-remove]').forEach(function (element) {
            element.addEventListener('click', function () {
                self.toggleFailure(element.getAttribute('data-sim-remove'));
            });
        });
        box.querySelectorAll('[data-sim-focus]').forEach(function (element) {
            element.addEventListener('click', function () {
                var id = element.getAttribute('data-sim-focus');
                self.network.selectNodes([id]);
                self.network.focus(id, {scale: Math.max(1, self.network.getScale()), animation: {duration: 400}});
                self.showNode(id);
            });
        });
        box.querySelector('[data-sim-reset]').addEventListener('click', function () {
            self.resetSimulation();
        });
    };

    // ------------------------------------------------------------------ tooltips & details

    TopologyMap.prototype.nodeTooltip = function (node) {
        if (node.type === 'site') {
            return '<b>' + esc(node.name) + '</b><br>' + esc(node.devices + ' device' + (node.devices === 1 ? '' : 's')) +
                (node.down ? '<br><span class="text-danger">' + esc(node.down + ' down') + '</span>' : '');
        }
        if (node.type === 'subnet') {
            return '<b>' + esc(node.network) + '</b>' + (node.vrf ? ' (' + esc(node.vrf) + ')' : '') + '<br>' + node.devices + ' device(s)';
        }
        var html = '<b>' + esc(node.name) + '</b>';
        if (node.hardware) html += '<br>' + esc(node.hardware);
        if (node.platform) html += '<br>' + esc(node.platform);
        if (node.role) html += '<br>Role: ' + esc(node.role);
        if (node.stp && node.stp.root) html += '<br><b>' + (node.stp.suspected ? 'Suspected STP root bridge' : 'STP root bridge') + '</b>';
        if (this.mode === 'l3') html += this.layerNodeTooltip(node);
        return html;
    };

    /**
     * Hover text of a link: both ends, speed or network, routing protocol state.
     */
    TopologyMap.prototype.edgeTooltip = function (edge) {
        if (edge.kind === 'site') {
            return esc(this.nodeName(edge.from) + ' \u2194 ' + this.nodeName(edge.to)) + '<br>' + esc(this.siteLinkLabel(edge) || '') + '<br>' + esc(edge.status);
        }
        var a = edge.a ? (this.nodeName(edge.from) + ' ' + (edge.a.short || '')) : this.nodeName(edge.from);
        var b = edge.b ? (this.nodeName(edge.to) + ' ' + (edge.b.short || '')) : this.nodeName(edge.to);
        var html = esc(a) + ' &harr; ' + esc(b);
        if (edge.speed) html += '<br>' + esc(formatSpeed(edge.speed)) + 'bps';
        if (edge.network) html += '<br>' + esc(edge.network);
        if (edge.kind === 'ospf' || edge.kind === 'bgp') html += '<br>' + esc((edge.type || 'OSPF') + ' ' + edge.state);
        if (L3_LAYERS[edge.kind]) html += '<br>' + esc(this.layerSummary(edge));
        return html;
    };

    TopologyMap.prototype.nodeName = function (id) {
        var node = this.nodes.get(id);
        return node ? node.data.name : id;
    };

    TopologyMap.prototype.deviceLink = function (node) {
        return node.url ? '<a href="' + esc(node.url) + '">' + esc(node.name) + '</a>' : esc(node.name);
    };

    TopologyMap.prototype.portLink = function (endpoint) {
        var text = esc(endpoint.name || '?');
        return endpoint.url ? '<a href="' + esc(endpoint.url) + '">' + text + '</a>' : text;
    };

    TopologyMap.prototype.row = function (label, value) {
        return value === null || value === undefined || value === '' ? '' : '<tr><th>' + esc(label) + '</th><td>' + value + '</td></tr>';
    };

    /**
     * Details panel of a device / subnet: properties, STP state, connections (clickable) and actions.
     */
    TopologyMap.prototype.showNode = function (id) {
        var self = this;
        var visNode = this.nodes.get(id);
        if (!visNode) {
            return;
        }
        var node = visNode.data;
        if (this.mode === 'sites') {
            this.showSite(id);
            return;
        }
        var html = '<h4 class="topo-panel-title">' + this.deviceLink(node) + '</h4><table class="table table-condensed topo-kv">';

        if (node.type === 'subnet') {
            html += this.row('Network', esc(node.network)) + this.row('VRF', esc(node.vrf)) + this.row('Devices', node.devices) +
                this.row('Addresses', esc((node.hosts || []).join(', ')));
        } else {
            html += this.row('Status', '<span class="topo-status-' + esc(node.status) + '">' + esc(node.status) + '</span>') +
                this.row('Role', esc(node.role)) + this.row('Hardware', esc(node.hardware)) + this.row('OS', esc((node.os || '') + ' ' + (node.version || ''))) +
                this.row('Location', esc(node.location)) + this.row('Platform', esc(node.platform));
            if (this.mode === 'l3') html += this.layerNodeRows(node);
            if (node.loopbacks && node.loopbacks.length) html += this.row('Loopbacks', esc(node.loopbacks.join(', ')));
            if (node.bgpLocalAs) html += this.row('BGP AS', esc(node.bgpLocalAs));
            if (node.stubs && node.stubs.length) html += this.row('Stub networks', esc(node.stubs.slice(0, 12).join(', ')) + (node.stubs.length > 12 ? ' &hellip;' : ''));
            if (node.peers && node.peers.length) html += this.row('Peers', esc(node.peers.join(', ')));
            if (node.stp && node.stp.suspected) {
                html += '<tr><th colspan="2" class="topo-section">Spanning tree</th></tr>' +
                    this.row('Role', '<b class="text-danger">Suspected root bridge</b>') +
                    this.row('Root ID', esc(node.stp.designatedRoot)) +
                    '<tr><td colspan="2" class="text-muted">A monitored switch uses its root port toward this unmanaged neighbor and the root bridge ID belongs to no monitored device.</td></tr>';
            } else if (node.stp) {
                var stp = node.stp;
                html += '<tr><th colspan="2" class="topo-section">Spanning tree (' + (stp.vlan ? 'VLAN ' + stp.vlan : 'VLAN 1 / CST') + ')</th></tr>' +
                    this.row('Role', stp.root ? '<b>Root bridge</b>' : 'Non-root') +
                    this.row('Bridge ID', esc(stp.priority + ' / ' + stp.bridge)) +
                    this.row('Root ID', esc(stp.designatedRoot)) +
                    this.row('Root port', esc(stp.rootPort)) +
                    this.row('Root cost', stp.root ? '' : esc(stp.rootCost)) +
                    this.row('Protocol', esc(stp.protocol)) +
                    this.row('Topology changes', esc(stp.topChanges + (stp.topChanges ? ' (last ' + formatDuration(stp.lastTopologyChange) + ' ago)' : '')));
            }
        }
        html += '</table>';

        if (this.mode === 'l2' && this.outages.top[id]) {
            var behind = this.outages.top[id];
            html += '<div class="text-danger"><i class="fa fa-arrow-down"></i> <b>Top-most down device of an outage</b>' + (behind.length
                ? ': ' + behind.length + ' down device' + (behind.length === 1 ? '' : 's') + ' behind it (' + esc(behind.map(function (other) {
                    return self.nodeName(other);
                }).join(', ')) + ').'
                : '.') + '</div>';
        } else if (this.mode === 'l2' && this.outages.behind[id]) {
            html += '<div class="text-danger"><i class="fa fa-arrow-down"></i> Down behind ' + esc(this.outages.behind[id].map(function (other) {
                return self.nodeName(other);
            }).join(' and ')) + ': probably fine, only unreachable.</div>';
        }

        // neighbor list
        var connected = this.network.getConnectedEdges(id).map(function (edgeId) {
            return self.edges.get(edgeId);
        }).filter(Boolean);
        if (connected.length && node.type !== 'subnet') {
            html += '<h5>Connections (' + connected.length + ')</h5><table class="table table-condensed topo-kv topo-neighbors">';
            connected.forEach(function (visEdge) {
                var edge = visEdge.data;
                var local = edge.from === id ? edge.a : edge.b;
                var remote = edge.from === id ? edge.b : edge.a;
                var other = self.nodeName(edge.from === id ? edge.to : edge.from);
                var localName = local ? (local.short || local.name) : (L3_LAYERS[edge.kind] ? L3_LAYERS[edge.kind].name : (edge.kind || ''));
                var remoteName = remote ? ' ' + (remote.short || remote.name || '') : '';
                html += '<tr data-edge="' + esc(visEdge.id) + '"><td>' + esc(localName) + '</td><td>' + esc(other + remoteName) + '</td></tr>';
            });
            html += '</table>';
        }

        var actions = '';
        if (node.device_id) {
            actions += '<button class="btn btn-xs btn-primary" data-recenter="' + node.device_id + '"><i class="fa fa-crosshairs"></i> Center map here</button> ' +
                '<a class="btn btn-xs btn-default" href="' + esc(this.config.urls.l2Page + '?device=' + node.device_id) + '">L2 map</a> ' +
                '<a class="btn btn-xs btn-default" href="' + esc(this.config.urls.l3Page + '?device=' + node.device_id) + '">L3 map</a> ' +
                '<a class="btn btn-xs btn-default" href="' + esc(this.config.urls.stpPage + '?device=' + node.device_id) + '">STP</a> ' +
                '<a class="btn btn-xs btn-default" href="' + esc(this.config.urls.redundancyPage + '?device=' + node.device_id) + '">Redundancy</a> ';
        }
        if (this.mode === 'l2' && node.type !== 'subnet') {
            actions += '<button class="btn btn-xs btn-danger" data-sim-node="' + esc(id) + '"><i class="fa fa-bolt"></i> ' +
                (this.isFailed('node', id) ? 'Undo simulated failure' : 'Simulate failure') + '</button>';
        }
        if (actions) {
            html += '<div class="topo-actions">' + actions + '</div>';
        }

        this.panel.innerHTML = html;
        this.panel.querySelectorAll('[data-sim-node]').forEach(function (button) {
            button.addEventListener('click', function () {
                self.toggleFailure(id);
                self.showNode(id);
            });
        });
        this.panel.querySelectorAll('[data-recenter]').forEach(function (button) {
            button.addEventListener('click', function () {
                self.recenter(node.device_id, node.name);
            });
        });
        this.panel.querySelectorAll('[data-edge]').forEach(function (row) {
            row.addEventListener('click', function () {
                self.network.selectEdges([row.getAttribute('data-edge')]);
                self.showEdge(row.getAttribute('data-edge'));
            });
        });
    };

    /**
     * Table rows describing one end of a link (interface, status, speed, VLANs, traffic, STP).
     */
    TopologyMap.prototype.endpointRows = function (endpoint) {
        if (!endpoint) {
            return '';
        }
        var html = this.row('Interface', this.portLink(endpoint)) + this.row('Description', esc(endpoint.alias));
        if (endpoint.ips) {
            html += this.row('Address', esc(endpoint.ips.join(', ') + (endpoint.prefix ? ' /' + endpoint.prefix : '')));
        }
        html += this.row('Status', endpoint.oper ? '<span class="topo-status-' + esc(endpoint.oper) + '">' + esc(endpoint.oper) + '</span>' + (endpoint.admin && endpoint.admin !== 'up' ? ' (admin ' + esc(endpoint.admin) + ')' : '') : '');
        if (endpoint.speed !== undefined) {
            html += this.row('Speed', esc(endpoint.speed ? formatSpeed(endpoint.speed) + 'bps' : '')) + this.row('Duplex', esc(endpoint.duplex)) + this.row('MTU', esc(endpoint.mtu));
            if (endpoint.managed) {
                html += this.row('Traffic in/out', esc(formatRate(endpoint.in_bps) + ' / ' + formatRate(endpoint.out_bps)));
                html += this.row('Native VLAN', esc(endpoint.native_vlan));
                if (endpoint.vlans && endpoint.vlans.length > 1) {
                    html += this.row('Trunk VLANs', '<span class="topo-vlans">' + esc(endpoint.vlans.length > 40 ? endpoint.vlans.slice(0, 40).join(',') + ' (' + endpoint.vlans.length + ')' : endpoint.vlans.join(',')) + '</span>');
                }
            }
        }
        if (endpoint.stp) {
            var stp = endpoint.stp;
            html += this.row('STP', esc(stp.state + ' / ' + stp.role + (stp.via_lag ? ' (on aggregate)' : '') + ' cost ' + stp.cost) +
                ' <small>' + esc(stp.transitions + ' fwd transitions') + '</small>');
        }
        return html;
    };

    /**
     * Details panel of a link: both ends, aggregate members, or routing session details.
     */
    TopologyMap.prototype.showEdge = function (id) {
        var visEdge = this.edges.get(id);
        if (!visEdge) {
            return;
        }
        var edge = visEdge.data;
        if (edge.kind === 'site') {
            this.showSiteLink(id);
            return;
        }
        var self = this;
        var html = '<h4 class="topo-panel-title">' + esc(this.nodeName(edge.from)) + ' &harr; ' + esc(this.nodeName(edge.to)) + '</h4>';

        if (L3_LAYERS[edge.kind]) {
            this.panel.innerHTML = html + this.layerDetails(edge);
            return;
        }
        if (edge.kind === 'ospf' || edge.kind === 'bgp') {
            html += '<table class="table table-condensed topo-kv">' +
                this.row('Protocol', esc(edge.kind === 'ospf' ? 'OSPF' : edge.type)) +
                this.row('State', esc(edge.state) + this.layerSideNote(edge)) +
                this.row('Remote AS', esc(edge.remote_as)) +
                this.row('Peer addresses', esc((edge.peers || edge.addresses || []).join(', '))) +
                this.row('Description', esc(edge.description)) + '</table>';
            this.panel.innerHTML = html;
            return;
        }

        html += '<table class="table table-condensed topo-kv">';
        if (this.mode === 'l2') {
            html += this.row('Speed', esc(formatSpeed(edge.speed) ? formatSpeed(edge.speed) + 'bps' : 'unknown')) +
                this.row('Status', '<span class="topo-status-' + esc(edge.status) + '">' + esc(edge.status) + '</span>') +
                this.row('Utilization', edge.util === null ? '' : esc(edge.util + '%')) +
                this.row('Errors/s', edge.errors ? esc(edge.errors) : '') +
                this.row('Discovered by', esc((edge.protocols || []).join(', ').toUpperCase())) +
                this.row('Common VLANs', edge.vlans && edge.vlans.length ? '<span class="topo-vlans">' + esc(edge.vlans.length > 60 ? edge.vlans.slice(0, 60).join(',') + ' (' + edge.vlans.length + ')' : edge.vlans.join(',')) + '</span>' : '');
            if (edge.lag) {
                html += this.row('Aggregate', esc((edge.lag.a || '-') + ' / ' + (edge.lag.b || '-') + ' (' + edge.lag.up + '/' + edge.lag.members + ' members up)'));
            }
        } else {
            html += this.row('Network', esc(edge.network)) + this.row('VRF', esc(edge.vrf)) + this.row('Type', esc(edge.kind === 'p2p' ? 'point-to-point' : 'segment member'));
        }
        html += '<tr><th colspan="2" class="topo-section">' + esc(this.nodeName(edge.from)) + '</th></tr>' + this.endpointRows(edge.a);
        if (edge.b) {
            html += '<tr><th colspan="2" class="topo-section">' + esc(this.nodeName(edge.to)) + '</th></tr>' + this.endpointRows(edge.b);
        }
        html += '</table>';

        if (edge.members && edge.members.length > 1) {
            html += '<h5>Member links</h5><table class="table table-condensed topo-kv">';
            edge.members.forEach(function (member) {
                html += '<tr><td>' + esc(member.a) + '</td><td>' + esc(member.b) + '</td><td>' + esc(formatSpeed(member.speed)) + '</td><td>' +
                    (member.up ? '<span class="topo-status-up">up</span>' : '<span class="topo-status-down">down</span>') + '</td></tr>';
            });
            html += '</table>';
        }
        if (this.mode === 'l2') {
            html += '<div class="topo-actions"><button class="btn btn-xs btn-danger" data-sim-edge="' + esc(id) + '"><i class="fa fa-bolt"></i> ' +
                (this.isFailed('edge', id) ? 'Undo simulated failure' : 'Simulate failure of this link') + '</button></div>';
        }

        this.panel.innerHTML = html;
        this.panel.querySelectorAll('[data-sim-edge]').forEach(function (button) {
            button.addEventListener('click', function () {
                self.toggleFailure(self.isFailed('edge', id) ? self.failureOf(edge) : self.edgeRef(edge));
                self.showEdge(id);
            });
        });
    };

    /**
     * Legend shown in the details panel when nothing is selected.
     */
    TopologyMap.prototype.showLegend = function () {
        if (this.mode === 'sites') {
            this.showSiteLegend();
            return;
        }
        var roles = this.mode === 'l2'
            ? [['l3switch', 'L3 switch'], ['switch', 'L2 switch'], ['router', 'Router'], ['firewall', 'Firewall'], ['wireless', 'Wireless / AP'], ['server', 'Server'], ['phone', 'Phone'], ['unknown', 'Unmanaged']]
            : [['router', 'Router'], ['l3switch', 'L3 switch'], ['firewall', 'Firewall'], ['cloud', 'External AS']];
        var html = '<h4 class="topo-panel-title">Legend</h4><div class="topo-legend">';
        roles.forEach(function (role) {
            html += '<div><img src="' + iconUrl(role[0], role[0] === 'unknown' ? 'unknown' : 'up', role[0] !== 'unknown') + '" width="22" height="22" alt=""> ' + esc(role[1]) + '</div>';
        });
        html += '</div><div class="topo-legend topo-legend-lines">';
        if (this.mode === 'l2') {
            if (this.view.colorBy === 'util') {
                [['#2e9e44', '< 30%'], ['#d4ac0d', '30-60%'], ['#e67e22', '60-80%'], ['#c0392b', '> 80%'], ['#8e9aa6', 'no data']].forEach(function (item) {
                    html += '<div><span class="topo-line" style="background:' + item[0] + '"></span> ' + item[1] + '</div>';
                });
            } else {
                [['#b0279e', '100G+'], ['#6f42c1', '40G'], ['#3949ab', '25G'], ['#1f6fb2', '10G'], ['#17a2b8', '2.5-5G'], ['#2e9e44', '1G'], ['#8e9aa6', '< 1G / unknown']].forEach(function (item) {
                    html += '<div><span class="topo-line" style="background:' + item[0] + '"></span> ' + item[1] + '</div>';
                });
            }
            html += '<div><span class="topo-line topo-line-dashed" style="border-color:#c0392b"></span> Link down</div>' +
                '<div><span class="topo-line topo-line-dashed" style="border-color:#e67e22"></span> STP blocked</div>' +
                '<div><span class="topo-marker">&#9733;</span> STP root bridge</div>' +
                '<div><small>[R] root port, [D] designated, [ALT] alternate/blocking, [FWD?] forwarding but not designated</small></div>' +
                '<div><span class="topo-legend-badge">&#9660; 3 behind</span> Top-most down device of an outage</div>' +
                '<div><span class="topo-legend-cross">&times;</span> Simulated failure</div>' +
                '<div><span class="topo-legend-ring"></span> Cut off from the core by it</div>';
        } else {
            html += '<div><span class="topo-line" style="background:#1f6fb2"></span> Point-to-point subnet</div>' +
                '<div><span class="topo-subnet">10.0.0.0/24</span> Multi-access subnet</div>' +
                '<div><span class="topo-line topo-line-dashed" style="border-color:#16a085"></span> OSPF adjacency</div>' +
                '<div><span class="topo-line topo-line-dashed" style="border-color:#8e44ad"></span> BGP session</div>' +
                this.layerLegend() +
                '<div><span class="topo-line topo-line-dashed" style="border-color:#c0392b"></span> Down / not established</div>';
        }
        html += '</div><p class="text-muted topo-hint">Click a device or link for details. Double-click a device to center the map on it. Drag devices to arrange, then <i>Save layout</i>.' +
            (this.mode === 'l2' ? ' <i>Simulate failure</i> in the details shows which devices would lose their path to the core.' : '') + '</p>';
        this.panel.innerHTML = html;
    };

    /**
     * Status line under the map: counts, centered device and truncation warning.
     */
    TopologyMap.prototype.updateStatus = function () {
        if (this.mode === 'sites') {
            this.updateSiteStatus();
            return;
        }
        var stats = this.data.stats || {};
        var scope = this.data.scope || {};
        var parts = [];
        if (this.mode === 'l2') {
            parts.push(stats.devices + ' devices', stats.unmanaged + ' unmanaged neighbors', stats.links + ' links');
            if (stats.down) parts.push('<span class="text-danger">' + stats.down + ' down</span>');
            if (stats.blocked) parts.push('<span class="topo-text-warning">' + stats.blocked + ' STP blocked</span>');
            var outages = Object.keys(this.outages.top).length;
            if (outages) parts.push('<span class="text-danger">' + outages + ' outage' + (outages === 1 ? '' : 's') + '</span>');
        } else {
            parts.push(stats.devices + ' L3 devices', stats.subnets + ' subnets', stats.p2p + ' point-to-point links');
            if (stats.ospf) parts.push(stats.ospf + ' OSPF adjacencies');
            if (stats.bgp) parts.push(stats.bgp + ' BGP sessions');
            parts = parts.concat(this.layerStatus(stats));
            if (stats.down) parts.push('<span class="text-danger">' + stats.down + ' down</span>');
        }
        var html = parts.join(' &middot; ');
        if (scope.root_name) {
            html = 'Centered on <b>' + esc(scope.root_name) + '</b> (' + esc(scope.depth) + ' hops) &middot; ' + html;
        }
        if (scope.truncated) {
            html += ' &middot; <span class="topo-text-warning"><i class="fa fa-exclamation-triangle"></i> Showing ' + scope.shown + ' of ' + scope.total +
                ' devices (limit ' + scope.max_nodes + '). Pick a device, group or location to narrow the map.</span>';
        }
        if (!this.data.nodes.length) {
            html = '<span class="topo-text-warning">No ' + (this.mode === 'l2' ? 'LLDP/CDP neighbor links' : 'L3 interfaces') + ' found for this scope.</span>';
        }
        this.status.innerHTML = html;
    };

    /**
     * Device names offered by the "Find device" box.
     */
    TopologyMap.prototype.updateSearchList = function () {
        var list = document.getElementById('topo-search-list');
        if (!list) {
            return;
        }
        list.innerHTML = this.data.nodes.filter(function (node) {
            return node.type !== 'subnet';
        }).map(function (node) {
            return '<option value="' + esc(node.name) + '">';
        }).join('');
    };

    /**
     * Select and zoom to the first device whose name matches (exact match first).
     */
    TopologyMap.prototype.find = function (text) {
        text = String(text || '').toLowerCase().trim();
        if (!text || !this.data) {
            return;
        }
        var match = this.data.nodes.find(function (node) {
            return String(node.name).toLowerCase() === text;
        }) || this.data.nodes.find(function (node) {
            return String(node.name).toLowerCase().indexOf(text) !== -1 || String(node.label).toLowerCase().indexOf(text) !== -1;
        });
        if (match) {
            this.network.selectNodes([match.id]);
            this.network.focus(match.id, {scale: Math.max(1, this.network.getScale()), animation: {duration: 500}});
            this.showNode(match.id);
        }
    };

    /**
     * Reload the map centered on a device (double-click or "Center map here").
     */
    TopologyMap.prototype.recenter = function (deviceId, name) {
        var $ = window.jQuery;
        var select = document.getElementById('topo-device');
        if (!select) {
            return;
        }
        if (!select.querySelector('option[value="' + Number(deviceId) + '"]')) {
            select.appendChild(new Option(name || ('#' + deviceId), deviceId, false, false));
        }
        if ($ && $.fn && $.fn.select2) {
            $(select).val(String(deviceId)).trigger('change');
        } else {
            select.value = String(deviceId);
            this.load();
        }
    };

    // ------------------------------------------------------------------ layout persistence & export

    TopologyMap.prototype.saveLayout = function () {
        if (!this.network) {
            return;
        }
        try {
            window.localStorage.setItem(this.layoutKey(), JSON.stringify(this.network.getPositions()));
            this.flash('Layout saved in this browser for this map scope.');
        } catch (e) {
            this.flash('Unable to save layout: ' + e.message);
        }
    };

    /**
     * Forget saved positions for this scope and lay the map out again.
     */
    TopologyMap.prototype.resetLayout = function () {
        try {
            window.localStorage.removeItem(this.layoutKey());
        } catch (e) {
            // ignore
        }
        this.render(true);
    };

    /**
     * Short notification (LibreNMS toastr when available).
     */
    TopologyMap.prototype.flash = function (message) {
        if (window.toastr) {
            window.toastr.info(message);
        } else {
            this.status.innerHTML = esc(message);
        }
    };

    /**
     * Let the browser save a data URI as a file.
     */
    TopologyMap.prototype.download = function (href, filename) {
        var link = document.createElement('a');
        link.href = href;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    TopologyMap.prototype.fileBase = function () {
        return 'topology-' + this.mode + '-' + new Date().toISOString().slice(0, 16).replace(/[:T]/g, '');
    };

    /**
     * Download the current view of the map as PNG.
     */
    TopologyMap.prototype.exportPng = function () {
        var canvas = this.container.querySelector('canvas');
        if (canvas) {
            this.network.redraw();
            this.download(canvas.toDataURL('image/png'), this.fileBase() + '.png');
        }
    };

    /**
     * Download the links of the map as CSV (one row per link / session).
     */
    TopologyMap.prototype.exportCsv = function () {
        if (!this.data) {
            return;
        }
        var self = this;
        var quote = function (value) {
            value = String(value === null || value === undefined ? '' : value);
            if (/^[=+\-@]/.test(value)) {
                value = "'" + value; // spreadsheet formula injection guard
            }
            return '"' + value.replace(/"/g, '""') + '"';
        };
        var rows;
        if (this.mode === 'sites') {
            rows = this.siteCsvRows();
        } else if (this.mode === 'l2') {
            rows = [['Device A', 'Interface A', 'Device B', 'Interface B', 'Speed', 'Status', 'Aggregate', 'Common VLANs', 'STP A', 'STP B', 'Protocols']];
            this.data.edges.forEach(function (e) {
                rows.push([self.nodeName(e.from), e.a && e.a.name, self.nodeName(e.to), e.b && e.b.name, formatSpeed(e.speed), e.status,
                    e.lag ? (e.lag.a || '') + '/' + (e.lag.b || '') + ' x' + e.lag.members : '', (e.vlans || []).join(' '),
                    e.a && e.a.stp ? e.a.stp.state + '/' + e.a.stp.role : '', e.b && e.b.stp ? e.b.stp.state + '/' + e.b.stp.role : '',
                    (e.protocols || []).join(' ')]);
            });
        } else {
            rows = [['Type', 'Device A', 'Interface A', 'Address A', 'Peer / Subnet', 'Interface B', 'Address B', 'Network', 'VRF', 'State']];
            this.data.edges.forEach(function (e) {
                rows.push([e.kind, self.nodeName(e.from), e.a && e.a.name, e.a && e.a.ips ? e.a.ips.join(' ') : '', self.nodeName(e.to),
                    e.b && e.b.name, e.b && e.b.ips ? e.b.ips.join(' ') : (e.peers || e.addresses || []).join(' '), e.network, e.vrf, e.state || e.status]);
            });
        }
        var csv = rows.map(function (row) {
            return row.map(quote).join(',');
        }).join('\r\n');
        this.download('data:text/csv;charset=utf-8,' + encodeURIComponent(csv), this.fileBase() + '.csv');
    };

    // ------------------------------------------------------------------ site / WAN map

    /**
     * "1 site", "3 sites".
     */
    function plural(count, word, words) {
        return count + ' ' + (count === 1 ? word : (words || word + 's'));
    }

    /**
     * Data URI of the site icon (a building) colored by status: blue all up, orange some devices down, red all down.
     */
    function siteIconUrl(status) {
        var color = STATUS_COLORS[status] || STATUS_COLORS.unknown;
        var key = 'site|' + color;
        if (!iconCache[key]) {
            var dark = shade(color, -45);
            var light = shade(color, 60);
            var windows = '';
            for (var row = 0; row < 3; row++) {
                for (var col = 0; col < 3; col++) {
                    windows += '<rect x="' + (18 + col * 10) + '" y="' + (20 + row * 9) + '" width="6" height="5" fill="' + light + '"/>';
                }
            }
            var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">' +
                '<polygon points="10,16 32,5 54,16" fill="' + dark + '"/>' +
                '<rect x="12" y="16" width="40" height="42" fill="' + color + '"/>' + windows +
                '<rect x="28" y="47" width="8" height="11" fill="' + dark + '"/></svg>';
            iconCache[key] = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
        }
        return iconCache[key];
    }

    /**
     * Site link: width and color from the capacity (or utilization / status) of its links, dashed when it
     * only consists of routing sessions or is not fully up, grey dotted when every session was shut down.
     */
    TopologyMap.prototype.visSiteEdge = function (edge) {
        var routingOnly = !edge.links;
        var color;
        if (this.view.colorBy === 'util') {
            color = utilColor(edge.util);
        } else if (this.view.colorBy === 'status') {
            color = edge.status === 'up' ? '#2e9e44' : (edge.status === 'degraded' ? '#e67e22' : '#c0392b');
        } else {
            color = routingOnly ? '#8e44ad' : speedColor(edge.speed);
        }
        var dashes = routingOnly ? [4, 4] : false;
        if (edge.status === 'down') {
            color = '#c0392b';
            dashes = [6, 4];
        } else if (edge.status === 'degraded' && !routingOnly) {
            dashes = [12, 4];
        } else if (edge.status === 'disabled') {
            color = STATUS_COLORS.disabled;
            dashes = [2, 4];
        }

        var tooltip = document.createElement('div');
        tooltip.className = 'topo-tooltip';
        tooltip.innerHTML = this.edgeTooltip(edge);

        return {
            id: edge.id,
            from: edge.from,
            to: edge.to,
            width: routingOnly ? 2 : speedWidth(edge.speed, edge.links > 1),
            dashes: dashes,
            label: this.view.linkLabels ? this.siteLinkLabel(edge) : undefined,
            color: {color: color, highlight: '#f39c12', hover: color, opacity: 1},
            title: tooltip,
            data: edge
        };
    };

    /**
     * "2 links 20G · BGP": the links of a site link and its routing sessions.
     */
    TopologyMap.prototype.siteLinkLabel = function (edge) {
        var parts = [];
        if (edge.links) {
            parts.push(edge.links + (edge.links === 1 ? ' link' : ' links') + (edge.speed ? ' ' + formatSpeed(edge.speed) : ''));
        }
        var routing = [];
        if (edge.routing.ospf) {
            routing.push('OSPF');
        }
        if (edge.routing.bgp) {
            routing.push('BGP');
        }
        if (routing.length) {
            parts.push(routing.join(' + '));
        }
        if (this.view.colorBy === 'util' && edge.util !== null) {
            parts.push(edge.util + '%');
        }
        return parts.join(' · ') || undefined;
    };

    /**
     * Push apart positions closer than gap (sites in one city or at one address). A crowd in one cell of
     * a grid of the gap's size first goes round its middle in a sunflower spiral; then each position is
     * only compared with those in its own and the neighboring cells, and moves by the sum of its pushes
     * (at most half the gap per round). Fixed positions do not move.
     */
    function spreadPositions(positions, ids, gap, fixed) {
        fixed = fixed || {};
        var count = ids.length;
        var x = new Float64Array(count);
        var y = new Float64Array(count);
        var still = ids.map(function (id, i) {
            x[i] = positions[id].x;
            y[i] = positions[id].y;
            return !!fixed[id];
        });
        var cellOf = function (i) {
            return (Math.floor(x[i] / gap) + 32768) * 65536 + Math.floor(y[i] / gap) + 32768;
        };
        var group = function (map, key, i) {
            var list = map.get(key);
            if (list) {
                list.push(i);
            } else {
                map.set(key, [i]);
            }
        };
        var i;

        var crowds = new Map();
        for (i = 0; i < count; i++) {
            if (!still[i]) {
                group(crowds, cellOf(i), i);
            }
        }
        crowds.forEach(function (crowd) {
            if (crowd.length < 3) {
                return;
            }
            var cx = 0;
            var cy = 0;
            crowd.forEach(function (p) {
                cx += x[p] / crowd.length;
                cy += y[p] / crowd.length;
            });
            crowd.sort(function (a, b) {
                return Math.hypot(x[a] - cx, y[a] - cy) - Math.hypot(x[b] - cx, y[b] - cy) || a - b;
            });
            crowd.forEach(function (p, k) {
                var radius = gap * 0.66 * Math.sqrt(k + 0.5); // one gap between neighbors
                x[p] = cx + radius * Math.cos(k * 2.39996); // the golden angle
                y[p] = cy + radius * Math.sin(k * 2.39996);
            });
        });

        var moveX = new Float64Array(count);
        var moveY = new Float64Array(count);
        for (var round = 0; round < 100; round++) {
            var cells = new Map();
            for (i = 0; i < count; i++) {
                group(cells, cellOf(i), i);
            }
            moveX.fill(0);
            moveY.fill(0);
            var overlaps = 0;
            cells.forEach(function (list, cell) {
                for (var dx = -1; dx <= 1; dx++) {
                    for (var dy = -1; dy <= 1; dy++) {
                        var near = cell + dx * 65536 + dy;
                        var others = near >= cell ? cells.get(near) : null;
                        for (var a = 0; others && a < list.length; a++) {
                            for (var b = near === cell ? a + 1 : 0; b < others.length; b++) {
                                var p = list[a];
                                var q = others[b];
                                var ex = x[q] - x[p];
                                var ey = y[q] - y[p];
                                var distance = Math.sqrt(ex * ex + ey * ey);
                                if (distance >= gap || (still[p] && still[q])) {
                                    continue;
                                }
                                overlaps++;
                                if (distance < 0.01) {
                                    var angle = ((p * 5 + q) % 12) * Math.PI / 6;
                                    ex = Math.cos(angle);
                                    ey = Math.sin(angle);
                                    distance = 1;
                                }
                                var push = (gap - distance) / 2 + 0.5;
                                var pushP = still[p] ? 0 : (still[q] ? 2 * push : push);
                                var pushQ = still[q] ? 0 : (still[p] ? 2 * push : push);
                                moveX[p] -= ex / distance * pushP;
                                moveY[p] -= ey / distance * pushP;
                                moveX[q] += ex / distance * pushQ;
                                moveY[q] += ey / distance * pushQ;
                            }
                        }
                    }
                }
            });
            if (!overlaps) {
                break;
            }
            for (i = 0; i < count; i++) {
                var length = Math.sqrt(moveX[i] * moveX[i] + moveY[i] * moveY[i]);
                var factor = length > gap / 2 ? gap / 2 / length : 1;
                x[i] += moveX[i] * factor;
                y[i] += moveY[i] * factor;
            }
        }

        ids.forEach(function (id, index) {
            positions[id].x = x[index];
            positions[id].y = y[index];
        });
    }

    /**
     * Geographic layout: sites by latitude / longitude (equirectangular projection around their center,
     * scaled to the map canvas, nudged apart where they overlap), sites without coordinates in a grid
     * under them, clouds above the middle of their sites. Null (the organic layout) when most sites have
     * no coordinates.
     */
    TopologyMap.prototype.geoPositions = function () {
        var nodes = this.data.nodes;
        var sites = nodes.filter(function (node) {
            return node.type === 'site';
        });
        var placed = sites.filter(function (node) {
            return node.lat !== null && node.lng !== null;
        });
        if (!placed.length || placed.length * 2 < sites.length) {
            return null;
        }

        var lats = placed.map(function (node) {
            return node.lat;
        });
        var lngs = placed.map(function (node) {
            return node.lng;
        });
        var lat0 = (Math.min.apply(null, lats) + Math.max.apply(null, lats)) / 2;
        var lng0 = (Math.min.apply(null, lngs) + Math.max.apply(null, lngs)) / 2;
        var k = Math.cos(lat0 * Math.PI / 180) || 1;
        // fill about 1100 x 600 px, the shape of the map canvas
        var width = (Math.max.apply(null, lngs) - Math.min.apply(null, lngs)) * k;
        var height = Math.max.apply(null, lats) - Math.min.apply(null, lats);
        var scale = Math.min(width > 0 ? 1100 / width : Infinity, height > 0 ? 600 / height : Infinity);
        scale = isFinite(scale) ? scale : 1;
        // a dense network gets more room: about two gap x gap squares per site, up to 8 times the canvas
        var gap = 120;
        scale *= Math.min(8, Math.max(1, Math.sqrt(placed.length * 2 * gap * gap / (Math.max(width * scale, 1) * Math.max(height * scale, 1)))));
        var positions = {};
        placed.forEach(function (node) {
            positions[node.id] = {x: (node.lng - lng0) * k * scale, y: -(node.lat - lat0) * scale};
        });

        // sites in one city, or at the same address, would overlap: push them apart
        var ids = Object.keys(positions);
        spreadPositions(positions, ids, gap);

        // sites without coordinates: a grid under the map, as wide as the placed sites
        var xs = ids.map(function (id) {
            return positions[id].x;
        });
        var ys = ids.map(function (id) {
            return positions[id].y;
        });
        var left = Math.min.apply(null, xs);
        var bottom = Math.max.apply(null, ys) + 180;
        var unplaced = sites.filter(function (node) {
            return !positions[node.id];
        });
        var columns = Math.max(Math.floor((Math.max.apply(null, xs) - left) / 170) + 1, Math.ceil(Math.sqrt(unplaced.length)));
        unplaced.forEach(function (node, index) {
            positions[node.id] = {x: left + (index % columns) * 170, y: bottom + Math.floor(index / columns) * 120};
        });
        bottom += Math.ceil(unplaced.length / columns) * 120;

        // clouds above the middle of their sites, moved off the sites they would cover
        var around = {};
        this.data.edges.forEach(function (edge) {
            (around[edge.from] = around[edge.from] || []).push(edge.to);
            (around[edge.to] = around[edge.to] || []).push(edge.from);
        });
        var fixed = {};
        sites.forEach(function (node) {
            fixed[node.id] = true;
        });
        var clouds = nodes.filter(function (node) {
            return node.type !== 'site';
        });
        clouds.forEach(function (node, index) {
            var points = (around[node.id] || []).map(function (id) {
                return positions[id];
            }).filter(Boolean);
            if (!points.length) {
                positions[node.id] = {x: left + index * 170, y: bottom + 60};
                return;
            }
            positions[node.id] = {
                x: points.reduce(function (sum, point) {
                    return sum + point.x;
                }, 0) / points.length + (index % 2 ? 60 : -60),
                y: points.reduce(function (sum, point) {
                    return sum + point.y;
                }, 0) / points.length - 150
            };
        });
        spreadPositions(positions, Object.keys(positions), gap, fixed);

        return positions;
    };

    /**
     * Open the L2 or L3 map of a site's location.
     */
    TopologyMap.prototype.openSite = function (site, page) {
        if (!site.location_id) {
            this.flash('Devices without a location cannot be filtered by location.');
            return;
        }
        window.location.href = page + '?location=' + encodeURIComponent(site.location_id);
    };

    /**
     * Reload the site map centered on a site.
     */
    TopologyMap.prototype.centerSite = function (locationId) {
        var select = document.getElementById('topo-site');
        if (select) {
            select.value = String(locationId);
            this.load();
        }
    };

    /**
     * Details panel of a site (devices, what is down, its site links, actions) or of an AS cloud.
     */
    TopologyMap.prototype.showSite = function (id) {
        var self = this;
        var node = this.nodes.get(id).data;
        var now = Date.now() / 1000;
        var html = '<h4 class="topo-panel-title">' + esc(node.name) + '</h4><table class="table table-condensed topo-kv">';

        if (node.type === 'site') {
            var roles = Object.keys(node.roles).map(function (role) {
                return node.roles[role] + ' ' + role;
            }).join(', ');
            html += this.row('Status', '<span class="topo-status-' + esc(node.status) + '">' + esc(node.status) + '</span>') +
                this.row('Devices', esc(node.devices + (roles ? ' (' + roles + ')' : ''))) +
                this.row('Down', node.down_devices.slice(0, 25).map(function (device) {
                    return (device.url ? '<a href="' + esc(device.url) + '">' + esc(device.name) + '</a>' : esc(device.name)) +
                        (device.since ? ' <small class="text-muted">' + esc(formatDuration(Math.max(0, Math.round(now - device.since)))) + '</small>' : '');
                }).join(', ') + (node.down_devices.length > 25 ? ' &hellip; and ' + (node.down_devices.length - 25) + ' more' : '')) +
                this.row('Coordinates', node.lat !== null ? esc(node.lat + ', ' + node.lng) : '<span class="text-muted">not set</span>');
        } else {
            html += this.row('Remote AS', esc(node.remote_as)) + '<tr><td colspan="2" class="text-muted">' + (node.private
                ? 'BGP peers in a private AS that LibreNMS does not monitor. Sites often reuse private AS numbers, so every site has a cloud of its own.'
                : 'BGP peers that LibreNMS does not monitor, grouped by their AS: a provider network or an internet transit.') + '</td></tr>';
        }
        html += '</table>';

        var connected = this.network.getConnectedEdges(id).map(function (edgeId) {
            return self.edges.get(edgeId);
        }).filter(Boolean);
        if (connected.length) {
            html += '<h5>Site links (' + connected.length + ')</h5><table class="table table-condensed topo-kv topo-neighbors">';
            connected.forEach(function (visEdge) {
                var edge = visEdge.data;
                html += '<tr data-edge="' + esc(visEdge.id) + '"><td>' + esc(self.nodeName(edge.from === id ? edge.to : edge.from)) + '</td><td>' +
                    esc(self.siteLinkLabel(edge) || '') + ' <span class="topo-status-' + esc(edge.status) + '">' + esc(edge.status) + '</span></td></tr>';
            });
            html += '</table>';
        }

        if (node.type === 'site') {
            var urls = this.config.urls;
            var location = node.location_id ? '?location=' + encodeURIComponent(node.location_id) : '';
            html += '<div class="topo-actions">' +
                (node.location_id ? '<button class="btn btn-xs btn-primary" data-center-site="' + esc(node.location_id) + '"><i class="fa fa-crosshairs"></i> Center map here</button> ' +
                    '<a class="btn btn-xs btn-default" href="' + esc(urls.l2Page + location) + '">L2 map</a> ' +
                    '<a class="btn btn-xs btn-default" href="' + esc(urls.l3Page + location) + '">L3 map</a> ' +
                    '<a class="btn btn-xs btn-default" href="' + esc(urls.redundancyPage + location) + '">Redundancy</a> ' +
                    '<a class="btn btn-xs btn-default" href="' + esc(urls.devicesPage + '/location=' + encodeURIComponent(node.location_id)) + '">Devices</a>'
                    : '<span class="text-muted">Set a location on these devices to see them by site.</span>') +
                '</div>';
        }

        this.panel.innerHTML = html;
        this.panel.querySelectorAll('[data-center-site]').forEach(function (button) {
            button.addEventListener('click', function () {
                self.centerSite(button.getAttribute('data-center-site'));
            });
        });
        this.panel.querySelectorAll('[data-edge]').forEach(function (row) {
            row.addEventListener('click', function () {
                self.network.selectEdges([row.getAttribute('data-edge')]);
                self.showSiteLink(row.getAttribute('data-edge'));
            });
        });
    };

    /**
     * Details panel of a site link: totals, then every link and routing session between the two sites.
     */
    TopologyMap.prototype.showSiteLink = function (id) {
        var visEdge = this.edges.get(id);
        if (!visEdge) {
            return;
        }
        var edge = visEdge.data;
        var fromName = this.nodeName(edge.from);
        var toName = this.nodeName(edge.to);
        var link = function (url, text) {
            return url ? '<a href="' + esc(url) + '">' + esc(text) + '</a>' : esc(text);
        };
        var routing = [];
        if (edge.routing.ospf) {
            routing.push(edge.routing.ospf + ' OSPF');
        }
        if (edge.routing.bgp) {
            routing.push(edge.routing.bgp + ' BGP');
        }
        // tunnels and other logical interfaces have no capacity of their own
        var unknown = edge.members.filter(function (member) {
            return (member.kind === 'l2' || member.kind === 'p2p') && !member.speed;
        }).length;
        var capacity = (edge.speed ? esc(formatSpeed(edge.speed) + 'bps') : '') + (unknown
            ? '<span class="text-muted">' + (edge.speed ? ' + ' : '') + esc(plural(unknown, 'link') + ' with traffic only (tunnel or logical interface)') + '</span>'
            : '');

        var html = '<h4 class="topo-panel-title">' + esc(fromName) + ' &harr; ' + esc(toName) + '</h4><table class="table table-condensed topo-kv">' +
            this.row('Status', '<span class="topo-status-' + esc(edge.status) + '">' + esc(edge.status) + '</span>') +
            this.row('Links', edge.links ? esc(edge.links) : '') +
            this.row('Capacity', capacity) +
            this.row('Busiest link', edge.util !== null ? esc(edge.util + '%') : '') +
            this.row('Traffic', edge.links ? esc(fromName + ' → ' + toName + ': ' + formatRate(edge.from_bps)) + '<br>' +
                esc(toName + ' → ' + fromName + ': ' + formatRate(edge.to_bps)) : '') +
            this.row('Routing', esc(routing.join(', '))) + '</table>';

        html += '<h5>Members (' + edge.members.length + ')</h5><table class="table table-condensed topo-kv topo-site-members">';
        edge.members.forEach(function (member) {
            var kind = {
                l2: (member.protocols || []).join('/').toUpperCase() || 'Link',
                p2p: 'Subnet',
                ospf: 'OSPF',
                bgp: member.type || 'BGP'
            }[member.kind] || member.kind;
            var end = function (point) {
                if (!point) {
                    return esc(member.peer || '');
                }
                return link(point.device_url, point.device) + (point.port ? ' ' + link(point.port_url, point.port) : '') +
                    (point.aggregate ? ' <small class="text-muted">(' + esc(point.aggregate) + ')</small>' : '');
            };
            var detail = member.kind === 'l2' || member.kind === 'p2p'
                ? (member.speed ? formatSpeed(member.speed) + (member.util !== null ? ' \u00b7 ' + member.util + '%' : '')
                    : 'traffic only \u00b7 ' + formatRate(Math.max(member.in_bps, member.out_bps)))
                : member.state || '';
            html += '<tr><th>' + esc(kind) + '<br><span class="topo-status-' + esc(member.status) + '">' + esc(member.status) + '</span> ' +
                '<small>' + esc(detail) + '</small></th><td>' + end(member.a) + '<br>&harr; ' + end(member.b) +
                (member.network ? '<br><small class="text-muted">' + esc(member.network) + '</small>' : '') + '</td></tr>';
        });
        html += '</table>';
        this.panel.innerHTML = html;
    };

    /**
     * Legend of the site map.
     */
    TopologyMap.prototype.showSiteLegend = function () {
        var html = '<h4 class="topo-panel-title">Legend</h4><div class="topo-legend">' +
            '<div><img src="' + siteIconUrl('up') + '" width="22" height="22" alt=""> Site</div>' +
            '<div><img src="' + siteIconUrl('degraded') + '" width="22" height="22" alt=""> Some devices down</div>' +
            '<div><img src="' + siteIconUrl('down') + '" width="22" height="22" alt=""> All devices down</div>' +
            '<div><img src="' + iconUrl('cloud', 'unknown', false) + '" width="22" height="22" alt=""> External AS (BGP peers not monitored)</div>' +
            '</div><div class="topo-legend topo-legend-lines">';
        var lines = this.view.colorBy === 'util'
            ? [['#2e9e44', 'busiest link < 30%'], ['#d4ac0d', '30-60%'], ['#e67e22', '60-80%'], ['#c0392b', '> 80%'], ['#8e9aa6', 'no data']]
            : [['#b0279e', '100G+'], ['#6f42c1', '40G'], ['#3949ab', '25G'], ['#1f6fb2', '10G'], ['#17a2b8', '2.5-5G'], ['#2e9e44', '1G'], ['#8e9aa6', '< 1G / unknown']];
        if (this.view.colorBy !== 'status') {
            lines.forEach(function (item) {
                html += '<div><span class="topo-line" style="background:' + item[0] + '"></span> ' + item[1] + '</div>';
            });
        }
        html += '<div><span class="topo-line topo-line-dashed" style="border-color:#8e44ad"></span> Routing sessions only (OSPF / BGP)</div>' +
            '<div><span class="topo-line topo-line-dashed" style="border-color:#e67e22"></span> Some links or sessions down</div>' +
            '<div><span class="topo-line topo-line-dashed" style="border-color:#c0392b"></span> All down</div>' +
            '<div><span class="topo-line topo-line-dashed" style="border-color:' + STATUS_COLORS.disabled + '"></span> BGP sessions shut down by an administrator</div>' +
            '</div><p class="text-muted topo-hint">A site is a LibreNMS location. Links between devices at different locations, point-to-point ' +
            'subnets and OSPF / BGP sessions add up to one link per pair of sites; tunnels and other logical interfaces only add their traffic. ' +
            'Click a site or link for details; double-click a site to open its L2 map. The geographic layout places sites by the latitude / ' +
            'longitude of their location.</p>';
        this.panel.innerHTML = html;
    };

    /**
     * Status line of the site map.
     */
    TopologyMap.prototype.updateSiteStatus = function () {
        var stats = this.data.stats || {};
        var scope = this.data.scope || {};
        var parts = [plural(stats.sites, 'site'), plural(stats.devices, 'device'), plural(stats.links, 'site link')];
        if (stats.clouds) {
            parts.push(plural(stats.clouds, 'external AS', 'external ASes'));
        }
        if (stats.down_devices) {
            parts.push('<span class="text-danger">' + plural(stats.down_devices, 'device') + ' down' +
                (stats.down_sites ? ' (' + plural(stats.down_sites, 'site') + ' entirely)' : '') + '</span>');
        }
        if (stats.down_links || stats.degraded_links) {
            parts.push('<span class="topo-text-warning">' + plural(stats.down_links + stats.degraded_links, 'site link') + ' not fully up</span>');
        }
        var html = parts.join(' &middot; ');
        if (scope.root_name) {
            html = 'Centered on <b>' + esc(scope.root_name) + '</b> (' + esc(plural(Number(scope.depth), 'hop')) + ') &middot; ' + html;
        }
        if (scope.truncated) {
            html += ' &middot; <span class="topo-text-warning"><i class="fa fa-exclamation-triangle"></i> Showing ' + scope.shown + ' of ' + scope.total +
                ' sites (limit ' + scope.max_nodes + '). Center on a site or pick a group to narrow the map.</span>';
        }
        var sites = this.data.nodes.filter(function (node) {
            return node.type === 'site';
        });
        var placed = sites.filter(function (node) {
            return node.lat !== null && node.lat !== undefined;
        }).length;
        if (this.view.layout === 'geographic' && !placed) {
            html += ' &middot; <span class="topo-text-warning">No location has a latitude / longitude, so the geographic layout is not available.</span>';
        } else if (this.view.layout === 'geographic' && placed * 2 < sites.length) {
            html += ' &middot; <span class="topo-text-warning">Only ' + placed + ' of ' + sites.length +
                ' sites have a latitude / longitude, so they are laid out without them.</span>';
        }
        if (!this.data.nodes.length) {
            html = '<span class="topo-text-warning">No sites with links found for this scope (tick <i>Unlinked sites</i> to show every location).</span>';
        }
        this.status.innerHTML = html;
    };

    /**
     * CSV rows of the site map: one per link or routing session between two sites.
     */
    TopologyMap.prototype.siteCsvRows = function () {
        var self = this;
        var rows = [['Site A', 'Site B', 'Type', 'Device A', 'Interface A', 'Device B / peer', 'Interface B', 'Speed', 'Status', 'Utilization %', 'Network / state']];
        this.data.edges.forEach(function (edge) {
            edge.members.forEach(function (member) {
                rows.push([self.nodeName(edge.from), self.nodeName(edge.to), member.kind === 'bgp' ? (member.type || 'BGP') : member.kind.toUpperCase(),
                    member.a && member.a.device, member.a && member.a.port, member.b ? member.b.device : member.peer, member.b && member.b.port,
                    formatSpeed(member.speed), member.status, member.util === null ? '' : member.util, member.network || member.state || '']);
            });
        });
        return rows;
    };

    window.TopologyMap = {
        init: function (config) {
            if (!window.vis || !window.vis.Network) {
                document.getElementById('topo-status').innerHTML = '<span class="text-danger">vis-network library is not available.</span>';
                return null;
            }
            return new TopologyMap(config);
        },
        // exposed for tests
        _formatSpeed: formatSpeed,
        _iconUrl: iconUrl
    };
})(window, document);
