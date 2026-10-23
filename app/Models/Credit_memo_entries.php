<?php

namespace App\Models;

class Credit_memo_entries extends Memo_entries
{
    protected $table = 'credit_memo_entries';
    protected $memo_type = 'credit_memo';
    protected $primaryKey = 'id';
    protected $allowedFields = ['credit_memo_id', 'billing_entry_id', 'billing_id', 'branch_id', 'amount', 'added_by', 'added_on', 'updated_by', 'updated_on', 'is_deleted'];
}
