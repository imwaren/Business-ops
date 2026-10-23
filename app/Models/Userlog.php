<?php
namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;
use CodeIgniter\Model;

class Userlog extends MYT_Model
{
    protected $table = 'userlog';
    protected $primaryKey = 'id';
    protected $allowedFields = ['user_id', 'ip_address', 'user_agent','platform', 'token', 'token_expiry', 'type', 'time_check', 'response', 'latitude', 'longitude', 'added_by', 'added_on', 'updated_by', 'updated_on', 'is_deleted'];
}

?>