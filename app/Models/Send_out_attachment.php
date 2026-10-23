<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Send_out_attachment extends MYT_Model
{
    protected $table = 'send_out_attachment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'send_out_id',
        'file_name',
        'file_path',
        'file_url',
        'mime',
    ];

    public function get_details($send_out_attachment_id = null, $send_out_id = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT send_out_attachment.*
FROM send_out_attachment
WHERE send_out_attachment.is_deleted = 0
EOT;

        if ($send_out_attachment_id) {
            $sql .= <<<EOT

AND send_out_attachment.id = ?
EOT;
            $binds[] = $send_out_attachment_id;
        }

        if ($send_out_id) {
            $sql .= <<<EOT

AND send_out_attachment.send_out_id = ?
EOT;
            $binds[] = $send_out_id;
        }

        $sql .= "\nORDER BY send_out_attachment.id ASC";

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}
