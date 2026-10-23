<?php

namespace App\Controllers\Traits;

use App\Libraries\Approval_reminder_schedule;
use App\Libraries\Approval_reminders;
use App\Models\Approval_reminder;

trait Approval_notifications
{
    protected $approvalBranchId;
    private $approvalBeforeStage;
    private $approvalTransactionOpen = false;

    protected function beginApprovalTransaction(string $module, ?int $id): void
    {
        $this->rollbackApprovalTransaction();
        $this->db->transBegin();
        $this->approvalTransactionOpen = true;
        $row = $id ? (new Approval_reminder($this->db))->document($module, $id, true) : null;
        if ($row && isset($row['branch_id']) && (int)$row['branch_id'] !== (int)$this->approvalBranchId
            && method_exists($this,'fund_router') && $this->fund_router()->procurement_group((int)$this->approvalBranchId)==='master') {
            $row = null; // The controller's scoped lookup returns the ordinary not-found response.
        }
        $this->approvalBeforeStage = Approval_reminder_schedule::stage($module, $row);
    }

    protected function commitApprovalTransaction(string $module, int $id): bool
    {
        try {
            $sequence = (new Approval_reminder($this->db))->transition($module, $id,
                (int) $this->approvalBranchId, $this->approvalBeforeStage, (int) $this->payload['requester']);
            if (!$this->db->transStatus()) {
                log_message('error', 'Approval reminder transaction status failed for ' . $module . ' #' . $id
                    . ' in branch ' . (int) $this->approvalBranchId);
                $this->rollbackApprovalTransaction();
                return false;
            }
            if (!$this->db->transCommit()) {
                log_message('error', 'Approval reminder transaction commit failed for ' . $module . ' #' . $id
                    . ' in branch ' . (int) $this->approvalBranchId);
                $this->rollbackApprovalTransaction();
                return false;
            }
            $this->approvalTransactionOpen = false;
        } catch (\Throwable $e) {
            $this->rollbackApprovalTransaction();
            log_message('error', 'Approval reminder transaction failed: ' . $e->getMessage());
            return false;
        }
        if ($sequence) {
            try {
                $this->dispatchApprovalReminder((int) $this->approvalBranchId, $sequence);
            } catch (\Throwable $e) {
                // Durable pending records remain for the worker. Never undo a committed document.
                log_message('error', 'Immediate approval reminder failed: ' . $e->getMessage());
            }
        }
        return true;
    }

    protected function rollbackApprovalTransaction(): void
    {
        if ($this->approvalTransactionOpen) {
            $this->db->transRollback();
            $this->approvalTransactionOpen = false;
        }
    }

    protected function dispatchApprovalReminder(int $branch, int $sequence): void
    {
        (new Approval_reminders())->run($branch, $sequence, true);
    }
}
