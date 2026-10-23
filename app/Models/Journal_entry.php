<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Journal_entry extends MYT_Model
{
    use \App\Core\Journal_center_scope;

    protected $table = 'journal_entry';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'cost_center_id',
        'date',
        'type',
        'remarks',
        'total_credit',
        'total_debit',
        'is_migrated',
    ];

    /** @var array<int, string> clinic branch_id → SQL DB placeholder */
    private const CLINIC_DBS = [
        1 => '{MOBILE_DB}',
        2 => '{OAKRIDGE_DB}',
        3 => '{MAC_DB}',
        4 => '{CONSO_DB}',
        7 => '{MANTLEWOOD_DB}',
    ];

    public function get_details(
        $journal_entry_id = null,
        $status = null,
        $term = null,
        $page = null,
        $data_size = null,
        $branch_id = null,
        $date_from = null,
        $date_to = null
    ) {
        $sources = $this->_details_sources($branch_id);
        if (!$sources) {
            return false;
        }

        $arms = [];
        $binds = [];
        foreach ($sources as [$db, $source_branch_id, $source_cost_center_id]) {
            [$filter_sql, $filter_binds] = $this->_details_filter_sql(
                $journal_entry_id,
                $status,
                $term,
                $date_from,
                $date_to,
                $source_cost_center_id
            );
            $arms[] = $this->_details_header_arm_sql($db, $source_branch_id, $filter_sql);
            foreach ($filter_binds as $bind) {
                $binds[] = $bind;
            }
        }

        $union = implode("\nUNION ALL\n", $arms);
        $sql = "SELECT * FROM ({$union}) AS journal_entry";
        if ($journal_entry_id && (int) $branch_id > 0) {
            // Preserve clinic detail lookup; master is the fallback for this branch.
            $sql .= ' ORDER BY CASE WHEN journal_entry.branch_id IS NULL THEN 1 ELSE 0 END ASC, journal_entry.date DESC, journal_entry.id DESC';
        } else {
            $sql .= ' ORDER BY journal_entry.date DESC, journal_entry.id DESC';
        }

        if ($journal_entry_id) {
            $sql .= ' LIMIT 1';
        } else {
            $page = $page ? (int) $page : 1;
            $data_size = $data_size ? (int) $data_size : 50;
            $offset = ($page - 1) * $data_size;
            $sql .= ' LIMIT ' . (int) $offset . ', ' . (int) $data_size;
        }

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        if (!$query) {
            return false;
        }

        $rows = $query->getResultArray();
        $this->_attach_items($rows);
        return $rows;
    }

    public function get_details_count(
        $journal_entry_id = null,
        $status = null,
        $term = null,
        $branch_id = null,
        $date_from = null,
        $date_to = null
    ): int {
        $sources = $this->_details_sources($branch_id);
        if (!$sources) {
            return 0;
        }

        $arms = [];
        $binds = [];
        foreach ($sources as [$db, $source_branch_id, $source_cost_center_id]) {
            [$filter_sql, $filter_binds] = $this->_details_filter_sql(
                $journal_entry_id,
                $status,
                $term,
                $date_from,
                $date_to,
                $source_cost_center_id
            );
            $arms[] = <<<EOT
SELECT COUNT(*) AS total
FROM {$db}.journal_entry AS journal_entry
WHERE journal_entry.is_deleted = 0
{$filter_sql}
EOT;
            foreach ($filter_binds as $bind) {
                $binds[] = $bind;
            }
        }

        $sql = 'SELECT SUM(total) AS total FROM (' . implode("\nUNION ALL\n", $arms) . ') AS journal_entry_count';
        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        if (!$query) {
            return 0;
        }

        $row = $query->getRowArray();
        return (int) ($row['total'] ?? 0);
    }

    /**
     * Return the active journal-entry movements linked to a page of APVs.
     * The result is keyed by APV ID so callers can attach it without N+1 queries.
     *
     * @param array<int, array<string, mixed>> $apvs
     * @return array<int, array<int, array<string, mixed>>>|false
     */
    public function get_apv_movements(array $apvs, int $branch_id, bool $shared = false)
    {
        if (!$apvs) {
            return [];
        }

        if (!$shared && !isset(self::CLINIC_DBS[$branch_id])) {
            return false;
        }

        $apv_ids = array_values(array_unique(array_filter(array_map(
            static fn($apv) => (int) ($apv['id'] ?? 0),
            $apvs
        ))));
        if (!$apv_ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($apv_ids), '?'));
        $branch_sql = $shared ? 'NULL' : (string) $branch_id;
        $send_out_entry_condition = '';
        if ($this->db->tableExists('send_out_entry')) {
            $send_out_entry_condition = <<<EOT
              OR (journal_entry.source = 'send_out_entry'
                  AND EXISTS (
                      SELECT 1 FROM send_out_entry AS source_item
                      WHERE source_item.id = journal_entry.source_id
                        AND source_item.send_out_id = accounts_payable_voucher.type_id
                        AND source_item.is_deleted = 0
                  ))
EOT;
        }

        $sql = <<<EOT
SELECT journal_entry.*,
    accounts_payable_voucher.id AS _apv_id,
    {$branch_sql} AS branch_id,
    CASE WHEN IFNULL(journal_entry.source, '') = '' THEN 'amortization' ELSE CASE journal_entry.source
        WHEN 'receipt_note_item' THEN 'receipt_note'
        WHEN 'reimbursement_item' THEN 'reimbursement'
        WHEN 'liquidation_detail' THEN 'liquidation'
        WHEN 'replenishment_item' THEN 'replenishment'
        WHEN 'accounts_payable_voucher' THEN 'apv'
        WHEN 'accounts_payable_voucher_payment_detail' THEN 'pay_apv'
        WHEN 'send_out_entry' THEN 'send_out'
        WHEN 'journal_entry' THEN 'amortization'
        ELSE journal_entry.source
    END END AS movement
FROM accounts_payable_voucher
INNER JOIN journal_entry ON journal_entry.is_deleted = 0
WHERE accounts_payable_voucher.is_deleted = 0
  AND accounts_payable_voucher.id IN ({$placeholders})
  AND (
      (journal_entry.source = 'accounts_payable_voucher'
          AND journal_entry.source_id = accounts_payable_voucher.id)
      OR (journal_entry.source = 'accounts_payable_voucher_payment_detail'
          AND EXISTS (
              SELECT 1
              FROM accounts_payable_voucher_payment_detail AS payment_detail
              WHERE payment_detail.id = journal_entry.source_id
                AND payment_detail.accounts_payable_voucher_id = accounts_payable_voucher.id
                AND payment_detail.is_deleted = 0
          ))
      OR (accounts_payable_voucher.type = 'receipt_note'
          AND journal_entry.source = 'receipt_note_item'
          AND EXISTS (
              SELECT 1 FROM receipt_note_item AS source_item
              WHERE source_item.id = journal_entry.source_id
                AND source_item.receipt_note_id = accounts_payable_voucher.type_id
                AND source_item.is_deleted = 0
          ))
      OR (accounts_payable_voucher.type = 'petty_cash'
          AND journal_entry.source = 'petty_cash'
          AND journal_entry.source_id = accounts_payable_voucher.type_id)
      OR (accounts_payable_voucher.type = 'reimbursement'
          AND journal_entry.source = 'reimbursement_item'
          AND EXISTS (
              SELECT 1 FROM reimbursement_item AS source_item
              WHERE source_item.id = journal_entry.source_id
                AND source_item.reimbursement_id = accounts_payable_voucher.type_id
                AND source_item.is_deleted = 0
          ))
      OR (accounts_payable_voucher.type = 'cash_advance'
          AND journal_entry.source = 'cash_advance'
          AND journal_entry.source_id = accounts_payable_voucher.type_id)
      OR (accounts_payable_voucher.type IN ('liquidation', 'excess')
          AND journal_entry.source = 'liquidation_detail'
          AND EXISTS (
              SELECT 1 FROM liquidation_detail AS source_item
              WHERE source_item.id = journal_entry.source_id
                AND source_item.liquidation_id = accounts_payable_voucher.type_id
                AND source_item.is_deleted = 0
          ))
      OR (accounts_payable_voucher.type = 'change_fund'
          AND journal_entry.source = 'change_fund'
          AND journal_entry.source_id = accounts_payable_voucher.type_id)
      OR (accounts_payable_voucher.type = 'replenishment'
          AND journal_entry.source = 'replenishment_item'
          AND EXISTS (
              SELECT 1 FROM replenishment_item AS source_item
              WHERE source_item.id = journal_entry.source_id
                AND source_item.replenishment_id = accounts_payable_voucher.type_id
                AND source_item.is_deleted = 0
          ))
      OR (accounts_payable_voucher.type = 'direct_payment'
          AND journal_entry.source='direct_payment'
          AND journal_entry.source_id=accounts_payable_voucher.type_id
          AND gl_apv_is_legacy(accounts_payable_voucher.id)=1)
      OR (accounts_payable_voucher.type = 'direct_payment'
          AND IFNULL(journal_entry.source, '') = ''
          AND EXISTS (SELECT 1 FROM journal_entry_item amortization_item
              WHERE amortization_item.journal_entry_id = journal_entry.id
              AND amortization_item.direct_payment_id = accounts_payable_voucher.type_id
              AND amortization_item.is_deleted = 0))
      OR (accounts_payable_voucher.type = 'refund'
          AND journal_entry.source = 'refund'
          AND journal_entry.source_id = accounts_payable_voucher.type_id)
      OR (accounts_payable_voucher.type = 'send_out'
          AND (
              (journal_entry.source = 'send_out'
                  AND journal_entry.source_id = accounts_payable_voucher.type_id)
{$send_out_entry_condition}
          ))
  )
ORDER BY journal_entry.date ASC, journal_entry.id ASC
EOT;

        if ($shared) {
            $receipt = strpos($sql, "      OR (accounts_payable_voucher.type = 'receipt_note'");
            $fund = strpos($sql, "      OR (accounts_payable_voucher.type = 'petty_cash'");
            $sql = substr($sql, 0, $receipt) . substr($sql, $fund);
            $sql = str_replace('AND accounts_payable_voucher.id IN',
                'AND accounts_payable_voucher.cost_center_id = ? AND accounts_payable_voucher.id IN', $sql);
            array_unshift($apv_ids, $branch_id);
        }
        $query = $this->db->query($sql, $apv_ids);
        if (!$query) {
            return false;
        }

        $rows = $query->getResultArray();
        $this->_attach_items($rows);

        $grouped = [];
        foreach ($rows as $row) {
            $apv_id = (int) $row['_apv_id'];
            unset($row['_apv_id']);
            if ($shared) { $row['branch_id'] = $branch_id; }
            $grouped[$apv_id][] = $row;
        }

        return $grouped;
    }

    /**
     * @return list<array{0: string, 1: int|null, 2: int|null}>
     * Values are database, item-source branch, and an optional stored cost-center filter.
     */
    private function _details_sources($branch_id): array
    {
        if ($branch_id !== null && $branch_id !== '') {
            if ((!is_int($branch_id) && !is_string($branch_id)) || !preg_match('/^\d+$/', (string) $branch_id)) {
                return [];
            }
            $branch_id = (int) $branch_id;
            if ($branch_id === 0) {
                // Keep the existing master marker for response metadata and item lookup.
                return [['{MASTER_DB}', null, null]];
            }
            if ((new \App\Libraries\Fund_cost_center())->group($branch_id) === 'master') {
                return [['{MASTER_DB}', null, $branch_id]];
            }
            if (!isset(self::CLINIC_DBS[$branch_id])) {
                return [];
            }
            return [
                [self::CLINIC_DBS[$branch_id], $branch_id, null],
                ['{MASTER_DB}', null, $branch_id],
            ];
        }

        $sources = [];
        foreach (self::CLINIC_DBS as $id => $db) {
            $sources[] = [$db, $id, null];
        }
        $sources[] = ['{MASTER_DB}', null, null];
        return $sources;
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function _details_filter_sql($journal_entry_id, $status, $term, $date_from = null, $date_to = null, $cost_center_id = null): array
    {
        $sql = '';
        $binds = [];

        if ($journal_entry_id) {
            $sql .= <<<EOT

AND journal_entry.id = ?
EOT;
            $binds[] = $journal_entry_id;
        }

        if ($status) {
            if ($status === 'for_approval_ga') {
                $sql .= <<<EOT

AND journal_entry.status = 'pending'
AND journal_entry.approved_by_general_accountant IS NULL
EOT;
            } elseif ($status === 'for_approval_fm') {
                $sql .= <<<EOT

AND journal_entry.status = 'pending'
AND journal_entry.approved_by_general_accountant IS NOT NULL
AND journal_entry.approved_by_finance_manager IS NULL
EOT;
            } elseif ($status === 'for_approval_fd') {
                $sql .= <<<EOT

AND journal_entry.status = 'pending'
AND journal_entry.approved_by_general_accountant IS NOT NULL
AND journal_entry.approved_by_finance_manager IS NOT NULL
AND journal_entry.approved_by_finance_director IS NULL
EOT;
            } else {
                $sql .= <<<EOT

AND journal_entry.status = ?
EOT;
                $binds[] = $status;
            }
        }

        if ($term) {
            $sql .= <<<EOT

AND (journal_entry.remarks LIKE ?)
EOT;
            $binds[] = "%{$term}%";
        }

        if (is_array($date_from)) {
            $date_from = end($date_from);
        }
        if (is_array($date_to)) {
            $date_to = end($date_to);
        }
        if ($date_from === '') {
            $date_from = null;
        }
        if ($date_to === '') {
            $date_to = null;
        }
        if ($date_from && !$date_to) {
            $date_to = $date_from;
        } elseif ($date_to && !$date_from) {
            $date_from = $date_to;
        }

        if ($date_from) {
            $sql .= <<<EOT

AND journal_entry.date >= ?
EOT;
            $binds[] = $date_from;
        }

        if ($date_to) {
            $sql .= <<<EOT

AND journal_entry.date <= ?
EOT;
            $binds[] = $date_to;
        }

        if ($cost_center_id !== null) {
            $sql .= <<<EOT

AND journal_entry.cost_center_id = ?
EOT;
            $binds[] = $cost_center_id;
        }

        return [$sql, $binds];
    }

    private function _details_header_arm_sql(string $db, $branch_id, string $filter_sql): string
    {
        $branch_sql = $branch_id === null ? 'NULL' : (string) (int) $branch_id;

        return <<<EOT
SELECT journal_entry.*,
    {$branch_sql} AS branch_id,
    CASE WHEN ga.id IS NULL THEN NULL ELSE TRIM(CONCAT(IFNULL(ga.first_name, ''), ' ', IFNULL(ga.last_name, ''))) END AS approved_by_general_accountant_name,
    CASE WHEN fm.id IS NULL THEN NULL ELSE TRIM(CONCAT(IFNULL(fm.first_name, ''), ' ', IFNULL(fm.last_name, ''))) END AS approved_by_finance_manager_name,
    CASE WHEN fd.id IS NULL THEN NULL ELSE TRIM(CONCAT(IFNULL(fd.first_name, ''), ' ', IFNULL(fd.last_name, ''))) END AS approved_by_finance_director_name
FROM {$db}.journal_entry AS journal_entry
LEFT JOIN {MASTER_DB}.user AS ga
    ON ga.id = journal_entry.approved_by_general_accountant AND ga.is_deleted = 0
LEFT JOIN {MASTER_DB}.user AS fm
    ON fm.id = journal_entry.approved_by_finance_manager AND fm.is_deleted = 0
LEFT JOIN {MASTER_DB}.user AS fd
    ON fd.id = journal_entry.approved_by_finance_director AND fd.is_deleted = 0
WHERE journal_entry.is_deleted = 0
{$filter_sql}
EOT;
    }

    private function _db_for_branch($branch_id): ?string
    {
        if ($branch_id === null || $branch_id === '') {
            return '{MASTER_DB}';
        }
        $branch_id = (int) $branch_id;
        return self::CLINIC_DBS[$branch_id] ?? null;
    }

    private function _attach_items(array &$rows): void
    {
        foreach ($rows as &$row) {
            $row['items'] = [];
        }
        unset($row);

        if (!$rows) {
            return;
        }

        $by_branch = [];
        foreach ($rows as $index => $row) {
            $branch = $row['branch_id'] ?? null;
            $key = ($branch === null || $branch === '') ? 'master' : (string) (int) $branch;
            $by_branch[$key][] = $index;
        }

        foreach ($by_branch as $key => $indexes) {
            $branch_id = $key === 'master' ? null : (int) $key;
            $db = $this->_db_for_branch($branch_id);
            if (!$db) {
                continue;
            }

            $ids = [];
            foreach ($indexes as $index) {
                $ids[] = (int) $rows[$index]['id'];
            }
            $ids = array_values(array_unique(array_filter($ids)));
            if (!$ids) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = <<<EOT
SELECT journal_items.*,
    account_type.name AS account_type_name
FROM {$db}.journal_entry_item AS journal_items
LEFT JOIN {MASTER_DB}.account_type AS account_type
    ON account_type.id = journal_items.account_type_id
    AND account_type.is_deleted = 0
WHERE journal_items.is_deleted = 0
  AND journal_items.journal_entry_id IN ({$placeholders})
EOT;
            $sql = $this->_resolve_cross_db_sql($sql);
            $query = $this->db->query($sql, $ids);
            $grouped = [];
            if ($query) {
                foreach ($query->getResultArray() as $item) {
                    $grouped[(int) $item['journal_entry_id']][] = $item;
                }
            }
            foreach ($indexes as $index) {
                $je_id = (int) $rows[$index]['id'];
                $rows[$index]['items'] = $grouped[$je_id] ?? [];
            }
        }
    }
}

?>
