<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Receipt_note_attachment extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'receipt_note_attachment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'receipt_note_id',
        'type',
        'file_name',
        'file_path',
        'file_url',
        'mime',
    ];
}

?>