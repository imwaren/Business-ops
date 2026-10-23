<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class User_portal_log extends MYT_Model
{
    protected $table = 'user_portal_log';
    protected $primaryKey = 'id';
    protected $allowedFields = false;
}

?>