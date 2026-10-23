<?php

namespace App\Commands;

use App\Models\General_ledger;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class BackfillGeneralLedger extends BaseCommand
{
    protected $group       = 'GL';
    protected $name        = 'gl:backfill';
    protected $description = 'Install clinic GL triggers, backfill each branch, then apply master billing GL once.';
    protected $usage       = 'gl:backfill [--branch 7] [--skip-triggers]';
    protected $options     = [
        '--branch'        => 'Only this branch id (1, 2, 3, 4, or 7). Omit to run all.',
        '--skip-triggers' => 'Skip applying trigger SQL (clinic and master).',
    ];

    public function run(array $params)
    {
        $db_map = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
        ];

        $branch_in = CLI::getOption('branch') ?? ($params[0] ?? null);
        if ($branch_in !== null && $branch_in !== '') {
            $branch_id = (int) $branch_in;
            if (! isset($db_map[$branch_id])) {
                CLI::error('Invalid branch. Use 1, 2, 3, 4, or 7.');
                return;
            }
            $branch_ids = [$branch_id];
        } else {
            $branch_ids = array_keys($db_map);
        }

        $skip_triggers = CLI::getOption('skip-triggers') !== null;
        $sql_path = ROOTPATH . 'sql/general_ledger_triggers.sql';

        foreach ($branch_ids as $id) {
            $group = $db_map[$id];
            CLI::write("Branch {$id} ({$group})", 'yellow');
            $db = db_connect($group);
            $ledger = new General_ledger($db);
            $ledger->ensure_posted_schema((int) $id);

            if (! $skip_triggers) {
                $applied = $this->_apply_trigger_sql($db, $sql_path);
                CLI::write("  triggers/functions: {$applied['ok']} ok, {$applied['skipped']} skipped");
                foreach ($applied['errors'] as $error) {
                    CLI::write('  ! ' . $error, 'red');
                }
            }

            $inserted = $ledger->backfill_from_live((int) $id);
            CLI::write("  backfill posted {$inserted} net new general_ledger row(s)", 'green');
        }

        $this->_backfill_master_billing($skip_triggers);
    }

    private function _backfill_master_billing(bool $skip_triggers): void
    {
        CLI::write('Master billing GL (default / mytemr_master)', 'yellow');
        $db = db_connect('default');

        if (! $skip_triggers) {
            $master_sql = ROOTPATH . 'sql/general_ledger_triggers_master.sql';
            $applied = $this->_apply_trigger_sql($db, $master_sql);
            CLI::write("  triggers/functions: {$applied['ok']} ok, {$applied['skipped']} skipped");
            foreach ($applied['errors'] as $error) {
                CLI::write('  ! ' . $error, 'red');
            }
        }

        $before = $this->_master_billing_row_count($db);
        try {
            $db->query('CALL gl_backfill_billing()');
            $this->_drain_call_results($db);
        } catch (\Throwable $e) {
            CLI::write('  ! CALL gl_backfill_billing: ' . $e->getMessage(), 'red');
            return;
        }
        $inserted = max(0, $this->_master_billing_row_count($db) - $before);
        CLI::write("  backfill posted {$inserted} net new billing general_ledger row(s)", 'green');
    }

    private function _master_billing_row_count($db): int
    {
        $query = $db->query(
            "SELECT COUNT(*) AS total FROM general_ledger
             WHERE is_deleted = 0
               AND source IN ('billing_entry', 'billing_payment', 'billing_payment_deposit')"
        );
        $row = $query ? $query->getRowArray() : null;

        return (int) ($row['total'] ?? 0);
    }

    private function _drain_call_results($db): void
    {
        $conn = $db->connID ?? null;
        if (! is_object($conn) || ! method_exists($conn, 'more_results')) {
            return;
        }
        while ($conn->more_results() && $conn->next_result()) {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        }
    }

    /**
     * @return array{ok: int, skipped: int, errors: array<int, string>}
     */
    private function _apply_trigger_sql($db, string $sql_path): array
    {
        $result = ['ok' => 0, 'skipped' => 0, 'errors' => []];
        if (! is_file($sql_path)) {
            $result['errors'][] = 'Missing ' . $sql_path;
            return $result;
        }

        foreach ($this->_split_sql_statements(file_get_contents($sql_path)) as $sql) {
            try {
                $ok = $db->query($sql);
            } catch (\Throwable $e) {
                $result['skipped']++;
                $result['errors'][] = $this->_sql_preview($sql) . ': ' . $e->getMessage();
                continue;
            }
            if ($ok) {
                $result['ok']++;
            } else {
                $error = $db->error();
                $message = is_array($error) ? (string) ($error['message'] ?? 'query failed') : 'query failed';
                $result['skipped']++;
                $result['errors'][] = $this->_sql_preview($sql) . ': ' . $message;
            }
        }

        return $result;
    }

    /**
     * Split a phpMyAdmin-style dump: honor DELIMITER, do not send DELIMITER to the server.
     *
     * @return array<int, string>
     */
    private function _split_sql_statements(string $contents): array
    {
        $delimiter = ';';
        $buffer = '';
        $statements = [];

        foreach (preg_split('/\R/', $contents) as $line) {
            if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match)) {
                $this->_flush_sql_buffer($buffer, $delimiter, $statements);
                $delimiter = $match[1];
                continue;
            }
            $buffer .= $line . "\n";
            $trimmed = rtrim($buffer);
            if ($delimiter !== '' && substr($trimmed, -strlen($delimiter)) === $delimiter) {
                $statement = substr($trimmed, 0, -strlen($delimiter));
                $this->_flush_sql_buffer($statement, '', $statements);
                $buffer = '';
            }
        }
        $this->_flush_sql_buffer($buffer, $delimiter, $statements);

        return $statements;
    }

    /**
     * @param array<int, string> $statements
     */
    private function _flush_sql_buffer(string $buffer, string $delimiter, array &$statements): void
    {
        $sql = $buffer;
        if ($delimiter !== '') {
            $trimmed = rtrim($sql);
            if (substr($trimmed, -strlen($delimiter)) === $delimiter) {
                $sql = substr($trimmed, 0, -strlen($delimiter));
            }
        }
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $sql = trim($sql);
        if ($sql !== '') {
            $statements[] = $sql;
        }
    }

    private function _sql_preview(string $sql): string
    {
        $line = preg_replace('/\s+/', ' ', $sql);

        return substr($line, 0, 80);
    }
}
