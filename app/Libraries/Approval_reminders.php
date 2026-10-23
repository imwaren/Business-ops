<?php

namespace App\Libraries;

use App\Models\Approval_reminder;
use App\Models\User;
use App\Models\Branch_master;

class Approval_reminders
{
    public const BRANCHES = [1 => 'mobile', 2 => 'oakridge', 3 => 'mactan', 4 => 'consolacion',
        5 => 'warehouse', 6 => 'pharmacy', 7 => 'mantlewood'];
    public const ROLES = ['department_manager' => 25, 'finance_manager' => 23,
        'department_director' => 28, 'president' => 29, 'finance_director' => 26];
    private $clock;
    private $connection;
    private $sender;

    public function __construct(?callable $clock = null, ?callable $connection = null, ?callable $sender = null)
    {
        $this->clock = $clock ?? static fn () => Approval_reminder_schedule::now();
        $this->connection = $connection ?? static fn ($group) => db_connect($group);
        $this->sender = $sender ?? static fn ($subject, $body, $to, $cc) => (new Approval_reminder_mailer())->send($subject, $body, $to, $cc);
    }

    public function run(?int $branch = null, ?int $sequence = null, bool $immediateOnly = false): array
    {
        $now = ($this->clock)();
        $summary = ['sent' => 0, 'logged' => 0, 'failed' => 0, 'uncertain' => 0];
        if ((string) env('APPROVAL_REMINDER_MODE', 'log') === 'hold' || !Approval_reminder_schedule::deliveryWindow($now)) {
            return $summary;
        }
        $shared_owners = [];
        $branches = self::BRANCHES;
        if ($branch !== null && isset(self::BRANCHES[$branch])) {
            $branches = [$branch => self::BRANCHES[$branch]];
        } else {
            $router = new Fund_cost_center(($this->connection)('master'));
            $shared_owners=array_filter($router->groups(),static fn($group)=>$group==='master');
            if ($branch !== null) { $branches=[$branch=>$router->procurement_group($branch)]; }
            elseif (in_array('master',$router->groups(),true)) { $branches[0]='master'; }
        }
        if ($branch === null) {
            $offset = (int) floor($now->getTimestamp() / 3600) % count($branches);
            $branches = array_slice($branches, $offset, null, true) + array_slice($branches, 0, $offset, true);
        }
        $deadline = microtime(true) + 45;
        helper('email_notification');
        foreach ($branches as $id => $group) {
            if ($group === null) {
                throw new \InvalidArgumentException('Invalid reminder branch.');
            }
            $model = new Approval_reminder(($this->connection)($group));
            $sequences = $sequence ? [['id' => $sequence]] : $model->dueSequences($now,50,$group==='master' && $branch!==null ? $branch : null);
            foreach ($sequences as $candidate) {
                if (array_sum($summary) >= 50 || microtime(true) >= $deadline) {
                    return $summary;
                }
                $seq = (int) $candidate['id'];
                if (!$model->acquire($seq)) {
                    continue;
                }
                try {
                    foreach ($model->prepare($seq, $now, $immediateOnly) as $delivery) {
                        if ($group==='master' && !isset($shared_owners[(int)$delivery['sequence']['branch_id']])) { continue; }
                        if ($group==='master' && $branch!==null && (int)$delivery['sequence']['branch_id']!==$branch) { continue; }
                        if (array_sum($summary) >= 50 || microtime(true) >= $deadline) {
                            return $summary;
                        }
                        if (!$model->isCurrent($delivery['sequence'])) {
                            break;
                        }
                        if (!$model->claim((int) $delivery['id'], $now)) {
                            continue;
                        }
                        [$to, $cc] = $this->recipients($delivery['sequence'], str_starts_with($delivery['delivery_key'], 'eod:'));
                        if (!$to) {
                            $status = 'failed';
                            $error = 'No valid approver or immediate-head email address.';
                        } else {
                            [$subject, $body] = $this->message($delivery);
                            $mode = (string) env('APPROVAL_REMINDER_MODE', 'log');
                            if ($mode === 'log') {
                                $status = 'logged';
                                $error = null;
                                log_message('info', 'Approval reminder preview: ' . json_encode([
                                    'branch_id' => $id, 'sequence_id' => $seq, 'delivery_key' => $delivery['delivery_key'],
                                    'subject' => $subject, 'to' => $to, 'cc' => $cc,
                                ]));
                            } elseif ($mode === 'send') {
                                $result = ($this->sender)($subject, $body, $to, $cc);
                                $status = is_array($result) ? $result['status'] : ($result ? 'sent' : 'uncertain');
                                $error = is_array($result) ? $result['error'] : ($result ? null : 'SMTP outcome requires review before retrying.');
                            } else {
                                $status = 'failed';
                                $error = 'Invalid APPROVAL_REMINDER_MODE; expected hold, log or send.';
                            }
                        }
                        $model->complete($delivery, $status, $to, $cc, $error, $now);
                        $summary[$status]++;
                    }
                } finally {
                    $model->release($seq);
                }
            }
        }
        return $summary;
    }

    public function recipients(array $sequence, bool $eod): array
    {
        $user = new User(($this->connection)('master'));
        $role = self::ROLES[$sequence['stage']] ?? null;
        if (!$role) {
            throw new \RuntimeException('Unknown approval stage.');
        }
        $to = resolve_emails_by_role($user, [$role]);
        $head = resolve_immediate_head_email($user, $sequence['creator_id']);
        if ($head) {
            $to[] = $head;
        }
        $to = normalize_email_list($to);
        $cc = $eod ? resolve_emails_by_role($user, [23, (int) env('APPROVAL_REMINDER_AP_ROLE', 35), 27]) : [];
        $toKeys = array_map('strtolower', $to);
        $cc = array_values(array_filter(normalize_email_list($cc), static fn ($email) => !in_array(strtolower($email), $toKeys, true)));
        return [$to, $cc];
    }

    /** Read-only release gate; no source records or reminder jobs are created. */
    public function readiness(): array
    {
        $master = ($this->connection)('master');
        $roles = self::ROLES + ['ap_staff' => (int) env('APPROVAL_REMINDER_AP_ROLE', 35), 'general_accountant' => 27];
        $names = [];
        foreach ($roles as $stage => $id) {
            $role = (new \App\Models\Role($master))->select('name', ['id' => $id, 'is_deleted' => 0], 1);
            if (!$role) {
                throw new \RuntimeException('Missing active reminder role: ' . $stage);
            }
            $names[$stage] = ['id' => $id, 'name' => $role->name];
        }
        $schemas = [];
        foreach (self::BRANCHES as $branch => $group) {
            $db = ($this->connection)($group);
            foreach (['approval_reminder_sequence', 'approval_reminder_delivery'] as $table) {
                $db->query('SELECT id FROM `' . $table . '` LIMIT 1');
            }
            $schemas[$branch] = $db->getDatabase();
        }
        return ['mode' => (string) env('APPROVAL_REMINDER_MODE', 'log'),
            'smtp_configured' => (bool) (OUTLOOK_EMAIL && OUTLOOK_EMAIL_PASSWORD),
            'schemas' => $schemas, 'roles' => $names];
    }

    protected function message(array $delivery): array
    {
        $s = $delivery['sequence'];
        $row = $delivery['document'];
        $titles = ['purchase_request' => 'Purchase request', 'purchase_order' => 'Purchase order',
            'aoq' => 'Abstract of quotations', 'receipt_note' => 'Receipt note'];
        $paths = ['purchase_request' => 'purchase-requests', 'purchase_order' => 'purchase-orders',
            'aoq' => 'abstract-of-quotations', 'receipt_note' => 'receipt-notes'];
        $number = $row['po_number'] ?? $row['purchase_order_no'] ?? $row['purchase_request_no']
            ?? $row['receipt_note_no'] ?? $row['receipt_no'] ?? $row['id'];
        $branch = (new Branch_master(($this->connection)('master')))->select('name', ['id' => $s['branch_id'], 'is_deleted' => 0], 1);
        $branchName = $branch->name ?? ucfirst(self::BRANCHES[$s['branch_id']] ?? 'Shared');
        $stage = ucwords(str_replace('_', ' ', $s['stage']));
        $label = str_starts_with($delivery['delivery_key'], 'eod:') ? 'EOD approval reminder'
            : ($delivery['delivery_key'] === 'first' ? 'Approval requested' : 'Second approval reminder');
        $subject = "{$label}: {$titles[$s['module']]} #{$number} ({$branchName})";
        $url = rtrim(ACCOUNTING_FRONT_END_URL, '/') . '/' . $paths[$s['module']];
        $escape = static fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        $body = '<p>' . $escape($titles[$s['module']]) . ' #' . $escape($number) . ' is awaiting ' . $escape($stage)
            . ' approval.</p><p>Branch: ' . $escape($branchName) . '<br>Approval requested: '
            . $escape($s['triggered_on']) . ' (Asia/Manila)</p><p><a href="' . $escape($url)
            . '">Open ' . $escape($titles[$s['module']]) . '</a></p>';
        return [$subject, $body];
    }
}
