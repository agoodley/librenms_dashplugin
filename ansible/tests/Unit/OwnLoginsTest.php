<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Inventory\OwnLogins;
use PHPUnit\Framework\TestCase;

class OwnLoginsTest extends TestCase
{
    /** LibreNMS' default oxidized.maps: Oxidized models by os, no group. */
    private const DEFAULT_MAPS = ['os' => ['os' => [['match' => 'arista_eos', 'value' => 'eos'], ['match' => 'vyos', 'value' => 'vyatta']]]];

    /** What device-logins.sh writes for devices 2 (sw2) and 7 (core7). */
    private const MAPS = self::DEFAULT_MAPS + ['group' => ['hostname' => [['match' => 'sw2', 'value' => 'login-2'], ['match' => 'core7', 'value' => 'login-7']]]];

    public function testWithoutGroupsEveryDeviceHasTheDefaultLogin(): void
    {
        foreach ([[self::DEFAULT_MAPS, true], [[], true], [null, false], ['x', true], [['group' => 'x'], true]] as [$maps, $support]) {
            $logins = OwnLogins::from($maps, $support);
            $this->assertSame([], $logins->given());
            $this->assertNull($logins->of(2, 'sw2', self::noValue()));
        }
    }

    public function testDeviceLoginsGroupsAreTheirOwnLogins(): void
    {
        $logins = OwnLogins::from(self::MAPS, true);

        $this->assertSame([2 => 'sw2', 7 => 'core7'], $logins->given());
        $this->assertSame(['state' => OwnLogins::OWN, 'group' => 'login-2', 'text' => 'LibreNMS gives Oxidized its group login-2'], $logins->of(2, 'sw2', self::noValue()));
        $this->assertSame(OwnLogins::OWN, $logins->of(7, 'core7', self::noValue())['state']);
        $this->assertNull($logins->of(1, 'sw1', self::noValue()), 'a device without a rule logs in with the default login');
        // LibreNMS' old format: the group under "group"
        $this->assertSame(OwnLogins::OWN, OwnLogins::from(['group' => ['hostname' => [['match' => 'sw2', 'group' => 'login-2']]]], true)->of(2, 'sw2', self::noValue())['state']);
    }

    public function testGroupSupportOffAppliesNone(): void
    {
        foreach ([false, null, 'true', 1] as $support) {
            $login = OwnLogins::from(self::MAPS, $support)->of(2, 'sw2', self::noValue());

            $this->assertSame(OwnLogins::NOT_APPLIED, $login['state'], var_export($support, true));
            $this->assertNull($login['group']);
            $this->assertStringContainsString("oxidized.group_support is off", $login['text']);
        }
        $this->assertNull(OwnLogins::from(self::MAPS, false)->of(1, 'sw1', self::noValue()));
    }

    public function testARenamedDeviceLosesItsGroup(): void
    {
        $login = OwnLogins::from(self::MAPS, true)->of(2, 'sw2-new', self::noValue());

        $this->assertSame(OwnLogins::NOT_APPLIED, $login['state']);
        $this->assertNull($login['group']);
        $this->assertSame('device-logins.sh gave it the group login-2 under the hostname sw2, and its hostname is sw2-new now. Run device-logins.sh again', $login['text']);
    }

    public function testADeviceWithAnotherDevicesHostnameGetsThatLogin(): void
    {
        // device 2 was deleted, or renamed, and device 9 has its hostname now
        $logins = OwnLogins::from(self::MAPS, true);
        $login = $logins->of(9, 'sw2', self::noValue());

        $this->assertSame(OwnLogins::OTHER, $login['state']);
        $this->assertSame('login-2', $login['group']);
        $this->assertSame('LibreNMS gives Oxidized the group login-2: the login device-logins.sh gave device #2 under the hostname sw2. Run device-logins.sh again', $login['text']);
        $this->assertSame(OwnLogins::NOT_APPLIED, $logins->of(2, 'sw2-old', self::noValue())['state']);
    }

    public function testALaterFieldsRuleWinsOverTheHostnames(): void
    {
        // a rule added in LibreNMS' settings after device-logins.sh's: list_oxidized() keeps the last field's match
        $maps = self::MAPS;
        $maps['group']['os'] = [['match' => 'iosxe', 'value' => 'cisco'], ['regex' => '/^jun/', 'value' => 'juniper']];
        $logins = OwnLogins::from($maps, true);
        $asked = [];
        $os = function (string $field) use (&$asked) {
            $asked[] = $field;

            return $field === 'os' ? 'iosxe' : null;
        };

        $login = $logins->of(2, 'sw2', $os);

        $this->assertSame(['os'], $asked);
        $this->assertSame(OwnLogins::NOT_APPLIED, $login['state']);
        $this->assertSame('cisco', $login['group']);
        $this->assertSame("LibreNMS' Oxidized map rule os = iosxe (group cisco) comes after device-logins.sh's rules by hostname, so it wins over the group login-2. "
            . "Change or delete it in LibreNMS' Oxidized settings", $login['text']);
        $this->assertSame(OwnLogins::NOT_APPLIED, $logins->of(2, 'sw2', fn ($field) => 'JunOS')['state'], 'a regex matches case-insensitively');
        $this->assertStringContainsString('os ~ /^jun/ (group juniper)', $logins->of(2, 'sw2', fn ($field) => 'junos')['text']);
        $this->assertSame(OwnLogins::OWN, $logins->of(2, 'sw2', fn ($field) => 'arista_eos')['state'], 'no later rule matches');
        $this->assertNull($logins->of(1, 'sw1', self::noValue()), 'a device without a rule of device-logins.sh needs no other field');
        // a device that only has another device's group, which a later rule wins over: not one of device-logins.sh's logins
        $this->assertNull($logins->of(9, 'sw2', $os));
    }

    public function testAnEarlierFieldsRuleLosesToTheHostnames(): void
    {
        $maps = ['group' => ['os' => [['match' => 'iosxe', 'value' => 'cisco']]] + self::MAPS['group']];

        $this->assertSame(OwnLogins::OWN, OwnLogins::from($maps, true)->of(2, 'sw2', self::noValue())['state']);
    }

    public function testAnEarlierHostnameRuleComesFirst(): void
    {
        $maps = ['group' => ['hostname' => [['regex' => '/^SW/', 'value' => 'switches'], ['match' => 'sw2', 'value' => 'login-2']]]];
        $login = OwnLogins::from($maps, true)->of(2, 'sw2', self::noValue());

        $this->assertSame(OwnLogins::NOT_APPLIED, $login['state']);
        $this->assertSame('switches', $login['group']);
        $this->assertSame("LibreNMS' Oxidized map rule hostname ~ /^SW/ (group switches) comes before device-logins.sh's rule for it. "
            . 'Run device-logins.sh again (it puts its rules first), or delete that rule', $login['text']);
    }

    public function testRulesMatchAsLibrenmsMatchesThem(): void
    {
        // an invalid regex matches nothing; a rule without a test, or not a rule, is skipped
        $maps = ['group' => ['hostname' => [['regex' => '/[/', 'value' => 'x'], ['value' => 'y'], 'z', ['match' => 'sw2', 'value' => 'login-2']]]];
        $this->assertSame(OwnLogins::OWN, OwnLogins::from($maps, true)->of(2, 'sw2', self::noValue())['state']);
        // a rule without a group that matches first gives none, as in LibreNMS
        $maps = ['group' => ['hostname' => [['match' => 'sw2'], ['match' => 'sw2', 'value' => 'login-2']]]];
        $login = OwnLogins::from($maps, true)->of(2, 'sw2', self::noValue());
        $this->assertSame([OwnLogins::NOT_APPLIED, null], [$login['state'], $login['group']]);
        // equal as PHP's == (LibreNMS' comparison): an empty match matches an empty value
        $maps = self::MAPS;
        $maps['group']['purpose'] = [['match' => '', 'value' => 'unset']];
        $this->assertSame('unset', OwnLogins::from($maps, true)->of(2, 'sw2', fn ($field) => null)['group']);
        // a regex test first, then the match of the same rule
        $maps = ['group' => ['hostname' => [['regex' => '/^nope/', 'match' => 'sw2', 'value' => 'login-2']]]];
        $this->assertSame(OwnLogins::OWN, OwnLogins::from($maps, true)->of(2, 'sw2', self::noValue())['state']);
    }

    public function testOnlyDeviceLoginsGroupsAreOwnLogins(): void
    {
        foreach (['login-0', 'login-007', 'login-2x', 'xlogin-2', 'login-', 'Login-2', 'login-12345678901'] as $group) {
            $logins = OwnLogins::from(['group' => ['hostname' => [['match' => 'sw2', 'value' => $group]]]], true);

            $this->assertSame([], $logins->given(), $group);
            $this->assertNull($logins->of(2, 'sw2', self::noValue()), $group);
        }
        // the first rule of a device id is the one it was given under; a regex rule gives none
        $logins = OwnLogins::from(['group' => ['hostname' => [['regex' => '/^a/', 'value' => 'login-3'], ['match' => 'b', 'value' => 'login-3'],
            ['match' => 'c', 'value' => 'login-3'], ['match' => ['d'], 'value' => 'login-4']]]], true);
        $this->assertSame([3 => 'b'], $logins->given());
        $this->assertSame(OwnLogins::OWN, $logins->of(3, 'alpha', self::noValue())['state'], 'LibreNMS gives the group by the regex');
    }

    public function testTextsOfHostnamesAreOneLine(): void
    {
        $login = OwnLogins::from(['group' => ['hostname' => [['match' => "sw2\nX", 'value' => 'login-2']]]], true)->of(2, 'sw2', self::noValue());

        $this->assertStringContainsString('under the hostname sw2 X, and', $login['text']);
    }

    /**
     * A device's values of the other fields that must not be needed.
     */
    private static function noValue(): \Closure
    {
        return function (string $field) {
            throw new \LogicException("the field $field was asked for");
        };
    }
}
