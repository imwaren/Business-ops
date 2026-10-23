<?php

namespace App\Models;

use App\Core\MYT_Model;
use App\Libraries\Fund_cost_center;

/** Retains PO formatting while serializing the shared master series across owners. */
class Shared_procurement_number extends MYT_Model
{
    protected $table = 'shared_procurement_number';
    protected $allowedFields = [];
    public static function next($db, string $code, int $year): int
    {
        if (!(int)$db->query('SELECT @@in_transaction AS active')->getRowArray()['active']) {
            throw new \RuntimeException('Shared PO numbering requires the document transaction.');
        }
        $prefix=$code.$year;
        $db->query("INSERT IGNORE INTO shared_procurement_number(code,year,last_number)
            SELECT ?,?,COALESCE(MAX(CAST(RIGHT(po_number,6) AS UNSIGNED)),0)
            FROM purchase_order WHERE po_number LIKE ?",[$code,$year,$prefix.'%']);
        $row=$db->query('SELECT last_number FROM shared_procurement_number WHERE code=? AND year=? FOR UPDATE',[$code,$year])->getRowArray();
        $next=(int)$row['last_number']+1;
        if ($next>999999) { throw new \RuntimeException('Shared PO number series exhausted.'); }
        $db->query('UPDATE shared_procurement_number SET last_number=? WHERE code=? AND year=?',[$next,$code,$year]);
        return $next;
    }
}
