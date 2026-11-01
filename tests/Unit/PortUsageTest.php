<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Capacity\ErrDisable;
use Agoodley\LibrenmsTopology\Capacity\PortClock;
use Agoodley\LibrenmsTopology\Capacity\PortUsage;
use Agoodley\LibrenmsTopology\Stp\SyslogPatterns;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use PHPUnit\Framework\TestCase;

class PortUsageTest extends TestCase
{
    private const NOW = 1793530800;

    private const DAY = 86400;

    public function testBootTime(): void
    {
        $this->assertSame(self::NOW - 100, PortClock::boot(100, self::NOW));
        $this->assertNull(PortClock::boot(null, self::NOW));
        $this->assertNull(PortClock::boot(0, self::NOW), 'LibreNMS stores no uptime for some devices');
        $this->assertNull(PortClock::boot(100, null));
    }

    public function testLastChange(): void
    {
        $uptime = 100 * self::DAY;
        $change = PortClock::lastChange($uptime, self::NOW, 90 * self::DAY * 100);
        $this->assertSame(['time' => self::NOW - 10 * self::DAY, 'at_boot' => false, 'wrapped' => false], $change);

        // no change since the switch started, or one in its first minutes
        $this->assertSame(['time' => self::NOW - $uptime, 'at_boot' => true, 'wrapped' => false], PortClock::lastChange($uptime, self::NOW, 0));
        $this->assertTrue(PortClock::lastChange($uptime, self::NOW, 60000)['at_boot'], '10 minutes after the start');
        $this->assertFalse(PortClock::lastChange($uptime, self::NOW, 100000)['at_boot'], '17 minutes after the start');

        // the port was polled a little after the uptime: a change in between is a change at the poll
        $this->assertSame(self::NOW, PortClock::lastChange($uptime, self::NOW, $uptime * 100 + 6000)['time']);
        // an ifLastChange that does not fit the uptime
        $this->assertNull(PortClock::lastChange($uptime, self::NOW, $uptime * 100 + 400000)['time']);
        $this->assertNull(PortClock::lastChange(null, self::NOW, 5000)['time']);
    }

    public function testLastChangeOfASwitchUpLongerThanTheCounter(): void
    {
        // up 600 days: sysUpTime started over at 497.1 days and runs at 102.9 days now
        $uptime = 600 * self::DAY;
        $counter = fmod($uptime, PortClock::WRAP);
        // a change 10 days ago, counted after the counter started over
        $after = PortClock::lastChange($uptime, self::NOW, (int) round(($counter - 10 * self::DAY) * 100));
        $this->assertTrue($after['wrapped']);
        $this->assertEqualsWithDelta(self::NOW - 10 * self::DAY, $after['time'], 1, 'LibreNMS\' uptime - ifLastChange would say 507 days');
        // a change 110 days ago, counted before
        $before = PortClock::lastChange($uptime, self::NOW, (int) round(($counter - 110 * self::DAY + PortClock::WRAP) * 100));
        $this->assertEqualsWithDelta(self::NOW - 110 * self::DAY, $before['time'], 1);
    }

    public function testEvidenceOfUse(): void
    {
        $device = ['uptime' => 100 * self::DAY, 'last_polled' => self::NOW];
        $this->assertSame(self::NOW - 30, PortUsage::evidence(['ifOperStatus' => 'up', 'poll_time' => self::NOW - 30], $device));
        $this->assertSame(self::NOW, PortUsage::evidence(['ifOperStatus' => 'dormant'], $device), 'no poll time: its device\'s');
        $this->assertSame(self::NOW - 10 * self::DAY, PortUsage::evidence(['ifOperStatus' => 'down', 'ifLastChange' => 90 * self::DAY * 100], $device),
            'a port that went down was up until then');
        $this->assertNull(PortUsage::evidence(['ifOperStatus' => 'down', 'ifLastChange' => 3000], $device), 'down since the start: no evidence');
        $this->assertNull(PortUsage::evidence(['ifOperStatus' => 'up'], ['uptime' => null, 'last_polled' => null]));
    }

    public function testRecordUpdates(): void
    {
        $devices = [1 => ['uptime' => 100 * self::DAY, 'last_polled' => self::NOW], 2 => ['uptime' => 3 * self::DAY, 'last_polled' => self::NOW]];
        $ports = [
            10 => ['device_id' => 1, 'ifOperStatus' => 'up', 'poll_time' => self::NOW],
            11 => ['device_id' => 1, 'ifOperStatus' => 'up', 'poll_time' => self::NOW],
            12 => ['device_id' => 1, 'ifOperStatus' => 'down', 'ifLastChange' => 90 * self::DAY * 100],
            13 => ['device_id' => 2, 'ifOperStatus' => 'down', 'ifLastChange' => 2000],
            14 => ['device_id' => 2, 'ifOperStatus' => 'down', 'ifLastChange' => 2000],
            15 => ['device_id' => 1, 'ifOperStatus' => 'down', 'ifLastChange' => 2000],
            16 => ['device_id' => 2, 'ifOperStatus' => 'down', 'ifLastChange' => 2000],
        ];
        $record = fn (int $device, ?int $lastUp, ?int $errAt = null) => ['device_id' => $device, 'first_seen' => self::NOW - 50 * self::DAY,
            'last_up' => $lastUp, 'err_disabled_at' => $errAt, 'err_disabled_message' => $errAt ? 'old message' : null];
        $records = [
            10 => $record(1, self::NOW - 300), // up and written 5 minutes ago: not again yet
            11 => $record(1, self::NOW - 3600),
            12 => $record(1, self::NOW - 20 * self::DAY),
            13 => $record(2, self::NOW - 100 * self::DAY), // restarted since: the record keeps what it knew
            14 => $record(2, null, self::NOW - 2 * self::DAY),
            16 => $record(2, self::NOW - 4 * self::DAY, self::NOW - 4 * self::DAY),
            99 => $record(1, self::NOW - 5 * self::DAY), // LibreNMS no longer has the port
        ];
        $errors = [
            14 => ['time' => self::NOW - 3 * self::DAY, 'message' => 'older than the recorded one', 'recovered' => null],
            15 => ['time' => self::NOW - 600, 'message' => str_repeat('x', 300), 'recovered' => null],
            16 => ['time' => null, 'message' => null, 'recovered' => self::NOW - 3 * self::DAY], // errdisable recovery re-enabled it
        ];

        $update = PortUsage::update($ports, $devices, $records, $errors, self::NOW);

        $this->assertSame([11, 12, 14, 15, 16], array_keys($update['write']));
        $this->assertSame(self::NOW - 3 * self::DAY, $update['write'][14]['last_up'], 'an err-disable shows the port in use then');
        $this->assertSame(self::NOW - 2 * self::DAY, $update['write'][14]['err_disabled_at'], 'the recorded err-disable is the later one');
        $this->assertNull($update['write'][16]['err_disabled_at'], 'a recovery after the err-disable ends it');
        $this->assertNull($update['write'][16]['err_disabled_message']);
        $this->assertSame(self::NOW - 4 * self::DAY, $update['write'][16]['last_up']);
        $this->assertSame(self::NOW, $update['write'][11]['last_up']);
        $this->assertSame(self::NOW - 50 * self::DAY, $update['write'][11]['first_seen'], 'kept');
        $this->assertSame(self::NOW - 10 * self::DAY, $update['write'][12]['last_up'], 'its last change is newer than the record');
        $this->assertSame(['device_id' => 1, 'first_seen' => self::NOW, 'last_up' => self::NOW - 600, 'err_disabled_at' => self::NOW - 600,
            'err_disabled_message' => str_repeat('x', 255)], $update['write'][15], 'a new port with its err-disable message');
        $this->assertSame([99], $update['remove']);

        // a port moved to another device (renumbered) is written again
        $moved = PortUsage::update([10 => ['device_id' => 2] + $ports[10]], $devices, [10 => $records[10]], [], self::NOW);
        $this->assertSame(2, $moved['write'][10]['device_id']);
    }

    public function testErrDisableMessages(): void
    {
        $errDisable = new ErrDisable(new PluginSettings);
        $this->assertTrue($errDisable->usable());
        foreach ([
            '%PM-4-ERR_DISABLE: bpduguard error detected on Gi1/0/7, putting Gi1/0/7 in err-disable state',
            '%ETHPORT-5-IF_DOWN_ERROR_DISABLED: Interface Ethernet1/1 is down (Error disabled. Reason:BPDUGuard)',
            'Ebra %ETH-4-ERRDISABLE: bpduguard error detected on Ethernet3.',
            'SNMP TRAP: bpduGuard error detected on Gi1/0/9 (Description: GigabitEthernet1/0/9). Gi1/0/9 in err-disable state.',
        ] as $message) {
            $this->assertTrue($errDisable->matches($message), $message);
        }
        $this->assertFalse($errDisable->matches('%PM-4-ERR_RECOVER: Attempting to recover from bpduguard err-disable state on Gi1/0/7'), 'a recovery');
        $this->assertSame(ErrDisable::RECOVERED, $errDisable->classify('%PM-4-ERR_RECOVER: Attempting to recover from bpduguard err-disable state on Gi1/0/7'));
        $this->assertSame(ErrDisable::RECOVERED, $errDisable->classify('%ETHPORT-5-IF_ERRDIS_RECOVERY: Interface Ethernet1/1 is being recovered from error disabled state'));
        $this->assertSame(ErrDisable::DISABLED, $errDisable->classify('%PM-4-ERR_DISABLE: bpduguard error detected on Gi1/0/7'));
        $this->assertNull($errDisable->classify('%PARSER-5-CFGLOG_LOGGEDCMD: User:admin logged command:shutdown, err-disable test'), 'a command echoed to syslog');
        $this->assertFalse($errDisable->matches('%LINK-3-UPDOWN: Interface GigabitEthernet1/0/7, changed state to down'));

        $this->assertFalse((new ErrDisable(new PluginSettings(['stp_syslog_err_disabled' => ''])))->usable());
        $invalid = new ErrDisable(new PluginSettings(['stp_syslog_err_disabled' => '(unclosed']));
        $this->assertFalse($invalid->usable());
        $this->assertTrue($invalid->invalid());
        $this->assertFalse($invalid->matches('%PM-4-ERR_DISABLE: on Gi1/0/7'));
    }

    public function testErrDisableMessagesOfPorts(): void
    {
        $index = ErrDisable::portIndex([
            7 => ['device_id' => 1, 'ifName' => 'Gi1/0/7', 'ifDescr' => 'GigabitEthernet1/0/7'],
            8 => ['device_id' => 1, 'ifName' => 'Gi1/0/8', 'ifDescr' => 'GigabitEthernet1/0/8'],
            31 => ['device_id' => 3, 'ifName' => 'Ethernet3', 'ifDescr' => 'Ethernet3'],
        ]);
        $messages = [
            ['device_id' => 1, 'time' => 100, 'text' => '%PM-4-ERR_DISABLE: bpduguard error detected on Gi1/0/7, putting Gi1/0/7 in err-disable state'],
            ['device_id' => 1, 'time' => 200, 'text' => '%PM-4-ERR_DISABLE: psecure-violation error detected on GigabitEthernet1/0/7, putting it in err-disable state'],
            ['device_id' => 1, 'time' => 150, 'text' => '%PM-4-ERR_DISABLE: link-flap error detected on Gi1/0/8.100, putting it in err-disable state'],
            ['device_id' => 2, 'time' => 300, 'text' => '%PM-4-ERR_DISABLE: bpduguard error detected on Gi1/0/7, putting Gi1/0/7 in err-disable state'],
            ['device_id' => 3, 'time' => 300, 'text' => 'Ebra %ETH-4-ERRDISABLE: bpduguard error detected on Ethernet3.'],
            ['device_id' => 1, 'time' => 400, 'text' => '%PM-4-ERR_RECOVER: Attempting to recover from bpduguard err-disable state on Gi1/0/7'],
        ];

        $latest = (new ErrDisable(new PluginSettings))->latest($messages, $index);

        $this->assertSame([7, 8, 31], array_keys($latest));
        $this->assertSame(200, $latest[7]['time'], 'the latest err-disable');
        $this->assertSame(400, $latest[7]['recovered'], 'and the recovery after it');
        $this->assertStringContainsString('psecure-violation', $latest[7]['message']);
        $this->assertSame(150, $latest[8]['time'], 'a sub-interface counts for its port');
        $this->assertNull($latest[8]['recovered']);
        $this->assertSame(300, $latest[31]['time']);

        // what still holds: the later of the latest and the recorded one, unless a recovery followed
        $this->assertNull(ErrDisable::holding($latest[7], null));
        $this->assertSame(['time' => 500, 'message' => 'again'], ErrDisable::holding($latest[7], ['time' => 500, 'message' => 'again']));
        $this->assertSame(['time' => 150, 'message' => $latest[8]['message']], ErrDisable::holding($latest[8], ['time' => 100, 'message' => 'older']));
        $this->assertNull(ErrDisable::holding(null, null));
    }

    public function testPortCandidatesOfAMessage(): void
    {
        $this->assertContains('Gi1/0/7', SyslogPatterns::portCandidates('%PM-4-ERR_DISABLE: bpduguard error detected on Gi1/0/7, putting Gi1/0/7 in err-disable state'));
        $this->assertContains('5', SyslogPatterns::portCandidates('ports: port 5 is Disabled by loop protect'));
        $this->assertNotContains('Gi0/1', SyslogPatterns::portCandidates('%CDP-4-NATIVE_VLAN_MISMATCH: Native VLAN mismatch discovered on Gi1/0/1 (10), with sw9 Gi0/1 (20).'),
            'the neighbor\'s port after ", with" is not this device\'s');
    }
}
