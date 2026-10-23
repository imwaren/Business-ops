<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Api_log extends MYT_Model
{
    protected $table = 'api_log';
    protected $primaryKey = 'id';
    protected $allowedFields = ['controller', 'method', 'ip_address', 'latitude', 'longitude', 'data_received', 'signature', 'requested_by', 'requested_on'];
}

?>