<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Api_response extends MYT_Model
{
    protected $table = 'api_response';
    protected $primaryKey = 'id';
    protected $allowedFields = ['api_log_id', 'response', 'status', 'responded_on'];
}

?>