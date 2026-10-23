<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Consultation extends MYT_Model
{
    protected $table = 'consultation';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
    ];

    public function find_by_name($name)
    {
        $name = preg_replace('/\s+/', ' ', trim((string) $name));
        $sql = <<<EOT
SELECT consultation.*
FROM consultation
WHERE consultation.is_deleted = 0
AND TRIM(consultation.name) = ?
LIMIT 1
EOT;
        $query = $this->db->query($sql, [$name]);
        return $query ? $query->getRowArray() : false;
    }

    public function get_details($consultation_id = null, $term = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT consultation.*
FROM consultation
WHERE consultation.is_deleted = 0
EOT;

        if ($consultation_id) {
            $sql .= "\nAND consultation.id = ?";
            $binds[] = $consultation_id;
        }

        if ($term) {
            $sql .= "\nAND consultation.name LIKE ?";
            $binds[] = '%' . $term . '%';
        }

        $sql .= " ORDER BY consultation.name ASC ";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>
