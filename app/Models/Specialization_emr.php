<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Specialization_emr extends MYT_Model
{
    protected $table = 'specialization';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
    ];

    public function __construct($db = null)
    {
        parent::__construct($db ?? db_connect('oakridge_emr'));
    }

    public function get_details($specialization_id = null, $term = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT specialization.*
FROM specialization
WHERE specialization.is_deleted = 0
EOT;

        if ($specialization_id) {
            $sql .= "\nAND specialization.id = ?";
            $binds[] = $specialization_id;
        }

        if ($term) {
            $sql .= "\nAND specialization.name LIKE ?";
            $binds[] = '%' . $term . '%';
        }

        $sql .= " ORDER BY specialization.name ASC ";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function exists_by_id($id)
    {
        if ($id === null || $id === '') {
            return false;
        }

        return (bool) $this->select('id', ['id' => $id, 'is_deleted' => 0], 1);
    }
}

?>
