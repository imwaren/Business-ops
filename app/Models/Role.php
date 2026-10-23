<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Role extends MYT_Model
{
    protected $table = 'role';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
        'description'
    ];

    public function get_details($role_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT role.*
FROM role
WHERE role.is_deleted = 0
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'role.id', $role_id)) {
            return [];
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Batch-load roles by id. Returns id-keyed map.
     */
    public function get_by_ids($role_ids = null): array
    {
        $rows = $this->get_details(is_array($role_ids) ? $role_ids : ($role_ids ? [$role_ids] : [])) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $map[$row['id']] = $row;
        }
        return $map;
    }
}

?>