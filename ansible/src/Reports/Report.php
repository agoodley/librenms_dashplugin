<?php

namespace Agoodley\LibrenmsAnsible\Reports;

/**
 * A report of a run or a change (RunReport, ChangeReport) as a list of blocks, which a renderer puts in its own format
 * (HtmlReport, DocxReport): headings, paragraphs with a tone, facts (a label and its value), tables, lists, and blocks of
 * text as a device or Ansible printed it (an output, a diff, commands, a log). Every text is plain text: the renderers
 * escape it for their format.
 */
final class Report
{
    /** The tones a paragraph or a table cell may have. */
    public const TONES = ['', 'muted', 'success', 'warning', 'danger'];

    /** The kinds of a block of text: how it is shown (a diff is coloured line by line). */
    public const TEXT_KINDS = ['output', 'diff', 'commands', 'yaml', 'text', 'log'];

    /** @var array<int, array<string, mixed>> */
    public array $blocks = [];

    public function __construct(
        public readonly string $title,
        public readonly string $subtitle = '',
        public readonly string $footer = '',
    ) {
    }

    /**
     * A heading: 2 for a section, 3 within one.
     */
    public function heading(string $text, int $level = 2): self
    {
        $this->blocks[] = ['type' => 'heading', 'text' => $text, 'level' => max(2, min(3, $level))];

        return $this;
    }

    public function paragraph(string $text, string $tone = ''): self
    {
        $this->blocks[] = ['type' => 'paragraph', 'text' => $text, 'tone' => self::tone($tone)];

        return $this;
    }

    /**
     * Facts: a label and its value each (a value may hold several lines), and the value's tone.
     *
     * @param  array<int, array{0: string, 1: string, 2?: string}>  $rows
     */
    public function facts(array $rows): self
    {
        $this->blocks[] = ['type' => 'facts', 'rows' => array_values(array_map(fn (array $row) => [(string) $row[0], (string) $row[1], self::tone((string) ($row[2] ?? ''))],
            $rows))];

        return $this;
    }

    /**
     * A table: its headers, and its rows of cells, each a text or ['text' => ..., 'tone' => ...].
     *
     * @param  string[]  $headers
     * @param  array<int, array<int, string|array{text: string, tone?: string}>>  $rows
     */
    public function table(array $headers, array $rows): self
    {
        $cells = fn (array $row) => array_values(array_map(fn ($cell) => is_array($cell)
            ? ['text' => (string) ($cell['text'] ?? ''), 'tone' => self::tone((string) ($cell['tone'] ?? ''))] : ['text' => (string) $cell, 'tone' => ''], $row));
        $this->blocks[] = ['type' => 'table', 'headers' => array_values(array_map('strval', $headers)), 'rows' => array_values(array_map($cells, $rows))];

        return $this;
    }

    /**
     * @param  string[]  $items
     */
    public function items(array $items): self
    {
        $this->blocks[] = ['type' => 'items', 'items' => array_values(array_map('strval', $items))];

        return $this;
    }

    /**
     * A block of text as it was printed, line by line, under a caption.
     *
     * @param  string[]  $lines
     */
    public function text(array $lines, string $kind = 'output', string $caption = ''): self
    {
        $this->blocks[] = ['type' => 'text', 'lines' => array_values(array_map('strval', $lines)), 'kind' => in_array($kind, self::TEXT_KINDS, true) ? $kind : 'output',
            'caption' => $caption];

        return $this;
    }

    /**
     * How a line of a block of text is shown: a diff's added and removed lines and its hunk headers ('add', 'del',
     * 'hunk'), as the change page colours them; '' for any other.
     */
    public static function lineKind(string $kind, string $line): string
    {
        if ($kind !== 'diff') {
            return '';
        }

        return match (true) {
            str_starts_with($line, '+') && ! str_starts_with($line, '+++') => 'add',
            str_starts_with($line, '-') && ! str_starts_with($line, '---') => 'del',
            str_starts_with($line, '@@') => 'hunk',
            default => '',
        };
    }

    /**
     * The columns of a table that hold numbers only (the counters of a result): shown aligned to the right.
     *
     * @param  array{rows: array<int, array<int, array{text: string}>>}  $table
     * @return array<int, bool>
     */
    public static function numericColumns(array $table): array
    {
        $numeric = [];
        $filled = [];
        foreach ($table['rows'] as $row) {
            foreach ($row as $column => $cell) {
                $numeric[$column] = ($numeric[$column] ?? true) && ($cell['text'] === '' || ctype_digit($cell['text']));
                $filled[$column] = ($filled[$column] ?? false) || $cell['text'] !== '';
            }
        }
        foreach ($numeric as $column => $number) {
            $numeric[$column] = $number && $filled[$column];
        }

        return $numeric;
    }

    /**
     * The columns of a table that hold times only ("2026-10-29 09:00:00"): kept on one line.
     *
     * @param  array{rows: array<int, array<int, array{text: string}>>}  $table
     * @return array<int, bool>
     */
    public static function timeColumns(array $table): array
    {
        $times = [];
        foreach ($table['rows'] as $row) {
            foreach ($row as $column => $cell) {
                $times[$column] = ($times[$column] ?? true) && ($cell['text'] === '-' || preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d(:\d\d)?$/', $cell['text']) === 1);
            }
        }

        return $times;
    }

    private static function tone(string $tone): string
    {
        return in_array($tone, self::TONES, true) ? $tone : '';
    }
}
