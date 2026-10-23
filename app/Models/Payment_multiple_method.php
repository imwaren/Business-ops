<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Payment_multiple_method extends MYT_Model
{
    protected $table = 'payment_multiple_method';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'payment_id',
        'type',
        'amount',
        'card_no',
        'card_holder',
        'card_expiry',
        'card_type',
        'card_brand',
        'other_payment_details',
        'reference_no',
        'remarks',
        'status',
        'posted_by',
        'posted_on',
    ];
}

?>
