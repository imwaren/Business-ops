<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Doctor_hmo extends MYT_Model
{
    protected $table = 'doctor_hmo';
    protected $primaryKey = 'id';
    protected $sync_error = '';
    protected $allowedFields = [
        'doctor_id',
        'company_id',
        'added_by',
        'added_on',
        'updated_by',
        'updated_on',
        'is_deleted',
    ];

    public function get_by_doctor($doctor_id = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT doctor_hmo.id,
    doctor_hmo.doctor_id,
    doctor_hmo.company_id,
    company.name AS company_name
FROM doctor_hmo
LEFT JOIN company ON company.id = doctor_hmo.company_id AND company.is_deleted = 0
WHERE doctor_hmo.is_deleted = 0
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'doctor_hmo.doctor_id', $doctor_id)) {
            return [];
        }

        $sql .= <<<EOT

ORDER BY doctor_hmo.id ASC
EOT;

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function sync_for_doctor($doctor_id, array $company_ids, $requester = 0): bool
    {
        $company_ids = array_values(array_unique(array_filter(array_map('intval', $company_ids))));

        $rows = $this->select('id, company_id, is_deleted', [
            'doctor_id' => $doctor_id,
        ]);

        if ($rows === false) {
            $this->sync_error = $this->error();
            return false;
        }

        $active = [];
        $inactive = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            if ((int) $row['is_deleted'] === 0) {
                $active[(int) $row['company_id']] = (int) $row['id'];
            } else {
                $inactive[(int) $row['company_id']] = (int) $row['id'];
            }
        }

        $to_delete = array_diff(array_keys($active), $company_ids);
        $to_add = array_diff($company_ids, array_keys($active));
        $now = date('Y-m-d H:i:s');

        foreach ($to_delete as $company_id) {
            if (!$this->update(['id' => $active[$company_id]], [
                'is_deleted' => 1,
                'updated_by' => $requester,
                'updated_on' => $now,
            ])) {
                $this->sync_error = 'remove company ' . $company_id . ': ' . $this->error();
                return false;
            }
        }

        foreach ($to_add as $company_id) {
            if (isset($inactive[$company_id])) {
                $saved = $this->update(['id' => $inactive[$company_id]], [
                    'is_deleted' => 0,
                    'updated_by' => $requester,
                    'updated_on' => $now,
                ]);
            } else {
                $saved = $this->insert([
                    'doctor_id' => $doctor_id,
                    'company_id' => $company_id,
                    'added_by' => $requester,
                    'added_on' => $now,
                ]) !== false;
            }

            if (!$saved) {
                $this->sync_error = 'add company ' . $company_id . ': ' . $this->error();
                log_message('error', 'doctor_hmo sync failed for doctor ' . $doctor_id . ' - ' . $this->sync_error);
                return false;
            }
        }

        return true;
    }

    public function sync_error(): string
    {
        return $this->sync_error;
    }
}

?>