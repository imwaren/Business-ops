<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Liquidation_attachment extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'liquidation_attachment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'liquidation_id',
        'file_name',
        'file_path',
        'file_url',
        'mime',
    ];
}

?>