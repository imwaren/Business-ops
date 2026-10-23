<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class User extends MYT_Model
{
    protected $table = 'user';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'first_name',
        'last_name',
        'middle_name',
        'email',
        'password',
        'role_id',
        'mobile_no',
        'branch_id',
    ];

    public function get_details($user_id = null, $role_ids = null, $branch_id = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT user.*, role.name AS role_name, GROUP_CONCAT(DISTINCT branch.name SEPARATOR ', ') AS branch_names
FROM user
LEFT JOIN role ON user.role_id = role.id
LEFT JOIN branch ON FIND_IN_SET(branch.id, user.branch_id)
WHERE user.is_deleted = 0
EOT;

        if ($user_id) {
            $sql .= <<<EOT

AND user.id = ?
EOT;
            $binds[] = $user_id;
        }

        if ($branch_id) {
            $sql .= <<<EOT

AND FIND_IN_SET(?, user.branch_id)
EOT;
            $binds[] = $branch_id;
        }

        if ($role_ids) {
            // Convert comma separated string into array
            $placeholders = implode(',', array_fill(0, count($role_ids), '?'));
        
            $sql .= <<<EOT
        
AND user.role_id IN ($placeholders)    
EOT;
            $binds = array_merge($binds, $role_ids);
        }
        
        if ($term) {
                $sql .= <<<EOT
    
AND (
    CONCAT_WS(' ', user.first_name, user.middle_name, user.last_name) LIKE ?
    OR user.email LIKE ?
)
EOT;
            $binds[] = '%' . $term . '%';
            $binds[] = '%' . $term . '%';
        }

        //GROUP BY user.id
        $sql .= " GROUP BY user.id ORDER BY user.last_name ASC, user.first_name ASC ";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Get user details by email
     */
    public function get_details_by_email($email)
    {
        
        $sql = <<<EOT
SELECT user.*, role.name AS role
FROM user
LEFT JOIN role ON user.role_id = role.id
WHERE user.is_deleted = 0
    AND user.email = ?
EOT;
        $binds = [$email];
        $query = $this->db->query($sql, $binds);
        return ($query AND $query->getResult()) ? $query->getResultArray()[0] : false;
    }

    public function validate_email($email, $user_id = null)
    {
        $sql = <<<EOT
SELECT user.email
FROM user
WHERE user.is_deleted = 0
EOT;

        if ($email) {
            $sql .= " AND REPLACE(LOWER(user.email), ' ', '') = ? ";
            $binds[] = $email;
        }

        if ($user_id) {
            $sql .= " AND user.id != ? ";
            $binds[] = $user_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_sae_in_charges($sae_in_charge_ids = null) {
        $binds = [];
        $sql = <<<EOT
SELECT user.id, CONCAT(user.first_name, ' ', user.last_name) AS sae_in_charge_name
FROM user
WHERE user.is_deleted = 0
EOT;

        if ($sae_in_charge_ids) {

            // Convert string to array
            $ids = explode(',', $sae_in_charge_ids);

            // Create ?,?,? placeholders dynamically
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $sql .= " AND user.id IN ($placeholders)";

            $binds = $ids;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Batch-load users by id. Accepts array or comma-separated string.
     * Returns id-keyed map: [id => ['id'=>..., 'first_name'=>..., 'last_name'=>..., 'role_id'=>..., 'name'=>...]]
     */
    public function get_by_ids($user_ids = null): array
    {
        if (is_string($user_ids)) {
            $user_ids = explode(',', $user_ids);
        }
        if (!is_array($user_ids)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter($user_ids, static function ($v) {
            return $v !== null && $v !== '';
        })));
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = <<<EOT
SELECT user.id, user.first_name, user.last_name, user.middle_name, user.role_id,
    user.email, user.mobile_no, user.immediate_head, user.signature,
    CONCAT(user.first_name, ' ', user.last_name) AS name
FROM user
WHERE user.id IS NOT NULL
AND user.id IN ($placeholders)
EOT;
        $query = $this->db->query($sql, $ids);
        if (!$query) {
            return [];
        }
        $map = [];
        foreach ($query->getResultArray() as $row) {
            $map[$row['id']] = $row;
        }
        return $map;
    }

    public function get_payors($user_id = null, $role_id = null, $branch_id = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT user.id, 
    CONCAT_WS(' ', user.first_name, user.middle_name, user.last_name) AS name, 
    'payor' AS type
FROM user
LEFT JOIN role ON user.role_id = role.id
LEFT JOIN branch ON FIND_IN_SET(branch.id, user.branch_id)
WHERE user.is_deleted = 0
EOT;

        if ($user_id) {
            $sql .= <<<EOT

AND user.id = ?
EOT;
            $binds[] = $user_id;
        }

        if ($branch_id) {
            $sql .= <<<EOT

AND FIND_IN_SET(?, user.branch_id)
EOT;
            $binds[] = $branch_id;
        }

        if ($role_id) {
            $sql .= <<<EOT

AND user.role_id = ?
EOT;
            $binds[] = $role_id;
        }
        
        if ($term) {
                $sql .= <<<EOT
    
AND (
    CONCAT_WS(' ', user.first_name, user.middle_name, user.last_name) LIKE ?
)
EOT;
            $binds[] = '%' . $term . '%';
        }

        //GROUP BY user.id
        $sql .= " GROUP BY user.id ORDER BY user.last_name ASC, user.first_name ASC ";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>