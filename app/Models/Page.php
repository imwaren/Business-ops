<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Page extends MYT_Model
{
    protected $table = 'page';
    protected $primaryKey = 'id';
    protected $allowedFields = ['controller','method'];
}

?>