<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Replenishment_attachment extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'replenishment_attachment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'replenishment_id',
        'file_name',
        'file_path',
        'file_url',
        'mime',
    ];
}

?>