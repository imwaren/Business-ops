<?php

namespace App\Models;

use App\Core\MYT_Model;
use App\Libraries\Fund_cost_center;

/** Atomic inventory for master-owned procurement delivered to a physical branch. */
class Shared_procurement_inventory extends MYT_Model
{
    protected $table = 'receipt_note';
    protected $allowedFields = [];
    public function post(string $type, int $id, int $owner, int $requester): bool
    {
        if (!in_array($type, ['receipt_note','purchase_return'], true)) {
            throw new \InvalidArgumentException('Unsupported inventory document.');
        }
        if (!(int)$this->db->query('SELECT @@in_transaction AS active')->getRowArray()['active']) {
            throw new \RuntimeException('Shared inventory requires the document approval transaction.');
        }
        $row = $this->db->query("SELECT * FROM `$type` WHERE id=? AND branch_id=? AND is_deleted=0 FOR UPDATE", [$id,$owner])->getRowArray();
        if (!$row) { throw new \InvalidArgumentException('Inventory document does not belong to this shared center.'); }
        $rn = $type === 'receipt_note' ? $row : $this->db->query(
            'SELECT * FROM receipt_note WHERE id=? AND branch_id=? AND is_deleted=0 FOR UPDATE',
            [$row['receipt_note_id'],$owner])->getRowArray();
        if (!$rn) { throw new \InvalidArgumentException('Shared receipt note not found.'); }
        $po = $this->db->query('SELECT * FROM purchase_order WHERE id=? AND branch_id=? AND is_deleted=0', [$rn['purchase_order_id'],$owner])->getRowArray();
        if (!$po) { throw new \InvalidArgumentException('Shared purchase order not found.'); }
        $lines = $this->db->query($type === 'receipt_note'
            ? "SELECT id,item_id,qty,unit,cost,'item' AS kind FROM receipt_note_item WHERE receipt_note_id=? AND is_deleted=0 UNION ALL SELECT id,item_id,qty,unit,0 AS cost,'additional' FROM receipt_note_additional_item WHERE receipt_note_id=? AND is_deleted=0 ORDER BY item_id,kind,id"
            : "SELECT id,item_id,qty,unit,receipt_note_item_id AS source_line_id,0 AS cost,'return' AS kind FROM purchase_return_item WHERE purchase_return_id=? AND is_deleted=0 ORDER BY item_id,id",
            $type === 'receipt_note' ? [$id,$id] : [$id])->getResultArray();
        $lines = array_values(array_filter($lines, static fn($line)=>!empty($line['item_id']) && (float)$line['qty']!=0));
        if (!$lines || ($rn['type'] ?? '') !== 'goods') { return true; }
        if ($type==='purchase_return') {
            if (($rn['status'] ?? '')!=='approved') { throw new \InvalidArgumentException('Returns require an approved receipt.'); }
            $requested=[];
            foreach ($lines as $line) {
                $source_id=(int)($line['source_line_id'] ?? 0);
                $source=$this->db->query('SELECT * FROM receipt_note_item WHERE id=? AND receipt_note_id=? AND is_deleted=0',[$source_id,$rn['id']])->getRowArray();
                if (!$source || (int)$source['item_id']!==(int)$line['item_id'] || (int)$source['unit']!==(int)$line['unit'] || (float)$line['qty']<=0) {
                    throw new \InvalidArgumentException('Return line must match its receipt item and unit.');
                }
                $requested[$source_id]=($requested[$source_id] ?? 0)+(float)$line['qty'];
                $returned=$this->db->query("SELECT COALESCE(SUM(i.qty),0) AS qty FROM purchase_return_item i JOIN purchase_return h ON h.id=i.purchase_return_id WHERE i.receipt_note_item_id=? AND i.is_deleted=0 AND h.is_deleted=0 AND h.status='approved' AND h.id<>?",[$source_id,$id])->getRowArray();
                if ((float)$returned['qty']+$requested[$source_id]>(float)$source['qty']) { throw new \InvalidArgumentException('Return quantity exceeds the received quantity.'); }
            }
        }
        $destination = (int)($rn['inventory_branch_id'] ?? 0);
        if (!$destination && $type === 'purchase_return') {
            throw new \InvalidArgumentException('Receipt inventory destination is not recorded; reconcile before returning shared goods.');
        }
        $destination = $destination ?: (int)($po['delivery_branch_id'] ?? 0);
        $router = new Fund_cost_center($this->db);
        $group = $router->procurement_group($destination);
        if (!$group || $group === 'master') { throw new \InvalidArgumentException('A physical delivery branch is required for shared goods.'); }
        $target = db_connect($group);
        if ($target->hostname !== $this->db->hostname || (int)$target->port !== (int)$this->db->port) {
            throw new \RuntimeException('Shared approval requires inventory databases on the same database endpoint.');
        }
        $schema = $target->getDatabase();
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $schema)) { throw new \RuntimeException('Invalid configured inventory schema.'); }
        $master_schema = $this->db->getDatabase();
        $owned = ['receipt_note','purchase_order','receipt_note_item','receipt_note_additional_item',
            'purchase_return','purchase_return_item','shared_procurement_inventory'];
        $slots = implode(',',array_fill(0,count($owned),'?'));
        $source_engines=$this->db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ($slots)",array_merge([$master_schema],$owned))->getResultArray();
        if (count($source_engines)!==count($owned) || array_filter($source_engines,static fn($t)=>strtoupper((string)$t['ENGINE'])!=='INNODB')) {
            throw new \RuntimeException('Shared document tables must be transactional.');
        }
        if ($this->db->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=? AND EVENT_OBJECT_TABLE IN ('item','branch_item','item_cost') AND ACTION_STATEMENT REGEXP 'gl_post|general_ledger'",[$schema])->getRowArray()) {
            throw new \RuntimeException('Review destination inventory accounting hooks before shared approval.');
        }
        $engines = $this->db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ('item','branch_item','item_cost')", [$schema])->getResultArray();
        if (count($engines)!==3 || array_filter($engines, static fn($t)=>strtoupper((string)$t['ENGINE'])!=='INNODB')) {
            throw new \RuntimeException('Delivery inventory tables must be present and transactional.');
        }
        $this->db->query('UPDATE receipt_note SET inventory_branch_id=? WHERE id=? AND branch_id=?', [$destination,$rn['id'],$owner]);
        $already = $this->db->query('SELECT id FROM shared_procurement_inventory WHERE document_type=? AND document_id=? FOR UPDATE', [$type,$id])->getRowArray();
        if ($already) { return true; }
        $this->db->query('INSERT INTO shared_procurement_inventory(document_type,document_id,branch_id,inventory_branch_id,added_by,added_on) VALUES(?,?,?,?,?,NOW())', [$type,$id,$owner,$destination,$requester]);
        foreach ($lines as $line) {
            $item = $this->db->query("SELECT id,cost,weighted_cost FROM `$schema`.item WHERE id=? AND is_deleted=0 FOR UPDATE", [$line['item_id']])->getRowArray();
            if (!$item) { throw new \InvalidArgumentException('Item not found in delivery inventory.'); }
            $conversions = $this->db->query('SELECT * FROM item_conversion WHERE item_id=? AND is_deleted=0 ORDER BY id', [$line['item_id']])->getResultArray();
            $source = null;
            foreach ($conversions as $conversion) { if ((int)$conversion['id']===(int)$line['unit']) { $source=$conversion; } }
            if (!$source || (float)$source['qty']<=0) { throw new \InvalidArgumentException('A valid source conversion is required for inventory.'); }
            $delta = (float)$line['qty'] * ($type==='receipt_note' ? 1 : -1);
            foreach ($conversions as $conversion) {
                $stock = $this->db->query("SELECT * FROM `$schema`.branch_item WHERE item_id=? AND branch_id=? AND unit=? AND is_deleted=0 FOR UPDATE", [$line['item_id'],$destination,$conversion['id']])->getRowArray();
                if (!$stock) {
                    $this->db->query("INSERT INTO `$schema`.branch_item(item_id,branch_id,unit,beggining_qty,current_qty,added_by,added_on,is_deleted) VALUES(?,?,?,0,0,?,NOW(),0)", [$line['item_id'],$destination,$conversion['id'],$requester]);
                    $stock=['id'=>$this->db->insertID(),'current_qty'=>0];
                }
                $adjustment=$delta*((float)$conversion['qty']/(float)$source['qty']);
                $this->db->query("UPDATE `$schema`.branch_item SET current_qty=?,latest_activity=?,latest_activity_id=?,updated_by=?,updated_on=NOW() WHERE id=?", [(float)$stock['current_qty']+$adjustment,'Shared '.($type==='receipt_note'?'Receipt Note':'Purchase Return').' (master)',$id,$requester,$stock['id']]);
            }
            $purchase = null;
            foreach ($conversions as $conversion) { if (($conversion['conversion_type'] ?? '') === 'purchase') { $purchase=$conversion; break; } }
            if (!$purchase) { throw new \InvalidArgumentException('Purchase-unit conversion is required.'); }
            $quantity = $delta*((float)$purchase['qty']/(float)$source['qty']);
            $current=$this->db->query("SELECT * FROM `$schema`.item_cost WHERE branch_id=? AND item_id=? AND is_deleted=0 ORDER BY id DESC LIMIT 1 FOR UPDATE", [$destination,$line['item_id']])->getRowArray();
            $current=$current ?: ['last_value'=>0,'latest_total'=>0,'current_amount'=>(float)$item['cost']];
            $at_current=$type==='purchase_return' || $line['kind']==='additional';
            $cost=$at_current ? (float)$current['current_amount'] : (float)$line['cost'];
            $next=(new \App\Models\Item_cost($this->db))->compute_next($current,$quantity,$cost,$at_current);
            $this->db->query("INSERT INTO `$schema`.item_cost(branch_id,item_id,last_value,latest_total,current_amount,added_by,added_on,is_deleted) VALUES(?,?,?,?,?,?,NOW(),0)", [$destination,$line['item_id'],$next['last_value'],$next['latest_total'],$next['current_amount'],$requester]);
            $this->db->query("UPDATE `$schema`.item SET weighted_cost=?,updated_by=?,updated_on=NOW() WHERE id=?", [$next['current_amount'],$requester,$line['item_id']]);
            if (!$at_current) { $this->db->query("UPDATE `$schema`.item SET cost=? WHERE id=?", [$cost,$line['item_id']]); }
        }
        return true;
    }
}
