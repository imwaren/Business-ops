<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Doctor extends MYT_Model
{
    protected $table = 'doctor';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'master_doctor_id',
        'first_name',
        'middle_name',
        'last_name',
        'full_name',
        'username',
        'password',
        'password_reset',
        'branch_in',
        'license_no',
        'ptr_no',
        's2_no',
        'type',
        'specializations',
        'other_services',
        'affiliated',
        'signature',
        'vat_type',
        'vat',
        'wht_percentage',
        'contact_no',
        'email',
        'suffix', 
        'birthdate', 
        'description', 
        'tin_no', 
        'phic_no', 
        'ahmopi_member',
        'hmo_member',
        'pahmoc_member',
        'consult_pf_fee', 
        'consult_pf_share',
        'profit_sharing',
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

    public function get_details($doctor_id = null, $status = null, $specialization_id = null, $term = null, $role_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT doctor.*,
    emr_role.name AS type_name,
    emr_spec.name AS specialization_name
FROM doctor
LEFT JOIN {OAKRIDGE_EMR_DB}.role AS emr_role
    ON emr_role.id = doctor.type AND emr_role.is_deleted = 0
LEFT JOIN {OAKRIDGE_EMR_DB}.specialization AS emr_spec
    ON emr_spec.id = doctor.specializations AND emr_spec.is_deleted = 0
WHERE doctor.is_deleted = 0
EOT;

        if ($doctor_id) {
            $sql .= <<<EOT

AND doctor.id = ?
EOT;
            $binds[] = $doctor_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND doctor.status = ?
EOT;
            $binds[] = $status;
        }

        if ($specialization_id) {
            $sql .= <<<EOT

AND doctor.specializations = ?
EOT;
            $binds[] = $specialization_id;
        }

        if ($role_id !== null && $role_id !== '' && $role_id !== 'all') {
            $sql .= <<<EOT

AND doctor.type = ?
EOT;
            $binds[] = $role_id;
        }

        if ($term) {
            $sql .= <<<EOT

AND CONCAT_WS(' ',
        doctor.first_name,
        doctor.middle_name,
        doctor.last_name
    ) LIKE ? 
EOT;
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function validate_username($username, $doctor_id = null)
    {
        $sql = <<<EOT
SELECT doctor.username
FROM doctor
WHERE doctor.is_deleted = 0
EOT;

        if ($username) {
            $sql .= " AND REPLACE(LOWER(doctor.username), ' ', '') = ? ";
            $binds[] = $username;
        }

        if ($doctor_id) {
            $sql .= " AND doctor.id != ? ";
            $binds[] = $doctor_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function validate_license_no($license_no, $doctor_id = null)
    {
        $sql = <<<EOT
SELECT doctor.license_no
FROM doctor
WHERE doctor.is_deleted = 0
EOT;

        if ($license_no) {
            $sql .= " AND doctor.license_no = ? ";
            $binds[] = $license_no;
        }

        if ($doctor_id) {
            $sql .= " AND doctor.id != ? ";
            $binds[] = $doctor_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function validate_full_name($first_name = null, $middle_name = null, $last_name = null, $doctor_id = null)
    {
        $sql = <<<EOT
SELECT doctor.full_name
FROM doctor
WHERE doctor.is_deleted = 0
EOT;

        if ($first_name) {
            $sql .= " AND REPLACE(LOWER(doctor.first_name), ' ', '') = ? ";
            $binds[] = $first_name;
        }

        if ($middle_name) {
            $sql .= " AND REPLACE(LOWER(doctor.middle_name), ' ', '') = ? ";
            $binds[] = $middle_name;
        }

        if ($last_name) {
            $sql .= " AND REPLACE(LOWER(doctor.last_name), ' ', '') = ? ";
            $binds[] = $last_name;
        }

        if ($doctor_id) {
            $sql .= " AND doctor.id != ? ";
            $binds[] = $doctor_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>