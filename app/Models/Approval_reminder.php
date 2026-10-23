<?php

namespace App\Models;

use App\Core\MYT_Model;
use App\Libraries\Approval_reminder_schedule as Schedule;

class Approval_reminder extends MYT_Model
{
    protected $table = 'approval_reminder_sequence';
    private const MODULES = ['purchase_request', 'purchase_order', 'aoq', 'receipt_note'];

    public function document(string $module, int $id, bool $lock = false): ?array
    {
        if (!in_array($module, self::MODULES, true)) {
            throw new \InvalidArgumentException('Unsupported approval module.');
        }
        $sql = <<<EOT
SELECT * FROM `{$module}` WHERE id = ?
EOT;
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        return $this->db->query($sql, [$id])->getRowArray() ?: null;
    }

    /** Called inside the source document's transaction, while its row is locked. */
    public function transition(string $module, int $id, int $branch, ?string $before, int $requester): ?int
    {
        $row = $this->document($module, $id, true);
        $stage = Schedule::stage($module, $row);
        if ($before === $stage) {
            return null; // Includes pre-deployment pending records: no backfill.
        }
        $now = Schedule::now();
        $timestamp = $now->format('Y-m-d H:i:s');
        $sql = <<<EOT
UPDATE approval_reminder_delivery d
INNER JOIN approval_reminder_sequence s ON s.id = d.sequence_id
SET d.status = 'cancelled', d.completed_on = ?
WHERE s.module = ? AND s.document_id = ? AND s.status = 'active'
AND d.status = 'pending'
EOT;
        $this->db->query($sql, [$timestamp, $module, $id]);
        $this->db->table($this->table)->where(['module' => $module, 'document_id' => $id, 'status' => 'active'])
            ->update(['status' => 'cancelled', 'cancelled_on' => $timestamp]);
        if ($stage === null) {
            return null;
        }
        $times = Schedule::times($now);
        $this->db->table($this->table)->insert([
            'module' => $module, 'document_id' => $id, 'branch_id' => $branch,
            'stage' => $stage, 'creator_id' => $row['added_by'] ?? null,
            'triggered_by' => $requester, 'triggered_on' => $timestamp,
            'next_eod_on' => $times['eod']->format('Y-m-d H:i:s'),
        ]);
        $sequence = (int) $this->db->insertID();
        foreach (['first', 'second'] as $key) {
            $this->addDelivery($sequence, $key, $times[$key]);
        }
        return $sequence;
    }

    private function addDelivery(int $sequence, string $key, \DateTimeImmutable $due): void
    {
        $sql = <<<EOT
INSERT INTO approval_reminder_delivery (sequence_id,delivery_key,due_on)
VALUES (?,?,?) ON DUPLICATE KEY UPDATE sequence_id = VALUES(sequence_id)
EOT;
        $this->db->query($sql, [$sequence, $key, $due->format('Y-m-d H:i:s')]);
    }

    public function dueSequences(\DateTimeImmutable $now, int $limit = 50, ?int $branch = null): array
    {
        $branch_filter=$branch===null ? '' : ' AND s.branch_id='.(int)$branch;
        $limit = max(1, min(100, $limit));
        $sql = <<<EOT
SELECT s.id FROM approval_reminder_sequence s
WHERE s.status = 'active' {$branch_filter} AND (s.next_eod_on <= ? OR EXISTS (
 SELECT 1 FROM approval_reminder_delivery d WHERE d.sequence_id = s.id
 AND ((d.status = 'pending' AND d.due_on <= ?) OR
      (d.status = 'processing' AND d.claimed_on < ?))
)) ORDER BY s.id LIMIT {$limit}
EOT;
        return $this->db->query($sql, [
            $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'),
            $now->modify('-10 minutes')->format('Y-m-d H:i:s'),
        ])->getResultArray();
    }

    public function acquire(int $sequence): bool
    {
        return (int) $this->db->query('SELECT GET_LOCK(?,0) AS acquired', [$this->lockName($sequence)])
            ->getRow()->acquired === 1;
    }

    public function release(int $sequence): void
    {
        $this->db->query('SELECT RELEASE_LOCK(?)', [$this->lockName($sequence)]);
    }

    private function lockName(int $id): string
    {
        return 'approval:' . substr(hash('sha256', $this->db->getDatabase()), 0, 24) . ':' . $id;
    }

    public function prepare(int $sequence, \DateTimeImmutable $now, bool $immediateOnly = false): array
    {
        $s = $this->db->table($this->table)->where('id', $sequence)->get()->getRowArray();
        if (!$s || $s['status'] !== 'active') {
            return [];
        }
        $row = $this->document($s['module'], (int) $s['document_id']);
        if (Schedule::stage($s['module'], $row) !== $s['stage']) {
            $this->cancel($sequence, $now);
            return [];
        }
        $this->db->table('approval_reminder_delivery')->where(['sequence_id' => $sequence, 'status' => 'processing'])
            ->where('claimed_on <', $now->modify('-10 minutes')->format('Y-m-d H:i:s'))
            ->update(['status' => 'uncertain', 'error_message' => 'Interrupted send: review before retrying.']);
        if (!$immediateOnly && $s['next_eod_on'] <= $now->format('Y-m-d H:i:s')) {
            $due = new \DateTimeImmutable($s['next_eod_on'], $now->getTimezone());
            $next = Schedule::nextBusinessDay($due)->setTime(17, 0);
            while ($next <= $now) {
                $due = $next;
                $next = Schedule::nextBusinessDay($due)->setTime(17, 0);
            }
            // Only the most recent overdue EOD is materialized after downtime.
            $this->addDelivery($sequence, 'eod:' . $due->format('Y-m-d'), $due);
            $this->db->table($this->table)->where('id', $sequence)
                ->update(['next_eod_on' => $next->format('Y-m-d H:i:s')]);
            $this->db->table('approval_reminder_delivery')->where(['sequence_id' => $sequence, 'status' => 'pending'])
                ->like('delivery_key', 'eod:', 'after')->where('delivery_key !=', 'eod:' . $due->format('Y-m-d'))
                ->update(['status' => 'skipped', 'completed_on' => $now->format('Y-m-d H:i:s'),
                    'error_message' => 'Superseded by current EOD escalation.']);
        }
        $builder = $this->db->table('approval_reminder_delivery')
            ->where(['sequence_id' => $sequence, 'status' => 'pending'])
            ->where('due_on <=', $now->format('Y-m-d H:i:s'));
        if ($immediateOnly) {
            $builder->where('delivery_key', 'first');
        }
        $deliveries = $builder->orderBy('due_on')->orderBy('id')->get()->getResultArray();
        foreach ($deliveries as &$delivery) {
            $delivery['sequence'] = $s;
            $delivery['document'] = $row;
        }
        return $deliveries;
    }

    public function claim(int $id, \DateTimeImmutable $now): bool
    {
        $sql = <<<EOT
UPDATE approval_reminder_delivery SET status = 'processing', claimed_on = ?, attempts = attempts + 1
WHERE id = ? AND status = 'pending'
EOT;
        $this->db->query($sql, [$now->format('Y-m-d H:i:s'), $id]);
        return $this->db->affectedRows() === 1;
    }

    public function isCurrent(array $sequence): bool
    {
        $active = $this->db->table($this->table)->where(['id' => $sequence['id'], 'status' => 'active'])->countAllResults();
        return $active === 1 && Schedule::stage($sequence['module'],
            $this->document($sequence['module'], (int) $sequence['document_id'])) === $sequence['stage'];
    }

    public function complete(array $delivery, string $status, array $to, array $cc, ?string $error, \DateTimeImmutable $now): void
    {
        $values = ['status' => $status, 'recipient_to' => json_encode($to), 'recipient_cc' => json_encode($cc),
            'error_message' => $error, 'completed_on' => $now->format('Y-m-d H:i:s')];
        if ($status === 'failed' && (int) $delivery['attempts'] + 1 < 3) {
            $retry = $now->modify('+5 minutes');
            if (!Schedule::deliveryWindow($retry)) {
                $retry = Schedule::nextBusinessDay($now);
            }
            $values['status'] = 'pending';
            $values['due_on'] = $retry->format('Y-m-d H:i:s');
            $values['completed_on'] = null;
        }
        $this->db->table('approval_reminder_delivery')->where(['id' => $delivery['id'], 'status' => 'processing'])->update($values);
    }

    private function cancel(int $sequence, \DateTimeImmutable $now): void
    {
        $this->db->transBegin();
        try {
            $this->db->table($this->table)->where('id', $sequence)
                ->update(['status' => 'cancelled', 'cancelled_on' => $now->format('Y-m-d H:i:s')]);
            $this->db->table('approval_reminder_delivery')->where(['sequence_id' => $sequence, 'status' => 'pending'])
                ->update(['status' => 'cancelled', 'completed_on' => $now->format('Y-m-d H:i:s')]);
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }
}
