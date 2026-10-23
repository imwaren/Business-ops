<?php

/*
 | --------------------------------------------------------------------
 | App Namespace
 | --------------------------------------------------------------------
 |
 | This defines the default Namespace that is used throughout
 | CodeIgniter to refer to the Application directory. Change
 | this constant to change the namespace that all application
 | classes should use.
 |
 | NOTE: changing this will require manually modifying the
 | existing namespaces of App\* namespaced-classes.
 */
defined('APP_NAMESPACE') || define('APP_NAMESPACE', 'App');

/*
 | --------------------------------------------------------------------------
 | Composer Path
 | --------------------------------------------------------------------------
 |
 | The path that Composer's autoload file is expected to live. By default,
 | the vendor folder is in the Root directory, but you can customize that here.
 */
defined('COMPOSER_PATH') || define('COMPOSER_PATH', ROOTPATH . 'vendor/autoload.php');

/*
 |--------------------------------------------------------------------------
 | Timing Constants
 |--------------------------------------------------------------------------
 |
 | Provide simple ways to work with the myriad of PHP functions that
 | require information to be in seconds.
 */
defined('SECOND') || define('SECOND', 1);
defined('MINUTE') || define('MINUTE', 60);
defined('HOUR')   || define('HOUR', 3600);
defined('DAY')    || define('DAY', 86400);
defined('WEEK')   || define('WEEK', 604800);
defined('MONTH')  || define('MONTH', 2_592_000);
defined('YEAR')   || define('YEAR', 31_536_000);
defined('DECADE') || define('DECADE', 315_360_000);

/*
 | --------------------------------------------------------------------------
 | Exit Status Codes
 | --------------------------------------------------------------------------
 |
 | Used to indicate the conditions under which the script is exit()ing.
 | While there is no universal standard for error codes, there are some
 | broad conventions.  Three such conventions are mentioned below, for
 | those who wish to make use of them.  The CodeIgniter defaults were
 | chosen for the least overlap with these conventions, while still
 | leaving room for others to be defined in future versions and user
 | applications.
 |
 | The three main conventions used for determining exit status codes
 | are as follows:
 |
 |    Standard C/C++ Library (stdlibc):
 |       http://www.gnu.org/software/libc/manual/html_node/Exit-Status.html
 |       (This link also contains other GNU-specific conventions)
 |    BSD sysexits.h:
 |       http://www.gsp.com/cgi-bin/man.cgi?section=3&topic=sysexits
 |    Bash scripting:
 |       http://tldp.org/LDP/abs/html/exitcodes.html
 |
 */
defined('EXIT_SUCCESS')        || define('EXIT_SUCCESS', 0);        // no errors
defined('EXIT_ERROR')          || define('EXIT_ERROR', 1);          // generic error
defined('EXIT_CONFIG')         || define('EXIT_CONFIG', 3);         // configuration error
defined('EXIT_UNKNOWN_FILE')   || define('EXIT_UNKNOWN_FILE', 4);   // file not found
defined('EXIT_UNKNOWN_CLASS')  || define('EXIT_UNKNOWN_CLASS', 5);  // unknown class
defined('EXIT_UNKNOWN_METHOD') || define('EXIT_UNKNOWN_METHOD', 6); // unknown class member
defined('EXIT_USER_INPUT')     || define('EXIT_USER_INPUT', 7);     // invalid user input
defined('EXIT_DATABASE')       || define('EXIT_DATABASE', 8);       // database error
defined('EXIT__AUTO_MIN')      || define('EXIT__AUTO_MIN', 9);      // lowest automatically-assigned error code
defined('EXIT__AUTO_MAX')      || define('EXIT__AUTO_MAX', 125);    // highest automatically-assigned error code

/**
 * @deprecated Use \CodeIgniter\Events\Events::PRIORITY_LOW instead.
 */
define('EVENT_PRIORITY_LOW', 200);

/**
 * @deprecated Use \CodeIgniter\Events\Events::PRIORITY_NORMAL instead.
 */
define('EVENT_PRIORITY_NORMAL', 100);

/**
 * @deprecated Use \CodeIgniter\Events\Events::PRIORITY_HIGH instead.
 */
define('EVENT_PRIORITY_HIGH', 10);

if (! function_exists('myt_env')) {
    /**
     * Read an environment value before the CI4 env() helper is available.
     */
    function myt_env(string $key, $default = '')
    {
        if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        $value = getenv($key);

        return ($value === false || $value === '') ? $default : $value;
    }
}

/**
 * CORE CONSTANTS — secrets come from .env only. Rotate any previously committed values.
 */
defined('API_KEY') || define('API_KEY', (string) myt_env('API_KEY', ''));
defined('CSR_API_KEY') || define('CSR_API_KEY', (string) myt_env('CSR_API_KEY', ''));

 /**
  * RESPONSE TRAITS
  */
defined('SUCCESS') || define('SUCCESS', 200);
defined('FAIL_UNAUTHORIZED') || define('FAIL_UNAUTHORIZED', 401);
defined('FAIL') || define('FAIL', 400);
defined('FAIL_NOT_FOUND') || define('FAIL_NOT_FOUND', 404);
defined('FAIL_SERVER_ERROR') || define('FAIL_SERVER_ERROR', 500);
defined('FAIL_TOO_MANY') || define('FAIL_TOO_MANY', 429);

/**
 * ENCRYPTION KEY
 */
defined('ENCRYPTION_KEY') || define('ENCRYPTION_KEY', (string) myt_env('ENCRYPTION_KEY', myt_env('encryption.key', '')));

//DATABASE CONFIG
defined('DB_USERNAME') || define('DB_USERNAME', (string) myt_env('database.default.username', myt_env('DB_USERNAME', '')));
defined('DB_PASSWORD') || define('DB_PASSWORD', (string) myt_env('database.default.password', myt_env('DB_PASSWORD', '')));
defined('MASTER_DB') || define('MASTER_DB', (string) myt_env('MASTER_DB', 'mytemr_master'));
defined('MOBILE_DB') || define('MOBILE_DB', (string) myt_env('MOBILE_DB', 'mytemr_diagnostic_mobile'));
defined('OAKRIDGE_DB') || define('OAKRIDGE_DB', (string) myt_env('OAKRIDGE_DB', 'mytemr_diagnostic'));
defined('MAC_DB') || define('MAC_DB', (string) myt_env('MAC_DB', 'mytemr_diagnostic_mactan'));
defined('CONSO_DB') || define('CONSO_DB', (string) myt_env('CONSO_DB', 'mytemr_diagnostic_conso'));
defined('MANTLEWOOD_DB') || define('MANTLEWOOD_DB', (string) myt_env('MANTLEWOOD_DB', 'mytemr_diagnostic_mantlewood'));
defined('WAREHOUSE_DB') || define('WAREHOUSE_DB', (string) myt_env('WAREHOUSE_DB', 'mytemr_oakridge_warehouse'));
defined('PHARMACY_DB') || define('PHARMACY_DB', (string) myt_env('PHARMACY_DB', 'mytemr_oakridge_pharmacy'));
defined('REPORT_DB') || define('REPORT_DB', (string) myt_env('REPORT_DB', 'mytemr_reports'));
defined('OAKRIDGE_EMR_DB') || define('OAKRIDGE_EMR_DB', (string) myt_env('OAKRIDGE_EMR_DB', 'mytemr_emr'));
defined('MAC_EMR_DB') || define('MAC_EMR_DB', (string) myt_env('MAC_EMR_DB', 'mytemr_emr_mactan'));
defined('CONSO_EMR_DB') || define('CONSO_EMR_DB', (string) myt_env('CONSO_EMR_DB', 'mytemr_emr_conso'));
defined('MANTLEWOOD_EMR_DB') || define('MANTLEWOOD_EMR_DB', (string) myt_env('MANTLEWOOD_EMR_DB', 'mytemr_emr_mantlewood'));
defined('MOBILE_EMR_DB') || define('MOBILE_EMR_DB', (string) myt_env('MOBILE_EMR_DB', 'mytemr_mobile'));

// EMAIL CONFIG
defined('MYT_EMAIL') || define('MYT_EMAIL', (string) myt_env('MYT_EMAIL', ''));
defined('MYT_NAME') || define('MYT_NAME', (string) myt_env('MYT_NAME', 'CLINICA PRIME'));
defined('MYT_PASSWORD') || define('MYT_PASSWORD', (string) myt_env('MYT_PASSWORD', ''));

defined('OUTLOOK_EMAIL') || define('OUTLOOK_EMAIL', (string) myt_env('OUTLOOK_EMAIL', ''));
defined('OUTLOOK_EMAIL_PASSWORD') || define('OUTLOOK_EMAIL_PASSWORD', (string) myt_env('OUTLOOK_EMAIL_PASSWORD', ''));

defined('ROLE_SALES_AE') || define('ROLE_SALES_AE', 19);
defined('ROLE_SALES_MANAGER') || define('ROLE_SALES_MANAGER', 20);
defined('ROLE_SALES_DIRECTOR') || define('ROLE_SALES_DIRECTOR', 21);
defined('ROLE_BUSINESS_OPS') || define('ROLE_BUSINESS_OPS', 22);
defined('ROLE_FINANCE_MANAGER') || define('ROLE_FINANCE_MANAGER', 23);
defined('ROLE_FINANCE_DIRECTOR') || define('ROLE_FINANCE_DIRECTOR', 26);
defined('ROLE_BDO') || define('ROLE_BDO', 32);
defined('ROLE_PHARMACIST') || define('ROLE_PHARMACIST', 39);
defined('ROLE_PHARMACY_MANAGER') || define('ROLE_PHARMACY_MANAGER', 54);
defined('ROLE_ADMIN') || define('ROLE_ADMIN', (int) myt_env('ROLE_ADMIN', 4));
defined('ROLE_IT') || define('ROLE_IT', (int) myt_env('ROLE_IT', 17));
defined('ENDORSEMENT_CORPORATE_EMAIL') || define('ENDORSEMENT_CORPORATE_EMAIL', (string) myt_env('ENDORSEMENT_CORPORATE_EMAIL', 'endorsement.corporate@clinicaprime.com.ph'));

defined('VM4_ENDPOINT') || define('VM4_ENDPOINT', (string) myt_env('VM4_ENDPOINT', 'https://file.myt-emr.com/'));
defined('VM4_SECRET_TOKEN') || define('VM4_SECRET_TOKEN', (string) myt_env('VM4_SECRET_TOKEN', ''));

defined('ACCOUNTING_FRONT_END_URL') || define('ACCOUNTING_FRONT_END_URL', (string) myt_env('ACCOUNTING_FRONT_END_URL', 'https://business-ops.myt-emr.com'));
defined('FRONT_END_URL') || define('FRONT_END_URL', (string) myt_env('FRONT_END_URL', 'https://business-ops.myt-emr.com'));
defined('CRON_SECRET') || define('CRON_SECRET', (string) myt_env('CRON_SECRET', ''));

//PO
defined('PO_APPROVAL_AMOUNT_LIMIT') || define('PO_APPROVAL_AMOUNT_LIMIT', 50000);

// DICOM Worklist
defined('DICOM_UPLOAD_WORKLIST_URL') || define('DICOM_UPLOAD_WORKLIST_URL', (string) myt_env('DICOM_UPLOAD_WORKLIST_URL', 'https://diagnostic-mobile-api.myt-emr.com/dicom/upload_worklist'));
defined('DICOM_API_KEY')             || define('DICOM_API_KEY', (string) myt_env('DICOM_API_KEY', ''));
defined('DICOM_STATION_AET')         || define('DICOM_STATION_AET', (string) myt_env('DICOM_STATION_AET', '1,2,3,4_AET'));

// Agreed TAT per section, in minutes. Daily TAT and Pending Result both read this so their
// classifications cannot drift. A section missing from the map has no agreed target.
defined('TAT_STANDARD_MINUTES') || define('TAT_STANDARD_MINUTES', [
    'HEMATOLOGY'          => 2 * 60,
    'CLINICAL CHEMISTRY'  => 4 * 60,
    'IMMUNO'              => 4 * 60,
    'SEROLOGY'            => 4 * 60,
    'CLINICAL MICROSCOPY' => 2 * 60,
    'XRAY'                => 24 * 60,
    'ECG'                 => 24 * 60,
    'ULTRASOUND'          => 1 * 60,
    'UTZ'                 => 1 * 60,
]);