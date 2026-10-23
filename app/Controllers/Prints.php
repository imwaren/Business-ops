<?php
namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Print_log;
use Exception;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector; // Added import for NetworkPrintConnector
use Mike42\Escpos\Printer;

class Prints extends MYT_Controller
{
    /**
     * An array of helpers to be loaded automatically upon
     * class instantiation. These helpers will be available
     * to all other controllers that extend BaseController.
     *
     * @var array
     */
    protected $helpers = ['form', 'text'];
    protected $print_log;

    /**
     * Constructor
     *
     */
    public function initController($request, $response, $logger)
    {
        // Do Not Edit This Line
        parent::initController($request, $response, $logger);
        $this->_load_essentials();
    }

    /**
     * Load Essentials
     */
    public function _load_essentials()
    {

        //models
        $this->print_log = new Print_log();
    }

    /**
     * Generate Dot Matrix Receipt
     */
    public function generate_billing_dotmatrix()
    {
        /**
         * $data should contain keys like:
         * - company_name, vat_reg, min, sn, ptu_no
         * - invoice_no, date_time
         * - items => array of ['qty' => , 'name' => , 'amount' => ]
         * - subtotal, total_discount, vat_exemption, total, payment_type, paid_amount, change,
         *   vat_sales, vat_exempt_sales, zero_rated_sales, total_vat, total_discount, vat_exempt, total_amount,
         * - cashier_code, senior_id, customer_name, amount, signature_date (EPSON U200)
         */
        $data = [
            'printer'          => 'Cashier POS',
            'company_name'     => 'Clinica Prime Cebu, Incorporated',
            'company_address'  => '2nd Floor IT Center 1, Oakridge Business Park, AS Fortuna Street Banilad, '."\n".'6014 Mandaue City, Cebu',
            'vat_reg'          => '601-924-809-00000',
            'min'              => $this->request->getVar('min'),
            'sn'               => $this->request->getVar('sn'),
            'ptu_no'           => $this->request->getVar('ptu_no'),
            'invoice_no'       => $this->request->getVar('invoice_no'),
            'date_time'        => $this->request->getVar('date_time'),
            'items'            => json_decode($this->request->getVar('items'), true), // Expecting JSON array
            'payment_multiples'            => json_decode($this->request->getVar('payment_multiples'), true), // Expecting JSON array
            'total_items'      => $this->request->getVar('total_items'),
            'subtotal'         => $this->request->getVar('subtotal'),
            'total_discount'   => $this->request->getVar('total_discount'), //not required
            'vat_exemption'    => $this->request->getVar('vat_exemption'),
            'vat_exempt'       => $this->request->getVar('vat_exempt'),
            'total'            => $this->request->getVar('total'),
            'payment_type'     => $this->request->getVar('payment_type'),
            'paid_amount'      => $this->request->getVar('paid_amount'),
            'change'           => $this->request->getVar('change'),
            'vat_sales'        => $this->request->getVar('vat_sales'),
            'vat_exempt_sales' => $this->request->getVar('vat_exempt_sales'),
            'zero_rated_sales' => $this->request->getVar('zero_rated_sales'),
            'total_vat'        => $this->request->getVar('total_vat'),
            'total_amount'     => $this->request->getVar('total_amount'),
            'cashier_code'     => $this->request->getVar('cashier_code'),
            'cashier_name'     => $this->request->getVar('cashier_name'),
            'senior_id'        => $this->request->getVar('senior_id'), //not required
            'customer_name'    => $this->request->getVar('customer_name'), //not required
            'amount'           => $this->request->getVar('amount'),
            'reprint'          => $this->request->getVar('reprint') === 'true' ? true : false,
            'reciept_type'     => $this->request->getVar('reciept_type') ?? 'billing',
        ];

        try {
            $connector = new WindowsPrintConnector($data['printer']);
            $printer   = new Printer($connector);

            // --- Header ---
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text($data['company_name'] . "\n");
            $printer->text($data['company_address'] . "\n");
            $printer->text("VAT Reg TIN: " . $data['vat_reg'] . "\n");
            $printer->text("MIN: " . $data['min'] . "\n");
            $printer->text("SN: " . $data['sn'] . "\n");
            $printer->text("PTU No: " . $data['ptu_no'] . "\n\n");

            if ($data['reprint']) {
                $printer->setJustification(Printer::JUSTIFY_CENTER);
                $printer->setEmphasis(true);
                $printer->text("** REPRINT **\n\n");
                $printer->setEmphasis(false);
            }

            $printer->setJustification(Printer::JUSTIFY_LEFT);
            $printer->text("INVOICE: " . str_pad($data['invoice_no'], 10, " ", STR_PAD_LEFT) . "\n");
            $printer->text($data['date_time'] . "\n\n");

            // --- Items Header ---
            $printer->text(str_pad("ITEM", 34) . "AMOUNT\n");

            $descWidth   = 30;
            $amountWidth = 10;

            // --- Items ---
            // foreach ($data['items'] as $item) {
            //     // Format: "1 x HBAlC" (qty x name)
            //     $itemName = substr($item['quantity'] . " x " . strtoupper($item['name']), 0, $descWidth);
            //     $printer->text(str_pad($itemName, 30)); // Left align item name

            //     // Format amount with commas and 2 decimals, right aligned
            //     $amountFormatted = number_format($item['amount'], 2);
            //     $printer->text(str_pad($amountFormatted, 10, " ", STR_PAD_LEFT) . "\n");
            // }

            // --- Items ---
            foreach ($data['items'] as $item) {

                $vat_type = "NV";
                $amountFormatted = number_format($item['amount'], 2);

                $prefix = $item['quantity'] . " x ";
                $indent = str_repeat(" ", strlen($prefix));

                $text = strtoupper($item['name']);

                // First line has reduced width because of prefix
                $firstLineWidth = $descWidth - strlen($prefix);

                // Wrap first line separately
                $wrapped = wordwrap($text, $firstLineWidth, "\n", true);
                $lines = explode("\n", $wrapped);

                // Attach prefix to first line
                $lines[0] = $prefix . $lines[0];

                // Wrap remaining lines with full width minus indent
                $finalLines = [];
                foreach ($lines as $i => $line) {
                    if ($i === 0) {
                        $finalLines[] = $line;
                    } else {
                        // wrap again for remaining lines if needed
                        $subLines = explode("\n", wordwrap($line, $descWidth - strlen($indent), "\n", true));
                        foreach ($subLines as $sub) {
                            $finalLines[] = $indent . $sub;
                        }
                    }
                }

                // Print
                foreach ($finalLines as $index => $line) {
                    if ($index === 0) {
                        $printer->text(
                            str_pad($line, $descWidth) .
                            str_pad($amountFormatted . $vat_type, $amountWidth, " ", STR_PAD_LEFT) .
                            "\n"
                        );
                    } else {
                        $printer->text(
                            str_pad($line, $descWidth) .
                            str_pad("", $amountWidth) .
                            "\n"
                        );
                    }
                }
            }


            $printer->text("\n");

            $printer->text(str_pad("SUBTOTAL", $descWidth) . str_pad(number_format($data['subtotal'], 2), $amountWidth, " ", STR_PAD_LEFT) . "\n");
            $printer->text(str_pad("TOTAL DISCOUNT", $descWidth) . str_pad(number_format($data['total_discount'], 2), $amountWidth, " ", STR_PAD_LEFT) . "\n");
            $printer->text(str_pad("VAT EXEMPTION", $descWidth) . str_pad(number_format($data['vat_exemption'], 2), $amountWidth, " ", STR_PAD_LEFT) . "\n");
            $printer->setEmphasis(true);
            $printer->text(str_pad("TOTAL (" . $data['total_items'] . " Item(s))", $descWidth) . str_pad(number_format($data['total'], 2), $amountWidth, " ", STR_PAD_LEFT) . "\n");
            $printer->setEmphasis(false);

            if ($data['reciept_type'] == 'mobile') {
                // --- Payment Multiples ---
                if (!empty($data['payment_multiples']) && is_array($data['payment_multiples'])) {
                    // HEADER
                    // $printer->text(str_pad('PAYMENT TYPE', 30)); // Left align item name
                    // $printer->text(str_pad('MULTIPLE', 10, " ", STR_PAD_LEFT) . "\n"); // Right align amount

                    foreach ($data['payment_multiples'] as $payment_multiple) {

                        // Format: TYPE: "CASH" or "GCASH" or "CARD"
                        $printer->text(str_pad(strtoupper($payment_multiple['type']), 30)); // Left align item name

                        // Format amount with commas and 2 decimals, right aligned
                        $amountFormatted = number_format($payment_multiple['amount'], 2);
                        $printer->text(str_pad($amountFormatted, 10, " ", STR_PAD_LEFT) . "\n");
                    }

                    $printer->text(str_pad("TOTAL PAID AMOUNT", $descWidth) . str_pad(number_format($data['paid_amount'], 2), $amountWidth, " ", STR_PAD_LEFT) . "\n");
                } else {
                    $printer->text(str_pad("PAID AMOUNT", $descWidth) . str_pad(number_format($data['paid_amount'], 2), $amountWidth, " ", STR_PAD_LEFT) . "\n");
                }
            } else {
                $printer->text(str_pad("CHARGED AMOUNT", $descWidth) . str_pad(number_format($data['paid_amount'], 2), $amountWidth, " ", STR_PAD_LEFT) . "\n");
            }
            $printer->text(str_pad("CHANGE", $descWidth) . str_pad(number_format($data['change'], 2), $amountWidth, " ", STR_PAD_LEFT) . "\n");

            $printer->text(str_pad("VAT SALES", $descWidth) . str_pad(number_format($data['vat_sales'], 2), $amountWidth, " ", STR_PAD_LEFT) . "\n");
            $printer->text(str_pad("VAT EXEMPT SALES", $descWidth) . str_pad(number_format($data['vat_exempt_sales'], 2), $amountWidth, " ", STR_PAD_LEFT) . "\n");
            $printer->text(str_pad("ZERO-RATED SALES", $descWidth) . str_pad(number_format($data['zero_rated_sales'], 2), $amountWidth, " ", STR_PAD_LEFT) . "\n");
            $printer->text(str_pad("TOTAL VAT", $descWidth) . str_pad(number_format($data['total_vat'], 2), $amountWidth, " ", STR_PAD_LEFT) . "\n");
            $printer->text(str_pad("VAT EXEMPT", $descWidth) . str_pad(number_format($data['vat_exempt'], 2), $amountWidth, " ", STR_PAD_LEFT) . "\n");
            $printer->setEmphasis(true);
            $printer->text(str_pad("TOTAL AMOUNT", $descWidth) . str_pad(number_format($data['total_amount'], 2), $amountWidth, " ", STR_PAD_LEFT) . "\n");
            $printer->setEmphasis(false);

            $printer->text("\n");

            $printer->text("001" . "\n");
            $printer->text("Cashier: " .$data['cashier_name']. "\n");
            $printer->text(str_repeat("=", 40) . "\n");

            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text("THIS SERVES AS INVOICE\n");
            $printer->text("THANK YOU, PLEASE COME AGAIN\n\n");

            $printer->setJustification(Printer::JUSTIFY_LEFT);
            // $printer->text("SENIOR ID : " . $data['senior_id'] . "\n");
            // $printer->text("NAME      : " . strtoupper($data['customer_name']) . "\n");
            // $printer->text("AMOUNT    : " . number_format($data['total_discount'], 2) . "\n");
            // $printer->text("SENIOR ID : ___________________________\n");
            // $printer->text("NAME      : ___________________________\n");
            // $printer->text("AMOUNT    : ___________________________\n");
            // $printer->text("SIGNATURE : ___________________________\n");

            $printer->text("\n");

            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text("MYT SoftDev Solutions, Inc." . "\n");
            $printer->text("301 The Greenery, Pope John Paul II Ave.\n");
            $printer->text("Kasambagan, Cebu City, Cebu\n");

            $printer->text("VAT Reg TIN: 497-280-919-00000" . "\n");
            $printer->text("Acc No: 0814972809192023071833" . "\n");
            $printer->text("Issue Date: 08/14/2023" . "\n");

            $printer->feed(3);
            $printer->cut();
            $printer->close();

            return $this->response->setJSON(['message' => 'Print jobs initiated']);
        } catch (\Exception $e) {
            return $this->fail("Invoice print failed: " . $e->getMessage());
        }
    }

    /**
     * Generate print job
     * Supports:
     *   - Multipart file upload (txtfile)
     *   - Direct base64 string (base64)
     */
    public function generate_billing()
    {
        try {
            $printer_name = sanitize_printer_name($this->request->getVar('printer'));
            $txt_file     = $this->request->getFile('txtfile');
            $base64Image  = $this->request->getVar('base64');

            if (empty($printer_name)) {
                return $this->_create_response(FAIL, ['Missing printer name']);
            }

            // ✅ Case 1: Handle file upload
            if ($txt_file && $txt_file->isValid() && ! $txt_file->hasMoved()) {

                $imageRawPath = FCPATH . 'public/assets/' . $printer_name;
                if (! is_dir($imageRawPath)) {
                    mkdir($imageRawPath, 0755, true);
                }

                $fileName  = $txt_file->getRandomName();
                $imagePath = $imageRawPath . DIRECTORY_SEPARATOR . $fileName;

                // Move uploaded file
                $txt_file->move($imageRawPath, $fileName);

                // Read file content
                $base64Image = file_get_contents($imagePath);
                unlink($imagePath);
            }

            // ✅ Case 2: Handle base64 directly (no upload)
            if (! empty($base64Image)) {

                // Optional: ensure it has a valid base64 data URI format
                if (strpos($base64Image, 'data:') !== 0) {
                    $base64Image = 'data:image/png;base64,' . trim($base64Image);
                }

                $values = [
                    'printer'  => $printer_name,
                    'file'     => $base64Image,
                    'added_on' => date('Y-m-d H:i:s'),
                ];

                // Save log
                $print_log_id = $this->print_log->insert($values);

                // Fetch job
                $file = $this->print_log->select('', ['is_deleted' => 0, 'id' => $print_log_id], 1);
                if (empty($file)) {
                    return $this->_create_response(FAIL, ['No files to print']);
                }

                // Mark as taken
                $this->print_log->update([
                    'is_deleted' => 1,
                    'updated_on' => date('Y-m-d H:i:s'),
                ], ['id' => $file->id]);

                // Run print job
                $content = $this->printJob($file);

                // Revert if failed
                if (strpos($content, 'successfully') === false) {
                    $this->print_log->update([
                        'is_deleted' => 0,
                        'updated_on' => date('Y-m-d H:i:s'),
                    ], ['id' => $file->id]);
                }

                // Log print output
                $logDir = FCPATH . 'public/printer_logs/';
                if (! is_dir($logDir)) {
                    mkdir($logDir, 0755, true);
                }

                $logFile = $logDir . date('Y-m-d') . '.txt';
                if (! empty($content)) {
                    file_put_contents($logFile, $content . PHP_EOL, FILE_APPEND | LOCK_EX);
                }
                file_put_contents($logFile, date('Y-m-d H:i:s') . ' Print job initiated' . PHP_EOL, FILE_APPEND | LOCK_EX);

                return $this->_create_response(SUCCESS, ['Print job initiated']);
            }

            // If no file and no base64 given
            return $this->_create_response(FAIL, ['No base64 data or file provided']);

        } catch (\Throwable $e) {
            log_message('error', 'PRINT ERROR: ' . $e->getMessage());
            return $this->_create_response(FAIL, ['Print job failed.']);
        }
    }

    /**
     * Print job processing with spooler wait
     */
    protected function printJob($file)
    {
        if (! empty($file)) {
            try {
                // Separate the data from the MIME type
                list($type, $data) = explode(';', $file->file);
                list(, $data)      = explode(',', $data);

                $extension = 'png';
                $imageData = base64_decode($data);

                $path = FCPATH . 'public/assets/' . $file->printer;
                if (! is_dir($path)) {
                    mkdir($path, 0755, true);
                }

                $imagePath = $path . '/temp_image_' . $file->id . '.' . $extension;
                file_put_contents($imagePath, $imageData);

                try {
                    $connector = new WindowsPrintConnector($file->printer);
                } catch (\Exception $e) {
                    return date('Y-m-d H:i:s') . " Printer connector failed: " . $e->getMessage();
                }

                $printer     = new Printer($connector);
                $escposImage = EscposImage::load($imagePath, false);

                if (filesize($imagePath) <= 300 * 1024) {
                    $printer->bitImage($escposImage, Printer::IMG_DEFAULT);
                } else {
                    $printer->bitImageColumnFormat($escposImage, Printer::IMG_DEFAULT);
                }

                $printer->feed(2);
                $printer->cut();

                $printer->close();
                unlink($imagePath);

                // 🔑 Wait for spooler to clear before returning
                $printer_name = escapeshellarg($file->printer);
                $timeout      = 30; // max wait seconds
                $elapsed      = 0;
                do {
                    $cmd    = 'powershell -Command "Get-PrintJob -PrinterName ' . $printer_name . ' | Select-Object -First 1 Id"';
                    $output = shell_exec($cmd);

                    if (trim($output) !== "") {
                        usleep(200000); // wait 0.2
                        $elapsed += 0.2;
                    } else {
                        break; // no jobs left
                    }
                } while ($elapsed < $timeout);

                if (empty(trim($output))) {
                    return date('Y-m-d H:i:s') . " Image printed successfully, no pending jobs (File ID: " . $file->id . ")";
                } else {
                    return date('Y-m-d H:i:s') . " Image sent to spooler but jobs still pending (waited {$elapsed}s) (File ID: " . $file->id . ")";
                }

            } catch (Exception $e) {
                if (isset($imagePath) && file_exists($imagePath)) {
                    unlink($imagePath);
                }
                return date('Y-m-d H:i:s') . " Couldn't print: " . $e->getMessage() . " (File ID: " . $file->id . ")";
            }
        } else {
            return date('Y-m-d H:i:s') . " Invalid file ID: " . $file->id;
        }
    }
}
