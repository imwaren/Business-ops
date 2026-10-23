<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Permission extends MYT_Model
{
    protected $table = 'permission';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'role_id',
        'page_id',
        'is_allowed'
    ];
}

?>