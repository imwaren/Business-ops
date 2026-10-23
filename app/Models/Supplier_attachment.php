<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Supplier_attachment extends MYT_Model
{
    protected $table = 'supplier_attachment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'supplier_id',
        'file_name',
        'file_url',
        'mime',
        'added_on',
        'added_by',
        'updated_on',
        'updated_by',
        'is_deleted'
    ];

    /**
    * Delete all supplier_attachments by purchase ID
    */
    public function delete_by_supplier_id($supplier_id = null, $requester = null)
    {

        $database = $this->db;
        $date_now = date('Y-m-d H:i:s');
        $sql = <<<EOT
UPDATE supplier_attachment
SET supplier_attachment.is_deleted = 1, updated_by = ?, updated_on = ?
WHERE supplier_id = ?
EOT;
        $binds = [$requester, $date_now, $supplier_id];      
        return $database->query($sql, $binds);
    }

    /**
     * Get purchase details by ID
     */
    public function get_details_by_supplier_id($supplier_id = null, $is_payment = null)
    {
        $doc_type = $is_payment?'payment':'waiver';
        $database = \Config\Database::connect();
        $sql = <<<EOT
SELECT *
FROM supplier_attachment
WHERE supplier_attachment.supplier_id = ? 
    AND supplier_attachment.is_deleted = 0
    AND doc_type = ?
GROUP BY supplier_attachment.id
EOT;
        $binds = [$supplier_id, $doc_type];
        $query = $database->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}
?>