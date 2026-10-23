<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class User_emr extends MYT_Model
{
    protected $table = 'user';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'master_doctor_id',
        'nurse_id',
        'professional_staff_id',
        'full_name',
        'signature',
        'doctor_fee',
        'ptr_no',
        'license_no',
        's2_no',
        'username',
        'password',
        'role_id',
        'role_name',
        'specializations',
        'status',
        'activated_by',
        'activated_on',
        'inactivated_by',
        'inactivated_on',
        'is_online',
        'added_by',
        'added_on',
        'updated_by',
        'updated_on',
        'is_deleted',
    ];
}

?>
