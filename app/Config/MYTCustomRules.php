<?php

namespace Config;
use App\Models\User;
use App\Models\Template;
// Add models here if you want to include their respective tables in the validation

class MYTCustomRules
{
    /**
     * Sample custom validation without extra parameters
     */
    public function even($value, ?string &$error = null): bool
    {
        if ((int) $value % 2 !== 0) {
            $error = lang('Passed value is not an even number');

            return false;
        }

        return true;
    }

    /**
    * Check if date format
    */
    public function _is_date($value): bool
    {
        // Use the required option if you want the field to be required
        if($value === null || $value === "") {
            return true;
        }
        // $regex = '/^MedEthix\/3\/([a-zA-Z]+)\/([a-zA-Z]+)\/(\d{2}-\d{2}-\d{4})$/';
        $yyyy_mm_dd_regex = '/^\d{4}-\d{2}-\d{2}$/';
        return preg_match($yyyy_mm_dd_regex, $value) ? true : false;
    }

    // Check if input is in the enum choices
    function _is_valid_enum($choice, $choices = null)
    {
        $choices = explode(',', $choices);
        $valid = !$choice || (in_array($choice, $choices));

        return $valid;
    }

    /**
    * is numeric but not required
    * the default is numeric option in CI4 validation causes the input field to be required
    */
    // $table, $field
    public function _is_numeric($value)
    {
        if($value === null || $value === "") {
            return true;
        }

        return is_numeric($value);
    }

    /**
     * Sample custom validation with extra parameters
     */
    public function is_too_large($value, string $params, array $data, ?string &$error = null): bool
    {
        $params = explode(',', $params);

        if ($params AND $params[0] >= $value) {
            return true;
        } else {
            $error = lang('Age should be less than ' . $params[0]);

            return false;
        }
    }
}