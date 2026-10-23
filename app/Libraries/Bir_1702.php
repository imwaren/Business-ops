<?php

namespace App\Libraries;

use setasign\Fpdi\Fpdi;

/**
 * Overlay P&L / TIN values onto flattened BIR 1702 RAW templates.
 * Page size of the RAW forms is 612 x 936 (points). FPDF origin is top-left.
 * Overlay is red Helvetica for visual QA against the printed boxes.
 */
class Bir_1702
{
    protected $page_count = 0;

    public function __construct()
    {
        $this->_load_fpdi();
    }

    protected function _load_fpdi(): void
    {
        if (class_exists(Fpdi::class)) {
            return;
        }

        $vendor_fpdf = ROOTPATH . 'vendor/setasign/fpdf/fpdf.php';
        $vendor_fpdi = ROOTPATH . 'vendor/setasign/fpdi/src/autoload.php';
        $local_fpdf = APPPATH . 'ThirdParty/setasign/fpdf/fpdf.php';
        $local_fpdi = APPPATH . 'ThirdParty/setasign/fpdi/src/autoload.php';

        $fpdf = is_file($vendor_fpdf) ? $vendor_fpdf : $local_fpdf;
        $fpdi = is_file($vendor_fpdi) ? $vendor_fpdi : $local_fpdi;

        if (! is_file($fpdf) || ! is_file($fpdi)) {
            throw new \RuntimeException('FPDF/FPDI libraries are not available.');
        }

        require_once $fpdf;
        require_once $fpdi;
    }

    public function fill_1702_rt(string $output_path, array $data): string
    {
        $pdf = $this->_start_pdf($this->_template_path('1702-RT.pdf'));
        $map = $this->_map_1702_rt();
        $this->_write_page1($pdf, $data, $map, false);
        $this->_write_page2($pdf, $data, $map['page2']);
        $this->_copy_remaining_pages($pdf, 3);
        $this->_save($pdf, $output_path);

        return $output_path;
    }

    public function fill_1702q(string $output_path, array $data): string
    {
        $pdf = $this->_start_pdf($this->_template_path('1702Q.pdf'));
        $map = $this->_map_1702q();
        $this->_write_page1($pdf, $data, $map, true);
        $this->_write_page2($pdf, $data, $map['page2']);
        $this->_copy_remaining_pages($pdf, 3);
        $this->_save($pdf, $output_path);

        return $output_path;
    }

    protected function _start_pdf(string $template): Fpdi
    {
        $pdf = new Fpdi('P', 'pt', [612, 936]);
        $pdf->SetAutoPageBreak(false);
        $this->page_count = $pdf->setSourceFile($template);
        $pdf->AddPage();
        $pdf->useTemplate($this->_import_page($pdf, 1));
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(220, 0, 0);

        return $pdf;
    }

    protected function _write_page1(Fpdi $pdf, array $data, array $map, bool $is_quarterly): void
    {
        $this->_mark($pdf, $map['calendar']);

        $year = (int) ($data['year'] ?? date('Y'));
        $month = $is_quarterly
            ? (int) ($data['month'] ?? 12)
            : \App\Libraries\Bir_return_generator::RT_MONTH;
        $this->_chars($pdf, $map['year_mm'], str_pad((string) $month, 2, '0', STR_PAD_LEFT), 9);
        $this->_chars($pdf, $map['year_yy'], substr((string) $year, -2), 9);

        $this->_mark($pdf, $map['amended_no']);
        if (! empty($map['short_period_no'])) {
            $this->_mark($pdf, $map['short_period_no']);
        }

        if ($is_quarterly) {
            $quarter = (int) ($data['quarter'] ?? 1);
            $key = 'quarter_' . $quarter;
            if (! empty($map[$key])) {
                $this->_mark($pdf, $map[$key]);
            }
        }

        $this->_write_tin($pdf, $map['tin'], (string) ($data['tin'] ?? ''));
        $this->_boxed_line($pdf, $map['name'], strtoupper((string) ($data['name'] ?? '')));
        $this->_boxed_line($pdf, $map['address'], strtoupper((string) ($data['address'] ?? '')));
        if (! empty($data['zip']) && ! empty($map['zip'])) {
            $this->_chars($pdf, $map['zip'], substr((string) $data['zip'], 0, 4), 8);
        }

        $this->_mark($pdf, $map['itemized']);

        $this->_amount($pdf, $map['item_14'], $data['tax_due'] ?? 0);
        $this->_amount($pdf, $map['item_15'], $data['tax_credits'] ?? 0);
        $this->_amount($pdf, $map['item_16'], $data['net_tax_payable'] ?? 0);
        $this->_amount($pdf, $map['item_21'], $data['total_payable'] ?? 0);
    }

    protected function _write_page2(Fpdi $pdf, array $data, array $map): void
    {
        if ($this->page_count < 2) {
            return;
        }

        $pdf->AddPage();
        $pdf->useTemplate($this->_import_page($pdf, 2));
        $pdf->SetTextColor(220, 0, 0);

        $this->_amount($pdf, $map['item_29'], $data['net_revenues'] ?? 0);
        $this->_amount($pdf, $map['item_30'], $data['cost_of_sales'] ?? 0);
        $this->_amount($pdf, $map['item_31'], $data['gross_income'] ?? 0);
        $this->_amount($pdf, $map['item_34'], $data['deductions'] ?? 0);
        $this->_amount($pdf, $map['item_39'], $data['net_income'] ?? 0);
        $this->_chars($pdf, $map['item_40'], '25', 8);
        $this->_amount($pdf, $map['item_41'], $data['tax_due'] ?? 0);
        $this->_amount($pdf, $map['item_43'], $data['tax_due'] ?? 0);
    }

    /**
     * 1702-RT Jan 2018 ENCS — measured against the RAW 612x936 template.
     */
    protected function _map_1702_rt(): array
    {
        $amount = ['w' => 11.8, 'boxes' => 12];
        $page2_amount = ['w' => 12.3, 'boxes' => 12];

        return [
            'calendar' => ['x' => 40, 'y' => 138],
            'year_mm' => ['x' => 56, 'y' => 154, 'w' => 9.5],
            'year_yy' => ['x' => 94, 'y' => 154, 'w' => 9.5],
            'amended_no' => ['x' => 222, 'y' => 150],
            'short_period_no' => ['x' => 310, 'y' => 150],
            'tin' => ['x' => 268, 'y' => 190, 'w' => 11.8, 'gap' => 8.2],
            'name' => ['x' => 40, 'y' => 238, 'w' => 8.15],
            'address' => ['x' => 40, 'y' => 298, 'w' => 8.15, 'rows' => 2, 'row_h' => 18],
            'zip' => ['x' => 500, 'y' => 336, 'w' => 11],
            'itemized' => ['x' => 136, 'y' => 382],
            'item_14' => ['x' => 430, 'y' => 423] + $amount,
            'item_15' => ['x' => 430, 'y' => 443] + $amount,
            'item_16' => ['x' => 430, 'y' => 463] + $amount,
            'item_21' => ['x' => 430, 'y' => 571] + $amount,
            'page2' => [
                'item_29' => ['x' => 422, 'y' => 232] + $page2_amount,
                'item_30' => ['x' => 422, 'y' => 250] + $page2_amount,
                'item_31' => ['x' => 422, 'y' => 268] + $page2_amount,
                'item_34' => ['x' => 422, 'y' => 340] + $page2_amount,
                'item_39' => ['x' => 422, 'y' => 448] + $page2_amount,
                'item_40' => ['x' => 548, 'y' => 464, 'w' => 10.4],
                'item_41' => ['x' => 422, 'y' => 482] + $page2_amount,
                'item_43' => ['x' => 422, 'y' => 518] + $page2_amount,
            ],
        ];
    }

    /**
     * 1702Q Jan 2018 ENCS — same overlay style as 1702-RT.
     */
    protected function _map_1702q(): array
    {
        $amount = ['w' => 12.15, 'boxes' => 12];
        $page2_amount = ['w' => 12.15, 'boxes' => 12];

        return [
            'calendar' => ['x' => 90, 'y' => 138],
            'year_mm' => ['x' => 100, 'y' => 158, 'w' => 9.2],
            'year_yy' => ['x' => 138, 'y' => 158, 'w' => 9.2],
            'quarter_1' => ['x' => 264, 'y' => 138],
            'quarter_2' => ['x' => 318, 'y' => 138],
            'quarter_3' => ['x' => 372, 'y' => 138],
            'quarter_4' => ['x' => 426, 'y' => 138],
            'amended_no' => ['x' => 506, 'y' => 138],
            'short_period_no' => null,
            'tin' => ['x' => 266, 'y' => 208, 'w' => 11.8, 'gap' => 8.2],
            'name' => ['x' => 40, 'y' => 224, 'w' => 8.15],
            'address' => ['x' => 40, 'y' => 262, 'w' => 8.15, 'rows' => 2, 'row_h' => 16],
            'zip' => ['x' => 500, 'y' => 300, 'w' => 11],
            'itemized' => ['x' => 226, 'y' => 350],
            'item_14' => ['x' => 430, 'y' => 399] + $amount,
            'item_15' => ['x' => 430, 'y' => 519] + $amount,
            'item_16' => ['x' => 430, 'y' => 539] + $amount,
            'item_21' => ['x' => 430, 'y' => 615] + $amount,
            'page2' => [
                'item_29' => ['x' => 422, 'y' => 242] + $page2_amount,
                'item_30' => ['x' => 422, 'y' => 260] + $page2_amount,
                'item_31' => ['x' => 422, 'y' => 278] + $page2_amount,
                'item_34' => ['x' => 422, 'y' => 350] + $page2_amount,
                'item_39' => ['x' => 422, 'y' => 458] + $page2_amount,
                'item_40' => ['x' => 546, 'y' => 476, 'w' => 10.4],
                'item_41' => ['x' => 422, 'y' => 492] + $page2_amount,
                'item_43' => ['x' => 422, 'y' => 528] + $page2_amount,
            ],
        ];
    }

    protected function _mark(Fpdi $pdf, $pos): void
    {
        if (! $pos) {
            return;
        }
        $pdf->SetFont('ZapfDingbats', '', 10);
        $pdf->SetXY($pos['x'], $pos['y']);
        $pdf->Cell(9, 9, chr(51), 0, 0, 'C');
        $pdf->SetFont('Helvetica', '', 8);
    }

    protected function _write_tin(Fpdi $pdf, array $pos, string $tin): void
    {
        $digits = preg_replace('/\D+/', '', $tin);
        $tin9 = substr($digits, 0, 9);
        $x = $pos['x'];
        $w = $pos['w'] ?? 12.2;
        $gap = $pos['gap'] ?? 8.2;
        $pdf->SetFont('Helvetica', 'B', 8);
        for ($i = 0; $i < strlen($tin9); $i++) {
            if ($i > 0 && $i % 3 === 0) {
                $x += $gap;
            }
            $pdf->SetXY($x, $pos['y']);
            $pdf->Cell($w, 9, $tin9[$i], 0, 0, 'C');
            $x += $w;
        }
    }

    protected function _chars(Fpdi $pdf, array $pos, string $text, float $size): void
    {
        $w = $pos['w'] ?? 11;
        $pdf->SetFont('Helvetica', 'B', $size);
        $x = $pos['x'];
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $pdf->SetXY($x, $pos['y']);
            $pdf->Cell($w, 9, $text[$i], 0, 0, 'C');
            $x += $w;
        }
    }

    protected function _boxed_line(Fpdi $pdf, array $pos, string $text): void
    {
        $w = $pos['w'] ?? 8.4;
        $row_h = $pos['row_h'] ?? 18;
        $rows = max(1, (int) ($pos['rows'] ?? 1));
        $max_cols = max(1, (int) floor((588 - $pos['x']) / $w));
        $pdf->SetFont('Helvetica', 'B', 7.5);
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $i = 0;
        $count = count($chars);
        for ($r = 0; $r < $rows && $i < $count; $r++) {
            $x = $pos['x'];
            $y = $pos['y'] + ($r * $row_h);
            for ($c = 0; $c < $max_cols && $i < $count; $c++, $i++) {
                $pdf->SetXY($x, $y);
                $pdf->Cell($w, 9, $chars[$i], 0, 0, 'C');
                $x += $w;
            }
        }
    }

    protected function _amount(Fpdi $pdf, array $pos, $amount): void
    {
        $pesos = (int) round((float) $amount, 0, PHP_ROUND_HALF_UP);
        $negative = $pesos < 0;
        $digits = (string) abs($pesos);
        if ($negative) {
            $digits = '-' . $digits;
        }

        $boxes = (int) ($pos['boxes'] ?? 0);
        if ($boxes < 1) {
            $boxes = max(strlen($digits), 1);
        }

        $w = $pos['w'] ?? 12.15;
        $chars = str_split($digits);
        if (count($chars) > $boxes) {
            $chars = array_slice($chars, -$boxes);
        }

        $pdf->SetFont('Helvetica', 'B', 8);
        $start = $pos['x'] + (($boxes - count($chars)) * $w);
        foreach ($chars as $i => $ch) {
            $pdf->SetXY($start + ($i * $w), $pos['y']);
            $pdf->Cell($w, 9, $ch, 0, 0, 'C');
        }
    }

    protected function _import_page(Fpdi $pdf, int $page)
    {
        return $pdf->importPage($page);
    }

    protected function _copy_remaining_pages(Fpdi $pdf, int $from): void
    {
        for ($page = $from; $page <= $this->page_count; $page++) {
            $pdf->AddPage();
            $pdf->useTemplate($this->_import_page($pdf, $page));
        }
    }

    protected function _template_path(string $file): string
    {
        $path = APPPATH . 'ThirdParty/bir_templates/' . $file;
        if (! is_file($path)) {
            throw new \RuntimeException('BIR template not found: ' . $file);
        }

        return $path;
    }

    protected function _save(Fpdi $pdf, string $output_path): void
    {
        $dir = dirname($output_path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $pdf->Output('F', $output_path);
    }
}
