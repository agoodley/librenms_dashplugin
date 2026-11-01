<?php

namespace Agoodley\LibrenmsInventory\Tests\Unit;

use Agoodley\LibrenmsInventory\Software\Policies;
use Agoodley\LibrenmsInventory\Software\Policy;
use Agoodley\LibrenmsInventory\Software\Status;
use PHPUnit\Framework\TestCase;

/**
 * The software policies as an admin writes them, which policy a device gets, and its status against them.
 */
class PoliciesTest extends TestCase
{
    public const POLICIES = <<<'YAML'
        - name: IOS-XE advisories
          os: [ios, iosxe]
          blocked: ['16.*', 17.3.1]
          note: cisco-sa-iosxe-webui-privesc
        - name: Catalyst 9300
          os: iosxe
          model: C9300-*
          approved: [17.9.5, '17.12.*']
          minimum: 17.9.4
        - name: Catalyst 2960-X
          model: [WS-C2960X-*]
          minimum: 15.2(7)E8
        - name: EOS
          os: arista_eos
          approved: ['4.30.*F']
        YAML;

    /** @return list<Policy> */
    private static function policies(): array
    {
        $parsed = Policies::parse(self::POLICIES);
        self::assertSame([], $parsed['errors']);

        return $parsed['policies'];
    }

    public function testThePoliciesAreRead(): void
    {
        $policies = self::policies();

        $this->assertSame(['IOS-XE advisories', 'Catalyst 9300', 'Catalyst 2960-X', 'EOS'], array_map(fn (Policy $p) => $p->name, $policies));
        $this->assertSame([['ios', 'iosxe'], [], ['16.*', '17.3.1'], null], [$policies[0]->os, $policies[0]->models, $policies[0]->blocked, $policies[0]->minimum]);
        $this->assertSame([['iosxe'], ['C9300-*'], ['17.9.5', '17.12.*'], '17.9.4'], [$policies[1]->os, $policies[1]->models, $policies[1]->approved, $policies[1]->minimum]);
        $this->assertSame([[], ['WS-C2960X-*'], '15.2(7)E8'], [$policies[2]->os, $policies[2]->models, $policies[2]->minimum]);
        $this->assertSame(['policies' => [], 'errors' => []], Policies::parse("  \n"), 'no policies');
        $this->assertSame('policy 1', Policies::parse("- os: junos\n  minimum: 21.4R3\n")['policies'][0]->name, 'a policy without a name');
        $this->assertSame('17', Policies::parse("- os: x\n  minimum: 17\n")['policies'][0]->minimum, 'a whole number is a version');
    }

    public function testWhatIsWrongWithThePoliciesIsSaid(): void
    {
        $errors = fn (string $yaml) => Policies::parse($yaml)['errors'];

        $this->assertStringStartsWith('The policies are not valid YAML', $errors("- os: [ios\n")[0]);
        $this->assertSame(['The policies must be a YAML list: each policy starts with "- ".'], $errors("os: ios\nminimum: 15\n"));
        $this->assertSame(['Policy 1: must be a map of keys (name, os, model, approved, minimum, blocked, note).'], $errors("- ios\n"));
        $this->assertSame(['Policy 1 (A): unknown key "aproved" (a policy takes name, os, model, approved, minimum, blocked, note).'],
            $errors("- name: A\n  os: ios\n  aproved: [15.2]\n  minimum: '15.2'\n"));
        $this->assertSame(["Policy 1: minimum: YAML reads 4.3 as a number: write versions in quotes, such as '4.30'."], $errors("- os: arista_eos\n  minimum: 4.30\n"),
            '4.30 would become 4.3');
        $this->assertSame(['Policy 1: names no devices: give an os, a model or both.'], $errors("- minimum: '15.2'\n"));
        $this->assertSame(['Policy 1: checks nothing: give approved versions, a minimum, blocked versions or several of them.'], $errors("- os: ios\n"));
        $this->assertSame(['Policy 2 (a): another policy has this name.'],$errors("- name: A\n  os: ios\n  minimum: '1'\n- name: a\n  os: nxos\n  minimum: '1'\n"));
        $this->assertSame(['Policy 1: minimum must be a text, not a list.'], $errors("- os: ios\n  minimum: ['1', '2']\n"));
        $this->assertSame(['Policy 1: blocked has an empty entry.'], $errors("- os: ios\n  blocked: ['']\n"));
        $this->assertSame([], Policies::parse("- os: ios\n  minimum: '1'\n- os: [ios\n")['policies'], 'a text with errors gives no policies');
    }

    public function testPatterns(): void
    {
        $this->assertTrue(Policy::matches('C9300-*', 'c9300-48p'), 'ignoring case');
        $this->assertTrue(Policy::matches('17.12.*', '17.12.4'));
        $this->assertFalse(Policy::matches('17.12.*', '17.1'));
        $this->assertTrue(Policy::matches('15.2(7)E?', '15.2(7)E8'), 'parentheses as written');
        $this->assertFalse(Policy::matches('15.2(7)E?', '15.2(7)E10'));
        $this->assertFalse(Policy::matches('17.9.5', '17.9.50'), 'the whole version');
        $this->assertFalse(Policy::matches('17.9.5', '17x9x5'), 'a dot is a dot');
    }

    public function testTheStatusOfADevice(): void
    {
        $policies = self::policies();
        $status = fn (string $os, array $models, string $version) => array_values(Policies::evaluate($policies, $os, $models, $version));

        $this->assertSame([Status::APPROVED, 'Catalyst 9300', '17.9.5 is approved by the policy Catalyst 9300.'], $status('iosxe', ['C9300-48P'], '17.9.5'),
            'the policy for its model, not the one for its os');
        $this->assertSame(Status::APPROVED, $status('iosxe', ['C9300-24T'], '17.12.4')[0]);
        $this->assertSame([Status::NOT_APPROVED, 'Catalyst 9300', '17.10.1 is not approved by the policy Catalyst 9300 (approved: 17.9.5, 17.12.*).'],
            $status('iosxe', ['C9300-48P'], '17.10.1'));
        $this->assertSame([Status::BELOW_MINIMUM, 'Catalyst 9300', '17.6.5 is older than 17.9.4, the minimum of the policy Catalyst 9300.'],
            $status('iosxe', ['C9300-48P'], '17.6.5'), 'below the minimum before not approved');
        $this->assertSame([Status::BLOCKED, 'IOS-XE advisories', '17.3.1 is blocked by the policy IOS-XE advisories. Note: cisco-sa-iosxe-webui-privesc'],
            $status('iosxe', ['C9300-48P'], '17.3.1'), 'the os policy blocks it on every model');
        $this->assertSame([Status::BLOCKED, 'IOS-XE advisories'], array_slice($status('iosxe', ['ISR4331/K9'], '16.12.4'), 0, 2), 'a model without a policy of its own');
        $this->assertSame([Status::ALLOWED, 'IOS-XE advisories', '17.9.4 is allowed by the policy IOS-XE advisories: not blocked.'], $status('iosxe', ['ISR4331/K9'], '17.9.4'));

        // a stack: a member's model gives it the policy; the version is compared number by number
        $this->assertSame([Status::ALLOWED, 'Catalyst 2960-X', '15.2(7)E10 is allowed by the policy Catalyst 2960-X: not blocked, and not older than its minimum 15.2(7)E8.'],
            $status('ios', ['', 'WS-C2960X-24PS-L', 'WS-C2960X-48FPS-L'], '15.2(7)E10'));
        $this->assertSame(Status::BELOW_MINIMUM, $status('ios', ['WS-C2960X-48FPS-L'], '15.2(4)E10')[0]);
        $this->assertSame(Status::NOT_APPROVED, $status('arista_eos', ['DCS-7050SX3-48YC8'], '4.30.1M')[0]);
        $this->assertSame(Status::APPROVED, $status('arista_eos', ['DCS-7050SX3-48YC8'], '4.30.1F')[0]);
        $this->assertSame([Status::UNKNOWN, 'Catalyst 9300', 'LibreNMS has no software version for it.'], $status('iosxe', ['C9300-48P'], ' '));
        $this->assertSame([Status::NO_POLICY, null, 'No policy applies to it.'], $status('junos', ['EX4300-48P'], '21.4R3'));
        $this->assertSame([Status::NO_POLICY, null], array_slice($status('nxos', ['C9300-48P'], '9.3(8)'), 0, 2), 'the model policy is for iosxe only');
    }

    public function testCounts(): void
    {
        $counts = Policies::counts([['status' => Status::BLOCKED], ['status' => Status::NOT_APPROVED], ['status' => Status::APPROVED], ['status' => Status::NO_POLICY]]);

        $this->assertSame([1, 0, 1, 1, 0, 0, 1, 2, 1], array_values($counts));
        $this->assertSame(['blocked', 'below the minimum', 'version unknown'], [Status::label(Status::BLOCKED), Status::label(Status::BELOW_MINIMUM), Status::label(Status::UNKNOWN)]);
        $this->assertSame(['critical', 'warning', null], [Status::severity(Status::BLOCKED), Status::severity(Status::NOT_APPROVED), Status::severity(Status::APPROVED)]);
        $this->assertSame([[Status::APPROVED, Status::ALLOWED], [Status::BLOCKED], []], [Status::filter('compliant'), Status::filter('blocked'), Status::filter('nope')]);
        $this->assertSame('a, b and 1 more', Policies::names(['a', 'b', 'c'], 2));
    }
}
