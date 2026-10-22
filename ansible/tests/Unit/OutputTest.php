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

    public function testHtmlEscapesEverything(): void
    {
        $this->assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp;amp;', Ansi::html('<script>alert("x")</script> &amp;'));
        $this->assertSame('<span class="ans-green">ok: [sw1] =&gt; &lt;b&gt;</span>', Ansi::html("\e[0;32mok: [sw1] => <b>\e[0m"));
        $this->assertSame('&#039;onmouseover=&#039;x', Ansi::html("\e[38;5;1m'onmouseover='x"), 'unknown codes give no class');
    }

    public function testTheLogIsAppendedWhileItFits(): void
    {
        $first = OutputBuffer::add([], ['one', 'two'], 40);
        $this->assertSame(['append' => "one\ntwo", 'values' => []], $first);
        $second = OutputBuffer::add(['output' => "one\ntwo"], ['three'], 40);
        $this->assertSame("\nthree", $second['append'], 'only the new text goes to the database');
        $this->assertSame(['append' => '', 'values' => []], OutputBuffer::add(['output' => 'x'], [], 40));
        $this->assertSame(['one', 'two'], OutputBuffer::lines("one\ntwo"));
        $this->assertSame([], OutputBuffer::lines(''));
    }

    public function testALongLogKeepsItsHeadAndItsTail(): void
    {
        $lines = array_map(fn ($i) => sprintf('line %02d', $i), range(1, 20)); // 7 bytes each
        $cut = OutputBuffer::add(['output' => ''], $lines, 64);

        $this->assertSame('', $cut['append']);
        $this->assertSame(implode("\n", array_slice($lines, 0, 4)), $cut['values']['output'], 'the head: the first half');
        $this->assertSame(implode("\n", array_slice($lines, 16)), $cut['values']['output_tail'], 'the tail: the last half');
        $this->assertSame(12, $cut['values']['output_omitted']);
        $this->assertTrue($cut['values']['output_truncated']);

        $run = $cut['values'] + ['output_lines' => 20];
        $more = OutputBuffer::add($run, ['line 21', 'line 22'], 64);
        $this->assertSame('line 19' . "\n" . 'line 20' . "\n" . 'line 21' . "\n" . 'line 22', $more['values']['output_tail']);
        $this->assertSame(14, $more['values']['output_omitted']);

        $run = $more['values'] + $run;
        $run['output_lines'] = 22;
        $display = OutputBuffer::display($run);
        $this->assertSame(['line 01', 'line 02', 'line 03', 'line 04'], array_slice($display, 0, 4));
        $this->assertStringContainsString('14 lines left out here', $display[4]);
        $this->assertSame(['line 19', 'line 20', 'line 21', 'line 22'], array_slice($display, 5));

        // a page that showed 10 lines gets a marker for the lines it never saw, then the tail
        $since = OutputBuffer::since($run, 10);
        $this->assertSame(22, $since['next']);
        $this->assertStringContainsString('8 lines left out here', $since['lines'][0]);
        $this->assertSame(['line 19', 'line 20', 'line 21', 'line 22'], array_slice($since['lines'], 1));
        $this->assertSame(['line 22'], OutputBuffer::since($run, 21)['lines']);
        $this->assertSame([], OutputBuffer::since($run, 22)['lines']);
    }

    public function testLongValuesAreWrittenInPiecesBetweenCharacters(): void
    {
        $text = str_repeat('é', OutputBuffer::CHUNK); // two bytes each
        $pieces = OutputBuffer::pieces($text);

        $this->assertCount(2, $pieces);
        $this->assertSame($text, implode('', $pieces));
        foreach ($pieces as $piece) {
            $this->assertTrue(mb_check_encoding($piece, 'UTF-8'));
            $this->assertLessThanOrEqual(OutputBuffer::CHUNK, strlen($piece));
        }
        $long = OutputBuffer::add([], [str_repeat('x', 200000)], 8 * 1024 * 1024)['append'];
        $this->assertLessThan(70000, strlen($long), 'a very long line is cut');
        $this->assertStringEndsWith('(line cut by the plugin)', $long);
    }
}
