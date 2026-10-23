<?php

namespace App\Models;

use App\Core\MYT_Model;

class Direct_payment_attachment extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'direct_payment_attachment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'direct_payment_id',
        'file_name',
        'file_path',
        'file_url',
        'mime',
        'added_by',
        'added_on',
        'updated_by',
        'updated_on',
        'is_deleted',
    ];

    public function get_details($attachment_id = null, $direct_payment_id = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT direct_payment_attachment.*
FROM direct_payment_attachment
WHERE direct_payment_attachment.is_deleted = 0
EOT;

        if ($attachment_id) {
            $sql .= "\nAND direct_payment_attachment.id = ?";
            $binds[] = $attachment_id;
        }
        if ($direct_payment_id) {
            $sql .= "\nAND direct_payment_attachment.direct_payment_id = ?";
            $binds[] = $direct_payment_id;
        }

        $sql .= "\nORDER BY direct_payment_attachment.id ASC";
        $query = $this->scoped_query($sql, $binds);

        return $query ? $query->getResultArray() : false;
    }
}
