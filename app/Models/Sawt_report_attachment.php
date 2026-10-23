<?php

namespace App\Models;

use App\Core\MYT_Model;

class Sawt_report_attachment extends MYT_Model
{
    protected $table      = 'sawt_report_attachment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'date_from',
        'date_to',
        'branch_id',
        'file_name',
        'file_path',
        'file_url',
        'mime',
    ];

    /**
     * Attachments for an exact SAWT report period.
     *
     * @return list<array>
     */
    public function get_by_period(string $date_from, string $date_to, $branch_id = null): array
    {
        $binds = [$date_from, $date_to];
        $sql   = <<<EOT
SELECT
    sawt_report_attachment.id,
    sawt_report_attachment.file_name,
    sawt_report_attachment.file_url,
    sawt_report_attachment.mime,
    sawt_report_attachment.added_on
FROM sawt_report_attachment
WHERE sawt_report_attachment.is_deleted = 0
  AND sawt_report_attachment.date_from = ?
  AND sawt_report_attachment.date_to = ?
EOT;

        if ($branch_id === null || $branch_id === '') {
            $sql .= "\n  AND sawt_report_attachment.branch_id IS NULL\n";
        } else {
            $sql .= "\n  AND sawt_report_attachment.branch_id = ?\n";
            $binds[] = (int) $branch_id;
        }

        $sql .= "ORDER BY sawt_report_attachment.id ASC";

        $query = $this->db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }
}
