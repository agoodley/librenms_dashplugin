<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Runs\Ansi;
use Agoodley\LibrenmsAnsible\Runs\OutputBuffer;
use PHPUnit\Framework\TestCase;

class OutputTest extends TestCase
{
    public function testStrip(): void
    {
        $this->assertSame('changed: [lab-sw1]', Ansi::strip("\e[0;33mchanged: [lab-sw1]\e[0m"));
        $this->assertSame('a b', Ansi::strip("a\e]0;title\x07 \e[2Kb\r"));
        $this->assertSame('x    y', Ansi::strip("x\ty"));
        $this->assertSame('ab', Ansi::strip("a\u{202E}b\x00"));
        $this->assertSame('a?b', Ansi::strip("a\xffb"), 'invalid UTF-8 is replaced');
    }

    public function testSegments(): void
    {
        $line = "\e[0;33mlab-sw1\e[0m                    : \e[0;32mok=1   \e[0m \e[0;33mchanged=1   \e[0m unreachable=0";

        $this->assertSame([
            ['ans-yellow', 'lab-sw1'],
            [null, '                    : '],
            ['ans-green', 'ok=1   '],
            [null, ' '],
            ['ans-yellow', 'changed=1   '],
            [null, ' unreachable=0'],
        ], Ansi::segments($line));
        $this->assertSame([['ans-magenta ans-bold', '[WARNING]: x']], Ansi::segments("\e[1;35m[WARNING]: x\e[0m"));
        $this->assertSame([['ans-red', 'a'], ['ans-red ans-bold', 'b'], [null, 'c']], Ansi::segments("\e[31ma\e[1mb\e[mc"));
        $this->assertSame([[null, '<script>alert(1)</script>']], Ansi::segments('<script>alert(1)</script>'), 'escaping is the page\'s job');
        $this->assertSame([], Ansi::segments("\e[0m"));
    }

    public function testBufferCapsWholeLines(): void
    {
        $first = OutputBuffer::append('', ['one', 'two'], 20);
        $this->assertSame(['text' => "one\ntwo", 'truncated' => false], $first);

        $second = OutputBuffer::append($first['text'], ['three', 'four', 'five-is-too-long'], 20);
        $this->assertSame("one\ntwo\nthree\nfour", $second['text']);
        $this->assertTrue($second['truncated']);

        $this->assertSame(['text' => $second['text'], 'truncated' => true], OutputBuffer::append($second['text'], ['x'], 1000, true),
            'nothing is added after a gap');
        $this->assertSame(['one', 'two'], OutputBuffer::lines("one\ntwo"));
        $this->assertSame([], OutputBuffer::lines(''));
        $this->assertSame('', OutputBuffer::append('', [''], 20)['text']);
    }
}
