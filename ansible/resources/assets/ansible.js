/*
 * LibreNMS Ansible plugin: the run page (live status and log, a diagnostic run's output per device), the new change page
 * (targets, first batch, preview, create), the diagnostics page (targets, answers, run), the change page (live state and
 * log, its actions) and the settings page (connection test). Everything
 * that comes from the server is inserted as text (textContent), never as HTML: device output, names and messages can
 * hold anything. The change page fetches itself again when the change moved on: that HTML is LibreNMS' own page.
 */
(function (window, document) {
    'use strict';

    /** Bootstrap label classes the server may name. */
    var LABELS = ['default', 'primary', 'success', 'info', 'warning', 'danger'];
    var RESULTS = {unreachable: 'Unreachable', failed: 'Failed', unknown: 'Unknown', not_run: 'Not run', changed: 'Changed', ok: 'OK', skipped: 'Skipped'};
    /** A dry run's results: what would change. */
    var DRY_RESULTS = {unreachable: 'Unreachable', failed: 'Failed', unknown: 'Unknown', not_run: 'Not run', changed: 'Would change', ok: 'No change', skipped: 'No change (skipped)'};
    var RESULT_CSS = {ok: 'success', changed: 'warning', unknown: 'warning', failed: 'danger', unreachable: 'danger'};
    var ORDER = ['unreachable', 'failed', 'unknown', 'not_run', 'changed', 'ok', 'skipped'];
    var STATUS_LABELS = {not_launched: 'Not launched', incomplete: 'Incomplete', lost: 'Lost', released: 'Released', failed: 'Failed', stopped: 'Stopped'};

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
     * Badges of the targets per result (summary: result => count), in a dry run's words for a dry run; for a dry run its
     * log does not prove to be one, that it may have changed its devices.
     */
    function resultBadges(summary, dryRun, unproven) {
        var span = el('span', 'ans-results');
        if (unproven) {
            span.appendChild(el('span', 'label label-danger ans-not-proven', 'NOT PROVEN: may have changed its devices'));
            return span;
        }
        var words = dryRun ? DRY_RESULTS : RESULTS;
        ORDER.forEach(function (result) {
            if (summary && summary[result]) {
                append(span, [el('span', label(RESULT_CSS[result] || 'default'), summary[result] + ' ' + words[result].toLowerCase()), ' ']);
            }
        });
        return span;
    }

    /**
     * A definition list of [term, description] rows.
     */
    function definition(rows) {
        var list = el('dl', 'dl-horizontal');
        rows.forEach(function (row) {
            append(list, [el('dt', null, row[0]), el('dd', null, row[1])]);
        });
        return list;
    }

    /**
     * A table: cells are text, nodes, or {text, cls}.
     */
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

    /**
     * Append log lines (each a list of [class, text] runs, from the plugin's ANSI parser) to a log, as text.
     */
    function appendLogLines(log, lines) {
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

    /* ---------------------------------------------------------------- output per device */

    /** A result of a run in check mode as the output per device names it, and its label's class. */
    var OUTPUT_RESULTS = {ok: 'ok', changed: 'would change', skipped: 'skipped', failed: 'failed', unreachable: 'unreachable'};
    var OUTPUT_CSS = {ok: 'success', changed: 'warning', skipped: 'default', failed: 'danger', unreachable: 'danger'};
    /** What a block of output is, said over it (an output is said nothing over). */
    var BLOCK_CAPTIONS = {commands: 'the commands it would send', diff: 'what would change', yaml: 'as Ansible printed it', text: 'the module\'s text'};

    /** Did the task print anything for a device? */
    function printed(task) {
        return task.groups.some(function (group) {
            return group.results.some(function (result) {
                return result.blocks.length > 0;
            });
        });
    }

    function outputResult(result) {
        return el('span', label(OUTPUT_CSS[result] || 'default'), OUTPUT_RESULTS[result] || result);
    }

    /** A block of output as text: a diff's lines coloured as the change page colours them. */
    function outputBlock(block) {
        var pre = el('pre', 'ans-diff ans-output-block');
        block.lines.forEach(function (line, index) {
            var cls = block.kind !== 'diff' ? '' : (/^\+(?!\+\+)/.test(line) ? 'ans-diff-add' : (/^-(?!--)/.test(line) ? 'ans-diff-del' : (/^@@/.test(line) ? 'ans-diff-hunk' : '')));
            append(pre, [cls ? el('span', cls, line) : line, index < block.lines.length - 1 ? '\n' : '']);
        });
        var caption = BLOCK_CAPTIONS[block.kind];
        return caption || block.cut ? el('div', null, [el('div', 'ans-help', (caption || 'output') + (block.cut ? ' (cut)' : '')), pre]) : pre;
    }

    /**
     * A diagnostic run's output per device (DiagnosticController::outputs): each task, its devices grouped by identical
     * results, side by side, each group's devices over its output; a task that printed nothing in one line, shown only
     * when asked for (showQuiet) once another task printed something; and the results the log shows that are not
     * attributed to a device, with why. Everything is inserted as text.
     */
    function outputsView(data, deviceUrl, showQuiet) {
        var box = el('div', 'ans-outputs');
        var names = data.devices || {};
        function devices(ids) {
            var list = el('span', 'ans-output-names');
            ids.forEach(function (id, index) {
                var link = el('a', null, names[id] || '#' + id);
                link.href = deviceUrl + '/' + Number(id);
                append(list, [index ? ', ' : '', link]);
            });
            return list;
        }
        if (data.source === 'cut') {
            box.appendChild(alertBox('warning', null, ['The log was cut to the Output size setting, and the runner no longer gives it whole: the output per device '
                + 'cannot be read from a log with a gap. The log below keeps its start and its end.']));
            return box;
        }
        var tasks = data.tasks || [];
        var spoken = tasks.filter(printed).length;
        if (!spoken) {
            box.appendChild(el('p', 'ans-muted', 'No task printed output for a device. To read what the devices answer, register the result of the commands and '
                + 'print it with a debug task (debug: var=result.stdout_lines), outside a loop.'));
        }
        tasks.forEach(function (task) {
            var quiet = !printed(task);
            if (quiet && spoken && !showQuiet) {
                return;
            }
            var count = task.groups.reduce(function (sum, group) {
                return sum + group.devices.length;
            }, 0);
            var section = el('div', 'ans-output-task' + (quiet ? ' ans-output-quiet' : ''));
            section.appendChild(el('div', 'ans-output-title', [el('span', 'ans-code', task.task ? 'TASK [' + task.task + ']' : 'PLAY'), ' ',
                el('span', 'ans-muted', count + (count === 1 ? ' device' : ' devices') + (quiet ? ', no output' : (count > 1 ? ' · ' + (task.groups.length === 1
                    ? 'all the same' : task.groups.length + ' different results') : '')))]));
            if (quiet) {
                section.appendChild(el('div', 'ans-output-line', task.groups.map(function (group) {
                    return el('span', 'ans-output-quiet-group', [outputResult(group.results[0] ? group.results[0].result : 'ok'), ' ', devices(group.devices)]);
                })));
            } else {
                var grid = el('div', 'ans-output-groups');
                task.groups.forEach(function (group) {
                    var card = el('div', 'ans-output-group');
                    card.appendChild(el('div', 'ans-output-devices', [devices(group.devices), group.devices.length > 1
                        ? el('span', 'ans-muted', ' (' + group.devices.length + ' devices, the same)') : null]));
                    group.results.forEach(function (result) {
                        card.appendChild(el('div', 'ans-output-result', [outputResult(result.result), result.item !== null && result.item !== undefined
                            ? el('span', 'ans-muted', ' item ' + result.item) : null]));
                        result.blocks.forEach(function (block) {
                            card.appendChild(outputBlock(block));
                        });
                    });
                    grid.appendChild(card);
                });
                section.appendChild(grid);
            }
            box.appendChild(section);
        });
        if (data.unattributed && data.unattributed.length) {
            var details = el('details', 'ans-output-unattributed');
            details.id = 'ans-outputs-unattributed';
            details.appendChild(el('summary', 'text-warning', data.unattributed.length + (data.unattributed.length === 1 ? ' result' : ' results')
                + ' of the log not attributed to a device, and why'));
            data.unattributed.forEach(function (entry) {
                var card = el('div', 'ans-output-group', [el('div', 'ans-output-devices', [el('span', 'ans-code', entry.host), ' · TASK [' + entry.task + ']',
                    entry.item !== null && entry.item !== undefined ? ' · item ' + entry.item : '', ' ', outputResult(entry.result)]),
                    el('div', 'ans-help', 'Not attributed: ' + entry.reason + '.')]);
                entry.blocks.forEach(function (block) {
                    card.appendChild(outputBlock(block));
                });
                details.appendChild(card);
            });
            box.appendChild(details);
        }
        if (data.cut) {
            box.appendChild(el('p', 'text-warning', 'The page keeps 4 MB of the output: the rest is in the log.'));
        }
        return box;
    }

    /* ---------------------------------------------------------------- run page */

    /**
     * Follow a run: poll the plugin every 2 seconds while it is unfinished, append the new log lines, update the
     * status, the targets and the alerts; the Stop and Release buttons.
     */
    function run(config) {
        var next = config.next;
        var log = byId('ans-log');
        var follow = byId('ans-log-follow');
        var stopButton = byId('ans-run-stop');
        var releaseButton = byId('ans-run-release');
        var timer = null;
        // a diagnostic run's output per device: read once, when the run has ended
        var outputs = null;
        var quietBox = byId('ans-outputs-quiet');

        function showOutputs() {
            if (outputs) {
                clear(byId('ans-outputs')).appendChild(outputsView(outputs, config.deviceUrl, quietBox && quietBox.checked));
            }
        }

        function loadOutputs() {
            if (!config.outputs || outputs) {
                return;
            }
            var state = byId('ans-outputs-state');
            state.textContent = 'loading…';
            request('GET', config.outputs).then(function (result) {
                if (result.status === 200 && result.body.final) {
                    outputs = result.body;
                    state.textContent = outputs.source === 'runner' ? '(from the runner\'s whole log)' : '';
                    showOutputs();
                } else if (result.status === 200) {
                    window.setTimeout(loadOutputs, 2000);
                } else {
                    state.textContent = '(cannot be read: ' + (result.body.error || 'HTTP ' + result.status) + ')';
                }
            });
        }

        if (quietBox) {
            quietBox.addEventListener('change', showOutputs);
        }

        function scroll() {
            if (follow && follow.checked) {
                log.scrollTop = log.scrollHeight;
            }
        }

        function setText(id, text) {
            var node = byId(id);
            if (node) {
                node.textContent = text === null || text === undefined || text === '' ? '-' : text;
            }
        }

        // the status label and its card's colour (as the page renders them)
        function showStatus(css, text) {
            var status = byId('ans-run-status');
            status.className = label(css);
            status.textContent = text;
            var card = byId('ans-run-status-card');
            if (card) {
                card.className = 'ans-card ans-' + ({ success: 'ok', danger: 'critical', warning: 'warning' }[css] || 'info');
            }
        }

        function update(data) {
            showStatus(data.css, data.label);
            setText('ans-run-runner-status', data.runner_status);
            setText('ans-run-duration', data.duration);
            setText('ans-run-started', data.started_at);
            setText('ans-run-finished', data.finished_at);
            clear(byId('ans-run-summary')).appendChild(resultBadges(data.summary, data.dry_run, data.proof === 'unverified'));
            var notProven = byId('ans-run-not-proven');
            if (notProven) {
                notProven.style.display = data.proof === 'unverified' ? '' : 'none';
            }
            var proof = byId('ans-run-proof');
            if (proof) {
                proof.textContent = data.proof === 'verified' ? 'Its log proves that every task ran in check mode: it changed nothing.'
                    : (data.proof === 'unverified' ? '' : (data.final ? 'Its log was not judged (the run did not start, or its log is not whole).' : 'Its log is judged when it ends.'));
            }
            data.targets.forEach(function (target) {
                var row = document.querySelector('#ans-run-targets tr[data-device="' + Number(target.device_id) + '"]');
                if (!row) {
                    // a device the runner ran on although it was not a target
                    row = el('tr', target.unexpected ? 'danger' : null, [
                        el('td', 'ans-wrap', [el('span', null, target.display || target.name), ' ',
                            target.unexpected ? el('span', 'label label-danger', 'not a target') : null]),
                        el('td', 'ans-code', target.name), el('td', 'ans-target-result')]);
                    ['ok', 'changed', 'unreachable', 'failed', 'skipped'].forEach(function (counter) {
                        var count = el('td', 'ans-num ans-count');
                        count.setAttribute('data-counter', counter);
                        row.appendChild(count);
                    });
                    row.setAttribute('data-device', Number(target.device_id));
                    if (target.unexpected) {
                        row.setAttribute('data-unexpected', '1');
                    }
                    document.querySelector('#ans-run-targets tbody').appendChild(row);
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
                append(unexpected, data.unexpected_hosts.join(', ') + '. Check in the runner that the template uses the LibreNMS inventory and lets a launch set the limit (Ansible prompts, Limit), and what was done to these hosts.');
                alerts.appendChild(unexpected);
            }
            if (data.error) {
                var error = alertBox(data.status === 'not_launched' || data.status === 'failed' ? 'danger' : 'warning', null, null);
                error.id = 'ans-run-error';
                append(error, [el('strong', null, (STATUS_LABELS[data.status] || data.label) + ': '), data.error]);
                alerts.appendChild(error);
            }
            if (data.stopped_note) {
                var stoppedNote = alertBox('warning', null, null);
                stoppedNote.id = 'ans-run-stopped-note';
                append(stoppedNote, data.stopped_note);
                if (data.stopped_command) {
                    var command = el('code', 'ans-code', data.stopped_command);
                    command.id = 'ans-run-leftover-command';
                    var commandLine = el('div', null, ['On the runner, ', command, ' lists them (besides the grep itself); end each with ', el('code', 'ans-code', 'kill PID'), '.']);
                    commandLine.style.marginTop = '6px';
                    stoppedNote.appendChild(commandLine);
                }
                if (data.held && config.release) {
                    stoppedNote.appendChild(holdRelease());
                }
                alerts.appendChild(stoppedNote);
            }
            if (data.poll_error && !data.final) {
                var pollError = alertBox('warning', null, null);
                pollError.id = 'ans-run-poll-error';
                append(pollError, [data.poll_error + ' ', el('span', 'ans-muted', 'The run keeps its devices until the runner reports its end.')]);
                alerts.appendChild(pollError);
            }
            var releaseBox = byId('ans-run-release-box');
            if (releaseBox) {
                releaseBox.style.display = data.releasable && !data.final ? '' : 'none';
            }
            if (data.lines.length) {
                appendLogLines(log, data.lines);
                scroll();
            }
            next = data.next;
            setText('ans-log-count', next.toLocaleString('en-US') + ' lines');
            if (data.truncated && !byId('ans-log-truncated')) {
                var note = el('span', 'text-warning', 'The log is longer than the Output size setting: its start and its end are kept here, the whole log is in the runner (task #' + data.runner_task_id + ').');
                note.id = 'ans-log-truncated';
                byId('ans-log-count').parentNode.appendChild(note);
            }
            if (data.final) {
                byId('ans-log-state').textContent = '';
                if (stopButton) {
                    stopButton.parentNode.style.display = 'none';
                }
                loadOutputs();
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

        // "Release the devices" of a stopped run that holds them: the step in progress at the stop may still run
        function releaseHold(button) {
            if (!window.confirm('Release the devices of this stopped run? Do it once the step it was in no longer runs on the runner: '
                + 'a new run on these devices could otherwise meet the changes it still makes. This is written to the eventlog.')) {
                return;
            }
            button.disabled = true;
            request('POST', config.release, {}).then(function (result) {
                if (result.status === 200) {
                    window.location.reload();
                } else {
                    button.disabled = false;
                    window.alert(result.body.error || 'The release failed.');
                }
            });
        }

        function holdRelease() {
            var button = el('button', 'btn btn-sm btn-warning', [el('i', 'fa fa-unlock'), ' Release the devices']);
            button.type = 'button';
            button.id = 'ans-run-release-hold';
            button.addEventListener('click', function () {
                releaseHold(button);
            });
            var box = el('div', null, [button, ' ', el('span', 'ans-muted', 'once that step no longer runs on the runner (written to the eventlog)')]);
            box.style.marginTop = '6px';
            return box;
        }

        var holdButton = byId('ans-run-release-hold');
        if (holdButton && config.release) {
            holdButton.addEventListener('click', function () {
                releaseHold(holdButton);
            });
        }

        if (stopButton) {
            stopButton.addEventListener('click', function () {
                if (!window.confirm('Stop this run? Semaphore UI stops Ansible\'s main process, so no further task starts. The step each device is in runs in a '
                    + 'process of its own that a stop does not end: it runs on with all its loop items, and a pause keeps waiting there until it is killed. '
                    + 'The devices stay held for a while after the stop.')) {
                    return;
                }
                stopButton.disabled = true;
                request('POST', config.stop, {}).then(function (result) {
                    if (result.status === 200) {
                        byId('ans-run-stop-note').textContent = 'the runner was asked to stop the task';
                        showStatus('warning', 'Stopping');
                    } else {
                        stopButton.disabled = false;
                        byId('ans-run-stop-note').textContent = result.body.error || 'the stop failed';
                    }
                });
            });
        }
        if (releaseButton) {
            releaseButton.addEventListener('click', function () {
                if (!window.confirm('Release the devices of this run? The plugin could not ask the runner about it for a while. The run ends as released, '
                    + 'its results unknown, and its devices can be targets again; the task may still run in the runner.')) {
                    return;
                }
                releaseButton.disabled = true;
                request('POST', config.release, {}).then(function (result) {
                    if (result.status === 200) {
                        window.clearTimeout(timer);
                        poll();
                    } else {
                        releaseButton.disabled = false;
                        window.alert(result.body.error || 'The release failed.');
                    }
                });
            });
        }
        scroll();
        if (!config.final) {
            timer = window.setTimeout(poll, 2000);
        } else {
            loadOutputs();
        }
        return {
            stop: function () {
                window.clearTimeout(timer);
            }
        };
    }

    /* ---------------------------------------------------------------- new change page */

    /**
     * The new change page: the targets list with filters and refusals, the first batch (the canary), the answers that
     * are not secret, the reference, the preview with the batch plan, and Create (a draft: its page dry-runs it).
     */
    function newChange(config) {
        var devices = [];
        var selected = {};
        var canary = {};
        var previewed = null;
        var creating = false;
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
        var createButton = byId('ans-create-button');
        var launchState = byId('ans-launch-state');
        var previewBox = byId('ans-preview');
        var reference = byId('ans-reference');
        var MAX_ROWS = 500;
        // the devices the topology plugin cannot plan: left out of the change, or in final batches planned by hand (as the earlier
        // change planned them, for a new change from one)
        var unplanned = config.prefill && config.prefill.unplanned === 'hand' ? 'hand' : 'leave';
        // a change made from fixes: its devices are the fix's, and the lines of its fix variable go with the preview and the creation
        var fix = config.fix || null;
        // fixes the plugin refuses (the page says why): nothing to choose, nothing to preview; back to the Fixes page
        var locked = !!fix || !!config.fixRefused;

        // the chosen devices in the list's order (the batches follow it)
        function selectedIds() {
            return devices.filter(function (device) {
                return selected[device.id];
            }).map(function (device) {
                return device.id;
            });
        }

        function canaryIds() {
            return selectedIds().filter(function (id) {
                return canary[id];
            });
        }

        function invalidate() {
            previewed = null;
            createButton.disabled = true;
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

        function checkbox(className, checked, disabled, title, onChange) {
            var box = el('input');
            box.type = 'checkbox';
            box.className = className;
            box.checked = checked;
            box.disabled = disabled;
            box.setAttribute('aria-label', title);
            box.title = title;
            box.addEventListener('change', function () {
                onChange(box.checked);
            });
            return box;
        }

        function render() {
            var list = shown();
            clear(tbody);
            list.slice(0, MAX_ROWS).forEach(function (device) {
                var row = el('tr', device.refusal ? 'ans-refused' : (selected[device.id] ? 'ans-selected' : ''));
                row.setAttribute('data-device', device.id);
                var link = el('a', null, device.display);
                link.href = config.deviceUrl + '/' + device.id;
                append(row, [
                    el('td', null, checkbox('ans-target-check', !!selected[device.id], locked || (!!device.refusal && !selected[device.id]), 'Target ' + device.display, function (on) {
                        toggle(device, on);
                    })),
                    el('td', null, checkbox('ans-canary-check', !!canary[device.id], locked || !selected[device.id], device.display + ' in the first batch', function (on) {
                        if (on) {
                            canary[device.id] = true;
                        } else {
                            delete canary[device.id];
                        }
                        invalidate();
                        render();
                    })),
                    el('td', 'ans-wrap', link),
                    el('td', device.refusal ? 'ans-refusal' : 'text-success', device.refusal ? device.refusal.text : 'yes'),
                    el('td', 'ans-code', device.name || '-'),
                    el('td', 'hidden-xs', device.os),
                    el('td', 'ans-code', device.platform || el('span', 'text-muted', 'no mapping')),
                    el('td', 'hidden-xs', el('span', (device.status === 'up' ? 'text-success' : (device.status === 'down' ? 'text-danger' : 'text-muted')), device.status)),
                    el('td', 'ans-wrap hidden-xs', device.location || '')
                ]);
                tbody.appendChild(row);
            });
            var count = selectedIds().length;
            var first = canaryIds().length;
            status.textContent = list.length + ' of ' + devices.length + ' devices shown' + (list.length > MAX_ROWS ? ' (the first ' + MAX_ROWS + ': narrow the filters)' : '')
                + ' · ' + count + ' selected (at most ' + config.maxTargets + ')'
                + (count ? ' · first batch: ' + (first ? first + ' chosen' + (config.topology ? ' (those in the planner\'s first batch)' : '')
                    : (config.topology ? 'from the planner\'s first batch' : 'the first of the list, as the settings say')) : '');
            status.className = count > config.maxTargets ? 'text-danger' : 'ans-muted';
        }

        function toggle(device, on) {
            if (on && !device.refusal) {
                if (selectedIds().length >= config.maxTargets) {
                    window.alert('A change may have at most ' + config.maxTargets + ' targets (Max targets per change setting).');
                } else {
                    selected[device.id] = true;
                }
            } else {
                delete selected[device.id];
                delete canary[device.id];
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
                var text = group.querySelector('.ans-var-error');
                if (text) {
                    text.textContent = message ? 'This answer ' + message + '.' : '';
                }
            });
        }

        function showPreview(plan, errors) {
            clear(previewBox);
            previewBox.style.opacity = '1';
            showVariableErrors(plan ? plan.variable_errors : null);
            // a refused creation gives the preview's errors twice (in its own and in its plan's): each is said once
            var messages = [];
            (errors || []).concat(plan && plan.errors ? plan.errors : []).forEach(function (message) {
                if (messages.indexOf(message) < 0) {
                    messages.push(message);
                }
            });
            if (plan && plan.variable_errors && Object.keys(plan.variable_errors).length && !errors) {
                messages.push('Fix the answers marked above.');
            }
            if (messages.length) {
                var box = alertBox('danger', 'This change cannot be created:', messages);
                box.id = 'ans-preview-errors';
                previewBox.appendChild(box);
            }
            if (plan && plan.warnings && plan.warnings.length) {
                previewBox.appendChild(alertBox('warning', null, plan.warnings));
            }
            if (!plan || !plan.template) {
                return;
            }
            var batchOf = {};
            (plan.batches || []).forEach(function (batch) {
                batch.devices.forEach(function (device) {
                    batchOf[device.device_id] = batch.number;
                });
            });
            var ready = plan.ok && plan.batches && plan.batches.length;
            var panel = el('div', 'panel panel-' + (ready ? 'success' : 'default'));
            panel.id = 'ans-preview-panel';
            append(panel, el('div', 'panel-heading', el('strong', null, ready ? 'Ready to create' : 'Preview')));
            var body = el('div', 'panel-body');
            append(body, definition([
                ['Template', plan.template.name + ' (' + plan.template.playbook + ', #' + plan.template.id + ')'],
                ['Targets', String(plan.targets.length) + ' (at most ' + plan.max_targets + ')'],
                ['Limit', el('span', 'ans-code', plan.limit || '-')],
                ['Batches', plan.plan_text || '-'],
                ['Reference', plan.reference ? el('span', 'ans-code', plan.reference) : el('span', 'text-muted', '(none)')]
            ]));
            append(body, plannerPanel(plan));
            if (plan.targets.length) {
                append(body, table(['Batch', 'Device', 'Inventory name', 'Platform'], plan.targets.map(function (t) {
                    return [{text: String(batchOf[t.device_id] || '-'), cls: 'ans-batch'}, t.display, {text: t.name, cls: 'ans-code'}, {text: t.platform, cls: 'ans-code'}];
                }).sort(function (a, b) {
                    return Number(a[0].text) - Number(b[0].text);
                }), 'ans-preview-targets'));
            }
            if (plan.refused.length) {
                append(body, el('p', 'text-warning', el('strong', null, 'Refused (not part of the change):')));
                append(body, table(['Device', 'Why'], plan.refused.map(function (r) {
                    return [r.display, r.text];
                }), 'ans-preview-refused'));
            }
            if (plan.variables.length) {
                append(body, table(['Survey variable', 'Answer'], plan.variables.map(function (v) {
                    // exactly as given: several lines stay several lines, a refused answer shows with its error
                    var answer = v.secret ? el('span', 'text-muted', 'secret: asked at the dry run and at the apply, never kept')
                        : (v.value === null ? el('span', 'text-muted', '(not given)') : el('span', 'ans-code ans-pre', String(v.value)));
                    return [v.label + ' (' + v.name + ')', {text: [answer, v.error && !v.secret ? el('div', 'text-danger ans-preview-error', 'This answer ' + v.error + '.') : null], cls: 'ans-wrap'}];
                }), 'ans-preview-variables'));
            }
            if (plan.secret_note) {
                append(body, el('p', 'ans-help', [el('i', 'fa fa-key'), ' ', plan.secret_note]));
            }
            append(panel, body);
            previewBox.appendChild(panel);
        }

        // what the topology plugin made of the batches: each one's place and what goes down while it runs, the devices it cannot
        // plan (with the choice to leave them out or plan them by hand), or why it did not plan them (the blast radius is unknown)
        function plannerPanel(plan) {
            var planner = plan.planner;
            var batches = plan.batches || [];
            var leftOut = (planner && planner.left_out) || [];
            // without batches only the devices the planner cannot plan are shown, with the choice that makes a plan of them
            if (!planner || (!batches.length && !(planner.used && leftOut.length))) {
                return null;
            }
            var box = el('div', 'panel panel-default');
            box.id = 'ans-preview-planner';
            box.style.margin = '8px 0';
            if (!planner.used) {
                var unknown = alertBox('info', null, null);
                unknown.id = 'ans-preview-blast-unknown';
                unknown.style.margin = '8px 0';
                append(unknown, [el('i', 'fa fa-question-circle'), ' Blast radius unknown: the batches are planned by the plugin\'s settings, as '
                    + (planner.why || 'the topology plugin does not plan them') + '. Nothing tells what each batch cuts off while it runs.']);
                return unknown;
            }
            // the core the plan is measured from: a guess of the topology plugin, or a core at the start of the plan, is said in the notes below
            var coreLine = planner.core_text ? el('div', 'ans-help', 'Measured from the core: ' + planner.core_text + '.') : null;
            if (coreLine) {
                coreLine.id = 'ans-preview-core';
            }
            append(box, el('div', 'panel-heading', [el('strong', null, 'Rollout plan and blast radius'), ' ',
                el('span', 'text-muted', '(the topology plugin: farthest from the core first, the core last, never two devices that back each other up in one batch; '
                    + 'what each batch cuts off is approved with the change and checked again before it starts)'), coreLine]));
            var rows = el('tbody');
            batches.forEach(function (batch) {
                var blast = batch.blast || {};
                var down = el('td', 'ans-wrap');
                if (blast.hand) {
                    append(down, [el('span', 'text-warning', 'unknown: nothing tells what depends on ' + (batch.devices.length === 1 ? 'it' : 'them')),
                        blast.skipped && blast.skipped.length ? el('div', 'ans-help', blast.skipped.map(function (s) {
                            return s.name + ': ' + s.text;
                        }).join('; ')) : null]);
                } else if (blast.lost && blast.lost.length) {
                    blast.lost.forEach(function (lost, index) {
                        var link = el('a', null, lost.name);
                        link.href = config.deviceUrl + '/' + lost.device_id;
                        append(down, [index ? ', ' : null, link]);
                    });
                } else {
                    append(down, el('span', 'text-muted', 'no other monitored device'));
                }
                if (!blast.hand && blast.unmanaged) {
                    append(down, el('div', 'ans-help', 'and ' + blast.unmanaged + ' unmanaged neighbor' + (blast.unmanaged === 1 ? '' : 's') + ' (phones, access points...)'));
                }
                if (!blast.hand && blast.reference && blast.reference !== 'core') {
                    append(down, el('div', 'ans-help', 'no core device of its part is left: counted from the largest piece that stays together'));
                }
                down.setAttribute('data-label', 'Down while it runs');
                var simulate = el('td');
                if (blast.simulate && !blast.hand) {
                    var sim = el('a', 'btn btn-xs btn-default', [el('i', 'fa fa-bolt'), ' Simulate']);
                    sim.href = blast.simulate;
                    sim.target = '_blank';
                    sim.rel = 'noopener';
                    sim.title = 'The topology plugin\'s failure simulation of this batch on the L2 map';
                    append(simulate, sim);
                }
                // labelled cells: stacked at phone width, a device the batch cuts off must not read like one of its devices
                var labelled = function (cell, label) {
                    cell.setAttribute('data-label', label);
                    return cell;
                };
                var row = el('tr', null, [labelled(el('td', null, String(batch.number) + (batch.number === 1 && batches.length > 1 ? ' (canary)' : '')), 'Batch'),
                    labelled(el('td', 'ans-wrap', batch.devices.map(function (d) {
                        return d.display;
                    }).join(', ')), 'Devices'), labelled(el('td', null, blast.tier_text || ''), 'Where'), down, simulate]);
                row.setAttribute('data-batch', batch.number);
                append(rows, row);
            });
            var table = el('table', 'table table-condensed ans-table ans-stack');
            table.id = 'ans-preview-blast';
            append(table, [el('thead', null, el('tr', null, [el('th', null, 'Batch'), el('th', null, 'Devices'), el('th', null, 'Where'),
                el('th', null, 'Down while it runs'), el('th', null, '')])), rows]);
            if (batches.length) {
                append(box, el('div', 'table-responsive', table));
            }
            var hand = batches.filter(function (batch) {
                return batch.blast && batch.blast.hand;
            }).length;
            if (leftOut.length || hand) {
                var choice = el('div', 'panel-body');
                choice.id = 'ans-preview-unplanned';
                if (leftOut.length) {
                    append(choice, el('p', 'text-warning', [el('strong', null, 'The topology plugin cannot plan ' + (batches.length ? leftOut.length + ' of the devices'
                        : (leftOut.length === 1 ? 'the device' : 'any of the ' + leftOut.length + ' devices'))),
                        ' (nothing tells what depends on them): ' + leftOut.map(function (entry) {
                            return entry.name + ' (' + entry.text + ')';
                        }).join(', ') + '.']));
                } else if (hand === batches.length) {
                    append(choice, el('p', 'text-warning', (hand === 1 ? 'Its batch is planned by hand: the topology plugin cannot plan its devices'
                        : 'All of its batches are planned by hand: the topology plugin can plan none of their devices') + ', nothing tells what depends on them.'));
                } else {
                    append(choice, el('p', 'text-warning', (hand === 1 ? 'The last batch is planned by hand: the topology plugin cannot plan its devices'
                        : 'The last ' + hand + ' batches are planned by hand: the topology plugin cannot plan their devices') + ', nothing tells what depends on them.'));
                }
                [['leave', 'Leave them out of the change'], ['hand', 'Put them in final batches planned by hand']].forEach(function (option) {
                    var radio = el('input');
                    radio.type = 'radio';
                    radio.name = 'ans-unplanned';
                    radio.value = option[0];
                    radio.checked = unplanned === option[0];
                    radio.addEventListener('change', function () {
                        unplanned = option[0];
                        preview();
                    });
                    var label = el('label', null, [radio, ' ', option[1]]);
                    label.style.marginRight = '16px';
                    label.style.fontWeight = 'normal';
                    append(choice, label);
                });
                append(box, choice);
            }
            (planner.notes || []).forEach(function (note) {
                append(box, el('div', 'panel-body ans-help text-warning', note));
            });
            return box;
        }

        function preview() {
            createButton.disabled = true;
            previewButton.disabled = true;
            launchState.textContent = 'checking with the runner…';
            var request_ = {template: config.template, devices: selectedIds(), vars: answers(), canary: canaryIds(), reference: reference.value, unplanned: unplanned};
            if (fix) {
                request_.fix = {kind: fix.kind, ids: fix.ids, scope: fix.scope, lines: fix.lines};
            }
            request('POST', config.preview, request_).then(function (result) {
                previewButton.disabled = false;
                if (result.status !== 200) {
                    showPreview(null, [result.body.error || 'The preview failed.']);
                    launchState.textContent = '';
                    return;
                }
                showPreview(result.body, null);
                if (result.body.ok && result.body.batches && result.body.batches.length) {
                    previewed = {devices: result.body.targets.map(function (t) {
                        return t.device_id;
                    }), canary: result.body.batches[0].devices.map(function (d) {
                        return d.device_id;
                    }), vars: request_.vars, reference: request_.reference, unplanned: unplanned, blast: result.body.blast_signature || '', fix: request_.fix || null};
                    // the devices in the order of the batches, and the batches: the change gets exactly these or none; the devices the
                    // topology plugin leaves out go along, so that its plan is the same, and what each batch cuts off too
                    previewed.devices = [];
                    previewed.batches = result.body.batches.map(function (batch) {
                        return batch.devices.map(function (d) {
                            previewed.devices.push(d.device_id);
                            return d.device_id;
                        });
                    });
                    ((result.body.planner && result.body.planner.left_out) || []).forEach(function (entry) {
                        previewed.devices.push(entry.device_id);
                    });
                    createButton.disabled = false;
                    launchState.textContent = 'creates exactly this (nothing runs yet)';
                } else {
                    launchState.textContent = '';
                }
            });
        }

        function create() {
            if (creating || !previewed) {
                return;
            }
            creating = true;
            createButton.disabled = true;
            previewButton.disabled = true;
            launchState.textContent = 'creating…';
            var stored = {template: config.template, devices: previewed.devices, vars: previewed.vars, canary: previewed.canary,
                batches: previewed.batches, reference: previewed.reference, unplanned: previewed.unplanned, blast_signature: previewed.blast,
                request_token: config.requestToken};
            if (previewed.fix) {
                stored.fix = previewed.fix;
            }
            request('POST', config.store, stored).then(function (result) {
                if ((result.status === 201 || result.status === 200) && result.body.url) {
                    launchState.textContent = 'created: opening the change…';
                    window.location.assign(result.body.url);
                    return;
                }
                creating = false;
                previewButton.disabled = false;
                showPreview(result.body.plan || null, result.body.errors || [result.body.error || 'The change was not created.']);
                launchState.textContent = 'not created';
            });
        }

        [filters.group, filters.location, filters.os, filters.eligible].forEach(function (input) {
            input.addEventListener('change', render);
        });
        filters.text.addEventListener('input', render);
        if (locked) {
            byId('ans-select-shown').disabled = true;
            byId('ans-select-none').disabled = true;
        }
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
            canary = {};
            invalidate();
            render();
        });
        Array.prototype.forEach.call(document.querySelectorAll('#ans-vars .ans-var'), function (input) {
            input.addEventListener('input', invalidate);
            input.addEventListener('change', invalidate);
        });
        reference.addEventListener('input', invalidate);
        previewButton.addEventListener('click', preview);
        createButton.addEventListener('click', create);

        // devices to choose from the start: ?device=ID (the device page), the devices of a new change made from an earlier one
        // (config.prefill, in that change's order, with its first batch), and #devices=1,2,3
        var wanted = [];
        if (config.device) {
            wanted.push(Number(config.device));
        }
        if (config.prefill) {
            config.prefill.devices.forEach(function (id) {
                wanted.push(Number(id));
            });
        }
        if (fix) {
            fix.devices.forEach(function (id) {
                wanted.push(Number(id));
            });
        }
        var hash = fix ? null : /(?:^#|&)devices=([0-9,]{1,20000})/.exec(window.location.hash || '');
        if (hash) {
            hash[1].split(',').forEach(function (id) {
                if (/^[0-9]+$/.test(id)) {
                    wanted.push(Number(id));
                }
            });
        }

        request('GET', config.targets).then(function (result) {
            if (result.status !== 200) {
                status.textContent = 'The devices cannot be loaded: ' + (result.body.error || 'HTTP ' + result.status);
                status.className = 'text-danger';
                return;
            }
            devices = result.body.devices;
            if (fix) {
                // the fix's devices first, in its order (a root priority fix: the intended roots, then their secondaries)
                var fixRank = {};
                fix.devices.forEach(function (id, index) {
                    fixRank[Number(id)] = index;
                });
                devices = devices.filter(function (device) {
                    return fixRank[device.id] !== undefined;
                }).sort(function (a, b) {
                    return fixRank[a.id] - fixRank[b.id];
                }).concat(devices.filter(function (device) {
                    return fixRank[device.id] === undefined;
                }));
            }
            if (config.prefill) {
                // the chosen devices first, in the earlier change's order (the batches follow the list)
                var rank = {};
                config.prefill.devices.forEach(function (id, index) {
                    rank[Number(id)] = index;
                });
                devices = devices.filter(function (device) {
                    return rank[device.id] !== undefined;
                }).sort(function (a, b) {
                    return rank[a.id] - rank[b.id];
                }).concat(devices.filter(function (device) {
                    return rank[device.id] === undefined;
                }));
            }
            var oses = {};
            devices.forEach(function (device) {
                oses[device.os] = device.platform;
            });
            Object.keys(oses).sort().forEach(function (os) {
                var option = el('option', null, os + (oses[os] ? ' (' + oses[os] + ')' : ' (no mapping)'));
                option.value = os;
                filters.os.appendChild(option);
            });
            var refused = [];
            wanted.forEach(function (id) {
                var preset = devices.filter(function (device) {
                    return device.id === id;
                })[0];
                if (preset && !preset.refusal && selectedIds().length < config.maxTargets) {
                    selected[preset.id] = true;
                } else if (preset && preset.refusal) {
                    refused.push(preset.display + ': ' + preset.refusal.text);
                }
            });
            if (config.prefill) {
                config.prefill.canary.forEach(function (id) {
                    if (selected[Number(id)]) {
                        canary[Number(id)] = true;
                    }
                });
            }
            if (refused.length) {
                var box = alertBox('warning', 'Not chosen, they cannot be targets now:', refused);
                box.id = 'ans-preset-refused';
                previewBox.appendChild(box);
            }
            render();
        });
    }

    /* ---------------------------------------------------------------- change page */

    /**
     * Follow a change: poll the plugin every 2 seconds while it is not over (every 5 while only its windows and
     * backups are followed), append the log of the run it follows, and fetch the page again when its state moved on
     * (the text typed in its fields stays). The actions post to the plugin, which checks everything again.
     *
     * Secret answers: given in the page's fields for a dry run, an apply or a continue, sent with that request and
     * kept in this page's memory only (never stored, not in the browser either). A batch that needs them while the
     * page is open is launched with them (the change paused for them); once the page is closed the change waits for an
     * admin to continue with them.
     */
    function change(config) {
        var state = {run: config.run, next: config.next, signature: config.signature, final: config.final};
        var heldSecrets = null;
        var busy = false;
        var timer = null;
        var reloading = null;
        // a refusal stays shown while the page puts its new state in place (the poll replaces the action bar with the rest of the
        // page: a refused Continue often changes what the page shows), for some polls or until the next action
        var refusal = null;
        var REFUSAL_POLLS = 15;
        // the page's config as it is now (the page's refresh brings a new one)
        function current() {
            var node = byId('ans-change-config');
            return node ? JSON.parse(node.textContent) : config;
        }

        function question(action) {
            var now = current();
            var batches = now.batches + (now.batches === 1 ? ' batch' : ' batches');
            var devices = now.targets === 1 ? 'its device' : 'its ' + now.targets + ' devices';
            return {
                'dry-run': 'Run the dry run of this change on ' + devices + '? Ansible runs the playbook in check mode (--check --diff): '
                    + 'it reports what would change and changes nothing, as far as the playbook\'s modules support check mode.'
                    + (now.dryRuns ? ' It replaces the dry run before, and an approval of it no longer counts.' : ''),
                approve: 'Approve this change as it was dry-run? The approval covers exactly this dry run, the template as it is and the batch plan: '
                    + 'a change of any of them voids it.',
                reject: 'Reject this change? It ends it: nothing is applied.',
                apply: 'Apply this change to ' + devices + ' in ' + batches + '? Batch 1 starts now' + (now.prepare ? ', after its ' + now.prepare : '') + '.',
                'continue': now.waiting === 'secrets' ? 'Launch batch ' + now.currentBatch + ' with the secret answers given here?'
                    : (now.waiting === 'blast_unknown' ? 'Continue batch ' + now.currentBatch + '? Its blast radius is computed first: if it can be, the batch '
                        + 'starts as any, checked against it. If it still cannot be computed, the batch goes without one: nothing checks what it cuts off, its '
                        + 'maintenance window covers its devices and the devices it was held to cut off, and your decision is recorded in the timeline and in '
                        + 'the eventlog.' + (now.continueAs ? ' You do not hold the run permission: the batch starts as ' + now.continueAs + ', who started it'
                            + (now.hasSecrets ? ', and waits for an admin with the run permission to give the secret answers' : '') + '.' : '')
                        : 'Continue with batch ' + (now.currentBatch + 1) + '? Check the devices of the batches so far first.'),
                accept: 'Accept the new blast radius of batch ' + now.currentBatch + ', as shown above? The batch then starts again, its blast radius checked '
                    + 'once more, and its maintenance window covers what it cuts off. Your acceptance is recorded in the timeline and in the eventlog.',
                'accept-verification': now.waiting === 'verification'
                    ? 'Accept the unexpected changes of batch ' + now.currentBatch + ', as shown above? The change then goes on to batch ' + (now.currentBatch + 1)
                        + '; its outcome becomes "verified with accepted changes". Your acceptance is recorded in the timeline and in the eventlog.'
                    : 'Go on without the verification of batch ' + now.currentBatch + '? Nothing then tells whether it changed more than intended; the change goes on to batch '
                        + (now.currentBatch + 1) + ' and its outcome is "not verified". Your decision is recorded in the timeline and in the eventlog.',
                stop: 'Stop this change? A running batch is stopped as a run\'s stop (the step in progress on each device runs on), no further batch starts, '
                    + 'and the change ends: it cannot be resumed.',
                cancel: 'Cancel this change? It ends it: nothing is applied.'
            }[action];
        }

        function log() {
            return byId('ans-log');
        }

        function scroll() {
            var follow = byId('ans-log-follow');
            if (log() && (!follow || follow.checked)) {
                log().scrollTop = log().scrollHeight;
            }
        }

        // what the last action did, next to the buttons; a refusal (keep) is shown again after each refresh of the page, for
        // REFUSAL_POLLS polls or until the next action
        function actionState(text, css, keep) {
            refusal = keep ? {text: text, css: css, polls: REFUSAL_POLLS} : null;
            paintActionState(text, css);
        }

        function paintActionState(text, css) {
            var node = byId('ans-action-state');
            if (node) {
                node.className = css || 'ans-muted';
                node.textContent = text;
            }
        }

        function readSecrets() {
            var secrets = {};
            Array.prototype.forEach.call(document.querySelectorAll('#ans-secret-fields .ans-secret'), function (input) {
                if (input.value !== '') {
                    secrets[input.name] = input.value;
                }
            });
            return secrets;
        }

        // the note that this page holds the secret answers (in its memory) for the next batches
        function showHeld() {
            var note = byId('ans-secrets-held');
            var alerts = byId('ans-change-alerts');
            if (heldSecrets && Object.keys(heldSecrets).length && !state.final && alerts) {
                if (!note) {
                    note = alertBox('info', null, null);
                    note.id = 'ans-secrets-held';
                    append(note, [el('i', 'fa fa-key'), ' This page holds the secret answers you gave, in its memory only, and launches each next batch with them while it stays open. '
                        + 'If you close it, the change waits for them: Continue asks for them again.']);
                    alerts.appendChild(note);
                }
            } else if (note) {
                note.parentNode.removeChild(note);
            }
            // while this page holds the answers, a batch that waits for them is launched from here within seconds: its wait is not
            // shown (the deadline and the end of the change that follows it), what the page does is
            Array.prototype.forEach.call(document.querySelectorAll('#ans-change [data-held-text], #ans-change [data-held-class]'), function (node) {
                var held = heldSecrets && !state.final;
                if (node.hasAttribute('data-held-text')) {
                    if (!node.hasAttribute('data-wait-text')) {
                        node.setAttribute('data-wait-text', node.textContent);
                    }
                    node.textContent = held ? node.getAttribute('data-held-text') : node.getAttribute('data-wait-text');
                }
                if (node.hasAttribute('data-held-class')) {
                    if (!node.hasAttribute('data-wait-class')) {
                        node.setAttribute('data-wait-class', node.className);
                    }
                    node.className = held ? node.getAttribute('data-held-class') : node.getAttribute('data-wait-class');
                }
            });
            // an answer the page holds needs no typing again: its field says so (a Continue with it empty uses the held one)
            Array.prototype.forEach.call(document.querySelectorAll('#ans-secret-fields .ans-secret-mark'), function (mark) {
                var held = heldSecrets && !state.final && Object.prototype.hasOwnProperty.call(heldSecrets, mark.getAttribute('data-name'));
                mark.textContent = held ? '(this page holds it: leave it empty to use it)' : (mark.getAttribute('data-required') === '1' ? '(required)' : '');
            });
        }

        function updateLog(data) {
            var node = log();
            if (!node) {
                return;
            }
            if (data.run_id !== state.run) {
                clear(node);
                state.run = data.run_id;
            }
            if (data.lines.length) {
                appendLogLines(node, data.lines);
                scroll();
            }
            state.next = data.next;
            var count = byId('ans-log-count');
            if (count) {
                count.textContent = state.next.toLocaleString('en-US') + ' lines';
            }
            var title = byId('ans-change-log-title');
            if (title) {
                var link = el('a', null, 'run #' + data.run_id);
                link.href = data.url;
                clear(title);
                append(title, [data.kind === 'dry_run' ? 'dry run' : 'batch ' + data.batch, ', ', link, ' (', data.label, ')']);
            }
        }

        // fetch the page again and put its new state in place; what was typed in its fields stays
        function refreshPage() {
            if (reloading) {
                return reloading;
            }
            reloading = window.fetch(window.location.href, {credentials: 'same-origin', headers: {'Accept': 'text/html'}}).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.text();
            }).then(function (html) {
                var fresh = new window.DOMParser().parseFromString(html, 'text/html').getElementById('ans-change');
                var current = byId('ans-change');
                if (!fresh || !current) {
                    throw new Error('not a change page');
                }
                var kept = {};
                Array.prototype.forEach.call(current.querySelectorAll('input[type="password"], input[type="text"]'), function (input) {
                    if (input.value !== '') {
                        kept[(input.id ? '#' : '@') + (input.id || input.name)] = input.value;
                    }
                });
                var follow = byId('ans-log-follow');
                var following = follow ? follow.checked : true;
                var node = document.importNode(fresh, true);
                current.parentNode.replaceChild(node, current);
                Array.prototype.forEach.call(node.querySelectorAll('input[type="password"], input[type="text"]'), function (input) {
                    var key = (input.id ? '#' : '@') + (input.id || input.name);
                    if (Object.prototype.hasOwnProperty.call(kept, key)) {
                        input.value = kept[key];
                    }
                });
                if (byId('ans-log-follow')) {
                    byId('ans-log-follow').checked = following;
                }
                var fresher = JSON.parse(byId('ans-change-config').textContent);
                state.run = fresher.run;
                state.next = fresher.next;
                state.signature = fresher.signature;
                state.final = fresher.final;
                if (fresher.over) {
                    heldSecrets = null;
                }
                if (refusal) {
                    paintActionState(refusal.text, refusal.css);
                }
                showHeld();
                scroll();
            }).catch(function () {
                window.location.reload();
            }).then(function () {
                reloading = null;
            });
            return reloading;
        }

        function schedule(delay) {
            window.clearTimeout(timer);
            timer = window.setTimeout(poll, delay);
        }

        // a batch waits for the secret answers this page holds: launch it with them
        function autoContinue(data) {
            if (busy || !heldSecrets || data.status !== 'paused' || data.waiting !== 'secrets' || !data.actions['continue']) {
                return;
            }
            busy = true;
            actionState('launching batch ' + data.current_batch + ' with the secret answers given on this page…');
            request('POST', config.actions['continue'], {secrets: heldSecrets}).then(function (result) {
                busy = false;
                if (result.status !== 200) {
                    heldSecrets = null;
                    actionState(result.body.error || 'The launch failed.', 'text-danger', true);
                }
                refreshPage();
            });
        }

        function poll() {
            var url = config.poll + '?run=' + encodeURIComponent(state.run === null ? '' : state.run) + '&after=' + encodeURIComponent(state.next || 0);
            request('GET', url).then(function (result) {
                var logState = byId('ans-log-state');
                if (result.status !== 200) {
                    if (logState) {
                        logState.textContent = '(cannot refresh: ' + (result.body.error || 'HTTP ' + result.status) + '; trying again)';
                    }
                    schedule(5000);
                    return;
                }
                var data = result.body;
                if (data.log) {
                    updateLog(data.log);
                }
                var settle = data.signature !== state.signature ? refreshPage() : Promise.resolve();
                settle.then(function () {
                    if (refusal && --refusal.polls <= 0) {
                        refusal = null;
                    }
                    autoContinue(data);
                    var over = data.final && !data.pending;
                    var stateNode = byId('ans-log-state');
                    if (stateNode) {
                        stateNode.textContent = over ? '' : (data.final ? '(its maintenance windows and backups are still followed)' : '(live: refreshed every 2 seconds)');
                    }
                    if (!over) {
                        schedule(data.final ? 5000 : 2000);
                    }
                });
            });
        }

        function act(action, button) {
            if (busy) {
                return;
            }
            if (!window.confirm(question(action))) {
                return;
            }
            var secrets = readSecrets();
            // a Continue uses the secret answers this page holds (given at the apply or a continue before) for the fields left empty
            if (action === 'continue' && heldSecrets) {
                secrets = Object.assign({}, heldSecrets, secrets);
            }
            var commentField = byId('ans-approval-comment');
            // an acceptance or a Continue without a blast radius is for the blast radius this page shows: refused when it changed since
            // an acceptance of a batch's verification likewise names the verification this page shows
            var body = {secrets: secrets, comment: commentField ? commentField.value : '', blast: current().blast || '', verify: current().verify || ''};
            busy = true;
            button.disabled = true;
            actionState('sending…');
            request('POST', config.actions[action], body).then(function (result) {
                busy = false;
                if (result.status === 200) {
                    // the page launches the next batches with the answers given here (none, when the template's secret answers are optional
                    // and were left empty), while it stays open
                    if ((action === 'apply' || action === 'continue') && current().hasSecrets) {
                        heldSecrets = secrets;
                    }
                    Array.prototype.forEach.call(document.querySelectorAll('#ans-secret-fields .ans-secret'), function (input) {
                        input.value = '';
                    });
                    if (commentField) {
                        commentField.value = '';
                    }
                    actionState('done');
                    refreshPage().then(function () {
                        if (!state.final) {
                            schedule(1000);
                        }
                    });
                } else {
                    button.disabled = false;
                    actionState(result.body.error || 'The request failed.', 'text-danger', true);
                }
            });
        }

        document.addEventListener('click', function (event) {
            var button = event.target.closest ? event.target.closest('[data-action]') : null;
            var root = byId('ans-change');
            if (button && root && root.contains(button) && config.actions[button.getAttribute('data-action')]) {
                event.preventDefault();
                act(button.getAttribute('data-action'), button);
            }
        });
        scroll();
        if (!state.final) {
            schedule(2000);
        }
        return {
            stop: function () {
                window.clearTimeout(timer);
            }
        };
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
                var ok = alertBox(data.blocked ? 'warning' : 'success', null, null);
                ok.id = 'ans-test-ok';
                append(ok, [el('strong', null, 'Connected: '), data.runner + ' ' + data.version + ', project "' + data.project + '", the token\'s role: '
                    + (data.role || 'none') + (data.admin ? ' (a Semaphore UI admin)' : '') + '.']);
                box.appendChild(ok);
                if (data.blocked) {
                    var loud = el('div', 'ans-warning-loud', data.blocked);
                    loud.id = 'ans-test-blocked';
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
                if (data.inventories && data.inventories.length) {
                    var inventories = el('table', 'table table-condensed ans-table');
                    inventories.id = 'ans-test-inventories';
                    append(inventories, el('thead', null, el('tr', null, [el('th', null, 'Inventory of the project'), el('th', null, 'Id'),
                        el('th', null, 'Type'), el('th', null, 'The LibreNMS one?')])));
                    var rows = el('tbody');
                    data.inventories.forEach(function (inventory) {
                        var verdict = el('td');
                        if (inventory.chosen) {
                            append(verdict, el('span', 'label label-' + (inventory.script ? 'success' : 'danger'), inventory.script ? 'chosen' : 'chosen, but it does not run the script'));
                        } else if (inventory.script) {
                            var use = el('button', 'btn btn-xs btn-default', 'use #' + inventory.id);
                            use.type = 'button';
                            use.addEventListener('click', function () {
                                byId('ans-inventory').value = inventory.id;
                                byId('ans-connection-save').focus();
                            });
                            append(verdict, [el('span', 'ans-muted', 'runs the plugin\'s script '), use]);
                        } else {
                            append(verdict, el('span', 'ans-muted', inventory.file ? 'a file inventory, but not the plugin\'s script' : 'no: not a file inventory'));
                        }
                        var row = el('tr', null, [el('td', 'ans-wrap', [inventory.name, inventory.source ? el('div', 'ans-code ans-muted', inventory.source) : null]),
                            el('td', 'ans-code', '#' + inventory.id), el('td', null, inventory.type), verdict]);
                        row.setAttribute('data-inventory', inventory.id);
                        append(rows, row);
                    });
                    append(inventories, rows);
                    box.appendChild(el('div', 'table-responsive', inventories));
                }
                var t = el('table', 'table table-condensed ans-table');
                t.id = 'ans-test-templates';
                append(t, el('thead', null, el('tr', null, [el('th', null, 'Template'), el('th', null, 'Playbook'), el('th', null, 'Usable for changes')])));
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
                box.appendChild(el('div', 'table-responsive', t));
            });
        });
    }

    /* ---------------------------------------------------------------- fixes */

    /**
     * The Fixes page: the topology plugin's fixes by kind, loaded from the plugin's JSON and shown as text; the fixes of one
     * kind ticked here open a new change made from them (the server computes their lines again).
     */
    function fixes(config) {
        var status = byId('ans-fixes-status');
        var list = byId('ans-fixes-list');
        var LABELS = {port_descriptions: 'Port descriptions', stp_root_priority: 'Spanning tree root priority'};
        var severity = {critical: 'danger', warning: 'warning', info: 'info'};
        // a topology plugin's page links a finding here by its identity: its fix is marked, and chosen when it can be
        var wanted = (/[?&]finding=([0-9a-f]{40})(?:&|$)/.exec(window.location.search) || [])[1] || null;
        var wantedRow = null;
        var hasWanted = function (fix) {
            return wanted !== null && fix.findings.some(function (finding) {
                return finding.identity === wanted;
            });
        };

        function findings(fix) {
            return fix.findings.map(function (finding) {
                return el('div', null, [el('span', 'label label-' + (severity[finding.severity] || 'default'), finding.severity), ' ', finding.title]);
            });
        }

        function changes(fix) {
            if (fix.kind === 'port_descriptions') {
                return fix.ports.map(function (port) {
                    return el('div', port.passes ? null : 'text-warning', [el('span', 'ans-code', port.device + ' ' + port.interface), ': ',
                        port.current ? el('span', 'ans-code', '"' + port.current + '"') : el('span', 'text-muted', '(none)'), ' \u2192 ',
                        el('span', 'ans-code', '"' + port.description + '"'),
                        port.passes ? null : el('div', 'ans-help', 'this interface name cannot be passed to a playbook (letters, digits and / . _ : -)')]);
                });
            }
            var bridge = function (b, role) {
                return b ? el('div', null, [role + ': ', el('strong', null, b.name), ' priority ' + b.priority + ' ',
                    el('span', 'ans-code', b.mode + (b.instances !== '-' ? ' ' + b.instances : ''))]) : null;
            };
            return [el('div', null, fix.vlans + (fix.roots_now ? ' (root now: ' + fix.roots_now + ')' : '')), bridge(fix.primary, 'intended root'),
                bridge(fix.secondary, 'secondary'), el('div', 'ans-help', 'chosen from ' + fix.chosen_from + '; set alone first, then the secondary')];
        }

        // the fixes chosen change at most maxTargets devices (Max targets per change): a change of more is refused
        function section(kind, entries, template, canRun, newChange, maxTargets) {
            var box = el('div', 'panel panel-default');
            box.id = 'ans-fixes-' + kind;
            var templateText = template.problem ? el('span', 'text-warning', [el('i', 'fa fa-exclamation-triangle'), ' Template: ' + template.problem + ' '])
                : el('span', 'text-muted', 'template: ' + template.name + ' (#' + template.id + ')');
            if (template.problem) {
                var settingsLink = el('a', null, 'Settings');
                settingsLink.href = config.settings;
                append(templateText, settingsLink);
            }
            var selected = {};
            var button = el('button', 'btn btn-sm btn-success', [el('i', 'fa fa-wrench'), ' Fix the selected']);
            button.type = 'button';
            button.disabled = true;
            button.id = 'ans-fix-' + kind;
            var chosenText = el('span', 'ans-help');
            chosenText.id = 'ans-fix-devices-' + kind;
            var capText = el('div', 'panel-body ans-help text-warning');
            capText.id = 'ans-fix-cap-' + kind;
            capText.style.display = 'none';
            // the checkboxes shown, by fix
            var boxes = {};
            var refresh = function () {
                var count = Object.keys(selected).length;
                button.disabled = !count || !canRun || !!template.problem;
                button.textContent = '';
                append(button, [el('i', 'fa fa-wrench'), ' Fix the selected (' + count + ')']);
                // the devices the chosen fixes change
                var chosen = {};
                entries.forEach(function (fix) {
                    if (selected[fix.id]) {
                        fix.devices.forEach(function (device) {
                            chosen[device.device_id] = true;
                        });
                    }
                });
                var devices = Object.keys(chosen).length;
                var capped = false;
                // a fix that would take the change past the most targets cannot be chosen, until one is no longer chosen
                Object.keys(boxes).forEach(function (id) {
                    var entry = boxes[id];
                    var more = entry.fix.devices.filter(function (device) {
                        return !chosen[device.device_id];
                    }).length;
                    var over = usable(entry.fix) && devices + more > maxTargets;
                    capped = capped || (!entry.box.checked && over);
                    entry.box.disabled = !entry.box.checked && (!usable(entry.fix) || over);
                    entry.box.title = whyNot(entry.fix) || (entry.box.disabled ? 'A change has at most ' + maxTargets + ' targets (Max targets per change): the fixes chosen change '
                        + devices + ' device' + (devices === 1 ? '' : 's') + '. Choose this fix in another change.' : '');
                });
                // said next to the button, and above the fixes once a fix cannot be chosen for it
                var those = devices + ' device' + (devices === 1 ? '' : 's');
                chosenText.textContent = !count ? '' : 'they change ' + those + (capped ? ', the most a change may have (see above)' : ' (a change has at most ' + maxTargets + ')');
                capText.textContent = capped ? 'The fixes chosen change ' + those + ', the most a change may have (Max targets per change): a fix of another device cannot be '
                    + 'chosen until one is no longer chosen.' : '';
                capText.style.display = capped ? '' : 'none';
            };
            button.addEventListener('click', function () {
                var ids = [];
                var scope = {};
                entries.forEach(function (fix) {
                    if (selected[fix.id]) {
                        ids.push(fix.id);
                        fix.devices.forEach(function (device) {
                            scope[device.device_id] = true;
                        });
                    }
                });
                window.location.assign(newChange + '?fix=' + encodeURIComponent(kind) + '&ids=' + ids.join(',') + '&scope=' + Object.keys(scope).join(','));
            });
            var rows = el('tbody');
            // a port whose name cannot be passed to a playbook, a device that cannot be a target (the target check of New change): the plugin
            // refuses the whole fix (every field is checked)
            var refusedFix = function (fix) {
                return fix.kind === 'port_descriptions' && fix.ports.some(function (port) {
                    return !port.passes;
                });
            };
            var whyNot = function (fix) {
                if (refusedFix(fix)) {
                    return 'The plugin cannot apply this fix: an interface name cannot be passed to a playbook';
                }
                if (fix.refusals && fix.refusals.length) {
                    return 'The plugin cannot apply this fix: ' + fix.refusals.map(function (refusal) {
                        return refusal.device + ' cannot be a target (' + refusal.text + ')';
                    }).join('; ');
                }
                return '';
            };
            var usable = function (fix) {
                return canRun && !template.problem && !whyNot(fix);
            };
            // the fix of the finding a link names is chosen, and shown on its page
            var wantedIndex = -1;
            entries.forEach(function (fix, index) {
                if (hasWanted(fix)) {
                    wantedIndex = index;
                    if (usable(fix)) {
                        selected[fix.id] = true;
                    }
                }
            });
            var rowOf = function (fix) {
                var box_ = el('input');
                box_.type = 'checkbox';
                box_.className = 'ans-fix-check';
                box_.setAttribute('aria-label', 'Fix ' + fix.title);
                box_.checked = !!selected[fix.id];
                boxes[fix.id] = {box: box_, fix: fix};
                box_.addEventListener('change', function () {
                    if (box_.checked) {
                        selected[fix.id] = true;
                    } else {
                        delete selected[fix.id];
                    }
                    refresh();
                });
                var refusals = (fix.refusals || []).map(function (refusal) {
                    return el('div', 'ans-help text-warning ans-fix-refusal', refusal.device + ' cannot be a target: ' + refusal.text);
                });
                var row = el('tr', whyNot(fix) ? 'ans-refused' : null, [el('td', null, box_), el('td', 'ans-wrap', [el('strong', null, fix.title), el('div', 'ans-help', fix.devices.map(function (d) {
                    return d.name;
                }).join(', '))].concat(refusals)), el('td', 'ans-wrap', changes(fix)), el('td', 'ans-wrap', findings(fix))]);
                row.setAttribute('data-fix', fix.id);
                if (hasWanted(fix)) {
                    row.classList.add('info', 'ans-fix-wanted');
                    wantedRow = row;
                }
                return row;
            };
            // a large network has many fixes of a kind: a page of them at a time (the choice is kept from page to page), or all of them
            var PAGE = 25;
            var page = wantedIndex >= 0 ? Math.floor(wantedIndex / PAGE) : 0;
            var all = false;
            var pageButton = function (label, id) {
                var b = el('button', 'btn btn-xs btn-default', label);
                b.type = 'button';
                b.id = id + kind;
                return b;
            };
            var prev = pageButton('\u2039 Previous', 'ans-fixes-prev-');
            var next = pageButton('Next \u203a', 'ans-fixes-next-');
            var allButton = pageButton('Show all ' + entries.length, 'ans-fixes-all-');
            var range = el('span', 'ans-muted');
            range.id = 'ans-fixes-range-' + kind;
            var render = function () {
                var from = all ? 0 : page * PAGE;
                var to = all ? entries.length : Math.min(entries.length, from + PAGE);
                rows.textContent = '';
                boxes = {};
                for (var i = from; i < to; i++) {
                    rows.appendChild(rowOf(entries[i]));
                }
                refresh();
                range.textContent = all ? 'all ' + entries.length : (from + 1) + '\u2013' + to + ' of ' + entries.length;
                prev.disabled = all || page === 0;
                next.disabled = all || to >= entries.length;
                allButton.textContent = all ? 'Show ' + PAGE + ' at a time' : 'Show all ' + entries.length;
            };
            prev.addEventListener('click', function () {
                page = Math.max(0, page - 1);
                render();
            });
            next.addEventListener('click', function () {
                page++;
                render();
            });
            allButton.addEventListener('click', function () {
                all = !all;
                page = 0;
                render();
            });
            render();
            var table = el('table', 'table table-condensed ans-table ans-stack');
            append(table, [el('thead', null, el('tr', null, [el('th', null, ''), el('th', null, 'Fix'), el('th', null, 'What it changes'), el('th', null, 'Findings it resolves')])), rows]);
            refresh();
            var pager = null;
            if (entries.length > PAGE) {
                pager = el('span', 'ans-fixes-pager', [prev, ' ', range, ' ', next, ' ', allButton]);
                pager.id = 'ans-fixes-pages-' + kind;
            }
            append(box, [el('div', 'panel-heading', [el('strong', null, LABELS[kind] + ' (' + entries.length + ')'), ' ', templateText]),
                pager ? el('div', 'panel-body', pager) : null, capText, el('div', 'table-responsive', table), el('div', 'panel-body', [button, ' ', chosenText, ' ', el('span', 'ans-help', canRun
                    ? 'opens a new change: nothing runs before its dry run' : 'making a change needs the run permission')])]);
            return box;
        }

        request('GET', config.list).then(function (result) {
            if (result.status !== 200) {
                status.textContent = result.body.error || ('The fixes cannot be loaded: HTTP ' + result.status);
                status.className = 'text-danger';
                return;
            }
            var data = result.body;
            var count = data.fixes.length + ' fix' + (data.fixes.length === 1 ? '' : 'es');
            if (config.filtered && !data.devices) {
                status.textContent = 'No device you may see matches the filter.';
            } else if (config.filtered) {
                status.textContent = data.fixes.length ? count + ' of the ' + data.devices + ' device' + (data.devices === 1 ? '' : 's') + ' of the filter, and of the findings about them'
                    : 'No finding of the topology plugin about the ' + data.devices + ' device' + (data.devices === 1 ? '' : 's') + ' of the filter has a clear fix now.';
            } else {
                status.textContent = data.fixes.length ? count + ' as you see the network' : 'No finding of the topology plugin has a clear fix now, as you see the network.';
            }
            if (data.role_hidden && data.role_hidden.length) {
                list.appendChild(alertBox('warning', null, ['Your LibreNMS role may not view ' + data.role_hidden.join(', ') + ': the list may be incomplete.']));
            }
            Object.keys(LABELS).forEach(function (kind) {
                var entries = data.fixes.filter(function (fix) {
                    return fix.kind === kind;
                });
                if (entries.length) {
                    list.appendChild(section(kind, entries, data.templates[kind] || {problem: 'unknown'}, data.can_run, data.new_change, data.max_targets || 25));
                }
            });
            if (data.not_fixable.length) {
                var box = el('div', 'panel panel-default');
                box.id = 'ans-fixes-not-fixable';
                append(box, [el('div', 'panel-heading', el('strong', null, 'Findings without a clear fix (' + data.not_fixable.length + ')')),
                    el('ul', 'list-unstyled panel-body', data.not_fixable.map(function (entry) {
                        var item = el('li', entry.identity === wanted && wanted !== null ? 'bg-info ans-fix-wanted' : null, [el('strong', null, entry.title), ': ', entry.why]);
                        if (entry.identity === wanted && wanted !== null) {
                            wantedRow = item;
                        }
                        return item;
                    }))]);
                list.appendChild(box);
            }
            if (wanted !== null && wantedRow === null) {
                list.insertBefore(alertBox('info', null, ['The finding you came from has no fix here now: it was resolved, or it changed since that page was loaded'
                    + (config.filtered ? ', or it is not about the devices of the filter.' : '.')]), list.firstChild);
            } else if (wantedRow !== null && wantedRow.scrollIntoView) {
                wantedRow.scrollIntoView({block: 'center'});
            }
        });
    }

    /* ---------------------------------------------------------------- diagnostics page */

    /**
     * The diagnostics page: the targets list with filters and refusals (the devices a link chose ticked), the answers (the
     * secret ones too: the run starts at once), and Run, which launches the diagnostic run and opens its page, or says
     * why it was refused.
     */
    function diagnostics(config) {
        var devices = [];
        var selected = {};
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
        var runButton = byId('ans-diagnostic-run');
        var launchState = byId('ans-launch-state');
        var errorsBox = byId('ans-diagnostic-errors');
        var MAX_ROWS = 500;

        function selectedIds() {
            return devices.filter(function (device) {
                return selected[device.id];
            }).map(function (device) {
                return device.id;
            });
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
                box.title = 'Target ' + device.display;
                box.setAttribute('aria-label', box.title);
                box.addEventListener('change', function () {
                    toggle(device, box.checked);
                });
                var link = el('a', null, device.display);
                link.href = config.deviceUrl + '/' + device.id;
                append(row, [
                    el('td', null, box),
                    el('td', 'ans-wrap', link),
                    el('td', device.refusal ? 'ans-refusal' : 'text-success', device.refusal ? device.refusal.text : 'yes'),
                    el('td', 'ans-code', device.name || '-'),
                    el('td', 'hidden-xs', device.os),
                    el('td', 'ans-code', device.platform || el('span', 'text-muted', 'no mapping')),
                    el('td', 'hidden-xs', el('span', (device.status === 'up' ? 'text-success' : (device.status === 'down' ? 'text-danger' : 'text-muted')), device.status)),
                    el('td', 'ans-wrap hidden-xs', device.location || '')
                ]);
                tbody.appendChild(row);
            });
            var count = selectedIds().length;
            status.textContent = list.length + ' of ' + devices.length + ' devices shown' + (list.length > MAX_ROWS ? ' (the first ' + MAX_ROWS + ': narrow the filters)' : '')
                + ' · ' + count + ' selected (at most ' + config.maxTargets + ')';
            status.className = count > config.maxTargets ? 'text-danger' : 'ans-muted';
            runButton.disabled = launching || !count || count > config.maxTargets;
        }

        function toggle(device, on) {
            if (on && !device.refusal) {
                if (selectedIds().length >= config.maxTargets) {
                    window.alert('A run may have at most ' + config.maxTargets + ' targets (Max targets per change setting).');
                } else {
                    selected[device.id] = true;
                }
            } else {
                delete selected[device.id];
            }
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
                var text = group.querySelector('.ans-var-error');
                if (text) {
                    text.textContent = message ? 'This answer ' + message + '.' : '';
                }
            });
        }

        function launch() {
            launching = true;
            runButton.disabled = true;
            launchState.textContent = 'launching…';
            clear(errorsBox);
            showVariableErrors(null);
            request('POST', config.store, {template: config.template, devices: selectedIds(), vars: answers(), request_token: config.requestToken}).then(function (result) {
                if ((result.status === 200 || result.status === 201) && result.body.url) {
                    launchState.textContent = 'launched: opening its page…';
                    window.location.href = result.body.url;
                    return;
                }
                launching = false;
                launchState.textContent = '';
                var box = alertBox('danger', 'The diagnostic run was not launched:', result.body.errors || [result.body.error || 'HTTP ' + result.status]);
                box.id = 'ans-diagnostic-refused';
                errorsBox.appendChild(box);
                showVariableErrors(result.body.variable_errors);
                render();
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
            render();
        });
        byId('ans-select-none').addEventListener('click', function () {
            selected = {};
            render();
        });
        runButton.addEventListener('click', launch);

        request('GET', config.targets).then(function (result) {
            if (result.status !== 200) {
                status.textContent = 'The devices cannot be loaded: ' + (result.body.error || 'HTTP ' + result.status);
                status.className = 'text-danger';
                return;
            }
            devices = result.body.devices;
            // the devices a link chose (a device's panel, the topology plugin's map, an earlier run): ticked and listed first
            var wanted = (config.devices || []).map(Number);
            var position = {};
            wanted.forEach(function (id, index) {
                position[id] = index;
            });
            devices = devices.filter(function (device) {
                return position[device.id] !== undefined;
            }).sort(function (a, b) {
                return position[a.id] - position[b.id];
            }).concat(devices.filter(function (device) {
                return position[device.id] === undefined;
            }));
            var oses = {};
            devices.forEach(function (device) {
                oses[device.os] = true;
            });
            Object.keys(oses).sort().forEach(function (os) {
                filters.os.appendChild(new Option(os, os));
            });
            var refused = [];
            var over = 0;
            wanted.forEach(function (id) {
                var device = devices.filter(function (candidate) {
                    return candidate.id === id;
                })[0];
                if (device && !device.refusal && selectedIds().length < config.maxTargets) {
                    selected[id] = true;
                } else if (device && device.refusal) {
                    refused.push(device.display + ': ' + device.refusal.text);
                } else if (device) {
                    over++;
                }
            });
            if (over) {
                refused.push(over + (over === 1 ? ' more device was' : ' more devices were') + ' chosen than a run may have (at most ' + config.maxTargets
                    + ', Max targets per change setting): they are listed first, not ticked');
            }
            if (refused.length) {
                var box = alertBox('warning', 'Of the devices chosen, these cannot be targets now:', refused.slice(0, 20));
                box.id = 'ans-diagnostic-preset-refused';
                errorsBox.appendChild(box);
            }
            render();
        });
    }

    window.AnsiblePlugin = {run: run, newChange: newChange, diagnostics: diagnostics, change: change, settings: settings, fixes: fixes};
})(window, document);
