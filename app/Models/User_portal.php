<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class User_portal extends MYT_Model
{
    protected $table = 'user_portal';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'company_id',
        'username',
        'password',
        'reset_token',
        'reset_token_expiry'
    ];

    public function get_details($company_id = null, $user_portal_id = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT user_portal.*, company.name as company_name
FROM user_portal
LEFT JOIN company ON user_portal.company_id = company.id
WHERE user_portal.is_deleted = 0
EOT;

        if ($company_id) {
            $sql .= <<<EOT

AND user_portal.company_id = ?
EOT;
            $binds[] = $company_id;
        }

        if ($user_portal_id) {
            $sql .= <<<EOT

AND user_portal.id = ?
EOT;
            $binds[] = $user_portal_id;
        }

        if ($term) {
            $sql .= <<<EOT

AND (user_portal.username LIKE ? OR company.name LIKE ?)
EOT;
            $binds[] = '%' . $term . '%';
            $binds[] = '%' . $term . '%';
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_corporate_consent($company_id = null, $date_from = null, $date_to = null, $term = null, $page = null, $data_size = null)
    {
        $sql = <<<EOT
SELECT
  user_portal.username,
  user_portal.company_id,
  company.name AS company_name,
  CASE WHEN user_portal.is_nda = 1 THEN 'yes' ELSE 'no' END AS consent,
  user_portal.nda_signed_on AS consent_date
FROM user_portal
LEFT JOIN company ON user_portal.company_id = company.id
WHERE user_portal.is_deleted = 0
EOT;

        $filter = $this->_corporate_consent_filters($company_id, $date_from, $date_to, $term);
        $sql .= $filter['sql'];
        $sql .= "\nORDER BY user_portal.username ASC";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $filter['binds']);
        return $query ? $query->getResultArray() : false;
    }

    public function get_corporate_consent_count($company_id = null, $date_from = null, $date_to = null, $term = null)
    {
        $sql = <<<EOT
SELECT COUNT(*) AS total_count
FROM user_portal
LEFT JOIN company ON user_portal.company_id = company.id
WHERE user_portal.is_deleted = 0
EOT;

        $filter = $this->_corporate_consent_filters($company_id, $date_from, $date_to, $term);
        $sql .= $filter['sql'];

        $query = $this->db->query($sql, $filter['binds']);
        return $query ? $query->getRow()->total_count : 0;
    }

    /**
     * Shared WHERE fragments for corporate consent list/count.
     *
     * @return array{sql: string, binds: array}
     */
    private function _corporate_consent_filters($company_id, $date_from, $date_to, $term)
    {
        $sql = '';
        $binds = [];

        if ($company_id) {
            $sql .= "\nAND user_portal.company_id = ?";
            $binds[] = $company_id;
        }

        if (! empty($date_from)) {
            $sql .= "\nAND user_portal.nda_signed_on >= ?";
            $binds[] = $date_from;
        }

        if (! empty($date_to)) {
            $sql .= "\nAND user_portal.nda_signed_on < DATE_ADD(?, INTERVAL 1 DAY)";
            $binds[] = $date_to;
        }

        if ($term) {
            $sql .= "\nAND (user_portal.username LIKE ? OR company.name LIKE ?)";
            $binds[] = '%' . $term . '%';
            $binds[] = '%' . $term . '%';
        }

        return ['sql' => $sql, 'binds' => $binds];
    }
}

?>