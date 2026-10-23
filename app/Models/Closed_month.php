<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Closed_month extends MYT_Model
{
    protected $table = 'closed_month';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'month',
        'year',
        'added_by',
        'added_on',
        'updated_by',
        'updated_on',
        'is_deleted',
    ];

    public function get_details($closed_month_id = null, $month = null, $year = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT closed_month.*
FROM closed_month
WHERE closed_month.is_deleted = 0
EOT;

        if ($closed_month_id) {
            $sql .= <<<EOT

AND closed_month.id = ?
EOT;
            $binds[] = $closed_month_id;
        }

        if ($month !== null && $month !== '') {
            $sql .= <<<EOT

AND closed_month.month = ?
EOT;
            $binds[] = $month;
        }

        if ($year !== null && $year !== '') {
            $sql .= <<<EOT

AND closed_month.year = ?
EOT;
            $binds[] = $year;
        }

        $sql .= " ORDER BY closed_month.year DESC, closed_month.month DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function exists_for_period($month, $year, $exclude_id = null)
    {
        $sql = <<<EOT
SELECT id
FROM closed_month
WHERE is_deleted = 0
    AND month = ?
    AND year = ?
EOT;
        $binds = [$month, $year];

        if ($exclude_id) {
            $sql .= <<<EOT

    AND id <> ?
EOT;
            $binds[] = $exclude_id;
        }

        $sql .= ' LIMIT 1';

        $query = $this->db->query($sql, $binds);
        $row = $query ? $query->getRow() : null;

        return (bool) $row;
    }
}

?>
