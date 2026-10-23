<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Wallet_history extends MYT_Model
{
    protected $table = 'wallet_history';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'patient_id',
        'company_id',
        'amount_value',
    ];

    public function get_details($wallet_id = null, $patient_id = null, $company_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT wallet_history.wallet_id,
    wallet_history.amount_value,
    wallet_history.last_transaction,
    wallet_history.last_transaction_id
FROM wallet_history
WHERE wallet_history.is_deleted = 0
EOT;

        if ($wallet_id) {
            $sql .= <<<EOT

AND wallet_history.wallet_id = ?
EOT;
            $binds[] = $wallet_id;
        }

        if ($patient_id) {
            $sql .= <<<EOT

AND wallet_history.patient_id = ?
EOT;
            $binds[] = $patient_id;
        }

        if ($company_id) {
            $sql .= <<<EOT

AND wallet_history.company_id = ?
EOT;
            $binds[] = $company_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

// Note: The following SQL code for triggers (WALLET HISTORY) should be executed directly in the database and not included in the PHP code. It's provided here for reference.

// DELIMITER $$

// -- INSERT TRIGGER
// CREATE TRIGGER trg_wallet_after_insert
// AFTER INSERT ON wallet
// FOR EACH ROW
// BEGIN
//     INSERT INTO wallet_history (
//         wallet_id,
//         company_id,
//         patient_id,
//         amount_value,
//         last_transaction,
//         last_transaction_id,
//         added_by,
//         added_on,
//         is_deleted
//     ) VALUES (
//         NEW.id,
//         NEW.company_id,
//         NEW.patient_id,
//         NEW.remaining_balance,
//         'wallet_insert',
//         NEW.id,
//         NEW.added_by,
//         NOW(),
//         0
//     );
// END$$

// -- UPDATE TRIGGER
// CREATE TRIGGER trg_wallet_after_update
// AFTER UPDATE ON wallet
// FOR EACH ROW
// BEGIN
//     -- only log if remaining_balance changed
//     IF NEW.remaining_balance <> OLD.remaining_balance THEN
//         INSERT INTO wallet_history (
//             wallet_id,
//             company_id,
//             patient_id,
//             amount_value,
//             last_transaction,
//             last_transaction_id,
//             added_by,
//             added_on,
//             is_deleted
//         ) VALUES (
//             NEW.id,
//             NEW.company_id,
//             NEW.patient_id,
//             NEW.remaining_balance,
//             NEW.last_transaction,
//             NEW.last_transaction_id,
//             IFNULL(NEW.updated_by, NEW.added_by),
//             NOW(),
//             0
//         );
//     END IF;
// END$$

// DELIMITER ;
}

?>