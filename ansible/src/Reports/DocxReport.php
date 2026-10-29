<?php

namespace Agoodley\LibrenmsAnsible\Reports;

/**
 * A report as a Word document (.docx, WordprocessingML), written by hand: an A4 page, the built-in heading styles (so
 * Word's navigation pane and table of contents find the sections), tables whose header row repeats on each page, blocks
 * of text in a monospaced font with a diff's lines coloured, and a footer with the page number. Every text is escaped
 * for XML, and the characters XML cannot hold are dropped.
 */
final class DocxReport
{
    /** A4 and its margins, in twentieths of a point: the width of the text. */
    private const PAGE_WIDTH = 11906;

    private const PAGE_HEIGHT = 16838;

    private const MARGIN = 1020;

    private const TEXT_WIDTH = self::PAGE_WIDTH - 2 * self::MARGIN;

    /** About the width of a character of a table's text (9 pt), and what a cell adds to its text (margins, border). */
    private const CHARACTER = 105;

    private const CELL = 200;

    /** The colour of a tone's text. */
    private const TONES = ['muted' => '59636E', 'success' => '1A7F37', 'warning' => '9A6700', 'danger' => 'D1242F'];

    /** The styles of a tone's paragraph, and of a diff's lines. */
    private const NOTES = ['muted' => 'Muted', 'success' => 'NoteSuccess', 'warning' => 'NoteWarning', 'danger' => 'NoteDanger'];

    private const LINES = ['add' => 'CodeAdd', 'del' => 'CodeDel', 'hunk' => 'CodeHunk'];

    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * The document's files, packed (Archive::zip()).
     */
    public static function render(Report $report, ?int $now = null): string
    {
        return Archive::zip(self::parts($report, $now ?? time()));
    }

    /**
     * The document's files: path in the package => content.
     *
     * @return array<string, string>
     */
    public static function parts(Report $report, int $now): array
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $type = 'application/vnd.openxmlformats-officedocument.wordprocessingml';
        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

        return [
            '[Content_Types].xml' => $xml . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/word/document.xml" ContentType="' . $type . '.document.main+xml"/>'
                . '<Override PartName="/word/styles.xml" ContentType="' . $type . '.styles+xml"/>'
                . '<Override PartName="/word/settings.xml" ContentType="' . $type . '.settings+xml"/>'
                . '<Override PartName="/word/footer1.xml" ContentType="' . $type . '.footer+xml"/>'
                . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
                . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>',
            '_rels/.rels' => $xml . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="word/document.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
                . '<Relationship Id="rId3" Type="' . $rel . '/extended-properties" Target="docProps/app.xml"/></Relationships>',
            'docProps/core.xml' => $xml . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
                . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
                . '<dc:title>' . self::x($report->title) . '</dc:title><dc:subject>' . self::x($report->subtitle) . '</dc:subject><dc:creator>LibreNMS Ansible plugin</dc:creator>'
                . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z', $now) . '</dcterms:created></cp:coreProperties>',
            'docProps/app.xml' => $xml . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>LibreNMS Ansible plugin</Application></Properties>',
            'word/_rels/document.xml.rels' => $xml . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="' . $rel . '/styles" Target="styles.xml"/><Relationship Id="rId2" Type="' . $rel . '/settings" Target="settings.xml"/>'
                . '<Relationship Id="rId3" Type="' . $rel . '/footer" Target="footer1.xml"/></Relationships>',
            'word/document.xml' => $xml . self::document($report),
            'word/styles.xml' => $xml . self::styles(),
            'word/settings.xml' => $xml . '<w:settings xmlns:w="' . self::W . '"><w:defaultTabStop w:val="720"/><w:characterSpacingControl w:val="doNotCompress"/>'
                . '<w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat></w:settings>',
            'word/footer1.xml' => $xml . self::footer($report),
        ];
    }

    private static function document(Report $report): string
    {
        $body = [self::paragraph($report->title, 'Title')];
        if ($report->subtitle !== '') {
            $body[] = self::paragraph($report->subtitle, 'Subtitle');
        }
        foreach ($report->blocks as $block) {
            switch ($block['type']) {
                case 'heading':
                    $body[] = self::paragraph($block['text'], $block['level'] === 2 ? 'Heading1' : 'Heading2');
                    break;
                case 'paragraph':
                    $body[] = self::paragraph($block['text'], self::NOTES[$block['tone']] ?? null);
                    break;
                case 'facts':
                    $body[] = self::facts($block['rows']);
                    break;
                case 'table':
                    $body[] = self::table($block);
                    break;
                case 'items':
                    foreach ($block['items'] as $item) {
                        $body[] = self::paragraph("•\t" . $item, 'ListItem');
                    }
                    break;
                case 'text':
                    if ($block['caption'] !== '') {
                        $body[] = self::paragraph($block['caption'], 'Caption');
                    }
                    foreach ($block['lines'] ?: [''] as $line) {
                        $body[] = self::paragraph($line, self::LINES[Report::lineKind($block['kind'], $line)] ?? 'Code');
                    }
                    $body[] = '<w:p><w:pPr><w:pStyle w:val="Gap"/></w:pPr></w:p>';
                    break;
            }
        }
        if ($report->footer !== '') {
            $body[] = self::paragraph($report->footer, 'Muted');
        }

        return '<w:document xmlns:w="' . self::W . '" xmlns:r="' . self::R . '"><w:body>' . implode('', $body)
            . '<w:sectPr><w:footerReference w:type="default" r:id="rId3"/><w:pgSz w:w="' . self::PAGE_WIDTH . '" w:h="' . self::PAGE_HEIGHT . '"/>'
            . '<w:pgMar w:top="' . self::MARGIN . '" w:right="' . self::MARGIN . '" w:bottom="' . self::MARGIN . '" w:left="' . self::MARGIN . '" w:header="567" w:footer="510" w:gutter="0"/>'
            . '<w:cols w:space="720"/></w:sectPr></w:body></w:document>';
    }

    /**
     * Facts: a table of two columns, the labels shaded.
     *
     * @param  array<int, array{0: string, 1: string, 2: string}>  $rows
     */
    private static function facts(array $rows): string
    {
        $label = (int) round(self::TEXT_WIDTH * 0.28);
        $value = self::TEXT_WIDTH - $label;
        $xml = '';
        foreach ($rows as [$name, $text, $tone]) {
            $xml .= '<w:tr>' . self::cell($name, $label, 'F6F8FA', 'muted', true) . self::cell($text, $value, null, $tone, $tone !== '' && $tone !== 'muted') . '</w:tr>';
        }

        return self::wrapTable([$label, $value], $xml);
    }

    private static function table(array $block): string
    {
        $columns = max(1, count($block['headers']), ...array_map('count', $block['rows'] ?: [[]]));
        $numeric = Report::numericColumns($block);
        $widths = self::widths($block, $columns);
        $xml = '';
        if ($block['headers']) {
            $xml .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
            foreach ($block['headers'] as $column => $header) {
                $xml .= self::cell($header, $widths[$column], 'EAEEF2', '', true, ! empty($numeric[$column]));
            }
            $xml .= '</w:tr>';
        }
        foreach ($block['rows'] as $row) {
            $xml .= '<w:tr>';
            for ($column = 0; $column < $columns; $column++) {
                $cell = $row[$column] ?? ['text' => '', 'tone' => ''];
                $xml .= self::cell($cell['text'], $widths[$column], null, $cell['tone'], in_array($cell['tone'], ['success', 'warning', 'danger'], true), ! empty($numeric[$column]));
            }
            $xml .= '</w:tr>';
        }

        return self::wrapTable($widths, $xml);
    }

    /**
     * The widths of a table's columns, in twentieths of a point, filling the text's width: each column as wide as its
     * longest line (a long text counted as 40 characters); when that is too wide, the columns narrowed toward their
     * longest word (a long one counted as 14 characters), those with the most room the most.
     *
     * @return int[]
     */
    private static function widths(array $block, int $columns): array
    {
        $want = array_fill(0, $columns, 0);
        $least = array_fill(0, $columns, 0);
        $rows = array_merge([array_map(fn (string $header) => ['text' => $header], $block['headers'])], $block['rows']);
        foreach ($rows as $index => $row) {
            foreach (array_slice($row, 0, $columns) as $column => $cell) {
                // a header is bold: a character more
                $bold = $index === 0 ? 1 : 0;
                foreach (explode("\n", $cell['text']) as $line) {
                    $want[$column] = max($want[$column], min(40, mb_strlen($line) + $bold));
                    foreach (preg_split('/\s+/u', trim($line)) ?: [] as $word) {
                        $least[$column] = max($least[$column], min(14, mb_strlen($word) + $bold));
                    }
                }
            }
        }
        $twips = fn (int $characters) => max(3, $characters) * self::CHARACTER + self::CELL;
        $want = array_map($twips, $want);
        $least = array_map(fn (int $characters, int $wanted) => min($wanted, $twips($characters)), $least, $want);
        $total = array_sum($want);
        if ($total <= self::TEXT_WIDTH) {
            return array_map(fn (int $width) => (int) floor($width * self::TEXT_WIDTH / $total), $want);
        }
        $room = array_sum($want) - array_sum($least);
        $over = $total - self::TEXT_WIDTH;
        if ($room < $over) {
            return array_map(fn (int $width) => (int) floor($width * self::TEXT_WIDTH / array_sum($least)), $least);
        }

        return array_map(fn (int $wanted, int $smallest) => (int) floor($wanted - ($wanted - $smallest) * $over / $room), $want, $least);
    }

    /**
     * @param  int[]  $widths
     */
    private static function wrapTable(array $widths, string $rows): string
    {
        return '<w:tbl><w:tblPr><w:tblStyle w:val="ReportTable"/><w:tblW w:w="5000" w:type="pct"/><w:tblLayout w:type="autofit"/>'
            . '<w:tblLook w:val="0020" w:firstRow="1" w:lastRow="0" w:firstColumn="0" w:lastColumn="0" w:noHBand="1" w:noVBand="1"/></w:tblPr><w:tblGrid>'
            . implode('', array_map(fn (int $width) => '<w:gridCol w:w="' . $width . '"/>', $widths)) . '</w:tblGrid>' . $rows . '</w:tbl>'
            . '<w:p><w:pPr><w:pStyle w:val="Gap"/></w:pPr></w:p>';
    }

    private static function cell(string $text, int $width, ?string $fill, string $tone, bool $bold, bool $right = false): string
    {
        return '<w:tc><w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/>' . ($fill !== null ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>' : '') . '</w:tcPr>'
            . '<w:p><w:pPr><w:pStyle w:val="TableText"/>' . ($right ? '<w:jc w:val="right"/>' : '') . '</w:pPr>' . self::runs($text, self::TONES[$tone] ?? null, $bold) . '</w:p></w:tc>';
    }

    /**
     * A paragraph of the text in a style (its lines kept, its tabs Word's).
     */
    private static function paragraph(string $text, ?string $style): string
    {
        return '<w:p>' . ($style !== null ? '<w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>' : '') . self::runs($text) . '</w:p>';
    }

    /**
     * The text as runs: a line break between its lines, a tab of Word's for each of its tabs.
     */
    private static function runs(string $text, ?string $colour = null, bool $bold = false): string
    {
        if ($text === '') {
            return '';
        }
        $properties = $bold || $colour !== null ? '<w:rPr>' . ($bold ? '<w:b/>' : '') . ($colour !== null ? '<w:color w:val="' . $colour . '"/>' : '') . '</w:rPr>' : '';
        $parts = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            $pieces = array_map(fn (string $piece) => $piece !== '' ? '<w:t xml:space="preserve">' . self::x($piece) . '</w:t>' : '', explode("\t", $line));
            $parts[] = implode('<w:tab/>', $pieces);
        }

        return '<w:r>' . $properties . implode('<w:br/>', $parts) . '</w:r>';
    }

    private static function footer(Report $report): string
    {
        $field = fn (string $name) => '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> ' . $name . ' </w:instrText></w:r>'
            . '<w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>1</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r>';

        return '<w:ftr xmlns:w="' . self::W . '" xmlns:r="' . self::R . '"><w:p><w:pPr><w:pStyle w:val="Footer"/></w:pPr>' . self::runs($report->title)
            . '<w:r><w:tab/></w:r><w:r><w:t xml:space="preserve">page </w:t></w:r>' . $field('PAGE') . '<w:r><w:t xml:space="preserve"> of </w:t></w:r>' . $field('NUMPAGES')
            . '</w:p></w:ftr>';
    }

    private static function styles(): string
    {
        $mono = '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas" w:eastAsia="Consolas" w:cs="Consolas"/>';
        $paragraph = fn (string $id, string $name, string $pPr, string $rPr, string $basedOn = 'Normal', bool $quick = false) => '<w:style w:type="paragraph" w:styleId="' . $id . '">'
            . '<w:name w:val="' . $name . '"/><w:basedOn w:val="' . $basedOn . '"/><w:next w:val="Normal"/>' . ($quick ? '<w:qFormat/>' : '')
            . ($pPr !== '' ? '<w:pPr>' . $pPr . '</w:pPr>' : '') . ($rPr !== '' ? '<w:rPr>' . $rPr . '</w:rPr>' : '') . '</w:style>';
        $note = fn (string $id, string $name, string $fill, string $border, string $colour, bool $bold = false) => $paragraph($id, $name,
            '<w:pBdr><w:left w:val="single" w:sz="24" w:space="6" w:color="' . $border . '"/></w:pBdr><w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>'
            . '<w:spacing w:before="60" w:after="140"/><w:ind w:left="160" w:right="80"/>', ($bold ? '<w:b/>' : '') . '<w:color w:val="' . $colour . '"/>');
        $code = fn (string $id, string $name, string $fill, string $colour) => $paragraph($id, $name, '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>',
            '<w:color w:val="' . $colour . '"/>', 'Code');

        return '<w:styles xmlns:w="' . self::W . '"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:eastAsia="Calibri" w:cs="Calibri"/>'
            . '<w:sz w:val="20"/><w:szCs w:val="20"/><w:lang w:val="en-GB"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="100" w:line="264" w:lineRule="auto"/></w:pPr>'
            . '</w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
            . $paragraph('Title', 'Title', '<w:spacing w:after="40"/>', '<w:b/><w:color w:val="0B3D5C"/><w:sz w:val="44"/><w:szCs w:val="44"/>', 'Normal', true)
            . $paragraph('Subtitle', 'Subtitle', '<w:spacing w:after="240"/>', '<w:color w:val="59636E"/><w:sz w:val="24"/><w:szCs w:val="24"/>', 'Normal', true)
            . $paragraph('Heading1', 'heading 1', '<w:keepNext/><w:keepLines/><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="2" w:color="D1D9E0"/></w:pBdr>'
                . '<w:spacing w:before="320" w:after="120"/><w:outlineLvl w:val="0"/>', '<w:b/><w:color w:val="0B3D5C"/><w:sz w:val="30"/><w:szCs w:val="30"/>', 'Normal', true)
            . $paragraph('Heading2', 'heading 2', '<w:keepNext/><w:keepLines/><w:spacing w:before="220" w:after="80"/><w:outlineLvl w:val="1"/>',
                '<w:b/><w:color w:val="1F2328"/><w:sz w:val="24"/><w:szCs w:val="24"/>', 'Normal', true)
            . $paragraph('Caption', 'caption', '<w:keepNext/><w:spacing w:before="120" w:after="40"/>', '<w:b/><w:color w:val="59636E"/><w:sz w:val="16"/><w:szCs w:val="16"/>')
            . $paragraph('Code', 'Code', '<w:shd w:val="clear" w:color="auto" w:fill="F6F8FA"/><w:spacing w:after="0" w:line="240" w:lineRule="auto"/>',
                $mono . '<w:sz w:val="16"/><w:szCs w:val="16"/>')
            . $code('CodeAdd', 'Code added', 'E6FFEC', '116329') . $code('CodeDel', 'Code removed', 'FFEBE9', 'A40E26') . $code('CodeHunk', 'Code hunk', 'DDF4FF', '0B5E8C')
            . $paragraph('Gap', 'Gap', '<w:spacing w:after="120" w:line="120" w:lineRule="exact"/>', '<w:sz w:val="8"/><w:szCs w:val="8"/>')
            . $paragraph('ListItem', 'List item', '<w:tabs><w:tab w:val="left" w:pos="360"/></w:tabs><w:spacing w:after="40"/><w:ind w:left="360" w:hanging="220"/>', '')
            . $paragraph('TableText', 'Table text', '<w:spacing w:after="0" w:line="240" w:lineRule="auto"/>', '<w:sz w:val="18"/><w:szCs w:val="18"/>')
            . $paragraph('Muted', 'Muted', '', '<w:color w:val="59636E"/>')
            . $note('NoteSuccess', 'Note success', 'DAFBE1', '1A7F37', '116329') . $note('NoteWarning', 'Note warning', 'FFF8C5', 'D4A72C', '6F4E00')
            . $note('NoteDanger', 'Note danger', 'FFEBE9', 'D1242F', 'A40E26', true)
            . $paragraph('Footer', 'footer', '<w:tabs><w:tab w:val="right" w:pos="' . self::TEXT_WIDTH . '"/></w:tabs><w:spacing w:after="0"/>',
                '<w:color w:val="59636E"/><w:sz w:val="16"/><w:szCs w:val="16"/>')
            . '<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/><w:uiPriority w:val="99"/><w:semiHidden/><w:unhideWhenUsed/>'
            . '<w:tblPr><w:tblInd w:w="0" w:type="dxa"/><w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/>'
            . '<w:right w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
            . '<w:style w:type="table" w:styleId="ReportTable"><w:name w:val="Report table"/><w:basedOn w:val="TableNormal"/><w:tblPr><w:tblBorders>'
            . implode('', array_map(fn (string $side) => '<w:' . $side . ' w:val="single" w:sz="4" w:space="0" w:color="D1D9E0"/>', ['top', 'left', 'bottom', 'right', 'insideH', 'insideV']))
            . '</w:tblBorders><w:tblCellMar><w:top w:w="40" w:type="dxa"/><w:left w:w="80" w:type="dxa"/><w:bottom w:w="40" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar>'
            . '</w:tblPr></w:style></w:styles>';
    }

    /**
     * Text for XML: escaped, and without the characters XML 1.0 cannot hold (control characters, invalid UTF-8).
     */
    public static function x(string $text): string
    {
        $text = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', mb_scrub($text, 'UTF-8'));

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
