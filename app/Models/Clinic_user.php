<?php

namespace App\Models;

use App\Core\MYT_Model;

class Clinic_user extends MYT_Model
{
    protected $table = 'clinic_user';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'doctor_id',
        'professional_staff_id',
        'name',
        'email',
        'password',
        'role_id',
        'status',
        'token',
        'token_expiry',
        'lane',
        'branch_in',
        'is_first_login',
        'added_by',
        'added_on',
        'updated_by',
        'updated_on',
        'is_deleted',
    ];

    public function __construct($db = null)
    {
        parent::__construct($db ?? db_connect('master'));
    }

    public function get_details($user_id = null, $role_id = null, $term = null, $status = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT clinic_user.*, role.name AS role_name
FROM clinic_user
LEFT JOIN role ON role.id = clinic_user.role_id AND role.is_deleted = 0
WHERE clinic_user.is_deleted = 0
EOT;

        if ($user_id) {
            $sql .= "\nAND (clinic_user.id = ? OR clinic_user.email = ?)";
            $binds[] = $user_id;
            $binds[] = $user_id;
        }

        if ($role_id) {
            $sql .= "\nAND clinic_user.role_id = ?";
            $binds[] = $role_id;
        }

        if ($status) {
            $sql .= "\nAND clinic_user.status = ?";
            $binds[] = $status;
        }

        if ($term) {
            $sql .= <<<EOT

AND (
    clinic_user.name LIKE ?
    OR clinic_user.email LIKE ?
)
EOT;
            $binds[] = '%' . $term . '%';
            $binds[] = '%' . $term . '%';
        }

        $sql .= " ORDER BY clinic_user.name ASC ";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function validate_email($email, $user_id = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT clinic_user.email
FROM clinic_user
WHERE clinic_user.is_deleted = 0
EOT;

        if ($email) {
            $sql .= " AND LOWER(clinic_user.email) = ? ";
            $binds[] = strtolower($email);
        }

        if ($user_id) {
            $sql .= " AND clinic_user.id != ? ";
            $binds[] = $user_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function validate_username($username, $user_id = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT clinic_user.username
FROM clinic_user
WHERE clinic_user.is_deleted = 0
EOT;

        if ($username) {
            $sql .= " AND LOWER(clinic_user.username) = ? ";
            $binds[] = strtolower($username);
        }

        if ($user_id) {
            $sql .= " AND clinic_user.id != ? ";
            $binds[] = $user_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>