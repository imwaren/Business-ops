<?php

namespace App\Models;

use App\Core\MYT_Model;

class Accounts_payable_voucher_attachment extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'accounts_payable_voucher_attachment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'accounts_payable_voucher_id',
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

    public function get_details($attachment_id = null, $accounts_payable_voucher_id = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT accounts_payable_voucher_attachment.*
FROM accounts_payable_voucher_attachment
WHERE accounts_payable_voucher_attachment.is_deleted = 0
EOT;

        if ($attachment_id) {
            $sql .= "\nAND accounts_payable_voucher_attachment.id = ?";
            $binds[] = $attachment_id;
        }
        if ($accounts_payable_voucher_id) {
            $sql .= "\nAND accounts_payable_voucher_attachment.accounts_payable_voucher_id = ?";
            $binds[] = $accounts_payable_voucher_id;
        }

        $sql .= "\nORDER BY accounts_payable_voucher_attachment.id ASC";
        $query = $this->scoped_query($sql, $binds);

        return $query ? $query->getResultArray() : false;
    }
}
