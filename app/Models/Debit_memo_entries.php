<?php

namespace App\Models;

class Debit_memo_entries extends Memo_entries
{
    protected $table = 'debit_memo_entries';
    protected $memo_type = 'debit_memo';
    protected $primaryKey = 'id';
    protected $allowedFields = ['debit_memo_id', 'billing_entry_id', 'billing_id', 'branch_id', 'amount', 'added_by', 'added_on', 'updated_by', 'updated_on', 'is_deleted'];
}
