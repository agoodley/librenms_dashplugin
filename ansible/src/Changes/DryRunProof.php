<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;

/**
 * Does the log of a dry run prove that it was one? A dry run is launched with --check, but a task with
 * "check_mode: false" runs for real even then, and a runner may ignore the flag. With ANSIBLE_CHECK_MODE_MARKERS=true
 * (the template's variable group, checked before the launch) Ansible's default callback prints "DRY RUN" before the
 * first play when --check is on, and "[CHECK MODE]" at the end of the banner of every play, task and handler that runs
 * in check mode. So a dry run is verified only when:
 *
 * - the DRY RUN banner comes before the first play (the runner passed --check);
 * - at least one play ran, and every PLAY, TASK and RUNNING HANDLER banner carries the marker; any line that starts
 *   like such a banner counts, so device output that imitates a banner without the marker blocks the dry run, and one
 *   with the marker cannot hide a real one without it;
 * - the log shows every result the PLAY RECAP counts as ok for each host: with display_ok_hosts switched off Ansible
 *   prints a task's banner only with a result it shows, so a task outside check mode whose result is hidden would
 *   leave no banner; ok results, ignored failures and includes are counted as Ansible's statistics count them.
 *
 * The rule fails safe: anything it cannot read (no recap, a cut log) leaves the dry run unverified. One log says
 * that nothing ran: ansible-playbook's own error that the limit leaves it no host to target, which it raises before it
 * starts the playbook, with no banner, result or recap at all. The log's word is not enough: only when the inventory
 * guard's own record shows that it left every target out of what it served during the dry run ($allLeftOut) does
 * such a dry run count as one that did not run on any device, with nothing to prove (nothing_ran); otherwise it is
 * unverified, as any log without a play.
 */
final class DryRunProof
{
    /** Banners without the marker listed at most. */
    private const MAX_LISTED = 10;

    /**
     * The verdict on a dry run's whole log.
     *
     * @param  string[]  $lines  the runner's whole log (ANSI codes allowed)
     * @param  array<string, array<string, int>>|null  $recap  RecapParser::parse() of the same log
     * @param  bool  $allLeftOut  the inventory guard left every target out of what it served during the dry run (its record)
     * @return array{verified: bool, findings: string[], unmarked: string[], nothing_ran: bool}
     */
    public static function check(array $lines, ?array $recap, bool $allLeftOut = false): array
    {
        $findings = [];
        $noHosts = false;
        $banners = 0;
        $named = 0;
        $unmarked = [];
        $unmarkedCount = 0;
        $dryRunBanner = false;
        $plays = 0;
        $section = 0;
        $okSections = [];
        $ignored = [];
        $includes = [];
        // hosts with a failure line in the current task, by their latest one, and those an "...ignoring" was counted for:
        // Ansible prints "...ignoring" with a host's final result, after its last loop item
        $failed = [];
        $credited = [];
        foreach ($lines as $line) {
            $plain = AnsibleLog::plain($line);
            if (AnsibleLog::noHosts($plain)) {
                $noHosts = true;

                continue;
            }
            $banner = AnsibleLog::banner($plain);
            if ($banner !== null) {
                $banners++;
                if ($banner['kind'] === 'dry_run' && $plays === 0) {
                    $dryRunBanner = true;
                } elseif (in_array($banner['kind'], AnsibleLog::MARKED_KINDS, true)) {
                    $section++;
                    $plays += $banner['kind'] === 'play' ? 1 : 0;
                    if (! $banner['marked']) {
                        $unmarkedCount++;
                        if (count($unmarked) < self::MAX_LISTED) {
                            $unmarked[] = strtoupper($banner['kind'] === 'handler' ? 'running handler' : $banner['kind']) . ' [' . UntrustedText::line($banner['name'], 120) . ']';
                        }
                    }
                }
                [$failed, $credited] = [[], []];

                continue;
            }
            if (AnsibleLog::ignoring($plain)) {
                $named++;
                foreach ($failed as $host) {
                    if (! isset($credited[$host])) {
                        $credited[$host] = true;
                        $ignored[$host] = ($ignored[$host] ?? 0) + 1;
                        break;
                    }
                }

                continue;
            }
            $hosts = AnsibleLog::included($plain);
            if ($hosts !== null) {
                $named++;
                foreach ($hosts as $host) {
                    $includes[$host] = ($includes[$host] ?? 0) + 1;
                }

                continue;
            }
            $result = AnsibleLog::result($plain);
            if ($result === null) {
                continue;
            }
            $named++;
            if ($result['kind'] === 'ok' || $result['kind'] === 'changed') {
                $okSections[$result['host']][$section] = true;
            } elseif ($result['kind'] === 'failed' || $result['kind'] === 'fatal') {
                $failed = array_values(array_diff($failed, [$result['host']]));
                $failed[] = $result['host'];
            }
        }

        $saysNothingRan = $noHosts && $banners === 0 && $named === 0 && $recap === null;
        if ($saysNothingRan && $allLeftOut) {
            return ['verified' => false, 'findings' => ['Ansible stopped before it started the playbook, as the limit left it no host to target: none of the '
                . 'targets was in the inventory the runner fetched, so the dry run did not run on any device.'], 'unmarked' => [], 'nothing_ran' => true];
        }
        if ($saysNothingRan) {
            $findings[] = 'The log says that Ansible found no host to target, but the plugin did not leave every target out of the inventory it served '
                . 'during the dry run: nothing proves that the dry run did not run on them.';
        }
        if (! $dryRunBanner) {
            $findings[] = 'The log does not start with Ansible\'s DRY RUN banner, which Ansible prints only when it runs the playbook with --check: '
                . 'the runner may have run it for real.';
        }
        if ($plays === 0) {
            $findings[] = 'The log shows no play: there is nothing that proves a dry run.';
        }
        if ($unmarkedCount > 0) {
            $findings[] = $unmarkedCount . ' ' . ($unmarkedCount === 1 ? 'banner has' : 'banners have') . ' no [CHECK MODE] marker, so '
                . ($unmarkedCount === 1 ? 'it' : 'they') . ' ran outside check mode (check_mode: false), or the runner ignored the dry run: '
                . implode(', ', $unmarked) . ($unmarkedCount > count($unmarked) ? ', ...' : '') . '.';
        }
        if ($recap === null) {
            $findings[] = 'The log has no PLAY RECAP: the plugin cannot tell whether it shows every task.';
        } else {
            $hidden = [];
            foreach ($recap as $host => $counts) {
                $shown = count($okSections[$host] ?? []) + ($ignored[$host] ?? 0) + ($includes[$host] ?? 0);
                if ($shown < (int) ($counts['ok'] ?? 0)) {
                    $hidden[] = UntrustedText::line($host, 80) . ' (' . ((int) $counts['ok'] - $shown) . ')';
                }
            }
            if ($hidden) {
                $findings[] = 'The log does not show every task result the PLAY RECAP counts, so a task may have run without its banner (is '
                    . 'display_ok_hosts or display_skipped_hosts switched off in ansible.cfg?): ' . implode(', ', array_slice($hidden, 0, self::MAX_LISTED))
                    . (count($hidden) > self::MAX_LISTED ? ', ...' : '') . '.';
            }
        }

        return ['verified' => ! $findings, 'findings' => $findings, 'unmarked' => $unmarked, 'nothing_ran' => false];
    }
}
