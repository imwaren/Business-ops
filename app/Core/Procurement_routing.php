<?php

namespace App\Core;

use App\Libraries\Fund_cost_center;

/** Procurement keeps header ownership separate from line cost-center allocations. */
trait Procurement_routing
{
    use Fund_routing;

    protected function scope_procurement_models(int $branch_id, array $properties): void
    {
        if ($this->fund_router()->procurement_group($branch_id) !== 'master') { return; }
        foreach ($properties as $property) {
            if (!method_exists($this->$property, 'with_shared_cost_centers')) { continue; }
            $this->$property->with_shared_cost_centers($branch_id);
            $table = $this->$property->table;
            if (isset(Fund_cost_center::CHILDREN[$table])) {
                [, $key] = Fund_cost_center::CHILDREN[$table];
                if (str_ends_with($table,'_attachment') && !empty($this->payload[$key])) { $this->$property->with_shared_parent((int)$this->payload[$key]); }
            }
        }
    }

    protected function number_generated_shared_po(int $id, int $branch): bool
    {
        if ($this->fund_router()->procurement_group($branch)!=='master') { return true; }
        $row=$this->db->query('SELECT code FROM branch WHERE id=? AND is_deleted=0',[$branch])->getRowArray();
        $code=str_pad((string)($row['code'] ?? '0'),2,'0',STR_PAD_LEFT);
        $next=\App\Models\Shared_procurement_number::next($this->db,$code,(int)date('Y'));
        return $this->purchase_order->update(['id'=>$id],['po_number'=>$code.date('Ymd').str_pad((string)$next,6,'0',STR_PAD_LEFT)]);
    }

    protected function shared_procurement_inventory(string $type, $row): bool
    {
        try {
            return (new \App\Models\Shared_procurement_inventory($this->db))
                ->post($type, (int)$row->id, (int)$row->branch_id, (int)($this->payload['requester'] ?? 0));
        } catch (\Throwable $e) {
            $this->error_message = $e->getMessage();
            log_message('error', 'Shared procurement inventory failed: {message}', ['message'=>$e->getMessage()]);
            return false;
        }
    }
}
