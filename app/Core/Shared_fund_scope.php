<?php

namespace App\Core;

use App\Libraries\Fund_cost_center;

/** Opt-in isolation for models routed to a shared master database. */
trait Shared_fund_scope
{
    protected ?array $shared_cost_centers = null;
    protected ?int $shared_parent_id = null;

    public function with_shared_parent(int $id): self
    {
        $this->shared_parent_id = $id;
        return $this;
    }


    public function with_shared_cost_centers($ids): self
    {
        $this->shared_cost_centers = array_values(array_unique(array_map('intval', (array) $ids)));
        if (!$this->shared_cost_centers || min($this->shared_cost_centers) <= 0) {
            throw new \InvalidArgumentException('A shared cost center is required.');
        }
        return $this;
    }

    protected function shared_builder(string $table)
    {
        $builder = $this->db->table($table);
        if (in_array($table, Fund_cost_center::owned_roots(), true)) {
            $builder->whereIn($table . '.' . Fund_cost_center::owner_column($table), $this->shared_cost_centers);
        } elseif (isset(Fund_cost_center::CHILDREN[$table])) {
            [$parent, $key] = Fund_cost_center::CHILDREN[$table];
            if ($table === $this->table && $this->shared_parent_id !== null) { $builder->where($table . '.' . $key, $this->shared_parent_id); }
            $builder->whereIn($table . '.' . $key, $this->db->table($parent)->select('id')
                ->whereIn(Fund_cost_center::owner_column($parent), $this->shared_cost_centers)->where('is_deleted', 0));
        } else {
            throw new \LogicException('Unsupported shared fund table.');
        }
        return $builder;
    }

    /** Restrict every owned source in a SELECT, including nested aggregates/UNIONs. */
    protected function scoped_query(string $sql, array $binds = [])
    {
        if ($this->shared_cost_centers === null) { return $this->db->query($sql, $binds); }
        $ordered = [];
        $index = 0;
        $tables = implode('|', array_merge(Fund_cost_center::owned_roots(), array_keys(Fund_cost_center::CHILDREN)));
        // Quoted SQL literals are consumed intact so question marks and keywords inside them stay literal.
        $pattern = '~\x27(?:\\\\.|\x27\x27|[^\x27])*\x27|"(?:\\\\.|""|[^"])*"|\?|\b(FROM|JOIN)\s+`?(' . $tables . ')`?(?=$|\s|[),])(?!\s*\.)(?:\s+(?:AS\s+)?((?!(?:WHERE|LEFT|RIGHT|INNER|OUTER|JOIN|ON|GROUP|ORDER|LIMIT|UNION|HAVING|SET|FOR|CROSS|AND|OR)\b)[a-zA-Z_][a-zA-Z0-9_]*))?~i';
        $sql = preg_replace_callback($pattern, function ($match) use (&$ordered, &$index, $binds) {
            if ($match[0] === '?') {
                $ordered[] = $binds[$index++];
                return '?';
            }
            if (empty($match[1])) { return $match[0]; }
            $table = strtolower($match[2]);
            $alias = $match[3] ?? $table;
            $placeholders = implode(',', array_fill(0, count($this->shared_cost_centers), '?'));
            array_push($ordered, ...$this->shared_cost_centers);
            if (in_array($table, Fund_cost_center::owned_roots(), true)) {
                $predicate = 'scope_row.' . Fund_cost_center::owner_column($table) . ' IN (' . $placeholders . ')';
            } else {
                [$parent, $key] = Fund_cost_center::CHILDREN[$table];
                $predicate = "EXISTS (SELECT 1 FROM `$parent` scope_parent WHERE scope_parent.id=scope_row.`$key`"
                    . " AND scope_parent.is_deleted=0 AND scope_parent." . Fund_cost_center::owner_column($parent) . " IN ($placeholders))";
            }
            return $match[1] . " (SELECT scope_row.* FROM `$table` scope_row WHERE $predicate) AS `$alias`";
        }, $sql);
        if ($index !== count($binds)) { throw new \LogicException('Shared query binding mismatch.'); }
        return $this->db->query($sql, $ordered);
    }

    public function select($columns = null, $conditions = null, $limit = null, $order = null)
    {
        if ($this->shared_cost_centers === null) { return parent::select($columns, $conditions, $limit, $order); }
        $builder = $this->shared_builder($this->table)->select($columns ?: '*');
        if ($conditions) { $builder->where($conditions); }
        if ($order && ($safe = sanitize_order_by($order))) { $builder->orderBy($safe); }
        if ($limit === 1) { $builder->limit(1); }
        $query = $builder->get();
        return !$query ? false : ($limit === 1 ? $query->getRow() : $query->getResult());
    }

    protected function shared_values(array &$data): bool
    {
        if (in_array($this->table, Fund_cost_center::owned_roots(), true)) {
            $owner = Fund_cost_center::owner_column($this->table);
            if (isset($data[$owner]) && !in_array((int) $data[$owner], $this->shared_cost_centers, true)) { return false; }
            if (!isset($data[$owner]) && count($this->shared_cost_centers) !== 1) { return false; }
            if (!isset($data[$owner]) && count($this->shared_cost_centers) === 1) {
                $data[$owner] = $this->shared_cost_centers[0];
            }
        }
        $references = ['cash_advance_id'=>'cash_advance','petty_cash_id'=>'petty_cash',
            'liquidation_id'=>'liquidation','reimbursement_id'=>'reimbursement',
            'replenishment_id'=>'replenishment','direct_payment_id'=>'direct_payment',
            'accounts_payable_voucher_id'=>'accounts_payable_voucher',
            'purchase_request_id'=>'purchase_request', 'aoq_id'=>'aoq', 'purchase_order_id'=>'purchase_order',
            'receipt_note_id'=>'receipt_note', 'purchase_return_id'=>'purchase_return',
            'purchase_order_item_id'=>'purchase_order_item', 'receipt_note_item_id'=>'receipt_note_item'];
        if ($this->table === 'accounts_payable_voucher' && isset($data['type'], $data['type_id'])) {
            if (!in_array($data['type'], array_merge(array_diff(Fund_cost_center::ROOTS, ['accounts_payable_voucher']), ['receipt_note']), true)) { return false; }
            $references['type_id'] = $data['type'];
        }
        if (isset(Fund_cost_center::CHILDREN[$this->table])) {
            [$parent, $parent_key] = Fund_cost_center::CHILDREN[$this->table];
            if ($this->shared_parent_id !== null && (int)($data[$parent_key] ?? 0) !== $this->shared_parent_id) { return false; }
        }
        $resolved = [];
        foreach ($references as $key => $parent) {
            if (empty($data[$key])) { continue; }
            $query = $this->shared_builder($parent)->where('id', $data[$key])->where('is_deleted', 0)->get(1);
            if (!$query || !$query->getRow()) { return false; }
            $resolved[$key] = $query->getRowArray();
        }
        $receipt = $resolved['receipt_note_id'] ?? null;
        if ($receipt && !empty($data['purchase_order_id']) && (int)$receipt['purchase_order_id'] !== (int)$data['purchase_order_id']) { return false; }
        $order_item = $resolved['purchase_order_item_id'] ?? null;
        if ($order_item && !empty($data['purchase_order_id']) && (int)$order_item['purchase_order_id'] !== (int)$data['purchase_order_id']) { return false; }
        if ($order_item && $receipt && (int)$order_item['purchase_order_id'] !== (int)$receipt['purchase_order_id']) { return false; }
        $receipt_item = $resolved['receipt_note_item_id'] ?? null;
        if ($receipt_item && !empty($data['receipt_note_id']) && (int)$receipt_item['receipt_note_id'] !== (int)$data['receipt_note_id']) { return false; }
        return true;
    }

    public function insert($data = null, bool $returnID = true)
    {
        if ($this->shared_cost_centers === null) { return parent::insert($data, $returnID); }
        if (count($this->shared_cost_centers) !== 1 || !is_array($data) || !$this->shared_values($data)) { return false; }
        if (isset(Fund_cost_center::CHILDREN[$this->table]) && empty($data[Fund_cost_center::CHILDREN[$this->table][1]])) { return false; }
        return parent::insert($data, $returnID);
    }

    public function update($conditions = null, $values = null): bool
    {
        if ($this->shared_cost_centers === null) { return parent::update($conditions, $values); }
        if (count($this->shared_cost_centers) !== 1 || !is_array($values)) { return false; }
        $builder = $this->shared_builder($this->table)->where($conditions);
        $query = (clone $builder)->get();
        if (!$query) { return false; }
        $rows = $query->getResultArray();
        if (!$rows && (in_array($this->table, Fund_cost_center::owned_roots(), true) || isset($conditions['id']))) { return false; }
        foreach ($rows as $row) {
            $candidate = array_replace($row, $values);
            if (!$this->shared_values($candidate)) { return false; }
        }
        $owner = Fund_cost_center::owner_column($this->table);
        if (in_array($this->table, Fund_cost_center::owned_roots(), true) && isset($values[$owner])
            && !in_array((int) $values[$owner], $this->shared_cost_centers, true)) { return false; }
        return (bool) $builder->set($values)->update();
    }

    public function insert_batch($data)
    {
        if ($this->shared_cost_centers === null) { return parent::insert_batch($data); }
        if (count($this->shared_cost_centers) !== 1) { return false; }
        foreach ($data as &$row) {
            if (!$this->shared_values($row)) { return false; }
            if (isset(Fund_cost_center::CHILDREN[$this->table]) && empty($row[Fund_cost_center::CHILDREN[$this->table][1]])) { return false; }
        }
        unset($row);
        return parent::insert_batch($data);
    }
}
