<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Nurse extends MYT_Model
{
    protected $table = 'nurse';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'first_name',
        'middle_name',
        'last_name',
        'full_name',
        'contact_no',
        'email',
        'suffix',
        'birthdate',
        'username',
        'password',
        'password_reset',
        'branch_in',
        'license_no',
        'status',
        'activated_by',
        'activated_on',
        'inactivated_by',
        'inactivated_on',
        'added_by',
        'added_on',
        'updated_by',
        'updated_on',
        'is_deleted',
    ];

    public function get_details($nurse_id = null, $status = null, $term = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT nurse.*
FROM nurse
WHERE nurse.is_deleted = 0
EOT;

        if ($nurse_id) {
            $sql .= <<<EOT

AND nurse.id = ?
EOT;
            $binds[] = $nurse_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND nurse.status = ?
EOT;
            $binds[] = $status;
        }

        if ($term) {
            $sql .= <<<EOT

AND CONCAT_WS(' ',
        nurse.first_name,
        nurse.middle_name,
        nurse.last_name
    ) LIKE ?
EOT;
            $binds[] = '%' . $term . '%';
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function validate_username($username, $nurse_id = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT nurse.username
FROM nurse
WHERE nurse.is_deleted = 0
EOT;

        if ($username) {
            $sql .= " AND REPLACE(LOWER(nurse.username), ' ', '') = ? ";
            $binds[] = $username;
        }

        if ($nurse_id) {
            $sql .= " AND nurse.id != ? ";
            $binds[] = $nurse_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function validate_license_no($license_no, $nurse_id = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT nurse.license_no
FROM nurse
WHERE nurse.is_deleted = 0
EOT;

        if ($license_no) {
            $sql .= " AND nurse.license_no = ? ";
            $binds[] = $license_no;
        }

        if ($nurse_id) {
            $sql .= " AND nurse.id != ? ";
            $binds[] = $nurse_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * License held by a different nurse (same username allowed — master + branch copies).
     */
    public function validate_license_no_other_username($license_no, $username)
    {
        return $this->find_username_with_license_other_than($license_no, $username) !== null;
    }

    /**
     * First conflicting username on this connection, or null.
     */
    public function find_username_with_license_other_than($license_no, $username)
    {
        if (!$license_no || !$username) {
            return null;
        }

        $username_key = strtolower(preg_replace('/\s+/', '', (string) $username));
        $binds = [$license_no, $username_key];
        $sql = <<<EOT
SELECT nurse.username
FROM nurse
WHERE nurse.is_deleted = 0
 AND TRIM(nurse.license_no) = ?
 AND REPLACE(LOWER(nurse.username), ' ', '') != ?
 LIMIT 1
EOT;

        $query = $this->db->query($sql, $binds);
        if (!$query) {
            return null;
        }

        $row = $query->getRow();
        if (!$row || empty($row->username)) {
            return null;
        }

        return (string) $row->username;
    }

    public function validate_full_name($first_name = null, $middle_name = null, $last_name = null, $nurse_id = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT nurse.full_name
FROM nurse
WHERE nurse.is_deleted = 0
EOT;

        if ($first_name) {
            $sql .= " AND REPLACE(LOWER(nurse.first_name), ' ', '') = ? ";
            $binds[] = $first_name;
        }

        if ($middle_name) {
            $sql .= " AND REPLACE(LOWER(nurse.middle_name), ' ', '') = ? ";
            $binds[] = $middle_name;
        }

        if ($last_name) {
            $sql .= " AND REPLACE(LOWER(nurse.last_name), ' ', '') = ? ";
            $binds[] = $last_name;
        }

        if ($nurse_id) {
            $sql .= " AND nurse.id != ? ";
            $binds[] = $nurse_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Full name held by a different nurse (same username allowed — master + branch copies).
     */
    public function validate_full_name_other_username($first_name, $middle_name, $last_name, $username)
    {
        if (!$username) {
            return false;
        }

        $binds = [];
        $sql = <<<EOT
SELECT nurse.full_name
FROM nurse
WHERE nurse.is_deleted = 0
EOT;

        if ($first_name) {
            $sql .= " AND REPLACE(LOWER(nurse.first_name), ' ', '') = ? ";
            $binds[] = $first_name;
        }

        if ($middle_name) {
            $sql .= " AND REPLACE(LOWER(nurse.middle_name), ' ', '') = ? ";
            $binds[] = $middle_name;
        }

        if ($last_name) {
            $sql .= " AND REPLACE(LOWER(nurse.last_name), ' ', '') = ? ";
            $binds[] = $last_name;
        }

        $username_key = strtolower(preg_replace('/\s+/', '', (string) $username));
        $sql .= " AND REPLACE(LOWER(nurse.username), ' ', '') != ? ";
        $binds[] = $username_key;

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>
