<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Endorsement_attachment extends MYT_Model
{
    protected $table = 'endorsement_attachment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'endorsement_id',
        'version_no',
        'file_name',
        'file_label',
        'file_path',
        'file_url',
        'mime',
    ];
}

?>