<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Cash_deposit_attachment extends MYT_Model
{
    protected $table = 'cash_deposit_attachment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'cash_deposit_id',
        'file_name',
        'file_path',
        'file_url',
        'mime',
    ];
}

?>