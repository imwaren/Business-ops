<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Quotation_signed_moa extends MYT_Model
{
    protected $table = 'quotation_signed_moa';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'quotation_id',
        'file_name',
        'file_path',
        'file_url',
        'mime',
    ];
}

?>