<?php

namespace App\Libraries;

/** Business-hour rules shared by the approval hooks, worker and workbook checks. */
class Approval_reminder_schedule
{
    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));
    }

    public static function nextBusinessDay(\DateTimeImmutable $date): \DateTimeImmutable
    {
        do {
            $date = $date->modify('+1 day')->setTime(8, 0);
        } while ((int) $date->format('N') > 5);
        return $date;
    }

    public static function times(\DateTimeImmutable $trigger): array
    {
        $trigger = $trigger->setTimezone(new \DateTimeZone('Asia/Manila'));
        $first = $trigger;
        if ((int) $trigger->format('N') > 5 || (int) $trigger->format('H') >= 17) {
            $first = self::nextBusinessDay($trigger);
        } elseif ((int) $trigger->format('H') < 8) {
            $first = $trigger->setTime(8, 0);
        }
        $anchor = $first->setTime((int) $first->format('H'), 0);
        $second = (int) $anchor->format('H') < 13
            ? $anchor->modify('+4 hours') : self::nextBusinessDay($anchor);
        return ['first' => $first, 'second' => $second, 'eod' => $second->setTime(17, 0)];
    }

    public static function deliveryWindow(\DateTimeImmutable $now): bool
    {
        return (int) $now->format('N') <= 5
            && $now->format('H:i') >= '08:00' && $now->format('H:i') <= '17:00';
    }

    public static function stage(string $module, ?array $row): ?string
    {
        if (!$row || (int) ($row['is_deleted'] ?? 0) !== 0) {
            return null;
        }
        if ($module === 'purchase_order') {
            if (($row['status'] ?? '') === 'pending') {
                return empty($row['approved_by_department_director']) ? 'department_director' : 'president';
            }
            if (($row['status'] ?? '') === 'approved' && ($row['received_status'] ?? '') === 'open'
                && (int) ($row['is_closed_request'] ?? 0) === 1
                && ($row['closed_request_status'] ?? '') === 'requested') {
                return 'finance_director';
            }
            return null;
        }
        if (($row['status'] ?? '') !== 'pending') {
            return null;
        }
        return $module === 'aoq' ? 'finance_manager' : 'department_manager';
    }
}
