<?php

namespace App\Models;

namespace App\Core;

use CodeIgniter\Model;

/**
 * MYT Model Class
 *
 * @package       MYT
 * @subpackage    Application
 * @category      Core
 * @author        MYT SoftDev Solutions, Inc.
 * @link          http://mytsoftdevsolutions.com
 */
class MYT_Model extends Model
{
    protected $db;
    protected $router;
    protected $fetch_class;
    protected $fetch_method;
    protected $encryption_key = ENCRYPTION_KEY;

    /**
     * Constructor
     *
     * @access public
     */
    public function __construct($db = null)
    {
        // Use the passed DB if provided, otherwise fallback to DBGroup
        $this->db = $db ?? \Config\Database::connect($this->DBGroup ?? null);

        $this->router       = service('router');
        $this->fetch_class  = $this->router->controllerName();
        $this->fetch_method = $this->router->methodName();
    }

    /**
     * Replace cross-database SQL placeholders with configured DB names.
     */
    protected function _resolve_cross_db_sql(string $sql): string
    {
        return str_replace(
            [
                '{MASTER_DB}', '{MOBILE_DB}', '{OAKRIDGE_DB}', '{MAC_DB}',
                '{CONSO_DB}', '{MANTLEWOOD_DB}', '{PHARMACY_DB}', '{REPORT_DB}',
                '{OAKRIDGE_EMR_DB}', '{MAC_EMR_DB}', '{CONSO_EMR_DB}',
                '{MANTLEWOOD_EMR_DB}', '{MOBILE_EMR_DB}',
            ],
            [
                MASTER_DB, MOBILE_DB, OAKRIDGE_DB, MAC_DB,
                CONSO_DB, MANTLEWOOD_DB, PHARMACY_DB, REPORT_DB,
                OAKRIDGE_EMR_DB, MAC_EMR_DB, CONSO_EMR_DB,
                MANTLEWOOD_EMR_DB, MOBILE_EMR_DB,
            ],
            $sql
        );
    }

    /**
     * Append `column = ?` or `column IN (...)` depending on $id.
     *
     * @param mixed $id int|string|array|null
     * @return bool false if $id is an empty array (caller should return []); true otherwise
     */
    protected function _append_id_filter(string &$sql, array &$binds, string $column, $id): bool
    {
        if ($id === null || $id === '' || $id === false) {
            return true;
        }

        if (is_array($id)) {
            $ids = [];
            foreach ($id as $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $ids[] = is_numeric($v) ? (int) $v : $v;
            }
            $ids = array_values(array_unique($ids));
            if (!$ids) {
                return false;
            }
            // CI4 expands a single IN ? bind when the value is an array → IN (1,2,3)
            $sql .= "\nAND {$column} IN ?";
            $binds[] = $ids;
            return true;
        }

        $sql .= "\nAND {$column} = ?";
        $binds[] = is_numeric($id) ? (int) $id : $id;
        return true;
    }

    /**
     * Cast to string before mysqli escapeString (PHP 8 rejects int).
     */
    private function _escape_sql_string($value): string
    {
        return $this->db->escapeString((string) $value);
    }

    /**
     * Generic Select
     *
     * @access    public
     * @param     string   table columns
     * @param     mixed    where conditions in this format - array('column' => 'data') - or - "name='Joe' AND status='boss' OR status='active'"
     * @param     int      limit; single select must be implicitly set
     * @param     mixed    order by in this format - 'title desc, name asc, id random' - or - 'rand()'
     * @return    array/object
     */
    public function select($columns = null, $conditions = null, $limit = null, $order = null)
    {

        $builder = $this->db->table($this->table);

        if (!empty($columns)) {
            $builder->select($columns);
        }

        if (!empty($order)) {
            $safeOrder = sanitize_order_by($order);
            if ($safeOrder) {
                $builder->orderBy($safeOrder);
            }
        }

        if (!empty($conditions)) {
            $query = $builder->getWhere($conditions);
        } else {
            if (isset($limit) and isset($offset)) {
                $query = $builder->get($limit, $offset);
            } else {
                $query = $builder->get();
            }
        }

        if (!$query) {
            log_message('error', 'Experiencing query error:');
            log_message('error', $this->db->error()['code'] . ' ' . $this->db->error()['message'] ? $this->db->error()['code'] . ' ' . $this->db->error()['message'] : '');
            log_message('error', 'in ' . $this->fetch_class . '/' . $this->fetch_method);
            log_message('error', 'SQL Query:');
            log_message('error', $this->db->getLastQuery());

            return false;

        } else {
            if ($this->db->DBDebug) {
                log_message('debug', 'SQL Query in ' . $this->fetch_class . '/' . $this->fetch_method . ':');
                log_message('debug', $this->db->getLastQuery());
            }

            if ($limit !== 1) {
                return $query->getResult();
            } else {
                return $query->getRow();
            }
        }
    }

    /**
     * Select Decrypt
     *
     * @access    public
     * @param     string   table columns
     * @param     mixed    where conditions in this format - array('column' => 'data') - or - "name='Joe' AND status='boss' OR status='active'"
     * @param     int      limit; single select must be implicitly set
     * @param     mixed    order by in this format - 'title desc, name asc, id random' - or - 'rand()'
     * @return    array/object
     */
    public function select_decrypt($columns = null, $conditions = null, $limit = null, $order = null, $fieldsToDecrypt = null)
    {
        $builder = $this->db->table($this->table);

        // Check if columns are provided, and apply AES_DECRYPT for encrypted fields
        if (!empty($columns)) {
            // Replace fields that need decryption with AES_DECRYPT
            $decryptedColumns = [];
            foreach ($columns as $column) {
                $safeColumn = sanitize_sql_identifier($column);
                if (! $safeColumn) {
                    continue;
                }
                // Check if the column needs to be decrypted
                if (in_array($column, $fieldsToDecrypt)) { // Add more encrypted fields if necessary
                    $decryptedColumns[] = "AES_DECRYPT({$safeColumn}, " . $this->db->escape(ENCRYPTION_KEY) . ") AS {$safeColumn}";
                } else {
                    $decryptedColumns[] = $safeColumn;
                }
            }
            $builder->select(implode(', ', $decryptedColumns));
        }

        if (!empty($order)) {
            $safeOrder = sanitize_order_by($order);
            if ($safeOrder) {
                $builder->orderBy($safeOrder);
            }
        }

        if (!empty($conditions)) {
            $query = $builder->getWhere($conditions);
        } else {
            if (isset($limit) && isset($offset)) {
                $query = $builder->get($limit, $offset);
            } else {
                $query = $builder->get();
            }
        }

        if (!$query) {
            log_message('error', 'Experiencing query error:');
            log_message('error', $this->db->error()['code'] . ' ' . ($this->db->error()['message'] ?? ''));
            log_message('error', 'in ' . $this->fetch_class . '/' . $this->fetch_method);
            log_message('error', 'SQL Query:');
            log_message('error', $this->db->getLastQuery());

            return false;
        } else {
            if ($this->db->DBDebug) {
                log_message('debug', 'SQL Query in ' . $this->fetch_class . '/' . $this->fetch_method . ':');
                log_message('debug', $this->db->getLastQuery());
            }

            if ($limit !== 1) {
                return $query->getResult();
            } else {
                return $query->getRow();
            }
        }
    }

    /**
     * Generic Insert
     *
     * @access    public
     * @param     array    values in this format - array('column' => 'value')
     * @return    int     id of inserted row
     */
    public function insert($data = null, bool $returnID = true)
    {

        $builder = $this->db->table($this->table);
        $query   = $builder->insert($data);

        if (!$query) {
            log_message('error', 'Experiencing query error:');
            log_message('error', $this->db->error()['code'] . ' ' . $this->db->error()['message']);
            log_message('error', 'in ' . $this->fetch_class . '/' . $this->fetch_method);
            log_message('error', 'SQL Query:');
            log_message('error', $this->db->getLastQuery());

            return false;
        } else {
            if ($this->db->DBDebug) {
                log_message('debug', 'SQL Query in ' . $this->fetch_class . '/' . $this->fetch_method . ':');
                log_message('debug', $this->db->getLastQuery());
            }

            if ($returnID) {
                return $this->db->insertID();
            } else {
                return $query;
            }

        }
    }

    /**
     * Insert data with AES encryption on specific fields
     *
     * @param array $data           Key-value array of columns and values
     * @param bool  $returnID       Return inserted ID if true
     * @param array $fieldsToEncrypt List of fields to encrypt
     * @return mixed                Insert ID, true/false
     */
    public function insert_encrypt(array $data, bool $returnID = true, array $fieldsToEncrypt = [])
    {
        if (empty($data)) {
            log_message('error', 'No data provided for insert_encrypt.');
            return false;
        }

        $providedId = $data['id'] ?? null;
        $encryptionKey = ENCRYPTION_KEY;

        foreach ($data as $key => $value) {
            if ($fieldsToEncrypt != null && in_array($key, $fieldsToEncrypt)) {
                if (is_null($value) || $value === '') {
                    // Store actual SQL NULL if value is empty
                    $data[$key] = "NULL";
                } else {
                    // Encrypt normally
                    $data[$key] = "AES_ENCRYPT('" . $this->_escape_sql_string($value) . "', '{$encryptionKey}')";
                }
            } else {
                // Non-encrypted fields
                $data[$key] = is_null($value) ? "NULL" : "'" . $this->_escape_sql_string($value) . "'";
            }
        }

        $builder = $this->db->table($this->table);

        // set() second param false = raw SQL
        $query = $builder->set($data, '', false)->insert();

        if (! $query) {
            log_message('error', 'Insert encrypt query failed: ' . json_encode($this->db->error()));
            log_message('error', 'SQL Query: ' . $this->db->getLastQuery());
            return false;
        }

        if ($returnID) {
            if ($providedId !== null && $providedId !== '') {
                return $providedId;
            }

            return $this->db->insertID();
        }

        return true;
    }

    /**
     * Multi-row insert with AES encryption on specific fields.
     *
     * @param array $rows            List of key-value column arrays (same keys)
     * @param array $fieldsToEncrypt List of fields to encrypt
     * @return bool
     */
    public function insert_batch_encrypt(array $rows, array $fieldsToEncrypt = []): bool
    {
        if (empty($rows)) {
            return true;
        }

        $encryptionKey = ENCRYPTION_KEY;
        $columns = array_keys($rows[0]);
        if (!$columns) {
            log_message('error', 'No columns provided for insert_batch_encrypt.');
            return false;
        }

        $columnList = implode(', ', array_map(static function ($column) {
            return '`' . str_replace('`', '', (string) $column) . '`';
        }, $columns));

        foreach (array_chunk($rows, 100) as $chunk) {
            $valueSets = [];
            foreach ($chunk as $data) {
                $parts = [];
                foreach ($columns as $key) {
                    $value = $data[$key] ?? null;
                    if (in_array($key, $fieldsToEncrypt, true)) {
                        if ($value === null || $value === '') {
                            $parts[] = 'NULL';
                        } else {
                            $parts[] = "AES_ENCRYPT('" . $this->_escape_sql_string($value) . "', '{$encryptionKey}')";
                        }
                    } else {
                        $parts[] = $value === null ? 'NULL' : "'" . $this->_escape_sql_string($value) . "'";
                    }
                }
                $valueSets[] = '(' . implode(', ', $parts) . ')';
            }

            $sql = "INSERT INTO `{$this->table}` ({$columnList}) VALUES " . implode(', ', $valueSets);
            $query = $this->db->query($sql);
            if (!$query) {
                log_message('error', 'Insert batch encrypt query failed: ' . json_encode($this->db->error()));
                log_message('error', 'SQL Query: ' . $this->db->getLastQuery());
                return false;
            }
        }

        return true;
    }

    /**
     * Last database error from this model's connection (code + message).
     */
    public function db_error(): array
    {
        $error = $this->db->error();

        return is_array($error) ? $error : ['code' => 0, 'message' => ''];
    }

    /**
     * Last database error message (used by controllers after failed insert/update).
     */
    public function error(): string
    {
        $error = $this->db_error();

        return (string) ($error['message'] ?? '');
    }

    /**
     * Batch Insert
     *
     * @access    public
     * @param     array    data
     * @return    int     id of inserted row
     */
    public function insert_batch($data)
    {
        $builder = $this->db->table($this->table);
        $query   = $builder->insertBatch($data);

        if (!$query) {
            log_message('error', 'Experiencing query error:');
            log_message('error', $this->db->error()['code'] . ' ' . $this->db->error()['message']);
            log_message('error', 'in ' . $this->fetch_class . '/' . $this->fetch_method);
            log_message('error', 'SQL Query:');
            log_message('error', $this->db->getLastQuery());
        } else {
            if ($this->db->DBDebug) {
                log_message('debug', 'SQL Query in ' . $this->fetch_class . '/' . $this->fetch_method . ':');
                log_message('debug', $this->db->getLastQuery());
            }
        }

        return $query;
    }

    /**
     * Generic Update
     *
     * @access    public
     * @param     array    where conditions in this format - array('column' => 'data')
     * @param     array    values this format - array('column' => 'value')
     * @return    boolean if update query is successful
     */
    public function update($conditions = null, $values = null): bool
    {
        $builder = $this->db->table($this->table);
        $query   = $builder->set($values)->where($conditions)->update();

        if (!$query) {
            log_message('error', 'Experiencing query error:');
            log_message('error', $this->db->error()['code'] . ' ' . $this->db->error()['message']);
            log_message('error', 'in ' . $this->fetch_class . '/' . $this->fetch_method);
            log_message('error', 'SQL Query:');
            log_message('error', $this->db->getLastQuery());

            return false;
        } elseif ($this->db->DBDebug) {
            log_message('debug', 'SQL Query in ' . $this->fetch_class . '/' . $this->fetch_method . ':');
            log_message('debug', $this->db->getLastQuery());
        }

        return true;

    }

    /**
     * Update Encrypt
     *
     * @access    public
     * @param     array    where conditions in this format - array('column' => 'data')
     * @param     array    values this format - array('column' => 'value')
     * @return    boolean if update query is successful
     */
    public function update_encrypt($conditions = null, $values = null, $fieldsToEncrypt = []): bool
    {
        // Check if conditions and values are provided
        if (is_null($conditions) || is_null($values)) {
            log_message('error', 'Conditions or values for update are not provided.');
            return false;
        }

        // Specify the encryption key
        $encryptionKey = ENCRYPTION_KEY;

        // Loop through the values and apply AES_ENCRYPT where necessary
        foreach ($values as $key => $value) {
            if (in_array($key, $fieldsToEncrypt)) {
                // If the value is null, produce SQL NULL (no quotes)
                if (is_null($value)) {
                    $values[$key] = "NULL";
                    continue;
                }

                // If encryption key is missing, store escaped plain value
                if (empty($encryptionKey)) {
                    $values[$key] = "'" . $this->_escape_sql_string($value) . "'";
                    continue;
                }

                // Otherwise encrypt the non-null value
                $escaped      = $this->_escape_sql_string($value);
                $values[$key] = "AES_ENCRYPT('{$escaped}', '{$encryptionKey}')";
            } else {
                // Non-encrypted fields: use escaped string in quotes (or NULL)
                if (is_null($value)) {
                    $values[$key] = "NULL";
                } else {
                    $values[$key] = "'" . $this->_escape_sql_string($value) . "'";
                }
            }
        }

        // Build the query with encryption for specific fields
        $builder = $this->db->table($this->table);

        // Use set() with second parameter as false to allow raw SQL for encrypted fields
        $query = $builder->set($values, '', false)->where($conditions)->update();

        if (!$query) {
            log_message('error', 'Experiencing query error:');
            log_message('error', $this->db->error()['code'] . ' ' . $this->db->error()['message']);
            log_message('error', 'in ' . $this->fetch_class . '/' . $this->fetch_method);
            log_message('error', 'SQL Query:');
            log_message('error', $this->db->getLastQuery());

            return false;
        } elseif ($this->db->DBDebug) {
            log_message('debug', 'SQL Query in ' . $this->fetch_class . '/' . $this->fetch_method . ':');
            log_message('debug', $this->db->getLastQuery());
        }

        return true;
    }

    /**
     * Batch Update
     *
     * @access    public
     * @param     array    data
     * @param     string    where key
     * @return    int if update query is successful
     */
    public function update_batch($data, $where_key)
    {
        $builder = $this->db->table($this->table);
        $query   = $builder->updateBatch($data, $where_key);

        if (!$query) {
            log_message('error', 'Experiencing query error:');
            log_message('error', $this->db->error()['code'] . ' ' . $this->db->error()['message']);
            log_message('error', 'in ' . $this->fetch_class . '/' . $this->fetch_method);
            log_message('error', 'SQL Query:');
            log_message('error', $this->db->getLastQuery());

            return false;
        } elseif ($this->db->DBDebug) {
            log_message('debug', 'SQL Query in ' . $this->fetch_class . '/' . $this->fetch_method . ':');
            log_message('debug', $this->db->getLastQuery());
        }

        return true;
    }

    /**
     * Update if data already exist, else insert
     *
     * @access    public
     * @param     array    where conditions in this format - array('column' => 'data')
     * @param     array    values this format - array('column' => 'value')
     * @return    int if update query is successful
     */
    public function insert_on_duplicate($conditions, $values)
    {
        if ($data = $this->select('', $conditions, 1)) {
            return $this->update($conditions, $values) ? $data->id : 0;
        } else {
            return $this->insert($values);
        }
    }
}

/*End of file MYT_Model.php*/
/*Location .application/Core/MYT_Model.php*/
