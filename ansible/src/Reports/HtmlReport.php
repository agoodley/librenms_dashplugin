<?php

namespace Agoodley\LibrenmsAnsible\Reports;

/**
 * A report as one HTML page that holds all it shows (its styles inline: no script, no image or font to load), to read
 * in a browser, to keep, or to print and save as PDF from the browser's print dialog (its print styles: A4, every line
 * of a block of text wrapped, not cut). Every text is escaped; the page's policy (CSP, in the page and in the
 * response's header) lets nothing run even if a text were not.
 */
final class HtmlReport
{
    /** What a report may load: its own styles. The response's header adds that no other page may frame it. */
    public const CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'";

    private const STYLE = <<<'CSS'
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f6f8fa; color: #1f2328; font: 14px/1.5 -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
        .r-page { max-width: 1180px; margin: 0 auto; padding: 24px 16px 40px; }
        .r-sheet { background: #fff; border: 1px solid #d1d9e0; border-radius: 6px; padding: 24px 28px 28px; }
        .r-kicker { color: #59636e; font-size: 12px; letter-spacing: .04em; text-transform: uppercase; }
        h1 { margin: 2px 0 2px; font-size: 26px; line-height: 1.25; color: #0b3d5c; }
        .r-sub { margin: 0 0 12px; color: #59636e; font-size: 15px; }
        .r-toc { display: flex; flex-wrap: wrap; gap: 2px 14px; margin: 0 0 8px; padding: 8px 0 10px; border-bottom: 1px solid #d1d9e0; font-size: 13px; }
        .r-toc a { color: #0b5e8c; text-decoration: none; white-space: nowrap; }
        h2 { margin: 26px 0 10px; padding-bottom: 4px; border-bottom: 1px solid #d1d9e0; font-size: 19px; color: #0b3d5c; }
        h3 { margin: 18px 0 8px; font-size: 15px; }
        p { margin: 0 0 10px; white-space: pre-line; }
        .t-muted { color: #59636e; }
        .t-success { color: #1a7f37; }
        .t-warning { color: #9a6700; }
        .t-danger { color: #d1242f; }
        p.t-success, p.t-warning, p.t-danger { padding: 7px 10px; border-left: 4px solid; border-radius: 3px; color: #1f2328; }
        p.t-success { background: #dafbe1; border-color: #1a7f37; }
        p.t-warning { background: #fff8c5; border-color: #d4a72c; }
        p.t-danger { background: #ffebe9; border-color: #d1242f; font-weight: 600; }
        .r-scroll { overflow-x: auto; margin: 0 0 12px; }
        table { border-collapse: collapse; width: 100%; font-size: 13px; }
        th, td { border: 1px solid #d1d9e0; padding: 4px 8px; text-align: left; vertical-align: top; white-space: pre-line; overflow-wrap: break-word; }
        thead th { background: #eaeef2; font-weight: 600; }
        td.r-num, th.r-num { text-align: right; white-space: nowrap; }
        td.r-time { white-space: nowrap; }
        td.t-success, td.t-warning, td.t-danger { font-weight: 600; }
        table.r-facts th { width: 28%; background: #f6f8fa; color: #59636e; font-weight: 600; }
        ul.r-items { margin: 0 0 12px; padding-left: 22px; }
        ul.r-items li { white-space: pre-line; }
        figure { margin: 0 0 12px; }
        figcaption { margin: 0 0 3px; color: #59636e; font-size: 12px; font-weight: 600; }
        pre { margin: 0; padding: 8px 10px; background: #f6f8fa; border: 1px solid #d1d9e0; border-radius: 4px; overflow-x: auto; white-space: pre;
            font: 12px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
        pre span { display: inline-block; min-width: 100%; }
        pre .d-add { background: #e6ffec; color: #116329; }
        pre .d-del { background: #ffebe9; color: #a40e26; }
        pre .d-hunk { background: #ddf4ff; color: #0b5e8c; }
        .r-foot { margin-top: 18px; color: #59636e; font-size: 12px; white-space: pre-line; }
        @page { size: A4; margin: 14mm 12mm; }
        @media print {
            body { background: #fff; font-size: 10pt; }
            .r-page { max-width: none; padding: 0; }
            .r-sheet { border: 0; padding: 0; }
            .r-toc { display: none; }
            h2, h3, figcaption { break-after: avoid; page-break-after: avoid; }
            tr { break-inside: avoid; page-break-inside: avoid; }
            .r-scroll { overflow: visible; padding: 0 1px; } /* a table's outer border half outside it: within the page */
            pre { white-space: pre-wrap; overflow-wrap: anywhere; overflow: visible; font-size: 8pt; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        CSS;

    public static function render(Report $report): string
    {
        $body = [];
        $contents = [];
        $section = 0;
        foreach ($report->blocks as $block) {
            switch ($block['type']) {
                case 'heading':
                    if ($block['level'] === 2) {
                        $id = 's' . ++$section;
                        $contents[] = '<a href="#' . $id . '">' . self::e($block['text']) . '</a>';
                        $body[] = '<h2 id="' . $id . '">' . self::e($block['text']) . '</h2>';
                    } else {
                        $body[] = '<h3>' . self::e($block['text']) . '</h3>';
                    }
                    break;
                case 'paragraph':
                    $body[] = '<p' . self::tone($block['tone']) . '>' . self::e($block['text']) . '</p>';
                    break;
                case 'facts':
                    $rows = array_map(fn (array $row) => '<tr><th>' . self::e($row[0]) . '</th><td' . self::tone($row[2]) . '>' . self::e($row[1]) . '</td></tr>', $block['rows']);
                    $body[] = '<div class="r-scroll"><table class="r-facts"><tbody>' . implode('', $rows) . '</tbody></table></div>';
                    break;
                case 'table':
                    $body[] = self::table($block);
                    break;
                case 'items':
                    $body[] = '<ul class="r-items">' . implode('', array_map(fn (string $item) => '<li>' . self::e($item) . '</li>', $block['items'])) . '</ul>';
                    break;
                case 'text':
                    $body[] = self::text($block);
                    break;
            }
        }

        return '<!DOCTYPE html>' . "\n" . '<html lang="en"><head><meta charset="utf-8">'
            . '<meta http-equiv="Content-Security-Policy" content="' . self::e(self::CSP) . '">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="generator" content="LibreNMS Ansible plugin">'
            . '<title>' . self::e($report->title) . '</title><style>' . self::STYLE . '</style></head>' . "\n"
            . '<body><div class="r-page"><div class="r-sheet">'
            . '<div class="r-kicker">LibreNMS · Ansible plugin</div><h1>' . self::e($report->title) . '</h1>'
            . ($report->subtitle !== '' ? '<p class="r-sub">' . self::e($report->subtitle) . '</p>' : '')
            . (count($contents) > 2 ? '<nav class="r-toc">' . implode('', $contents) . '</nav>' : '') . "\n"
            . implode("\n", $body) . "\n"
            . '</div>' . ($report->footer !== '' ? '<div class="r-foot">' . self::e($report->footer) . '</div>' : '') . '</div></body></html>' . "\n";
    }

    private static function table(array $block): string
    {
        $numeric = Report::numericColumns($block);
        $times = Report::timeColumns($block);
        $head = '';
        foreach ($block['headers'] as $column => $header) {
            $head .= '<th' . (! empty($numeric[$column]) ? ' class="r-num"' : '') . '>' . self::e($header) . '</th>';
        }
        $rows = '';
        foreach ($block['rows'] as $row) {
            $rows .= '<tr>';
            foreach ($row as $column => $cell) {
                $classes = trim((! empty($numeric[$column]) ? 'r-num ' : '') . (! empty($times[$column]) ? 'r-time ' : '') . ($cell['tone'] !== '' ? 't-' . $cell['tone'] : ''));
                $rows .= '<td' . ($classes !== '' ? ' class="' . $classes . '"' : '') . '>' . self::e($cell['text']) . '</td>';
            }
            $rows .= '</tr>';
        }

        return '<div class="r-scroll"><table>' . ($head !== '' ? '<thead><tr>' . $head . '</tr></thead>' : '') . '<tbody>' . $rows . '</tbody></table></div>';
    }

    private static function text(array $block): string
    {
        $lines = [];
        foreach ($block['lines'] as $line) {
            // a diff's coloured line as wide as the block (the newline right after <pre> is not the text's: HTML drops it)
            $kind = Report::lineKind($block['kind'], $line);
            $lines[] = $kind !== '' ? '<span class="d-' . $kind . '">' . self::e($line) . '</span>' : self::e($line);
        }
        $caption = $block['caption'];

        return '<figure>' . ($caption !== '' ? '<figcaption>' . self::e($caption) . '</figcaption>' : '') . '<pre>' . "\n" . implode("\n", $lines) . '</pre></figure>';
    }

    private static function tone(string $tone): string
    {
        return $tone !== '' ? ' class="t-' . $tone . '"' : '';
    }

    /**
     * Text for the page: escaped, invalid UTF-8 replaced.
     */
    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
