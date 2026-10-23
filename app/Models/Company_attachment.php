<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Company_attachment extends MYT_Model
{
    protected $table = 'company_attachment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'company_id',
        'file_name',
        'file_path',
        'file_url',
        'mime',
    ];
}

?>