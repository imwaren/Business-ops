<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Purchase_request_attachment extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'purchase_request_attachment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'purchase_request_id',
        'file_name',
        'file_path',
        'file_url',
        'mime',
        'type',
    ];
}

?>