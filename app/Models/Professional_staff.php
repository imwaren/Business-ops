<?php

namespace App\Models;

use App\Core\MYT_Model;

class Professional_staff extends MYT_Model
{
    protected $table = 'professional_staff';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
        'email',
        'lic_no',
        'signature',
        'description',
        'is_deleted',
    ];

    public function __construct($db = null)
    {
        parent::__construct($db);
    }

    public function get_details($id = null, $term = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT professional_staff.*
FROM professional_staff
WHERE professional_staff.is_deleted = 0
EOT;

        if ($id) {
            $sql .= "\nAND professional_staff.id = ?";
            $binds[] = $id;
        }

        if ($term) {
            $sql .= <<<EOT

AND (
    professional_staff.name LIKE ?
    OR professional_staff.lic_no LIKE ?
)
EOT;
            $binds[] = '%' . $term . '%';
            $binds[] = '%' . $term . '%';
        }

        $sql .= " ORDER BY professional_staff.name ASC ";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function validate_lic_no($lic_no, $id = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT professional_staff.lic_no
FROM professional_staff
WHERE professional_staff.is_deleted = 0
EOT;

        if ($lic_no) {
            $sql .= " AND professional_staff.lic_no = ? ";
            $binds[] = $lic_no;
        }

        if ($id) {
            $sql .= " AND professional_staff.id != ? ";
            $binds[] = $id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Live row by contact email (case-insensitive); exact match fallback.
     */
    public function find_live_by_email($email)
    {
        $normalized = $this->normalized_email($email);
        if (!$normalized) {
            return null;
        }

        $sql = <<<EOT
SELECT professional_staff.*
FROM professional_staff
WHERE professional_staff.is_deleted = 0
AND LOWER(TRIM(professional_staff.email)) = ?
LIMIT 1
EOT;
        $query = $this->db->query($sql, [$normalized]);
        if ($query && ($row = $query->getRow())) {
            return $row;
        }

        return $this->select('', ['email' => $email, 'is_deleted' => 0], 1) ?: null;
    }

    /**
     * Row by contact email including soft-deleted.
     */
    public function find_by_email($email)
    {
        $normalized = $this->normalized_email($email);
        if (!$normalized) {
            return null;
        }

        $sql = <<<EOT
SELECT professional_staff.*
FROM professional_staff
WHERE LOWER(TRIM(professional_staff.email)) = ?
LIMIT 1
EOT;
        $query = $this->db->query($sql, [$normalized]);
        if ($query && ($row = $query->getRow())) {
            return $row;
        }

        return $this->select('', ['email' => $email], 1) ?: null;
    }

    /**
     * Another live row already owns this normalized contact email.
     */
    public function find_live_by_email_excluding_id($email, int $exclude_id)
    {
        $normalized = $this->normalized_email($email);
        if (!$normalized || !$exclude_id) {
            return null;
        }

        $sql = <<<EOT
SELECT professional_staff.id
FROM professional_staff
WHERE professional_staff.is_deleted = 0
AND LOWER(TRIM(professional_staff.email)) = ?
AND professional_staff.id != ?
LIMIT 1
EOT;
        $query = $this->db->query($sql, [$normalized, $exclude_id]);

        return $query && ($row = $query->getRow()) ? $row : null;
    }

    protected function normalized_email($email): ?string
    {
        if ($email === null || $email === '') {
            return null;
        }

        $normalized = strtolower(trim((string) $email));

        return $normalized !== '' ? $normalized : null;
    }
}

?>