<?php

namespace App\Core;

/** Opt-in ownership for manual journals stored together in master. */
trait Journal_center_scope
{
    private ?int $journal_cost_center = null;
    private ?int $journal_parent = null;

    public function with_journal_cost_center(int $id): self
    {
        $this->journal_cost_center = $id;
        return $this;
    }

    public function with_journal_parent(int $id): self
    {
        $this->journal_parent = $id;
        return $this;
    }

    private function journal_builder()
    {
        $builder = $this->db->table($this->table);
        if ($this->table === 'journal_entry') {
            if ($this->journal_cost_center !== null) {
                $builder->where('cost_center_id', $this->journal_cost_center);
            }
        } else {
            if ($this->journal_parent !== null) {
                $builder->where('journal_entry_id', $this->journal_parent);
            }
            if ($this->journal_cost_center !== null) {
                $parents = $this->db->table('journal_entry')->select('id')
                    ->where('cost_center_id', $this->journal_cost_center)->where('is_deleted', 0);
                $builder->whereIn('journal_entry_id', $parents);
            }
        }
        return $builder;
    }

    public function select($columns = null, $conditions = null, $limit = null, $order = null)
    {
        if ($this->journal_cost_center === null && $this->journal_parent === null) {
            return parent::select($columns, $conditions, $limit, $order);
        }
        $builder = $this->journal_builder()->select($columns ?: '*');
        if ($conditions) { $builder->where($conditions); }
        if ($order && ($safe = sanitize_order_by($order))) { $builder->orderBy($safe); }
        if ($limit) { $builder->limit($limit); }
        $query = $builder->get();
        return !$query ? false : ($limit === 1 ? $query->getRow() : $query->getResult());
    }

    private function journal_values_valid(array $data): bool
    {
        if ($this->table === 'journal_entry') {
            return $this->journal_cost_center === null || !array_key_exists('cost_center_id', $data)
                || (int) $data['cost_center_id'] === $this->journal_cost_center;
        }
        if (!array_key_exists('journal_entry_id', $data)) { return true; }
        $parent = (int) $data['journal_entry_id'];
        if ($this->journal_parent !== null && $parent !== $this->journal_parent) { return false; }
        if ($this->journal_cost_center === null) { return true; }
        return (bool) $this->db->table('journal_entry')->where('id', $parent)
            ->where('cost_center_id', $this->journal_cost_center)->where('is_deleted', 0)->get(1)->getRow();
    }

    public function insert($data = null, bool $returnID = true)
    {
        if (!$this->journal_values_valid($data ?? [])) { return false; }
        if ($this->table === 'journal_entry_item' && $this->journal_cost_center !== null
            && empty($data['journal_entry_id'])) { return false; }
        if ($this->table === 'journal_entry' && $this->journal_cost_center !== null) {
            $data['cost_center_id'] = $this->journal_cost_center;
        }
        return parent::insert($data, $returnID);
    }

    public function update($conditions = null, $data = null): bool
    {
        if (!$this->journal_values_valid($data ?? [])) { return false; }
        if ($this->journal_cost_center === null && $this->journal_parent === null) {
            return parent::update($conditions, $data);
        }
        return $this->journal_builder()->where($conditions)->update($data);
    }

    public function lock_for_write(int $id)
    {
        $sql = $this->journal_builder()->where('id', $id)->where('is_deleted', 0)->getCompiledSelect();
        return $this->db->query($sql . ' FOR UPDATE')->getRow();
    }
}
