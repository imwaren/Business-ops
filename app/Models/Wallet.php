<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Wallet extends MYT_Model
{
    protected $table = 'wallet';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'patient_id',
        'company_id',
        'amount',
        'remaining_balance',
        'valid_from',
        'valid_to',
    ];

    public function get_details($wallet_id = null, $patient_id = null, $company_id = null, $valid_from = null, $valid_to = null, $page = null, $data_size = null) {
        $binds = [];
        $key = ENCRYPTION_KEY;
        $sql = <<<EOT
SELECT wallet.*, 
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    company.name AS company_name
FROM wallet
LEFT JOIN patient ON patient.id = wallet.patient_id
LEFT JOIN company ON company.id = wallet.company_id
WHERE wallet.is_deleted = 0
EOT;

        if ($wallet_id) {
            $sql .= <<<EOT

AND wallet.id = ?
EOT;
            $binds[] = $wallet_id;
        }

        if ($patient_id) {
            $sql .= <<<EOT

AND wallet.patient_id = ?
EOT;
            $binds[] = $patient_id;
        }

        if ($company_id) {
            $sql .= <<<EOT

AND wallet.company_id = ?
EOT;
            $binds[] = $company_id;
        }

        if ($valid_from) {
            $sql .= <<<EOT

AND wallet.valid_from = ?
EOT;
            $binds[] = $valid_from;
        }

        if ($valid_to) {
            $sql .= <<<EOT

AND wallet.valid_to = ?
EOT;
            $binds[] = $valid_to;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_by_company($company_id = null, $valid_from = null, $valid_to = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT wallet.company_id, 
	company.name AS company_name,
    wallet.valid_from,
    wallet.valid_to,
    wallet.amount,
    COUNT(wallet.patient_id) AS patient_count
FROM wallet
LEFT JOIN company ON company.id = wallet.company_id
WHERE wallet.is_deleted = 0
EOT;

        if ($company_id) {
            $sql .= <<<EOT

AND wallet.company_id = ?
EOT;
            $binds[] = $company_id;
        }

        if ($valid_from) {
            $sql .= <<<EOT

AND wallet.valid_from = ?
EOT;
            $binds[] = $valid_from;
        }

        if ($valid_to) {
            $sql .= <<<EOT

AND wallet.valid_to = ?
EOT;
            $binds[] = $valid_to;
        }

        $sql .= <<<EOT

GROUP BY wallet.company_id, wallet.valid_from, wallet.valid_to, wallet.amount
ORDER BY wallet.valid_from DESC
EOT;

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>