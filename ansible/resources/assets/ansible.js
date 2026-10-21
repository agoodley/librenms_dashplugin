/*
 * LibreNMS Ansible plugin: the run page (live status and log), the new run page (targets, preview, launch) and
 * the settings page (connection test). Everything that comes from the server is inserted as text
 * (textContent), never as HTML: device output, names and messages can hold anything.
 */
(function (window, document) {
    'use strict';

    /** Bootstrap label classes the server may name. */
    var LABELS = ['default', 'primary', 'success', 'info', 'warning', 'danger'];
    var RESULTS = {unreachable: 'Unreachable', failed: 'Failed', not_run: 'Not run', changed: 'Changed', ok: 'OK', skipped: 'Skipped'};
    var RESULT_CSS = {ok: 'success', changed: 'warning', failed: 'danger', unreachable: 'danger'};
    var ORDER = ['unreachable', 'failed', 'not_run', 'changed', 'ok', 'skipped'];

    /**
     * An element with a class and text (or child nodes).
     */
    function el(tag, className, content) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        append(node, content);
        return node;
    }

    /**
     * Append text, a node or a list of them.
     */
    function append(node, content) {
        if (content === undefined || content === null) {
            return node;
        }
        if (Array.isArray(content)) {
            content.forEach(function (item) {
                append(node, item);
            });
        } else if (content instanceof window.Node) {
            node.appendChild(content);
        } else {
            node.appendChild(document.createTextNode(String(content)));
        }
        return node;
    }

    function byId(id) {
        return document.getElementById(id);
    }

    function clear(node) {
        while (node && node.firstChild) {
            node.removeChild(node.firstChild);
        }
        return node;
    }

    function label(css) {
        return 'label label-' + (LABELS.indexOf(css) >= 0 ? css : 'default');
    }

    /**
     * fetch() with LibreNMS' CSRF token; resolves to {status, body}, body {error} when it is not JSON.
     */
    function request(method, url, body) {
        var token = document.querySelector('meta[name="csrf-token"]');
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
        };
        if (method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-TOKEN'] = token ? token.getAttribute('content') : '';
            options.body = JSON.stringify(body || {});
        }
        return window.fetch(url, options).then(function (response) {
            return response.json().catch(function () {
                return null;
            }).then(function (data) {
                var fallback = 'HTTP ' + response.status + (response.status === 419 ? ': the page expired, reload it' : '');
                return {status: response.status, body: data || {error: fallback}};
            });
        }, function (error) {
            return {status: 0, body: {error: 'LibreNMS cannot be reached: ' + error.message}};
        });
    }

    /**
     * An alert box with a list of messages.
     */
    function alertBox(kind, title, messages) {
        var box = el('div', 'alert alert-' + kind);
        if (title) {
            append(box, el('strong', null, title));
        }
        if (messages && messages.length) {
            var list = el('ul', null);
            list.style.margin = '4px 0 0';
            messages.forEach(function (message) {
                append(list, el('li', null, message));
            });
            append(box, list);
        }
        return box;
    }

    /**
     * Badges of the targets per result (summary: result => count).
     */
    function resultBadges(summary) {
        var span = el('span', 'ans-results');
        ORDER.forEach(function (result) {
            if (summary && summary[result]) {
                append(span, [el('span', label(RESULT_CSS[result] || 'default'), summary[result] + ' ' + RESULTS[result].toLowerCase()), ' ']);
            }
        });
        return span;
    }

    /* ---------------------------------------------------------------- run page */

    /**
     * Follow a run: poll the plugin every 2 seconds while it is unfinished, append the new log lines, update the
     * status, the targets and the alerts; the Stop button.
     */
    function run(config) {
        var next = config.next;
        var log = byId('ans-log');
        var follow = byId('ans-log-follow');
        var stopButton = byId('ans-run-stop');
        var timer = null;

        function scroll() {
            if (follow && follow.checked) {
                log.scrollTop = log.scrollHeight;
            }
        }

        function appendLines(lines) {
            var fragment = document.createDocumentFragment();
            lines.forEach(function (segments) {
                var line = el('span', 'ans-line');
                segments.forEach(function (segment) {
                    var cls = segment[0];
                    if (cls && /^[a-z -]+$/.test(cls)) {
                        append(line, el('span', cls, segment[1]));
                    } else {
                        append(line, segment[1]);
                    }
                });
                fragment.appendChild(line);
                fragment.appendChild(document.createTextNode('\n'));
            });
            log.appendChild(fragment);
        }

        function setText(id, text) {
            var node = byId(id);
            if (node) {
                node.textContent = text === null || text === undefined || text === '' ? '-' : text;
            }
        }

        function update(data) {
            var status = byId('ans-run-status');
            status.className = label(data.css);
            status.textContent = data.label;
            setText('ans-run-runner-status', data.runner_status);
            setText('ans-run-duration', data.duration);
            setText('ans-run-started', data.started_at);
            setText('ans-run-finished', data.finished_at);
            clear(byId('ans-run-summary')).appendChild(resultBadges(data.summary));
            data.targets.forEach(function (target) {
                var row = document.querySelector('#ans-run-targets tr[data-device="' + Number(target.device_id) + '"]');
                if (!row) {
                    return;
                }
                var cell = clear(row.querySelector('.ans-target-result'));
                if (target.result) {
                    cell.appendChild(el('span', label(target.css) + ' ans-result', target.label));
                } else {
                    cell.appendChild(el('span', 'text-muted', data.final ? '-' : 'pending'));
                }
                Array.prototype.forEach.call(row.querySelectorAll('.ans-count'), function (count) {
                    var value = target.counts ? target.counts[count.getAttribute('data-counter')] : '';
                    count.textContent = value === undefined || value === null ? '' : value;
                });
            });
            var alerts = clear(byId('ans-run-alerts'));
            if (data.unexpected_hosts && data.unexpected_hosts.length) {
                var unexpected = alertBox('danger', 'The runner ran this template on hosts that were not its targets: ',
                    null);
                unexpected.id = 'ans-run-unexpected';
                append(unexpected, data.unexpected_hosts.join(', ') + '. Check in the runner that the template uses the LibreNMS inventory and allows the limit override, and what was done to these hosts.');
                alerts.appendChild(unexpected);
            }
            if (data.error) {
                var error = alertBox(data.status === 'lost' ? 'warning' : 'danger', null, null);
                error.id = 'ans-run-error';
                append(error, (data.status === 'not_launched' ? 'Not launched' : (data.status === 'lost' ? 'Lost' : 'Error')) + ': ' + data.error);
                alerts.appendChild(error);
            }
            if (data.poll_error && !data.final) {
                var pollError = alertBox('warning', null, null);
                pollError.id = 'ans-run-poll-error';
                append(pollError, 'The plugin cannot ask the runner right now: ' + data.poll_error);
                alerts.appendChild(pollError);
            }
            if (data.lines.length) {
                appendLines(data.lines);
                scroll();
            }
            next = data.next;
            setText('ans-log-count', next + ' lines');
            if (data.truncated && !byId('ans-log-truncated')) {
                var note = el('span', 'text-warning', 'The output is longer than the Output size setting: the rest is in the runner (task #' + data.runner_task_id + ').');
                note.id = 'ans-log-truncated';
                byId('ans-log-count').parentNode.appendChild(note);
            }
            if (data.final) {
                byId('ans-log-state').textContent = '';
                if (stopButton) {
                    stopButton.parentNode.style.display = 'none';
                }
            }
        }

        function poll() {
            request('GET', config.poll + '?after=' + encodeURIComponent(next)).then(function (result) {
                if (result.status === 200) {
                    update(result.body);
                    if (!result.body.final) {
                        timer = window.setTimeout(poll, 2000);
                    }
                } else {
                    byId('ans-log-state').textContent = '(cannot refresh: ' + (result.body.error || 'HTTP ' + result.status) + '; trying again)';
                    timer = window.setTimeout(poll, 5000);
                }
            });
        }

        if (stopButton) {
            stopButton.addEventListener('click', function () {
                if (!window.confirm('Stop this run? Ansible finishes the current step on each device, then stops.')) {
                    return;
                }
                stopButton.disabled = true;
                request('POST', config.stop, {}).then(function (result) {
                    if (result.status === 200) {
                        byId('ans-run-stop-note').textContent = 'the runner was asked to stop the task';
                        var status = byId('ans-run-status');
                        status.className = label('warning');
                        status.textContent = 'Stopping';
                    } else {
                        stopButton.disabled = false;
                        byId('ans-run-stop-note').textContent = result.body.error || 'the stop failed';
                    }
                });
            });
        }
        scroll();
        if (!config.final) {
            timer = window.setTimeout(poll, 2000);
        }
        return {
            stop: function () {
                window.clearTimeout(timer);
            }
        };
    }

    /* ---------------------------------------------------------------- new run page */

    /**
     * The new run page: the targets list with filters and refusals, the preview and the launch.
     */
    function newRun(config) {
        var devices = [];
        var selected = {};
        var previewed = null;
        var launching = false;
        var filters = {
            group: byId('ans-filter-group'),
            location: byId('ans-filter-location'),
            os: byId('ans-filter-os'),
            text: byId('ans-filter-text'),
            eligible: byId('ans-filter-eligible')
        };
        var status = byId('ans-targets-status');
        var tbody = document.querySelector('#ans-targets tbody');
        var previewButton = byId('ans-preview-button');
        var launchButton = byId('ans-launch-button');
        var launchState = byId('ans-launch-state');
        var previewBox = byId('ans-preview');
        var MAX_ROWS = 500;

        function selectedIds() {
            return Object.keys(selected).map(Number).sort(function (a, b) {
                return a - b;
            });
        }

        function invalidate() {
            previewed = null;
            launchButton.disabled = true;
            if (previewBox.firstChild) {
                previewBox.style.opacity = '0.5';
                launchState.textContent = 'changed since the preview: preview again';
            }
        }

        function matches(device) {
            if (filters.eligible.checked && device.refusal) {
                return false;
            }
            if (filters.group.value && device.groups.indexOf(Number(filters.group.value)) < 0) {
                return false;
            }
            if (filters.location.value && String(device.location_id) !== filters.location.value) {
                return false;
            }
            if (filters.os.value && device.os !== filters.os.value) {
                return false;
            }
            var text = filters.text.value.trim().toLowerCase();
            if (text) {
                var haystack = [device.display, device.hostname, device.os, device.name || '', device.platform || '', device.location].join(' ').toLowerCase();
                return haystack.indexOf(text) >= 0;
            }
            return true;
        }

        function shown() {
            return devices.filter(function (device) {
                return matches(device) || selected[device.id];
            });
        }

        function render() {
            var list = shown();
            clear(tbody);
            list.slice(0, MAX_ROWS).forEach(function (device) {
                var row = el('tr', device.refusal ? 'ans-refused' : (selected[device.id] ? 'ans-selected' : ''));
                row.setAttribute('data-device', device.id);
                var box = el('input');
                box.type = 'checkbox';
                box.className = 'ans-target-check';
                box.checked = !!selected[device.id];
                box.disabled = !!device.refusal && !selected[device.id];
                box.setAttribute('aria-label', 'Target ' + device.display);
                box.addEventListener('change', function () {
                    toggle(device, box.checked);
                });
                var link = el('a', null, device.display);
                link.href = config.deviceUrl + '/' + device.id;
                append(row, [
                    el('td', null, box),
                    el('td', 'ans-wrap', link),
                    el('td', 'ans-code', device.name || '-'),
                    el('td', null, device.os),
                    el('td', 'ans-code', device.platform || el('span', 'text-muted', 'no mapping')),
                    el('td', null, el('span', (device.status === 'up' ? 'text-success' : (device.status === 'down' ? 'text-danger' : 'text-muted')), device.status)),
                    el('td', 'ans-wrap', device.location || ''),
                    el('td', device.refusal ? 'ans-refusal' : 'text-success', device.refusal ? device.refusal.text : 'yes')
                ]);
                tbody.appendChild(row);
            });
            var count = selectedIds().length;
            status.textContent = list.length + ' of ' + devices.length + ' devices shown' + (list.length > MAX_ROWS ? ' (the first ' + MAX_ROWS + ': narrow the filters)' : '')
                + ' · ' + count + ' selected (at most ' + config.maxTargets + ')';
            status.className = count > config.maxTargets ? 'text-danger' : 'ans-muted';
        }

        function toggle(device, on) {
            if (on && !device.refusal) {
                if (selectedIds().length >= config.maxTargets) {
                    window.alert('A run may have at most ' + config.maxTargets + ' targets (Max targets per run setting).');
                } else {
                    selected[device.id] = true;
                }
            } else {
                delete selected[device.id];
            }
            invalidate();
            render();
        }

        function answers() {
            var vars = {};
            Array.prototype.forEach.call(document.querySelectorAll('#ans-vars .ans-var'), function (input) {
                vars[input.name] = input.value;
            });
            return vars;
        }

        function showVariableErrors(errors) {
            Array.prototype.forEach.call(document.querySelectorAll('#ans-vars [data-var]'), function (group) {
                var message = errors ? errors[group.getAttribute('data-var')] : null;
                group.classList.toggle('has-error', !!message);
                group.querySelector('.ans-var-error').textContent = message ? 'This answer ' + message + '.' : '';
            });
        }

        function definition(rows) {
            var list = el('dl', 'dl-horizontal');
            rows.forEach(function (row) {
                append(list, [el('dt', null, row[0]), el('dd', null, row[1])]);
            });
            return list;
        }

        function table(headers, rows, id) {
            var t = el('table', 'table table-condensed ans-table');
            if (id) {
                t.id = id;
            }
            var head = el('tr');
            headers.forEach(function (h) {
                append(head, el('th', null, h));
            });
            append(t, el('thead', null, head));
            var body = el('tbody');
            rows.forEach(function (cells) {
                var tr = el('tr');
                cells.forEach(function (cell) {
                    append(tr, el('td', cell && cell.cls ? cell.cls : 'ans-wrap', cell && cell.text !== undefined ? cell.text : cell));
                });
                append(body, tr);
            });
            append(t, body);
            return t;
        }

        function showPreview(plan, errors) {
            clear(previewBox);
            previewBox.style.opacity = '1';
            showVariableErrors(plan ? plan.variable_errors : null);
            var messages = (errors || []).concat(plan && plan.errors ? plan.errors : []);
            if (plan && plan.variable_errors && Object.keys(plan.variable_errors).length && !errors) {
                messages.push('Fix the answers marked above.');
            }
            if (messages.length) {
                var box = alertBox('danger', 'This cannot be launched:', messages);
                box.id = 'ans-preview-errors';
                previewBox.appendChild(box);
            }
            if (plan && plan.warnings && plan.warnings.length) {
                previewBox.appendChild(alertBox('warning', null, plan.warnings));
            }
            if (!plan || !plan.template) {
                return;
            }
            var panel = el('div', 'panel panel-' + (plan.ok ? 'success' : 'default'));
            panel.id = 'ans-preview-panel';
            append(panel, el('div', 'panel-heading', el('strong', null, plan.ok ? 'Ready to launch' : 'Preview')));
            var body = el('div', 'panel-body');
            append(body, definition([
                ['Template', plan.template.name + ' (' + plan.template.playbook + ', #' + plan.template.id + ')'],
                ['Targets', String(plan.targets.length) + ' (at most ' + plan.max_targets + ')'],
                ['Limit', el('span', 'ans-code', plan.limit || '-')]
            ]));
            if (plan.targets.length) {
                append(body, table(['Device', 'Inventory name', 'Platform'], plan.targets.map(function (t) {
                    return [t.display, {text: t.name, cls: 'ans-code'}, {text: t.platform, cls: 'ans-code'}];
                }), 'ans-preview-targets'));
            }
            if (plan.refused.length) {
                append(body, el('p', 'text-warning', el('strong', null, 'Refused (not part of the run):')));
                append(body, table(['Device', 'Why'], plan.refused.map(function (r) {
                    return [r.display, r.text];
                }), 'ans-preview-refused'));
            }
            if (plan.variables.length) {
                append(body, table(['Survey variable', 'Answer'], plan.variables.map(function (v) {
                    return [v.label + ' (' + v.name + ')', v.value === null ? '(not given)' : {text: String(v.value), cls: v.secret ? 'text-muted' : 'ans-code ans-wrap'}];
                }), 'ans-preview-variables'));
            }
            append(panel, body);
            previewBox.appendChild(panel);
        }

        function preview() {
            launchButton.disabled = true;
            previewButton.disabled = true;
            launchState.textContent = 'checking with the runner…';
            var devicesChosen = selectedIds();
            request('POST', config.preview, {template: config.template, devices: devicesChosen, vars: answers()}).then(function (result) {
                previewButton.disabled = false;
                if (result.status !== 200) {
                    showPreview(null, [result.body.error || 'The preview failed.']);
                    launchState.textContent = '';
                    return;
                }
                showPreview(result.body, null);
                if (result.body.ok) {
                    previewed = result.body.targets.map(function (t) {
                        return t.device_id;
                    });
                    launchButton.disabled = false;
                    launchState.textContent = 'launches exactly this';
                } else {
                    launchState.textContent = '';
                }
            });
        }

        function launch() {
            if (launching || !previewed) {
                return;
            }
            launching = true;
            launchButton.disabled = true;
            previewButton.disabled = true;
            launchState.textContent = 'launching…';
            request('POST', config.store, {template: config.template, devices: previewed, vars: answers(), request_token: config.requestToken}).then(function (result) {
                if ((result.status === 201 || result.status === 200) && result.body.url) {
                    launchState.textContent = 'launched: opening the run…';
                    window.location.assign(result.body.url);
                    return;
                }
                launching = false;
                previewButton.disabled = false;
                showPreview(result.body.plan || null, result.body.errors || [result.body.error || 'The launch failed.']);
                launchState.textContent = 'not launched';
            });
        }

        [filters.group, filters.location, filters.os, filters.eligible].forEach(function (input) {
            input.addEventListener('change', render);
        });
        filters.text.addEventListener('input', render);
        byId('ans-select-shown').addEventListener('click', function () {
            shown().forEach(function (device) {
                if (!device.refusal && selectedIds().length < config.maxTargets) {
                    selected[device.id] = true;
                }
            });
            invalidate();
            render();
        });
        byId('ans-select-none').addEventListener('click', function () {
            selected = {};
            invalidate();
            render();
        });
        Array.prototype.forEach.call(document.querySelectorAll('#ans-vars .ans-var'), function (input) {
            input.addEventListener('input', invalidate);
            input.addEventListener('change', invalidate);
        });
        previewButton.addEventListener('click', preview);
        launchButton.addEventListener('click', launch);

        request('GET', config.targets).then(function (result) {
            if (result.status !== 200) {
                status.textContent = 'The devices cannot be loaded: ' + (result.body.error || 'HTTP ' + result.status);
                status.className = 'text-danger';
                return;
            }
            devices = result.body.devices;
            var oses = {};
            devices.forEach(function (device) {
                oses[device.os] = device.platform;
            });
            Object.keys(oses).sort().forEach(function (os) {
                var option = el('option', null, os + (oses[os] ? ' (' + oses[os] + ')' : ' (no mapping)'));
                option.value = os;
                filters.os.appendChild(option);
            });
            if (config.device) {
                var preset = devices.filter(function (device) {
                    return device.id === config.device;
                })[0];
                if (preset && !preset.refusal) {
                    selected[preset.id] = true;
                } else if (preset) {
                    previewBox.appendChild(alertBox('warning', preset.display + ' cannot be a target: ', [preset.refusal.text]));
                }
            }
            render();
        });
    }

    /* ---------------------------------------------------------------- settings page */

    /**
     * The settings page: Test connection.
     */
    function settings() {
        var button = byId('ans-test-connection');
        if (!button) {
            return;
        }
        var box = byId('ans-test-result');
        button.addEventListener('click', function () {
            button.disabled = true;
            clear(box).appendChild(el('p', 'ans-muted', 'Asking the runner…'));
            request('POST', button.getAttribute('data-url'), {}).then(function (result) {
                button.disabled = false;
                clear(box);
                var data = result.body;
                if (result.status !== 200 || !data.ok) {
                    var failed = alertBox('danger', 'The connection test failed: ', null);
                    failed.id = 'ans-test-error';
                    append(failed, data.error || 'HTTP ' + result.status);
                    box.appendChild(failed);
                    return;
                }
                var ok = alertBox(data.too_powerful ? 'warning' : 'success', null, null);
                ok.id = 'ans-test-ok';
                append(ok, [el('strong', null, 'Connected: '), data.runner + ' ' + data.version + ', project "' + data.project + '", the token\'s role: ' + (data.role || 'unknown') + '.']);
                box.appendChild(ok);
                if (data.too_powerful) {
                    var loud = el('div', 'ans-warning-loud', 'This token can change templates; use a Task Runner token. ');
                    loud.id = 'ans-test-powerful';
                    append(loud, el('span', null, 'Whoever holds an Owner or Manager token can change a template\'s playbook and repository, and so run any code with the stored device credentials.'));
                    box.appendChild(loud);
                }
                if (data.warnings.length) {
                    var warnings = alertBox('warning', 'Check:', data.warnings);
                    warnings.id = 'ans-test-warnings';
                    box.appendChild(warnings);
                }
                if (data.inventory) {
                    box.appendChild(el('p', null, 'LibreNMS inventory: #' + data.inventory.id + ' ' + data.inventory.name + ' (' + data.inventory.type
                        + (data.inventory.source ? ': ' + data.inventory.source : '') + ')'));
                }
                var t = el('table', 'table table-condensed ans-table');
                t.id = 'ans-test-templates';
                append(t, el('thead', null, el('tr', null, [el('th', null, 'Template'), el('th', null, 'Playbook'), el('th', null, 'Usable by the plugin')])));
                var body = el('tbody');
                data.templates.forEach(function (template) {
                    var why = el('td');
                    if (template.usable) {
                        append(why, el('span', 'label label-success', 'yes'));
                    } else {
                        append(why, el('span', 'label label-danger', 'no'));
                        var reasons = el('ul');
                        reasons.style.margin = '4px 0 0';
                        template.reasons.forEach(function (reason) {
                            append(reasons, el('li', null, reason));
                        });
                        append(why, reasons);
                    }
                    template.notes.forEach(function (note) {
                        append(why, el('div', 'ans-help', 'Note: ' + note));
                    });
                    var row = el('tr', null, [el('td', 'ans-wrap', template.name + ' (#' + template.id + ')'), el('td', 'ans-code', template.playbook), why]);
                    row.setAttribute('data-template', template.id);
                    append(body, row);
                });
                append(t, body);
                box.appendChild(t);
            });
        });
    }

    window.AnsiblePlugin = {run: run, newRun: newRun, settings: settings};
})(window, document);
