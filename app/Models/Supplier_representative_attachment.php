<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Supplier_representative_attachment extends MYT_Model
{
    protected $table = 'supplier_representative_attachment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'supplier_representative_id',
        'file_name',
        'file_path',
        'file_url',
        'mime',
    ];
}

?>