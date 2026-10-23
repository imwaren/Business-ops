<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Quotation_history extends MYT_Model
{
    protected $table = 'quotation_history';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'version_no',
        'source_id',
        'col_name',
        'old_value',
        'new_value'
    ];

    public function get_details($quotation_id = null, $version_no = null) {
        $binds = [];
        $sql = <<<EOT
SELECT quotation_history.id,
    quotation_history.version_no,
    quotation_history.source_id,
    quotation_history.col_name,
    quotation_history.old_value,
    quotation_history.new_value,
    quotation_history.added_by,
    quotation_history.added_on
FROM quotation_history
WHERE quotation_history.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'quotation_history.source_id', $quotation_id)) {
            return [];
        }

        if ($version_no) {
            $sql .= <<<EOT

AND quotation_history.version_no = ?
EOT;
            $binds[] = $version_no;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>