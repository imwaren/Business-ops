<?php

namespace App\Libraries;

use App\Controllers\Reports;
use App\Models\Bir_return_file;
use App\Models\Branch_master;
use Config\Services;

class Bir_return_generator
{
    public const DEFAULT_BRANCH_ID = 2;
    public const QUARTER_MONTHS = [3, 6, 9, 12];
    public const RT_MONTH = 12;

    protected $bir_return_file;
    protected $branch_master;

    public function __construct()
    {
        $master = db_connect('master');
        $this->bir_return_file = new Bir_return_file($master);
        $this->branch_master = new Branch_master($master);
    }

    public static function quarter_from_month(int $month): int
    {
        return (int) ceil($month / 3);
    }

    public static function is_quarter_month(int $month): bool
    {
        return in_array($month, self::QUARTER_MONTHS, true);
    }

    public static function is_rt_month(int $month): bool
    {
        return $month === self::RT_MONTH;
    }

    public static function rt_range(int $year): array
    {
        return [
            sprintf('%04d-01-01', $year),
            self::period_last_day($year, self::RT_MONTH),
        ];
    }

    public static function period_last_day(int $year, int $month): string
    {
        return date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    }

    public static function quarter_range(int $year, int $quarter): array
    {
        $start_month = (($quarter - 1) * 3) + 1;
        $end_month = $start_month + 2;

        return [
            sprintf('%04d-%02d-01', $year, $start_month),
            self::period_last_day($year, $end_month),
        ];
    }

    public static function canonical_range(string $type, int $year, $quarter = null, $month = null): array
    {
        if ($type === '1702_rt') {
            return self::rt_range($year);
        }

        $q = ($quarter !== null && $quarter !== '')
            ? (int) $quarter
            : self::quarter_from_month((int) ($month ?: 1));

        return self::quarter_range($year, $q);
    }

    public function spawn(int $bir_return_file_id): void
    {
        $php = $this->php_cli();
        $spark = ROOTPATH . 'spark';
        $log = WRITEPATH . 'logs/bir_return.log';
        $cmd = sprintf(
            'nohup %s %s bir:generate %d >> %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($spark),
            $bir_return_file_id,
            escapeshellarg($log)
        );
        log_message('info', 'BIR return generation running in background for id ' . $bir_return_file_id . ': ' . $cmd);
        exec($cmd);
    }

    /**
     * PHP_BINARY is php-fpm under the web SAPI and cannot run Spark.
     * Never is_file() /usr/sbin (outside open_basedir).
     */
    protected function php_cli(): string
    {
        $binary = PHP_BINARY ?: '';
        if ($binary !== '' && ! $this->_is_fpm_or_cgi($binary) && $this->_is_cli_php($binary)) {
            return $binary;
        }

        $candidates = [];
        $version = ($binary !== '' && preg_match('/(\d+\.\d+)/', basename($binary), $m))
            ? $m[1]
            : null;
        if ($version) {
            $candidates[] = '/usr/bin/php' . $version;
        }
        $candidates[] = '/usr/bin/php';
        $candidates[] = '/usr/local/bin/php';
        $candidates[] = '/bin/php';
        $candidates[] = 'php';

        foreach ($candidates as $path) {
            if ($path === 'php' || $this->_is_cli_php($path)) {
                return $path;
            }
        }

        return 'php';
    }

    protected function _is_fpm_or_cgi(string $path): bool
    {
        $name = strtolower(basename($path));

        return strpos($name, 'fpm') !== false || strpos($name, 'cgi') !== false;
    }

    protected function _is_cli_php(string $path): bool
    {
        if ($path === '' || $this->_is_fpm_or_cgi($path) || ! $this->_is_statable_php($path)) {
            return false;
        }

        return is_file($path) && is_executable($path);
    }

    protected function _is_statable_php(string $path): bool
    {
        $allowed = ['/usr/bin/', '/usr/local/bin/', '/bin/', '/opt/'];
        $normalized = str_replace('\\', '/', $path) . '/';
        foreach ($allowed as $prefix) {
            if (strpos($normalized, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    public function queue_for_closed_month(int $closed_month_id, int $year, int $month, $added_by = null, int $branch_id = self::DEFAULT_BRANCH_ID): array
    {
        $queued = [];
        if (self::is_rt_month($month)) {
            [$from, $to] = self::rt_range($year);
            $rt = $this->insert_pending([
                'closed_month_id' => $closed_month_id,
                'type' => '1702_rt',
                'branch_id' => $branch_id,
                'year' => $year,
                'month' => self::RT_MONTH,
                'quarter' => null,
                'date_from' => $from,
                'date_to' => $to,
                'added_by' => $added_by,
            ]);
            if ($rt) {
                $this->spawn((int) $rt);
                $queued[] = ['type' => '1702_rt', 'bir_return_file_id' => (int) $rt];
            }
        }

        if (self::is_quarter_month($month)) {
            $quarter = self::quarter_from_month($month);
            [$from, $to] = self::quarter_range($year, $quarter);
            $q = $this->insert_pending([
                'closed_month_id' => $closed_month_id,
                'type' => '1702q',
                'branch_id' => $branch_id,
                'year' => $year,
                'month' => $month,
                'quarter' => $quarter,
                'date_from' => $from,
                'date_to' => $to,
                'added_by' => $added_by,
            ]);
            if ($q) {
                $this->spawn((int) $q);
                $queued[] = ['type' => '1702q', 'bir_return_file_id' => (int) $q, 'quarter' => $quarter];
            }
        }

        return $queued;
    }

    public function insert_pending(array $values)
    {
        $values['status'] = $values['status'] ?? 'pending';
        $values['added_on'] = $values['added_on'] ?? date('Y-m-d H:i:s');

        return $this->bir_return_file->insert($values);
    }

    public function generate(int $bir_return_file_id): bool
    {
        $row = $this->bir_return_file->select('', ['id' => $bir_return_file_id, 'is_deleted' => 0], 1);
        if (! $row) {
            log_message('error', 'BIR return file not found: ' . $bir_return_file_id);
            return false;
        }

        $this->_update_status($bir_return_file_id, 'processing');
        log_message('info', 'BIR return generation started for id ' . $bir_return_file_id . ' type ' . $row->type);

        try {
            $branch_id = (int) ($row->branch_id ?: self::DEFAULT_BRANCH_ID);
            $branch = $this->branch_master->select('', ['id' => $branch_id, 'is_deleted' => 0], 1);
            if (! $branch) {
                throw new \RuntimeException('Branch is not found.');
            }

            $year = (int) $row->year;
            $quarter = $row->quarter
                ? (int) $row->quarter
                : self::quarter_from_month((int) ($row->month ?: 1));
            [$date_from, $date_to] = self::canonical_range((string) $row->type, $year, $quarter, $row->month);
            if (($row->date_from ?? '') !== $date_from || ($row->date_to ?? '') !== $date_to) {
                $this->bir_return_file->update(['id' => $bir_return_file_id], [
                    'date_from' => $date_from,
                    'date_to' => $date_to,
                    'updated_on' => date('Y-m-d H:i:s'),
                ]);
            }

            $pnl = $this->_pnl_totals($date_from, $date_to, $branch_id);
            $data = array_merge($pnl, [
                'tin' => $branch->tin ?? '',
                'name' => $branch->name ?? '',
                'address' => $branch->address ?? '',
                'year' => $year,
                'month' => $row->type === '1702_rt'
                    ? self::RT_MONTH
                    : (int) ($row->month ?: date('n', strtotime($date_to))),
                'quarter' => $quarter,
            ]);

            $local_name = sprintf(
                '%s_%s_b%s_%s.pdf',
                $row->type,
                $row->year,
                $branch_id,
                $row->type === '1702q' ? 'q' . $row->quarter : 'm' . $row->month
            );
            $local_path = WRITEPATH . 'bir_returns/' . $local_name;

            $filler = new Bir_1702();
            if ($row->type === '1702q') {
                $filler->fill_1702q($local_path, $data);
            } else {
                $filler->fill_1702_rt($local_path, $data);
            }

            $file_url = null;
            $vm4_path = 'assets/bir_returns/' . $row->year . '/' . $row->type . '/' . $bir_return_file_id . '/';
            $upload = upload_to_vm4(new Local_upload_file($local_path, $local_name), $vm4_path);
            if ($upload && ! empty($upload['success']) && ! empty($upload['path'])) {
                $file_url = rtrim(VM4_ENDPOINT, '/') . '/' . ltrim($upload['path'], '/');
            } else {
                log_message('error', 'BIR VM4 upload failed for id ' . $bir_return_file_id . ': ' . ($upload['error'] ?? json_encode($upload)));
            }

            $this->bir_return_file->update(['id' => $bir_return_file_id], [
                'status' => 'ready',
                'file_path' => $local_path,
                'file_url' => $file_url,
                'error_message' => null,
                'updated_on' => date('Y-m-d H:i:s'),
            ]);
            log_message('info', 'BIR return generation finished for id ' . $bir_return_file_id);

            return true;
        } catch (\Throwable $e) {
            log_message('error', 'BIR return generation failed for id ' . $bir_return_file_id . ': ' . $e->getMessage());
            $this->_update_status($bir_return_file_id, 'failed', $e->getMessage());
            return false;
        }
    }

    protected function _update_status(int $id, string $status, $error = null): void
    {
        $this->bir_return_file->update(['id' => $id], [
            'status' => $status,
            'error_message' => $error,
            'updated_on' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * P&L → 1702 boxes. See docs/bir_1702_figures.md.
     */
    protected function _pnl_totals(string $date_from, string $date_to, int $branch_id): array
    {
        $reports = new Reports();
        $reports->initController(Services::request(), Services::response(), Services::logger());
        $sheet = $reports->build_profit_and_loss_for_bir($date_from, $date_to, $branch_id);

        $net_revenues = $this->_pnl_sheet_amount($sheet['net_revenues'] ?? 0);
        $cost_of_sales = $this->_pnl_sheet_amount($sheet['cost_of_sales']['total'] ?? 0);
        $gross_income = array_key_exists('gross_margin', $sheet)
            ? $this->_pnl_sheet_amount($sheet['gross_margin'])
            : ($net_revenues - $cost_of_sales);
        $boc = $this->_pnl_sheet_amount($sheet['branch_operating_costs']['total'] ?? 0);
        $ga = $this->_pnl_sheet_amount($sheet['general_and_admin_expenses']['total'] ?? 0);
        $nopex = (float) ($sheet['nopex']['amount'] ?? 0);
        $deductions = $boc + $ga + $nopex;
        $net_income = $this->_pnl_sheet_amount($sheet['net_income'] ?? 0);
        $tax_due = $net_income > 0 ? round($net_income * 0.25, 2) : 0.0;

        return [
            'net_revenues' => $net_revenues,
            'cost_of_sales' => $cost_of_sales,
            'gross_income' => $gross_income,
            'deductions' => $deductions,
            'net_income' => $net_income,
            'tax_due' => $tax_due,
            'tax_credits' => 0.0,
            'net_tax_payable' => $tax_due,
            'total_payable' => $tax_due,
        ];
    }

    /**
     * Scalar stay a float; P&L rollup nodes use amount.
     */
    protected function _pnl_sheet_amount($node): float
    {
        if (is_array($node)) {
            return (float) ($node['amount'] ?? 0);
        }

        return (float) ($node ?? 0);
    }
}
