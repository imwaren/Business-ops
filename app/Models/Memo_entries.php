<?php

namespace App\Models;

use App\Core\MYT_Model;

abstract class Memo_entries extends MYT_Model
{
    protected $memo_type;

    public function active_rows($memo_id, bool $lock = false): array
    {
        return $this->db->query("SELECT * FROM {$this->table} WHERE {$this->memo_type}_id = ? AND is_deleted = 0 ORDER BY id" . ($lock ? " FOR UPDATE" : ""), [$memo_id])->getResultArray();
    }

    public function validate_reference($entry_id, $billing_id, $branch_id): bool
    {
        return (bool) $this->db->query('SELECT id FROM billing_entry WHERE id = ? AND billing_id = ? AND branch_id = ? AND is_deleted = 0 FOR UPDATE', [$entry_id, $billing_id, $branch_id])->getRowArray();
    }

    public function retire($memo_id, $requester): void
    {
        if (!$this->db->table($this->table)->where($this->memo_type . '_id', $memo_id)->where('is_deleted', 0)->update(['is_deleted' => 1, 'updated_by' => $requester, 'updated_on' => date('Y-m-d H:i:s')])) {
            throw new \RuntimeException('Failed to retire memo entries.');
        }
    }

    public function replace_rows($memo_id, array $rows, $requester): void
    {
        $this->retire($memo_id, $requester);
        foreach ($rows as $row) {
            $row[$this->memo_type . '_id'] = $memo_id;
            $row['added_by'] = $requester;
            $row['added_on'] = date('Y-m-d H:i:s');
            if (!$this->insert($row)) {
                throw new \RuntimeException('Failed to save memo entries.');
            }
        }
    }

    public function for_memos(array $memo_ids): array
    {
        if (!$memo_ids) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($memo_ids), '?'));
        return $this->db->query("SELECT e.* FROM {$this->table} e
            JOIN {$this->memo_type} m ON m.id = e.{$this->memo_type}_id
                AND m.is_deleted = 0 AND m.billing_id = e.billing_id AND m.branch_id = e.branch_id
            JOIN billing_entry b ON b.id = e.billing_entry_id AND b.billing_id = e.billing_id
                AND b.branch_id = e.branch_id AND b.is_deleted = 0
            WHERE e.is_deleted = 0 AND m.id IN ($marks) ORDER BY e.id", $memo_ids)->getResultArray();
    }

    public function for_billings(array $billing_ids): array
    {
        if (!$billing_ids) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($billing_ids), '?'));
        return $this->db->query("SELECT e.* FROM {$this->table} e JOIN {$this->memo_type} m ON m.id = e.{$this->memo_type}_id AND m.is_deleted = 0 AND m.status = 'approved' AND m.billing_id = e.billing_id AND m.branch_id = e.branch_id JOIN billing_entry b ON b.id = e.billing_entry_id AND b.billing_id = e.billing_id AND b.branch_id = e.branch_id AND b.is_deleted = 0 WHERE e.is_deleted = 0 AND e.billing_id IN ($marks) ORDER BY e.id", $billing_ids)->getResultArray();
    }
}
