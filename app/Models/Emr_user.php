<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Emr_user extends MYT_Model
{
    protected $table = 'emr_user';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'master_doctor_id',
        'branch_in',
        'full_name',
        'signature',
        'doctor_fee',
        'ptr_no',
        'license_no',
        's2_no',
        'username',
        'password',
        'role_id',
        'role_name',
        'specializations',
        'status',
        'activated_by',
        'activated_on',
        'inactivated_by',
        'inactivated_on',
        'is_online',
        'added_by',
        'added_on',
        'updated_by',
        'updated_on',
        'is_deleted',
    ];

    public function __construct($db = null)
    {
        parent::__construct($db ?? db_connect('oakridge_emr'));
    }

    public function get_details($user_id = null, $role_id = null, $term = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT emr_user.*
FROM emr_user
WHERE emr_user.is_deleted = 0
EOT;

        if ($user_id) {
            $sql .= "\nAND (emr_user.id = ? OR emr_user.username = ?)";
            $binds[] = $user_id;
            $binds[] = $user_id;
        }

        if ($role_id) {
            $sql .= "\nAND emr_user.role_id = ?";
            $binds[] = $role_id;
        }

        if ($term) {
            $sql .= "\nAND (emr_user.full_name LIKE ? OR emr_user.username LIKE ?)";
            $binds[] = '%' . $term . '%';
            $binds[] = '%' . $term . '%';
        }

        $sql .= " ORDER BY emr_user.full_name ASC ";

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    
}
