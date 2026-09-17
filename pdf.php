<?php
/**
 * A small PDF writer.
 *
 * Enough of PDF 1.4 to lay out a statement: text in the base-14 fonts, rules,
 * filled boxes and tables, across as many pages as the rows need. No composer,
 * no extension beyond what PHP ships with — the same approach totp.php and
 * btc.php take.
 *
 * Coordinates are given the way people think about a page: x from the left, y
 * from the *top*, in points (72 per inch). PDF itself measures y from the
 * bottom, and this flips it for you.
 *
 *   $pdf = new HxPdf();
 *   $pdf->text('Statement', 40, 40, ['size' => 18, 'font' => 'bold']);
 *   $pdf->rule(40, 70, 515);
 *   file_put_contents('out.pdf', $pdf->render());
 */

class HxPdf
{
    // A4 in points.
    public const WIDTH = 595.28;
    public const HEIGHT = 841.89;

    private const FONTS = [
        'regular' => 'Helvetica',
        'bold' => 'Helvetica-Bold',
        'oblique' => 'Helvetica-Oblique',
        'mono' => 'Courier',
        'mono-bold' => 'Courier-Bold',
    ];

    /** @var string[] one content stream per page */
    private array $pages = [];
    private string $current = '';
    private array $meta;

    public function __construct(array $meta = [])
    {
        $this->meta = $meta + ['title' => 'Statement', 'author' => 'HarbourX'];
    }

    /* ------------------------------------------------------------- pages -- */

    public function newPage(): void
    {
        $this->pages[] = $this->current;
        $this->current = '';
    }

    public function pageCount(): int
    {
        return count($this->pages) + 1;
    }

    /* -------------------------------------------------------------- text -- */

    /**
     * Draw a line of text. $options: size, font (a key of self::FONTS),
     * colour ([r,g,b] 0..1), and align ('left'|'right'|'centre') with width.
     */
    public function text(string $value, float $x, float $y, array $options = []): void
    {
        $size = (float)($options['size'] ?? 10);
        $font = self::FONTS[$options['font'] ?? 'regular'] ?? 'Helvetica';
        $key = array_search($font, self::FONTS, true) ?: 'regular';

        $align = $options['align'] ?? 'left';
        if ($align !== 'left') {
            $width = $this->textWidth($value, $size, $key);
            $box = (float)($options['width'] ?? 0);
            if ($align === 'right') $x += $box - $width;
            elseif ($align === 'centre') $x += ($box - $width) / 2;
        }

        $this->current .= $this->colourOp($options['colour'] ?? [0, 0, 0], false);
        $this->current .= sprintf(
            "BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
            $this->fontResource($key),
            $size,
            $x,
            self::HEIGHT - $y,
            $this->escape($value)
        );
    }

    /**
     * Draw text wrapped to a width, returning the y just past the last line.
     */
    public function paragraph(string $value, float $x, float $y, float $width, array $options = []): float
    {
        $size = (float)($options['size'] ?? 10);
        $leading = (float)($options['leading'] ?? $size * 1.45);
        $key = $options['font'] ?? 'regular';

        $line = '';
        foreach (preg_split('/\s+/', trim($value)) as $word) {
            $attempt = $line === '' ? $word : $line . ' ' . $word;
            if ($this->textWidth($attempt, $size, $key) > $width && $line !== '') {
                $this->text($line, $x, $y, $options);
                $y += $leading;
                $line = $word;
            } else {
                $line = $attempt;
            }
        }
        if ($line !== '') {
            $this->text($line, $x, $y, $options);
            $y += $leading;
        }
        return $y;
    }

    /* ---------------------------------------------------------- graphics -- */

    public function rule(float $x, float $y, float $width, array $options = []): void
    {
        $thickness = (float)($options['thickness'] ?? 0.6);
        $this->current .= $this->colourOp($options['colour'] ?? [0.80, 0.84, 0.87], false);
        $this->current .= sprintf(
            "%.2F w %.2F %.2F m %.2F %.2F l S\n",
            $thickness,
            $x, self::HEIGHT - $y,
            $x + $width, self::HEIGHT - $y
        );
    }

    public function box(float $x, float $y, float $width, float $height, array $options = []): void
    {
        $this->current .= $this->colourOp($options['fill'] ?? [0.96, 0.97, 0.98], true);
        $this->current .= sprintf(
            "%.2F %.2F %.2F %.2F re f\n",
            $x, self::HEIGHT - $y - $height, $width, $height
        );
    }

    /* ------------------------------------------------------------ metrics -- */

    /**
     * Width of a string at a size. Helvetica widths are approximated from the
     * AFM average per character class — close enough to wrap and right-align
     * text, which is all this needs it for. Courier is exactly 0.6 em.
     */
    public function textWidth(string $value, float $size, string $font = 'regular'): float
    {
        if (str_starts_with($font, 'mono')) {
            return strlen($value) * 0.6 * $size;
        }
        $units = 0;
        $bold = $font === 'bold';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === ' ') { $units += 278; continue; }
            if (ctype_upper($char)) { $units += $bold ? 722 : 667; continue; }
            if (ctype_digit($char)) { $units += 556; continue; }
            if (strpos('iljt.,:;!|\'', $char) !== false) { $units += $bold ? 333 : 244; continue; }
            if (strpos('mwMW', $char) !== false) { $units += 889; continue; }
            $units += $bold ? 611 : 545;
        }
        return $units / 1000 * $size;
    }

    /* ------------------------------------------------------------- render -- */

    public function render(): string
    {
        $streams = $this->pages;
        $streams[] = $this->current;

        $objects = [];
        $pageCount = count($streams);

        // 1 catalog, 2 pages tree, then per page: page object + content stream,
        // then the font objects.
        $firstPageObj = 3;
        $fontBase = $firstPageObj + $pageCount * 2;

        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = ($firstPageObj + $i * 2) . ' 0 R';
        }

        $fontKeys = array_keys(self::FONTS);
        $fontRefs = '';
        foreach ($fontKeys as $i => $key) {
            $fontRefs .= sprintf('/F%d %d 0 R ', $i + 1, $fontBase + $i);
        }

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = sprintf(
            "<< /Type /Pages /Count %d /Kids [%s] >>",
            $pageCount,
            implode(' ', $kids)
        );

        for ($i = 0; $i < $pageCount; $i++) {
            $pageObj = $firstPageObj + $i * 2;
            $contentObj = $pageObj + 1;
            $objects[$pageObj] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] "
                . "/Resources << /Font << %s>> >> /Contents %d 0 R >>",
                self::WIDTH,
                self::HEIGHT,
                $fontRefs,
                $contentObj
            );
            $stream = $streams[$i];
            $objects[$contentObj] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($stream),
                $stream
            );
        }

        foreach ($fontKeys as $i => $key) {
            $objects[$fontBase + $i] = sprintf(
                "<< /Type /Font /Subtype /Type1 /BaseFont /%s /Encoding /WinAnsiEncoding >>",
                self::FONTS[$key]
            );
        }

        $infoObj = $fontBase + count($fontKeys);
        $objects[$infoObj] = sprintf(
            "<< /Title %s /Author %s /Producer (HarbourX) /CreationDate (D:%s) >>",
            $this->textString((string)$this->meta['title']),
            $this->textString((string)$this->meta['author']),
            gmdate('YmdHis') . 'Z'
        );

        // --- assemble, recording byte offsets for the xref table ------------
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        ksort($objects);
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($out);
            $out .= "$number 0 obj\n$body\nendobj\n";
        }

        $xrefAt = strlen($out);
        $count = max(array_keys($objects)) + 1;
        $out .= "xref\n0 $count\n0000000000 65535 f \n";
        for ($n = 1; $n < $count; $n++) {
            $out .= isset($offsets[$n])
                ? sprintf("%010d 00000 n \n", $offsets[$n])
                : "0000000000 65535 f \n";
        }
        $out .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF\n",
            $count,
            $infoObj,
            $xrefAt
        );

        return $out;
    }

    /* ------------------------------------------------------------ private -- */

    private function fontResource(string $key): string
    {
        $index = array_search($key, array_keys(self::FONTS), true);
        return 'F' . (($index === false ? 0 : $index) + 1);
    }

    private function colourOp(array $rgb, bool $fill): string
    {
        [$r, $g, $b] = array_pad($rgb, 3, 0);
        return sprintf("%.3F %.3F %.3F %s\n", $r, $g, $b, $fill ? 'rg' : 'rg');
    }

    /**
     * A PDF *text string* — the kind used in the document info dictionary — is
     * not encoded like page content. Page content is read through the font's
     * WinAnsiEncoding; an info string is PDFDocEncoding unless it opens with a
     * UTF-16BE byte order mark. Escaping an em dash to Windows-1252 0x97 and
     * putting it here made viewers show the title as "statement Š February".
     *
     * Anything outside ASCII therefore goes out as a UTF-16BE hex string, which
     * every viewer reads correctly whatever is in it.
     */
    private function textString(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return '(' . strtr($value, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
        }
        $utf16 = @iconv('UTF-8', 'UTF-16BE', $value);
        if ($utf16 === false) $utf16 = $value;
        return '<FEFF' . strtoupper(bin2hex($utf16)) . '>';
    }

    /**
     * Page content is drawn with WinAnsiEncoding fonts, so it really is Latin-1
     * here, and backslash and brackets have to be escaped or the file will not
     * parse.
     */
    private function escape(string $value): string
    {
        $latin = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
        if ($latin === false) $latin = $value;
        $latin = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $latin);
        return strtr($latin, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }
}
