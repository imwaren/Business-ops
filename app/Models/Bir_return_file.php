<?php

namespace App\Models;

use App\Core\MYT_Model;

class Bir_return_file extends MYT_Model
{
    protected $table = 'bir_return_file';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'closed_month_id',
        'type',
        'branch_id',
        'year',
        'month',
        'quarter',
        'date_from',
        'date_to',
        'status',
        'file_path',
        'file_url',
        'error_message',
        'added_by',
        'added_on',
        'updated_by',
        'updated_on',
        'is_deleted',
    ];

    public function get_for_closed_month($closed_month_id)
    {
        $sql = <<<EOT
SELECT *
FROM bir_return_file
WHERE is_deleted = 0
    AND closed_month_id = ?
ORDER BY id ASC
EOT;
        $query = $this->db->query($sql, [$closed_month_id]);

        return $query ? $query->getResultArray() : [];
    }

    public function find_return($type, array $filters)
    {
        $binds = [$type];
        $sql = <<<EOT
SELECT *
FROM bir_return_file
WHERE is_deleted = 0
    AND type = ?
EOT;

        $closed_month_id = $filters['closed_month_id'] ?? null;
        if ($closed_month_id !== null && $closed_month_id !== '') {
            $sql .= <<<EOT

    AND closed_month_id = ?
EOT;
            $binds[] = $closed_month_id;
        }

        $branch_id = $filters['branch_id'] ?? null;
        if ($branch_id !== null && $branch_id !== '') {
            $sql .= <<<EOT

    AND branch_id = ?
EOT;
            $binds[] = $branch_id;
        }

        if ($type === '1702_rt') {
            $year = $filters['year'] ?? null;
            $month = $filters['month'] ?? null;
            if ($year !== null && $year !== '') {
                $sql .= <<<EOT

    AND year = ?
EOT;
                $binds[] = $year;
            }
            if ($month !== null && $month !== '') {
                $sql .= <<<EOT

    AND month = ?
EOT;
                $binds[] = $month;
            }
        }

        if ($type === '1702q') {
            $year = $filters['year'] ?? null;
            $quarter = $filters['quarter'] ?? null;
            if ($year !== null && $year !== '') {
                $sql .= <<<EOT

    AND year = ?
EOT;
                $binds[] = $year;
            }
            if ($quarter !== null && $quarter !== '') {
                $sql .= <<<EOT

    AND quarter = ?
EOT;
                $binds[] = $quarter;
            }
        }

        $sql .= ' ORDER BY id DESC LIMIT 1';

        $query = $this->db->query($sql, $binds);
        $row = $query ? $query->getRowArray() : null;

        return $row ?: null;
    }
}
