<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Company_validity_date extends MYT_Model
{
    protected $table = 'company_validity_date';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'consumable_amount_per_employee',
        'wallet_validity_date_from',
        'wallet_validity_date_to',
    ];

    public function get_details($company_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT company_validity_date.*
FROM company_validity_date
WHERE company_validity_date.is_deleted = 0
EOT;

        if ($company_id) {
            $sql .= <<<EOT

AND company_validity_date.company_id = ?
EOT;
            $binds[] = $company_id;
        }

        $sql .= <<<EOT
ORDER BY company_validity_date.id DESC
EOT;

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function find_overlapping($company_id, $valid_from, $valid_to)
    {
        $sql = <<<EOT
SELECT company_validity_date.*
FROM company_validity_date
WHERE company_validity_date.is_deleted = 0
AND company_validity_date.company_id = ?
AND company_validity_date.wallet_validity_date_from <= ?
AND company_validity_date.wallet_validity_date_to >= ?
AND NOT (
    company_validity_date.wallet_validity_date_from = ?
    AND company_validity_date.wallet_validity_date_to = ?
)
LIMIT 1
EOT;

        $query = $this->db->query($sql, [$company_id, $valid_to, $valid_from, $valid_from, $valid_to]);
        return $query ? $query->getRow() : false;
    }

// DELIMITER $$

// -- INSERT TRIGGER
// CREATE TRIGGER trg_company_after_insert
// AFTER INSERT ON company
// FOR EACH ROW
// BEGIN
//     IF NEW.wallet_validity_date_from IS NOT NULL 
//        AND NEW.wallet_validity_date_to IS NOT NULL THEN

//         INSERT INTO company_validity_date (
//             company_id,
//             consumable_amount_per_employee,
//             wallet_validity_date_from,
//             wallet_validity_date_to,
//             added_by,
//             added_on,
//             is_deleted
//         ) VALUES (
//             NEW.id,
//             NEW.consumable_amount_per_employee,
//             NEW.wallet_validity_date_from,
//             NEW.wallet_validity_date_to,
//             NEW.added_by,
//             NOW(),
//             0
//         );

//     END IF;
// END$$


// -- UPDATE TRIGGER
// CREATE TRIGGER trg_company_after_update
// AFTER UPDATE ON company
// FOR EACH ROW
// BEGIN
//     IF NEW.wallet_validity_date_from IS NOT NULL 
//        AND NEW.wallet_validity_date_to IS NOT NULL
//        AND (
//             NOT (NEW.wallet_validity_date_from <=> OLD.wallet_validity_date_from)
//             OR NOT (NEW.wallet_validity_date_to <=> OLD.wallet_validity_date_to)
//        ) THEN

//         INSERT INTO company_validity_date (
//             company_id,
//             consumable_amount_per_employee,
//             wallet_validity_date_from,
//             wallet_validity_date_to,
//             added_by,
//             added_on,
//             is_deleted
//         ) VALUES (
//             NEW.id,
//             NEW.consumable_amount_per_employee,
//             NEW.wallet_validity_date_from,
//             NEW.wallet_validity_date_to,
//             NEW.updated_by,
//             NOW(),
//             0
//         );

//     END IF;
// END$$

// DELIMITER ;
}

?>