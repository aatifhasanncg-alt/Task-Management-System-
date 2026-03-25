<?php
// vendor/SimplePDF.php
// Minimal PDF generator — no external dependencies
// Drop-in replacement for TCPDF for basic table reports

class TCPDF {
    protected array  $pages      = [];
    protected int    $page       = 0;
    protected float  $x          = 10;
    protected float  $y          = 10;
    protected float  $w          = 297; // A4 landscape
    protected float  $h          = 210;
    protected float  $lMargin    = 12;
    protected float  $rMargin    = 12;
    protected float  $tMargin    = 28;
    protected float  $bMargin    = 20;
    protected string $fontFamily = 'Helvetica';
    protected int    $fontSize   = 10;
    protected string $fontStyle  = '';
    protected array  $fillColor  = [255, 255, 255];
    protected array  $textColor  = [0, 0, 0];
    protected array  $drawColor  = [0, 0, 0];
    protected float  $lineWidth  = 0.2;
    protected bool   $printHeader = false;
    protected bool   $printFooter = false;
    protected string $title      = '';
    protected string $creator    = '';
    protected string $author     = '';
    protected bool   $autoPageBreak = true;
    protected float  $pageBreakTrigger = 190;
    protected array  $pageLinks  = [];

    // Buffer for current page content
    protected string $buffer = '';
    protected array  $pageBuffers = [];

    public function __construct(
        string $orientation = 'L',
        string $unit = 'mm',
        string $format = 'A4',
        bool $unicode = true,
        string $encoding = 'UTF-8'
    ) {
        if ($orientation === 'L') {
            $this->w = 297;
            $this->h = 210;
        } else {
            $this->w = 210;
            $this->h = 297;
        }
        $this->pageBreakTrigger = $this->h - $this->bMargin;
    }

    public function SetCreator(string $c): void  { $this->creator = $c; }
    public function SetAuthor(string $a): void   { $this->author  = $a; }
    public function SetTitle(string $t): void    { $this->title   = $t; }
    public function setPrintHeader(bool $b): void { $this->printHeader = $b; }
    public function setPrintFooter(bool $b): void { $this->printFooter = $b; }
    public function SetHeaderMargin(float $m): void {}
    public function SetFooterMargin(float $m): void {}
    public function SetAutoPageBreak(bool $auto, float $margin = 0): void {
        $this->autoPageBreak      = $auto;
        $this->pageBreakTrigger   = $this->h - $margin;
    }
    public function SetMargins(float $l, float $t, float $r = -1): void {
        $this->lMargin = $l;
        $this->tMargin = $t;
        $this->rMargin = ($r == -1) ? $l : $r;
        $this->x = $l;
    }
    public function getPageWidth(): float  { return $this->w; }
    public function getPageHeight(): float { return $this->h; }
    public function GetX(): float { return $this->x; }
    public function GetY(): float { return $this->y; }
    public function SetX(float $x): void  { $this->x = $x; }
    public function SetY(float $y): void  { $this->y = $y; $this->x = $this->lMargin; }
    public function SetXY(float $x, float $y): void { $this->x = $x; $this->y = $y; }
    public function getAliasNumPage(): string  { return '{nb}'; }
    public function getAliasNbPages(): string  { return '{pnb}'; }

    public function SetFont(string $family, string $style = '', float $size = 0): void {
        $this->fontFamily = $family;
        $this->fontStyle  = strtoupper($style);
        if ($size > 0) $this->fontSize = (int)$size;
    }

    public function SetFillColor(int $r, int $g = -1, int $b = -1): void {
        $this->fillColor = [$r, $g < 0 ? $r : $g, $b < 0 ? $r : $b];
    }

    public function SetTextColor(int $r, int $g = -1, int $b = -1): void {
        $this->textColor = [$r, $g < 0 ? $r : $g, $b < 0 ? $r : $b];
    }

    public function SetDrawColor(int $r, int $g = -1, int $b = -1): void {
        $this->drawColor = [$r, $g < 0 ? $r : $g, $b < 0 ? $r : $b];
    }

    public function SetLineWidth(float $w): void { $this->lineWidth = $w; }

    public function AddPage(string $orientation = ''): void {
        if ($this->page > 0) {
            $this->pageBuffers[$this->page] = $this->buffer;
        }
        $this->page++;
        $this->pages[$this->page] = '';
        $this->buffer = '';
        $this->x = $this->lMargin;
        $this->y = $this->tMargin;
        if ($this->printHeader) $this->Header();
    }

    // Override in subclass
    public function Header(): void {}
    public function Footer(): void {}

    public function Ln(float $h = 5): void { $this->y += $h; $this->x = $this->lMargin; }

    public function Rect(float $x, float $y, float $w, float $h, string $style = ''): void {
        $this->buffer .= $this->_rect($x, $y, $w, $h, $style);
    }

    public function RoundedRect(
        float $x, float $y, float $w, float $h,
        float $r, string $round_corner = '1111', string $style = ''
    ): void {
        // Draw as regular rect for simplicity
        $this->Rect($x, $y, $w, $h, $style);
    }

    public function Line(float $x1, float $y1, float $x2, float $y2): void {
        [$dr, $dg, $db] = $this->drawColor;
        $this->buffer .= sprintf(
            "%.3f %.3f %.3f RG %.3f w %.3f %.3f m %.3f %.3f l S\n",
            $dr/255, $dg/255, $db/255,
            $this->lineWidth * 2.835,
            $x1 * 2.835, ($this->h - $y1) * 2.835,
            $x2 * 2.835, ($this->h - $y2) * 2.835
        );
    }

    public function Cell(
        float $w, float $h = 0,
        string $txt = '', $border = 0,
        int $ln = 0, string $align = 'L',
        bool $fill = false, string $link = ''
    ): void {
        $x = $this->x;
        $y = $this->y;

        // Check page break
        if ($this->autoPageBreak && ($y + $h) > $this->pageBreakTrigger && $this->page > 0) {
            $this->pageBuffers[$this->page] = $this->buffer;
            $this->page++;
            $this->pages[$this->page] = '';
            $this->buffer = '';
            $this->x = $this->lMargin;
            $this->y = $this->tMargin;
            $x = $this->x;
            $y = $this->y;
            if ($this->printHeader) $this->Header();
        }

        // Fill
        if ($fill) {
            [$fr, $fg, $fb] = $this->fillColor;
            $this->buffer .= sprintf(
                "%.3f %.3f %.3f rg %.3f %.3f %.3f %.3f re f\n",
                $fr/255, $fg/255, $fb/255,
                $x * 2.835, ($this->h - $y - $h) * 2.835,
                $w * 2.835, $h * 2.835
            );
        }

        // Border
        if ($border === 1 || $border === '1') {
            [$dr, $dg, $db] = $this->drawColor;
            $this->buffer .= sprintf(
                "%.3f %.3f %.3f RG %.3f w %.3f %.3f %.3f %.3f re S\n",
                $dr/255, $dg/255, $db/255,
                $this->lineWidth * 2.835,
                $x * 2.835, ($this->h - $y - $h) * 2.835,
                $w * 2.835, $h * 2.835
            );
        }

        // Text
        if ($txt !== '') {
            [$tr, $tg, $tb] = $this->textColor;
            $fontName = $this->_getFontName();
            $fontSize = $this->fontSize;

            // Clip text to cell width
            $maxChars = max(1, (int)($w / ($fontSize * 0.45)));
            if (mb_strlen($txt) > $maxChars) {
                $txt = mb_substr($txt, 0, $maxChars - 1) . '…';
            }

            // Calculate x position based on alignment
            $textWidth  = mb_strlen($txt) * $fontSize * 0.45;
            $padding    = 1.5;
            if ($align === 'C') {
                $tx = $x + ($w - $textWidth) / 2;
            } elseif ($align === 'R') {
                $tx = $x + $w - $textWidth - $padding;
            } else {
                $tx = $x + $padding;
            }

            $ty = $y + $h - ($h - $fontSize * 0.35) / 2 - $fontSize * 0.1;

            $escapedTxt = $this->_escapeText($txt);
            $this->buffer .= sprintf(
                "%.3f %.3f %.3f rg BT /%s %d Tf %.3f %.3f Td (%s) Tj ET\n",
                $tr/255, $tg/255, $tb/255,
                $fontName, $fontSize,
                $tx * 2.835, ($this->h - $ty) * 2.835,
                $escapedTxt
            );
        }

        // Move position
        if ($ln == 0) {
            $this->x += $w;
        } elseif ($ln == 1) {
            $this->x  = $this->lMargin;
            $this->y += $h;
        } elseif ($ln == 2) {
            $this->x += $w;
        }
    }

    public function MultiCell(
        float $w, float $h, string $txt,
        $border = 0, string $align = 'L', bool $fill = false
    ): void {
        // Split into lines and render each
        $lines = explode("\n", wordwrap($txt, (int)($w / ($this->fontSize * 0.45)), "\n", true));
        foreach ($lines as $line) {
            $this->Cell($w, $h, $line, $border, 1, $align, $fill);
        }
    }

    protected function _getFontName(): string {
        $map = [
            'helvetica'  => [''=>'Helvetica',  'B'=>'Helvetica-Bold', 'I'=>'Helvetica-Oblique', 'BI'=>'Helvetica-BoldOblique'],
            'times'      => [''=>'Times-Roman','B'=>'Times-Bold',     'I'=>'Times-Italic',      'BI'=>'Times-BoldItalic'],
            'courier'    => [''=>'Courier',    'B'=>'Courier-Bold',   'I'=>'Courier-Oblique',   'BI'=>'Courier-BoldOblique'],
        ];
        $family = strtolower($this->fontFamily);
        return $map[$family][$this->fontStyle] ?? 'Helvetica';
    }

    protected function _rect(float $x, float $y, float $w, float $h, string $style): string {
        [$fr, $fg, $fb] = $this->fillColor;
        [$dr, $dg, $db] = $this->drawColor;
        $op = match($style) {
            'F'  => 'f',
            'DF','FD' => 'B',
            default  => 'S',
        };
        $fill_str = ($style === 'F' || $style === 'DF' || $style === 'FD')
            ? sprintf("%.3f %.3f %.3f rg ", $fr/255, $fg/255, $fb/255)
            : '';
        return sprintf(
            "%s%.3f %.3f %.3f RG %.3f w %.3f %.3f %.3f %.3f re %s\n",
            $fill_str,
            $dr/255, $dg/255, $db/255,
            $this->lineWidth * 2.835,
            $x * 2.835, ($this->h - $y - $h) * 2.835,
            $w * 2.835, $h * 2.835, $op
        );
    }

    protected function _escapeText(string $txt): string {
        $txt = str_replace(['\\','(',')',"\r"], ['\\\\','\\(','\\)',''], $txt);
        // Remove non-ASCII for basic PDF compatibility
        $txt = preg_replace('/[^\x20-\x7E]/', '?', $txt);
        return $txt;
    }

    public function Output(string $name = '', string $dest = 'I'): string {
        // Save last page buffer
        if ($this->page > 0) {
            $this->pageBuffers[$this->page] = $this->buffer;
        }
        // Add footers
        if ($this->printFooter) {
            $savedPage = $this->page;
            for ($i = 1; $i <= $savedPage; $i++) {
                $this->page   = $i;
                $this->buffer = $this->pageBuffers[$i] ?? '';
                $this->x = $this->lMargin;
                $this->y = $this->h - $this->bMargin;
                $this->Footer();
                $this->pageBuffers[$i] = $this->buffer;
            }
            $this->page = $savedPage;
        }

        $pdf = $this->_buildPDF();
        // Replace page number aliases
        $totalPages = $this->page;
        $pdf = str_replace(
            [$this->_escapeText('{nb}'), $this->_escapeText('{pnb}')],
            [$totalPages, $totalPages],
            $pdf
        );

        if ($dest === 'D' || $dest === 'I') {
            header('Content-Type: application/pdf');
            if ($dest === 'D') {
                header('Content-Disposition: attachment; filename="' . basename($name) . '"');
            }
            echo $pdf;
            return '';
        }
        return $pdf;
    }

    protected function _buildPDF(): string {
        $pageCount = $this->page;
        $pdf       = "%PDF-1.4\n";
        $offsets   = [];
        $objNum    = 0;

        // Font objects (standard PDF fonts — no embedding needed)
        $fonts = [
            'Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique',
            'Times-Roman', 'Times-Bold', 'Courier',
        ];
        $fontObjNums = [];
        foreach ($fonts as $font) {
            $objNum++;
            $fontObjNums[$font] = $objNum;
            $offsets[$objNum]   = strlen($pdf);
            $pdf .= "{$objNum} 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /{$font} /Encoding /WinAnsiEncoding >>\nendobj\n";
        }

        // Font resource dictionary
        $fontResources = '';
        foreach ($fontObjNums as $name => $num) {
            $safeKey = str_replace(['-', ' '], '', $name);
            $fontResources .= "/{$safeKey} {$num} 0 R ";
        }

        // Page content streams
        $contentObjNums = [];
        for ($p = 1; $p <= $pageCount; $p++) {
            $objNum++;
            $contentObjNums[$p] = $objNum;
            $offsets[$objNum]   = strlen($pdf);
            $stream = $this->pageBuffers[$p] ?? '';
            $pdf .= "{$objNum} 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream\nendobj\n";
        }

        // Page objects
        $pageObjNums = [];
        for ($p = 1; $p <= $pageCount; $p++) {
            $objNum++;
            $pageObjNums[$p] = $objNum;
        }

        // Pages parent object number
        $pagesObjNum = $objNum + 1;

        // Write page objects
        for ($p = 1; $p <= $pageCount; $p++) {
            $offsets[$pageObjNums[$p]] = strlen($pdf);
            $wPt = $this->w * 2.835;
            $hPt = $this->h * 2.835;
            $pdf .= "{$pageObjNums[$p]} 0 obj\n";
            $pdf .= "<< /Type /Page /Parent {$pagesObjNum} 0 R\n";
            $pdf .= "/MediaBox [0 0 {$wPt} {$hPt}]\n";
            $pdf .= "/Contents {$contentObjNums[$p]} 0 R\n";
            $pdf .= "/Resources << /Font << {$fontResources}>> >>\n";
            $pdf .= ">>\nendobj\n";
        }

        // Pages object
        $objNum++;
        $offsets[$objNum] = strlen($pdf);
        $kids = '';
        for ($p = 1; $p <= $pageCount; $p++) {
            $kids .= $pageObjNums[$p] . " 0 R ";
        }
        $pdf .= "{$objNum} 0 obj\n<< /Type /Pages /Kids [{$kids}] /Count {$pageCount} >>\nendobj\n";

        // Catalog
        $objNum++;
        $catalogObjNum    = $objNum;
        $offsets[$objNum] = strlen($pdf);
        $pdf .= "{$objNum} 0 obj\n<< /Type /Catalog /Pages {$pagesObjNum} 0 R >>\nendobj\n";

        // Info
        $objNum++;
        $offsets[$objNum] = strlen($pdf);
        $pdf .= "{$objNum} 0 obj\n<< /Title (" . $this->_escapeText($this->title) . ") /Creator (MISPro) >>\nendobj\n";

        // Cross-reference table
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . ($objNum + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $objNum; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        // Trailer
        $pdf .= "trailer\n<< /Size " . ($objNum + 1) . " /Root {$catalogObjNum} 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }
}