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
        $this->assertSame(['append' => ['output' => "one\ntwo"], 'values' => []], $first);
        $second = OutputBuffer::add(['output' => "one\ntwo"], ['three'], 40);
        $this->assertSame(['output' => "\nthree"], $second['append'], 'only the new text goes to the database');
        $this->assertSame(['append' => [], 'values' => []], OutputBuffer::add(['output' => 'x'], [], 40));
        $this->assertSame(['one', 'two'], OutputBuffer::lines("one\ntwo"));
        $this->assertSame([], OutputBuffer::lines(''));
        $this->assertSame(['output' => "a\nb", 'status' => 'x', 'output_tail' => 'c'],
            OutputBuffer::applied(['output' => 'a', 'status' => 'y'], ['status' => 'x'], ['output' => "\nb", 'output_tail' => 'c']));
    }

    public function testALongLogKeepsItsHeadAndItsTail(): void
    {
        $lines = array_map(fn ($i) => sprintf('line %02d', $i), range(1, 20)); // 7 bytes each
        $cut = OutputBuffer::add(['output' => ''], $lines, 64);

        $this->assertSame([], $cut['append']);
        $this->assertSame(implode("\n", array_slice($lines, 0, 4)), $cut['values']['output'], 'the head: the first half');
        $this->assertSame(implode("\n", array_slice($lines, 17)), $cut['values']['output_tail'], 'the tail: three quarters of the last half, room for the next lines');
        $this->assertSame(13, $cut['values']['output_omitted']);
        $this->assertTrue($cut['values']['output_truncated']);

        // the tail is full: cut to its last three quarters at once
        $run = $cut['values'] + ['output_lines' => 20];
        $more = OutputBuffer::add($run, ['line 21', 'line 22'], 64);
        $this->assertSame([], $more['append']);
        $this->assertSame('line 20' . "\n" . 'line 21' . "\n" . 'line 22', $more['values']['output_tail']);
        $this->assertSame(15, $more['values']['output_omitted']);

        // while it has room, only the new lines are written
        $run = $more['values'] + $run;
        $next = OutputBuffer::add($run, ['line 23'], 64);
        $this->assertSame(['append' => ['output_tail' => "\nline 23"], 'values' => []], $next);
        $run = OutputBuffer::applied($run, $next['values'], $next['append']);
        $run['output_lines'] = 23;
        $display = OutputBuffer::display($run);
        $this->assertSame(['line 01', 'line 02', 'line 03', 'line 04'], array_slice($display, 0, 4));
        $this->assertStringContainsString('15 lines left out here', $display[4]);
        $this->assertSame(['line 20', 'line 21', 'line 22', 'line 23'], array_slice($display, 5));
        $this->assertLessThanOrEqual(64, strlen($run['output']) + strlen($run['output_tail']), 'never more than the Output size');

        // a page that showed 10 lines gets a marker for the lines it never saw, then the tail
        $since = OutputBuffer::since($run, 10);
        $this->assertSame(23, $since['next']);
        $this->assertStringContainsString('9 lines left out here', $since['lines'][0]);
        $this->assertSame(['line 20', 'line 21', 'line 22', 'line 23'], array_slice($since['lines'], 1));
        $this->assertSame(['line 23'], OutputBuffer::since($run, 22)['lines']);
        $this->assertSame([], OutputBuffer::since($run, 23)['lines']);
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
        $long = OutputBuffer::add([], [str_repeat('x', 200000)], 8 * 1024 * 1024)['append']['output'];
        $this->assertLessThan(70000, strlen($long), 'a very long line is cut');
        $this->assertStringEndsWith('(line cut by the plugin)', $long);
    }
}
