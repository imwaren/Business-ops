<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Template_attachment extends MYT_Model
{
    protected $table = 'template_attachment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'template_id',
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
    * Delete all template_attachments by purchase ID
    */
    public function delete_by_template_id($template_id = null, $requester = null)
    {

        $database = $this->db;
        $date_now = date('Y-m-d H:i:s');
        $sql = <<<EOT
UPDATE template_attachment
SET template_attachment.is_deleted = 1, updated_by = ?, updated_on = ?
WHERE template_id = ?
EOT;
        $binds = [$requester, $date_now, $template_id];      
        return $database->query($sql, $binds);
    }

    /**
     * Get purchase details by ID
     */
    public function get_details_by_template_id($template_id = null, $is_payment = null)
    {
        $doc_type = $is_payment?'payment':'waiver';
        $database = \Config\Database::connect();
        $sql = <<<EOT
SELECT *
FROM template_attachment
WHERE template_attachment.template_id = ? 
    AND template_attachment.is_deleted = 0
    AND doc_type = ?
GROUP BY template_attachment.id
EOT;
        $binds = [$template_id, $doc_type];
        $query = $database->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}
?>