<?php

namespace App\Models;

use App\Core\MYT_Model;

class User_clinic extends MYT_Model
{
    protected $table = 'user';
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
        'is_first_login',
        'added_by',
        'added_on',
        'updated_by',
        'updated_on',
        'is_deleted',
    ];

    /**
     * Live branch user by login (case-insensitive); exact match fallback for unicode logins.
     */
    public function find_live_by_email($email)
    {
        $normalized = $this->normalized_email($email);
        if (!$normalized) {
            return null;
        }

        $sql = <<<EOT
SELECT user.*
FROM user
WHERE user.is_deleted = 0
AND LOWER(TRIM(user.email)) = ?
LIMIT 1
EOT;
        $query = $this->db->query($sql, [$normalized]);
        if ($query && ($row = $query->getRow())) {
            return $row;
        }

        return $this->select('', ['email' => $email, 'is_deleted' => 0], 1) ?: null;
    }

    /**
     * Branch user by login including soft-deleted (unique email may still be occupied).
     */
    public function find_by_email($email)
    {
        $normalized = $this->normalized_email($email);
        if (!$normalized) {
            return null;
        }

        $sql = <<<EOT
SELECT user.*
FROM user
WHERE LOWER(TRIM(user.email)) = ?
LIMIT 1
EOT;
        $query = $this->db->query($sql, [$normalized]);
        if ($query && ($row = $query->getRow())) {
            return $row;
        }

        return $this->select('', ['email' => $email], 1) ?: null;
    }

    /**
     * Live branch user linked to professional_staff with the given license number.
     */
    public function find_live_by_staff_license($lic_no)
    {
        if (!$lic_no) {
            return null;
        }

        $sql = <<<EOT
SELECT user.*
FROM user
INNER JOIN professional_staff ON professional_staff.id = user.professional_staff_id
WHERE user.is_deleted = 0
AND professional_staff.is_deleted = 0
AND professional_staff.lic_no = ?
LIMIT 1
EOT;
        $query = $this->db->query($sql, [$lic_no]);

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