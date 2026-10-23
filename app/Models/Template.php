<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Template extends MYT_Model
{
    protected $table = 'template';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'foreign_id',
        'text_field',
        'numeric_field',
        'date_field',
        'enum_field',
        'unique_field',
    ];

    public function get_details($template_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT template.*
FROM template
WHERE template.is_deleted = 0
EOT;

        if ($template_id) {
            $sql .= <<<EOT

AND template.id = ?
EOT;
            $binds[] = $template_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>