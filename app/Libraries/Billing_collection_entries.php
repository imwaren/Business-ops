<?php

namespace App\Libraries;

use App\Models\Billing_entry;
use App\Models\Billing_payment_entry;
use App\Models\Booking;
use App\Models\Booking_detail;
use App\Models\Branch_master;
use App\Models\Yakap_billing;

/** Shared collection-entry response assembly and billing-detail decoration. */
class Billing_collection_entries
{
    private ?array $branch_names = null;
    private array $clinic_models = [];
    private $clinic_models_factory;

    public function __construct(
        private Billing_payment_entry $billing_payment_entry,
        private Billing_entry $billing_entry,
        private Branch_master $branch_master,
        private Yakap_billing $yakap_billing,
        ?callable $clinic_models_factory = null
    ) {
        $this->clinic_models_factory = $clinic_models_factory;
    }

    /** @return array<int, array<int, array<string, mixed>>> */
    public function get_entries_by_payment_ids(array $payment_ids): array
    {
        $payment_ids = array_values(array_unique(array_filter(array_map('intval', $payment_ids))));
        if (!$payment_ids) {
            return [];
        }
        $payment_entries = $this->billing_payment_entry->get_details(null, $payment_ids) ?: [];
        $billing_entry_ids = array_values(array_unique(array_filter(array_map('intval', array_column($payment_entries, 'billing_entry_id')))));
        $billing_entry_rows = $billing_entry_ids
            ? ($this->billing_entry->get_details($billing_entry_ids, include_invoice_no: true) ?: [])
            : [];
        $billing_entries_by_id = [];
        foreach ($billing_entry_rows as $billing_entry) {
            $billing_entries_by_id[(int) $billing_entry['id']] = $this->decorate_billing_entry($billing_entry, $billing_entry['charge_to'] ?? null);
        }

        $entries_by_payment = [];
        foreach ($payment_entries as $entry) {
            $billing_entry_id = (int) ($entry['billing_entry_id'] ?? 0);
            $entry['billing_entry_invoice_no'] = $billing_entries_by_id[$billing_entry_id]['invoice_no'] ?? null;
            $entry['billing_entry_branch_name'] = $billing_entries_by_id[$billing_entry_id]['branch_name'] ?? null;
            $entry['billing_entry_details'] = isset($billing_entries_by_id[$billing_entry_id]) ? [$billing_entries_by_id[$billing_entry_id]] : [];
            $entries_by_payment[(int) $entry['billing_payment_id']][] = $entry;
        }
        return $entries_by_payment;
    }

    /** Decorate the same billing detail returned by collection GET, without clinic payment extras. */
    public function decorate_billing_entry(array $entry, $charge_to = null): array
    {
        if ($this->branch_names === null) {
            $this->branch_names = [];
            foreach ($this->branch_master->select('id, name', ['is_deleted' => 0]) ?: [] as $branch) {
                $this->branch_names[(int) $branch->id] = $branch->name;
            }
        }
        $entry['branch_name'] = $this->branch_names[(int) ($entry['branch_id'] ?? 0)] ?? null;
        $booking_id = (int) ($entry['booking_id'] ?? 0);
        $clinic_models = $booking_id > 0 ? $this->_clinic_models((int) ($entry['branch_id'] ?? 0)) : null;
        $unmatched = $entry;
        if ($clinic_models === null) {
            $unmatched['booking_id'] = 0;
        }
        $enrichment = $this->enrich_billing_entry($unmatched, $clinic_models['booking'] ?? null);
        $entry['patient_id'] = $enrichment['patient_id'];
        $entry['patient_name'] = $enrichment['patient_name'];
        $entry['transaction_no'] = $enrichment['transaction_no'];
        $entry['booking_details'] = $clinic_models === null ? []
            : $this->entry_booking_details($entry, $charge_to ?? ($entry['charge_to'] ?? null), $clinic_models['booking_detail']);
        $entry['booking_medicines'] = [];
        $entry['payment_details'] = [];
        $entry['payment_summary'] = self::empty_payment_summary();
        return $entry;
    }

    private function _clinic_models(int $branch_id): ?array
    {
        if ($this->clinic_models_factory !== null) {
            return ($this->clinic_models_factory)($branch_id);
        }
        $connection = config('Branch')->getClinicDB($branch_id);
        if ($connection === null) {
            return null;
        }
        if (!isset($this->clinic_models[$branch_id])) {
            $db = db_connect($connection);
            $this->clinic_models[$branch_id] = [
                'booking' => new Booking($db),
                'booking_detail' => new Booking_detail($db),
            ];
        }
        return $this->clinic_models[$branch_id];
    }

    public static function empty_payment_summary(): array
    {
        return [
            'total_withholding_tax' => 0,
            'total_withholding_percent' => 0,
            'total_discount' => 0,
            'total_subtotal' => 0,
            'total_grand_total' => 0,
        ];
    }

    public function enrich_billing_entry(array $entry, ?Booking $booking_model = null): array
    {
        $result = [
            'patient_id' => null,
            'patient_name' => null,
            'transaction_no' => null,
        ];

        if ($this->yakap_billing->is_tranche_entry($entry)) {
            $tranche = Yakap_billing::tranche_from_entry($entry) ?: 1;
            $result['patient_id'] = $this->yakap_billing->stored_patient_ids($entry)
                ?? ($entry['yakap_master_patient_ids'] ?? ($entry['master_patient_id'] ?? null));
            $result['patient_name'] = $this->yakap_billing->stored_patient_name($entry);
            if ($result['patient_name'] === null && !empty($entry['master_patient_id']) && strpos((string) $entry['master_patient_id'], ',') === false) {
                $result['patient_name'] = $this->yakap_billing->get_fpe_patient_name(
                    (int) ($entry['branch_id'] ?? 0),
                    (int) $entry['master_patient_id']
                );
            }
            $result['transaction_no'] = $tranche === 2 ? '02' : '01';
            return $result;
        }

        if (empty($entry['booking_id'])) {
            if (isset($entry['floating_patient_no']) && $entry['floating_patient_no'] !== '' && $entry['floating_patient_no'] !== null) {
                $result['patient_name'] = 'Floating Patient ' . (int) $entry['floating_patient_no'];
                return $result;
            }
            if ($this->yakap_billing->is_fpe_entry($entry) && !empty($entry['master_patient_id'])) {
                $result['patient_id'] = $entry['master_patient_id'];
                $result['patient_name'] = $this->yakap_billing->get_fpe_patient_name(
                    (int) ($entry['branch_id'] ?? 0),
                    (int) $entry['master_patient_id']
                );
                return $result;
            }
            $remarks = $entry['remarks'] ?? null;
            if ($remarks !== null && $remarks !== '') {
                $name = trim((string) (explode(' | ', (string) $remarks)[0] ?? ''));
                if ($name !== '') {
                    $result['patient_name'] = $name;
                }
            }
            return $result;
        }

        $encryption_key = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : null;
        if (!$encryption_key) {
            $booking = (array) $booking_model->select('id, patient_id, transaction_id', ['id' => $entry['booking_id'], 'is_deleted' => 0], 1);
            $result['patient_id'] = $booking['patient_id'] ?? null;
            return $result;
        }

        $sql = <<<EOT
SELECT booking.patient_id,
    transc.transaction_no,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, ?) AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, ?) AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, ?) AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, ?) AS CHAR), '')
    )) AS patient_name
FROM booking
LEFT JOIN transaction AS transc ON transc.id = booking.transaction_id
LEFT JOIN patient ON patient.id = booking.patient_id
WHERE booking.is_deleted = 0
AND booking.id = ?
EOT;
        $query = $booking_model->db->query($sql, [$encryption_key, $encryption_key, $encryption_key, $encryption_key, $entry['booking_id']]);
        $row = $query ? $query->getRowArray() : null;
        if ($row) {
            $result['patient_id'] = $row['patient_id'] ?? null;
            $result['patient_name'] = $row['patient_name'] ?? null;
            $result['transaction_no'] = $row['transaction_no'] ?? null;
        }

        return $result;
    }

    public function entry_booking_details(array $entry, $charge_to = null, ?Booking_detail $booking_detail = null): array
    {
        $tranche = Yakap_billing::tranche_from_entry($entry);
        if ($tranche) {
            return Yakap_billing::booking_details_for_tranche($tranche);
        }

        if ($this->yakap_billing->is_fpe_entry($entry, $charge_to)) {
            return Yakap_billing::tranche_1_booking_details();
        }

        if (empty($entry['booking_id']) || strpos((string) $entry['booking_id'], ',') !== false) {
            return [];
        }

        return $booking_detail->get_details($entry['booking_id'], $charge_to) ?: [];
    }
}
