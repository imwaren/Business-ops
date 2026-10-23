<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Endorsement_signed_moa extends MYT_Model
{
    protected $table = 'endorsement_signed_moa';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'endorsement_id',
        'file_name',
        'file_path',
        'file_url',
        'mime',
    ];
}

?>