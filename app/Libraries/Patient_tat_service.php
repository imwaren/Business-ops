<?php

namespace App\Libraries;

use App\Models\Patient_queue;
use App\Models\Patient_tat;

class Patient_tat_service
{
    public const ACTION_KIOSK_WAIT        = 'kiosk_wait';
    public const ACTION_CSR_SERVING       = 'csr_serving';
    public const ACTION_LAB_WAIT          = 'lab_wait';
    public const ACTION_LAB_SERVING       = 'lab_serving';
    public const ACTION_DIAG_SERVING      = 'diag_serving';
    public const ACTION_HEALTH_SERVING    = 'health_serving';
    public const ACTION_PHARMACY_SERVING  = 'pharmacy_serving';
    public const ACTION_EMR_WAIT          = 'emr_wait';
    public const ACTION_SKIP              = 'skip';
    public const ACTION_DONE              = 'done';

    
    public const SOURCE_KIOSK           = 'kiosk';
    public const SOURCE_CSR             = 'csr';
    public const SOURCE_LABORATORY      = 'laboratory';
    public const SOURCE_ULTRASOUND      = 'ultrasound';
    public const SOURCE_DIAGNOSTIC      = 'diagnostic';
    public const SOURCE_HEALTH_SERVICES = 'health_services';
    public const SOURCE_PHARMACY        = 'pharmacy';
    public const SOURCE_EMR             = 'emr';

    protected $patient_tat;
    protected $patient_queue;

    public function __construct($db = null)
    {
        $this->patient_tat   = new Patient_tat($db);
        $this->patient_queue = new Patient_queue($db);
    }

    /**
     * Insert an open TAT segment.
     */
    public function start_segment(array $data)
    {
        $now = date('Y-m-d H:i:s');

        $values = [
            'patient_master_id' => $data['patient_master_id'] ?? null,
            'queue_id'          => $data['queue_id'] ?? null,
            'queue_no'          => $this->resolve_queue_no($data),
            'transaction_id'    => $data['transaction_id'] ?? null,
            'booking_detail_id' => $data['booking_detail_id'] ?? null,
            'category_id'       => $data['category_id'] ?? null,
            'category_name'     => $data['category_name'] ?? null,
            'exam_name'         => $data['exam_name'] ?? null,
            'action_performed'  => $data['action_performed'],
            'source'            => $data['source'],
            'section'           => $data['section'] ?? null,
            'group_id'          => $data['group_id'] ?? null,
            'time_start'        => $data['time_start'] ?? $now,
            'time_end'          => null,
            'added_by'          => $data['added_by'] ?? null,
            'added_on'          => $now,
            'updated_by'        => $data['added_by'] ?? null,
            'updated_on'        => $now,
            'is_deleted'        => 0,
        ];

        return $this->patient_tat->insert($values);
    }

    /**
     * Close the latest open segment matching filters.
     */
    public function close_segment(array $filters, $updated_by = null, $time_end = null)
    {
        $segment = $this->patient_tat->get_latest_open($filters);

        if (empty($segment)) {
            return false;
        }

        return $this->close_segment_by_id($segment->id, $updated_by, $time_end);
    }

    /**
     * Close all open segments matching filters.
     */
    public function close_segments(array $filters, $updated_by = null, $time_end = null)
    {
        $segments = $this->patient_tat->get_open_segments($filters);
        $closed   = 0;

        foreach ($segments as $segment) {
            if ($this->close_segment_by_id($segment->id, $updated_by, $time_end)) {
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * Close any open segment for a queue (used by skip/done).
     */
    public function close_open_for_queue($queue_id, $updated_by = null, $time_end = null)
    {
        return $this->close_segments(['queue_id' => $queue_id], $updated_by, $time_end);
    }

    /**
     * Close only CSR-leg segments. Downstream waits (lab_wait, emr_wait) stay open
     * because they measure the next station's waiting time.
     */
    public function close_csr_side_for_queue($queue_id, $updated_by = null, $time_end = null)
    {
        $actions = [
            self::ACTION_KIOSK_WAIT,
            self::ACTION_CSR_SERVING,
            self::ACTION_PHARMACY_SERVING,
        ];

        $closed = 0;

        foreach ($actions as $action) {
            $closed += $this->close_segments(
                [
                    'queue_id'         => $queue_id,
                    'action_performed' => $action,
                ],
                $updated_by,
                $time_end
            );
        }

        return $closed;
    }

    /**
     * Close an open segment and start the next one.
     */
    public function close_and_start(array $close_filters, array $start_data, $updated_by = null, $time_end = null)
    {
        $time_end = $time_end ?? date('Y-m-d H:i:s');
        $this->close_segment($close_filters, $updated_by, $time_end);

        if (empty($start_data['added_by']) && ! empty($updated_by)) {
            $start_data['added_by'] = $updated_by;
        }

        if (empty($start_data['time_start'])) {
            $start_data['time_start'] = $time_end;
        }

        return $this->start_segment($start_data);
    }

    /**
     * Close kiosk wait and start CSR serving.
     */
    public function handoff_kiosk_to_csr(array $context)
    {
        $time_end = $context['time_end'] ?? date('Y-m-d H:i:s');

        return $this->close_and_start(
            [
                'queue_id'         => $context['queue_id'],
                'action_performed' => self::ACTION_KIOSK_WAIT,
            ],
            [
                'patient_master_id' => $context['patient_master_id'] ?? null,
                'queue_id'          => $context['queue_id'],
                'transaction_id'    => $context['transaction_id'] ?? null,
                'queue_no'          => $context['queue_no'] ?? null,
                'action_performed'  => self::ACTION_CSR_SERVING,
                'source'            => self::SOURCE_CSR,
                'section'           => $context['section'] ?? 'csr',
                'added_by'          => $context['added_by'] ?? null,
                'time_start'        => $time_end,
            ],
            $context['added_by'] ?? null,
            $time_end
        );
    }

    /**
     * Close CSR serving and optionally start lab wait.
     */
    public function handoff_csr_to_lab(array $context)
    {
        $time_end = $context['time_end'] ?? date('Y-m-d H:i:s');
        $updated_by = $context['added_by'] ?? null;

        $this->close_segment(
            [
                'queue_id'         => $context['queue_id'],
                'action_performed' => self::ACTION_CSR_SERVING,
            ],
            $updated_by,
            $time_end
        );

        if (empty($context['start_lab_wait']) && empty($context['start_lab_wait'])) {
            return true;
        }

        return $this->start_segment([
            'patient_master_id' => $context['patient_master_id'] ?? null,
            'queue_id'          => $context['queue_id'],
            'transaction_id'    => $context['transaction_id'] ?? null,
            'queue_no'          => $context['queue_no'] ?? null,
            'action_performed'  => self::ACTION_LAB_WAIT,
            'source'            => self::SOURCE_LABORATORY,
            'section'           => $context['section'] ?? 'diagnosis',
            'added_by'          => $updated_by,
            'time_start'        => $time_end,
        ]);
    }

    /**
     * Close CSR serving and start EMR wait (patient handed to EMR).
     * The EMR app writes to this same table and may have already opened the
     * wait while handling queues/create, so reuse that row when it exists.
     */
    public function handoff_csr_to_emr(array $context)
    {
        $time_end   = $context['time_end'] ?? date('Y-m-d H:i:s');
        $updated_by = $context['added_by'] ?? null;

        $this->close_segment(
            [
                'queue_id'         => $context['queue_id'],
                'action_performed' => self::ACTION_CSR_SERVING,
            ],
            $updated_by,
            $time_end
        );

        $existing = $this->patient_tat->get_latest_open([
            'queue_id'         => $context['queue_id'],
            'action_performed' => self::ACTION_EMR_WAIT,
        ]);

        if (! empty($existing)) {
            $this->backfill_segment(
                $existing,
                [
                    'patient_master_id' => $context['patient_master_id'] ?? null,
                    'queue_no'          => $this->resolve_queue_no($context),
                    'added_by'          => $updated_by,
                ],
                $updated_by
            );

            return $existing->id;
        }

        return $this->start_segment([
            'patient_master_id' => $context['patient_master_id'] ?? null,
            'queue_id'          => $context['queue_id'],
            'transaction_id'    => $context['transaction_id'] ?? null,
            'queue_no'          => $context['queue_no'] ?? null,
            'action_performed'  => self::ACTION_EMR_WAIT,
            'source'            => self::SOURCE_EMR,
            'section'           => $context['section'] ?? 'consultation',
            'added_by'          => $updated_by,
            'time_start'        => $time_end,
        ]);
    }

    /**
     * On skip: close open serving (no soft-delete), insert skip marker, start open wait for retake.
     * skip_scope: csr => kiosk_wait; lab => lab_wait.
     */
    public function record_skip_and_requeue(array $context)
    {
        $now        = $context['time_end'] ?? date('Y-m-d H:i:s');
        $queue_id   = $context['queue_id'];
        $updated_by = $context['added_by'] ?? null;
        $skip_scope = $context['skip_scope'] ?? 'csr';

        if ($skip_scope === 'csr') {
            $this->close_segment(
                [
                    'queue_id'         => $queue_id,
                    'action_performed' => self::ACTION_CSR_SERVING,
                ],
                $updated_by,
                $now
            );
            $this->close_segment(
                [
                    'queue_id'         => $queue_id,
                    'action_performed' => self::ACTION_PHARMACY_SERVING,
                ],
                $updated_by,
                $now
            );
        } else {
            $this->close_open_serving_for_queue($queue_id, $updated_by, null, $now);
        }

        $this->patient_tat->insert([
            'patient_master_id' => $context['patient_master_id'] ?? null,
            'queue_id'          => $queue_id,
            'queue_no'          => $this->resolve_queue_no($context),
            'transaction_id'    => $context['transaction_id'] ?? null,
            'booking_detail_id' => $context['booking_detail_id'] ?? null,
            'action_performed'  => self::ACTION_SKIP,
            'source'            => $skip_scope === 'csr' ? self::SOURCE_CSR : self::SOURCE_LABORATORY,
            'section'           => $context['section'] ?? ($skip_scope === 'csr' ? 'csr' : 'diagnosis'),
            'group_id'          => null,
            'time_start'        => $now,
            'time_end'          => $now,
            'added_by'          => $updated_by,
            'added_on'          => $now,
            'updated_by'        => $updated_by,
            'updated_on'        => $now,
            'is_deleted'        => 0,
        ]);

        if ($skip_scope === 'csr') {
            return $this->start_segment([
                'patient_master_id' => $context['patient_master_id'] ?? null,
                'queue_id'          => $queue_id,
                'transaction_id'    => $context['transaction_id'] ?? null,
                'queue_no'          => $context['queue_no'] ?? null,
                'action_performed'  => self::ACTION_KIOSK_WAIT,
                'source'            => self::SOURCE_KIOSK,
                'section'           => $context['section'] ?? 'csr',
                'added_by'          => $updated_by,
                'time_start'        => $now,
            ]);
        }

        return $this->start_segment([
            'patient_master_id' => $context['patient_master_id'] ?? null,
            'queue_id'          => $queue_id,
            'transaction_id'    => $context['transaction_id'] ?? null,
            'queue_no'          => $context['queue_no'] ?? null,
            'action_performed'  => self::ACTION_LAB_WAIT,
            'source'            => self::SOURCE_LABORATORY,
            'section'           => $context['section'] ?? 'diagnosis',
            'added_by'          => $updated_by,
            'time_start'        => $now,
        ]);
    }

    /**
     * Close lab wait (if open) and start a per-category serving segment.
     */
    public function handoff_lab_wait_to_serving(array $context)
    {
        $time_end   = $context['time_end'] ?? date('Y-m-d H:i:s');
        $section    = $context['section'] ?? null;
        $action     = $this->serving_action_for_section($section);
        $source     = $this->source_for_section($section);
        $group_id   = $this->group_id_for_section($section);
        $updated_by = $context['added_by'] ?? null;

        $this->close_segment(
            [
                'queue_id'         => $context['queue_id'],
                'action_performed' => self::ACTION_LAB_WAIT,
            ],
            $updated_by,
            $time_end
        );

        return $this->start_segment([
            'patient_master_id' => $context['patient_master_id'] ?? null,
            'queue_id'          => $context['queue_id'],
            'transaction_id'    => $context['transaction_id'] ?? null,
            'booking_detail_id' => $context['booking_detail_id'] ?? null,
            'category_id'       => $context['category_id'] ?? null,
            'category_name'     => $context['category_name'] ?? null,
            'exam_name'         => $context['exam_name'] ?? null,
            'queue_no'          => $context['queue_no'] ?? null,
            'action_performed'  => $action,
            'source'            => $source,
            'section'           => $section,
            'group_id'          => $group_id,
            'added_by'          => $updated_by,
            'time_start'        => $time_end,
        ]);
    }

    /**
     * Close the open serving segment for a category (or legacy booking_detail_id).
     */
    public function close_category_serving(array $context)
    {
        if (empty($context['category_id']) && empty($context['booking_detail_id'])) {
            return false;
        }

        $filters = [];

        if (! empty($context['queue_id'])) {
            $filters['queue_id'] = $context['queue_id'];
        }

        if (! empty($context['category_id'])) {
            $filters['category_id'] = $context['category_id'];
        } elseif (! empty($context['booking_detail_id'])) {
            $filters['booking_detail_id'] = $context['booking_detail_id'];
        }

        if (empty($filters)) {
            return false;
        }

        if (! empty($context['action_performed'])) {
            $filters['action_performed'] = $context['action_performed'];
        } elseif (! empty($context['group_id'])) {
            $action = $this->serving_action_for_group_id($context['group_id']);
            if (! empty($action)) {
                $filters['action_performed'] = $action;
            }
        }

        return $this->close_segment(
            $filters,
            $context['added_by'] ?? null,
            $context['time_end'] ?? date('Y-m-d H:i:s')
        );
    }

    /**
     * Close a per-test serving segment.
     */
    public function close_test_serving(array $context)
    {
        return $this->close_category_serving($context);
    }

    public function has_open_serving_for_category($queue_id, $category_id)
    {
        if (empty($queue_id) || $category_id === null || $category_id === '') {
            return false;
        }

        return ! empty($this->patient_tat->get_open_serving_segments($queue_id, null, $category_id));
    }

    public function has_open_serving($queue_id)
    {
        if (empty($queue_id)) {
            return false;
        }

        return ! empty($this->patient_tat->get_open_serving_segments($queue_id));
    }

    /**
     * Close open serving segments with time_end (preserve journey, no soft-delete).
     */
    public function close_open_serving_for_queue($queue_id, $updated_by = null, $booking_detail_id = null, $time_end = null, $category_id = null)
    {
        $segments = $this->patient_tat->get_open_serving_segments($queue_id, $booking_detail_id, $category_id);
        $closed   = 0;

        foreach ($segments as $segment) {
            if ($this->close_segment_by_id($segment->id, $updated_by, $time_end)) {
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * Soft-delete open serving segments (explicit abandon only).
     */
    public function cancel_open_serving_for_queue($queue_id, $updated_by = null, $booking_detail_id = null)
    {
        $segments = $this->patient_tat->get_open_serving_segments($queue_id, $booking_detail_id);
        $now      = date('Y-m-d H:i:s');
        $cancelled = 0;

        foreach ($segments as $segment) {
            if ($this->patient_tat->update(
                ['id' => $segment->id],
                [
                    'time_end'   => $now,
                    'is_deleted' => 1,
                    'updated_by' => $updated_by,
                    'updated_on' => $now,
                ]
            )) {
                $cancelled++;
            }
        }

        return $cancelled;
    }

    public function serving_action_for_section($section)
    {
        if (in_array($section, ['laboratory', 'ultrasound'], true)) {
            return self::ACTION_LAB_SERVING;
        }

        if ($section === 'diagnostic') {
            return self::ACTION_DIAG_SERVING;
        }

        if ($section === 'services') {
            return self::ACTION_HEALTH_SERVING;
        }

        if ($section === 'pharmacy') {
            return self::ACTION_PHARMACY_SERVING;
        }

        return self::ACTION_LAB_SERVING;
    }

    public function source_for_section($section)
    {
        if ($section === 'ultrasound') {
            return self::SOURCE_ULTRASOUND;
        }

        if ($section === 'diagnostic') {
            return self::SOURCE_DIAGNOSTIC;
        }

        if ($section === 'services') {
            return self::SOURCE_HEALTH_SERVICES;
        }

        if ($section === 'pharmacy') {
            return self::SOURCE_PHARMACY;
        }

        return self::SOURCE_LABORATORY;
    }

    public function group_id_for_section($section)
    {
        if (in_array($section, ['laboratory', 'ultrasound'], true)) {
            return 1;
        }

        if ($section === 'diagnostic') {
            return 2;
        }

        if ($section === 'services') {
            return 3;
        }

        return null;
    }

    public function serving_action_for_group_id($group_id)
    {
        if ((int) $group_id === 1) {
            return self::ACTION_LAB_SERVING;
        }

        if ((int) $group_id === 2) {
            return self::ACTION_DIAG_SERVING;
        }

        if ((int) $group_id === 3) {
            return self::ACTION_HEALTH_SERVING;
        }

        return null;
    }

    /**
     * Snapshot queue_no from the payload, or from patient_queue when omitted.
     */
    protected function resolve_queue_no(array $data)
    {
        if (array_key_exists('queue_no', $data) && $data['queue_no'] !== null && $data['queue_no'] !== '') {
            return $data['queue_no'];
        }

        $queue_id = $data['queue_id'] ?? null;
        if (empty($queue_id)) {
            return null;
        }

        $queue = $this->patient_queue->select('queue_no', ['id' => $queue_id], 1);

        return $queue->queue_no ?? null;
    }

    /**
     * Fill only the columns another writer left empty on an existing segment.
     */
    protected function backfill_segment($segment, array $values, $updated_by = null)
    {
        $updates = [];

        foreach ($values as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $current = $segment->{$column} ?? null;

            if ($current === null || $current === '') {
                $updates[$column] = $value;
            }
        }

        if (empty($updates)) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        $updates['updated_by'] = $updated_by ?? ($segment->updated_by ?? null);
        $updates['updated_on'] = $now;

        return $this->patient_tat->update(['id' => $segment->id], $updates);
    }

    protected function close_segment_by_id($segment_id, $updated_by = null, $time_end = null)
    {
        $time_end = $time_end ?? date('Y-m-d H:i:s');

        return $this->patient_tat->update(
            ['id' => $segment_id],
            [
                'time_end'   => $time_end,
                'updated_by' => $updated_by,
                'updated_on' => $time_end,
            ]
        );
    }
}
