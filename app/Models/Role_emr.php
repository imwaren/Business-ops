<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Role_emr extends MYT_Model
{
    protected $table = 'role';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
    ];

    public function __construct($db = null)
    {
        parent::__construct($db ?? db_connect('oakridge_emr'));
    }

    public function get_details($role_id = null, $term = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT role.*
FROM role
WHERE role.is_deleted = 0
EOT;

        if ($role_id) {
            $sql .= "\nAND role.id = ?";
            $binds[] = $role_id;
        }

        if ($term) {
            $sql .= "\nAND role.name LIKE ?";
            $binds[] = '%' . $term . '%';
        }

        $sql .= " ORDER BY role.name ASC ";

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

    public function get_name_by_id($id)
    {
        $row = $this->select('name', ['id' => $id, 'is_deleted' => 0], 1);
        return $row ? $row->name : null;
    }
}

?>
