<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Group extends MYT_Model
{
    protected $DBGroup = 'master';
    protected $table = 'group';
    protected $primaryKey = 'id';
}
