<?php

namespace App\Models;

use App\Core\MYT_Model;

class Branch_item_blindsheet extends MYT_Model
{
    protected $table = 'branch_item_blindsheet';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'branch_item_id', 'branch_id', 'department_id', 'item_id', 'counted_by_id',
        'physical_count', 'difference', 'prev_qty', 'date_counted', 'remarks', 'status',
        'added_by_branch_id', 'updated_by_branch_id', 'approved_by_branch_id', 'disapproved_by_branch_id',
    ];

    public static function database_groups(): array
    {
        return [0 => 'master', 1 => 'mobile', 2 => 'oakridge', 3 => 'mactan',
            4 => 'consolacion', 5 => 'warehouse', 6 => 'pharmacy', 7 => 'mantlewood'];
    }

    public static function configured_schemas(): array
    {
        $config = config('Database');
        $master = $config->master;
        $schemas = [];
        foreach (self::database_groups() as $branch_id => $group) {
            $settings = $config->$group;
            if (($settings['hostname'] ?? '') !== ($master['hostname'] ?? '')
                || (int) ($settings['port'] ?? 3306) !== (int) ($master['port'] ?? 3306)
                || ($settings['DBDriver'] ?? '') !== 'MySQLi') {
                throw new \RuntimeException('Inventory mirrors must use the same MySQL server.');
            }
            $schema = $settings['database'] ?? '';
            if (!is_string($schema) || !preg_match('/^[a-zA-Z0-9_]+$/D', $schema)) {
                throw new \RuntimeException('Invalid inventory schema configuration.');
            }
            $schemas[$branch_id] = $schema;
        }
        if (count(array_unique($schemas)) !== count($schemas)) {
            throw new \RuntimeException('Inventory schema connections must be distinct.');
        }
        return $schemas;
    }

    private function build_sql($blindsheet_id, $item_id, $branch_id, $branch_item_id, $status, array &$binds, bool $for_count): string
    {
        $binds = [];
        $sql = $for_count ? 'SELECT COUNT(*) AS total FROM branch_item_blindsheet'
            : 'SELECT branch_item_blindsheet.*, item.name AS item_name, department.name AS department_name
                FROM branch_item_blindsheet
                LEFT JOIN item ON item.id = branch_item_blindsheet.item_id
                LEFT JOIN {MASTER_DB}.department AS department ON department.id = branch_item_blindsheet.department_id';
        $sql .= ' WHERE branch_item_blindsheet.is_deleted = 0';
        foreach (['id' => $blindsheet_id, 'item_id' => $item_id, 'branch_id' => $branch_id,
            'branch_item_id' => $branch_item_id, 'status' => $status] as $field => $value) {
            if ($value !== null && $value !== '') {
                $sql .= ' AND branch_item_blindsheet.' . $field . ' = ?';
                $binds[] = $value;
            }
        }
        return $this->_resolve_cross_db_sql($sql);
    }

    public function get_details($blindsheet_id = null, $item_id = null, $branch_id = null, $branch_item_id = null, $status = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql = $this->build_sql($blindsheet_id, $item_id, $branch_id, $branch_item_id, $status, $binds, false);
        $sql .= ' ORDER BY branch_item_blindsheet.id DESC' . sql_limit_clause($page, $data_size);
        $query = $this->db->query($sql, $binds);
        if ($query === false) { return false; }
        return $this->decorate_audit_names($query->getResultArray());
    }

    public function get_count($blindsheet_id = null, $item_id = null, $branch_id = null, $branch_item_id = null, $status = null)
    {
        $binds = [];
        $sql = $this->build_sql($blindsheet_id, $item_id, $branch_id, $branch_item_id, $status, $binds, true);
        $query = $this->db->query($sql, $binds);
        return $query === false ? false : (int) ($query->getRowArray()['total'] ?? 0);
    }

    /** Resolve one batch per actual user source, after pagination. Never infer a source for legacy rows. */
    private function decorate_audit_names(array $rows): array
    {
        if (!$rows) { return []; }
        $schemas = self::configured_schemas();
        $user_ids = [];
        foreach ($rows as $row) {
            foreach (['added', 'updated', 'approved', 'disapproved'] as $audit) {
                $source_id = $row[$audit . '_by_branch_id'] ?? null;
                $user_id = $row[$audit . '_by'] ?? null;
                if ($source_id !== null && $source_id !== '' && isset($schemas[(int) $source_id]) && $user_id) {
                    $user_ids[(int) $source_id][(int) $user_id] = (int) $user_id;
                }
            }
        }
        $names = [];
        if ($user_ids) {
            $source_schemas = array_map(static fn($source_id) => $schemas[$source_id], array_keys($user_ids));
            $placeholders = implode(',', array_fill(0, count($source_schemas), '?'));
            $metadata = $this->db->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_SCHEMA IN ($placeholders) AND TABLE_NAME = ?", array_merge($source_schemas, ['user']));
            if ($metadata === false) { throw new \RuntimeException('Unable to resolve blindsheet audit sources.'); }
            $available = array_column($metadata->getResultArray(), 'TABLE_SCHEMA');
            foreach ($user_ids as $source_id => $ids) {
                if (!in_array($schemas[$source_id], $available, true)) { continue; }
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $name_expression = (int) $source_id === 0
                    ? "CONCAT_WS(' ', first_name, last_name)" : 'name';
                $query = $this->db->query('SELECT id, ' . $name_expression . ' AS audit_name FROM `' . $schemas[$source_id]
                    . "`.`user` WHERE is_deleted = 0 AND id IN ($placeholders)", array_values($ids));
                if ($query === false) { throw new \RuntimeException('Unable to resolve blindsheet audit names.'); }
                foreach ($query->getResultArray() as $user) {
                    $name = trim((string) ($user['audit_name'] ?? ''));
                    $names[$source_id][(int) $user['id']] = $name !== '' ? $name : null;
                }
            }
        }
        foreach ($rows as &$row) {
            foreach (['added', 'updated', 'approved', 'disapproved'] as $audit) {
                $source_id = $row[$audit . '_by_branch_id'] ?? null;
                $user_id = $row[$audit . '_by'] ?? null;
                $row[$audit . '_by_name'] = $source_id === null || $source_id === '' || !$user_id
                    ? null : ($names[(int) $source_id][(int) $user_id] ?? null);
            }
        }
        unset($row);
        return $rows;
    }

    /** Inspect configured inventory dependencies without changing business records. */
    public function check_readiness(array $schemas): void
    {
        $tables = ['branch_item_blindsheet', 'branch_item', 'item_cost', 'item', 'item_conversion', 'item_history'];
        $financial_tables = ['general_ledger', 'journal_entry', 'journal_entry_item'];
        $placeholders = implode(',', array_fill(0, count($schemas), '?'));
        $query = $this->db->query("SELECT TABLE_SCHEMA, TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA IN ($placeholders)", array_values($schemas));
        if ($query === false) {
            throw new \RuntimeException('Unable to inspect inventory tables.');
        }
        $rows = $query->getResultArray();
        $available = [];
        foreach ($rows as $row) {
            $available[$row['TABLE_SCHEMA']][$row['TABLE_NAME']] = strtoupper((string) $row['ENGINE']);
        }
        foreach ($schemas as $branch_id => $schema) {
            if (in_array($branch_id, [1, 2, 3, 4, 7], true)) {
                foreach ($financial_tables as $name) {
                    if (($available[$schema][$name] ?? null) !== 'INNODB') {
                        throw new \InvalidArgumentException('Blindsheet accounting schema is not ready for branch ' . $branch_id . '.');
                    }
                }
            }
            foreach ($tables as $name) {
                if (($available[$schema][$name] ?? null) !== 'INNODB') {
                    throw new \InvalidArgumentException('Blindsheet schema is not ready for branch ' . $branch_id . ': ' . $name . ' must exist and use InnoDB.');
                }
            }
            foreach ($financial_tables as $name) {
                if (isset($available[$schema][$name]) && $available[$schema][$name] !== 'INNODB') {
                    throw new \InvalidArgumentException('Accounting tables must use InnoDB for blindsheet approval.');
                }
            }
        }
        $query = $this->db->query("SELECT TABLE_SCHEMA, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA IN ($placeholders) AND TABLE_NAME = ?", array_merge(array_values($schemas), ['branch_item_blindsheet']));
        if ($query === false) {
            throw new \RuntimeException('Unable to inspect blindsheet columns.');
        }
        $columns = $query->getResultArray();
        $available_columns = [];
        foreach ($columns as $column) {
            $available_columns[$column['TABLE_SCHEMA']][$column['COLUMN_NAME']] = true;
        }
        $required_columns = ['id', 'branch_item_id', 'branch_id', 'department_id', 'item_id', 'counted_by_id',
            'physical_count', 'difference', 'prev_qty', 'date_counted', 'remarks', 'status', 'is_deleted'];
        foreach (['added', 'updated', 'approved', 'disapproved'] as $audit) {
            array_push($required_columns, $audit . '_by', $audit . '_on', $audit . '_by_branch_id');
        }
        foreach ($schemas as $branch_id => $schema) {
            foreach ($required_columns as $column) {
                if (!isset($available_columns[$schema][$column])) {
                    throw new \InvalidArgumentException('Blindsheet schema is not ready for branch ' . $branch_id . ': missing ' . $column . '.');
                }
            }
        }
        $this->check_privileges($tables, $schemas);
        $query = $this->db->query("SELECT TRIGGER_SCHEMA, TRIGGER_NAME, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA IN ($placeholders)", array_values($schemas));
        if ($query === false) {
            throw new \RuntimeException('Unable to inspect inventory triggers.');
        }
        $triggers = $query->getResultArray();
        $definitions = [];
        foreach ($triggers as $trigger) {
            $definitions[$trigger['TRIGGER_SCHEMA']][$trigger['TRIGGER_NAME']] = $trigger['ACTION_STATEMENT'];
        }
        foreach ($schemas as $branch_id => $schema) {
            $update = $definitions[$schema]['trg_branch_item_update'] ?? '';
            if (!preg_match('/qty_diff\s+DECIMAL\s*\(\s*13\s*,\s*2\s*\)/i', $update)) {
                throw new \InvalidArgumentException('Blindsheet inventory trigger is not ready for branch ' . $branch_id . '.');
            }
            // Preserve the existing readiness requirements for the five clinic branches.
            if (in_array($branch_id, [1, 2, 3, 4, 7], true)) {
                foreach (['trg_gl_item_history_ai', 'trg_gl_item_history_au'] as $name) {
                    if (!str_contains($definitions[$schema][$name] ?? '', 'Blindsheet')) {
                        throw new \InvalidArgumentException('Blindsheet accounting trigger is not ready for branch ' . $branch_id . '.');
                    }
                }
                $query = $this->db->query('SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND ROUTINE_NAME = ?', [$schema, 'gl_post_item_history']);
                if ($query === false) {
                    throw new \RuntimeException('Unable to inspect inventory posting procedures.');
                }
                $routine = $query->getRowArray();
                if (!$routine || !str_contains($routine['ROUTINE_DEFINITION'] ?? '', 'branch_item_blindsheet')) {
                    throw new \InvalidArgumentException('Blindsheet accounting procedure is not ready for branch ' . $branch_id . '.');
                }
                $query = $this->db->query('SELECT `' . $schema . '`.gl_branch_id() AS branch_id');
                if ($query === false) {
                    throw new \RuntimeException('Unable to inspect inventory posting branch.');
                }
                $posting_branch = $query->getRowArray();
                if ((int) ($posting_branch['branch_id'] ?? 0) !== $branch_id) {
                    throw new \InvalidArgumentException('Blindsheet accounting branch mapping is not ready for branch ' . $branch_id . '.');
                }
            }
        }
    }

    public function check_privileges(array $tables, array $schemas): void
    {
        foreach (Branch_item_blindsheet::database_groups() as $source_id => $group) {
            $db = db_connect($group);
            $query = $db->query('SELECT CURRENT_USER() AS account');
            if ($query === false) {
                throw new \RuntimeException('Unable to verify inventory database account.');
            }
            $account = $query->getRowArray()['account'] ?? '';
            $separator = strrpos($account, '@');
            if ($separator === false) {
                throw new \RuntimeException('Unable to verify inventory database privileges.');
            }
            $grantee = "'" . substr($account, 0, $separator) . "'@'" . substr($account, $separator + 1) . "'";
            $grants = $db->query("SELECT '' AS schema_name, '' AS table_name, PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = ?
                UNION ALL SELECT TABLE_SCHEMA, '', PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = ?
                UNION ALL SELECT TABLE_SCHEMA, TABLE_NAME, PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = ?", [$grantee, $grantee, $grantee]);
            if ($grants === false) {
                throw new \RuntimeException('Unable to verify inventory database privileges.');
            }
            $available = [];
            foreach ($grants->getResultArray() as $grant) {
                $available[$grant['schema_name']][$grant['table_name']][$grant['PRIVILEGE_TYPE']] = true;
            }
            $schema = $schemas[$source_id];
            foreach ($tables as $name) {
                $required = ['SELECT'];
                if (in_array($name, ['branch_item_blindsheet', 'item_cost'], true)) {
                    $required[] = 'INSERT';
                }
                if (in_array($name, ['branch_item_blindsheet', 'branch_item'], true)) {
                    $required[] = 'UPDATE';
                }
                foreach ($required as $privilege) {
                    if (!isset($available[''][''][$privilege]) && !isset($available[$schema][''][$privilege])
                        && !isset($available[$schema][$name][$privilege])
                        && !$this->has_effective_privilege($db, $name, $privilege)) {
                        throw new \InvalidArgumentException('Inventory connection for branch ' . $source_id . ' lacks required ' . $privilege . ' privileges.');
                    }
                }
            }
        }
    }

    public function has_effective_privilege($db, string $table_name, string $privilege): bool
    {
        try {
            if ($privilege === 'SELECT') {
                $sql = 'SELECT * FROM `' . $table_name . '` WHERE 0';
            } elseif ($privilege === 'INSERT') {
                $sql = 'EXPLAIN INSERT INTO `' . $table_name . '` SELECT * FROM `' . $table_name . '` WHERE 0';
            } elseif ($privilege === 'UPDATE') {
                $assignments = [];
                foreach ($db->getFieldNames($table_name) as $column_name) {
                    $column_name = str_replace('`', '``', $column_name);
                    $assignments[] = '`' . $column_name . '`=`' . $column_name . '`';
                }
                if (!$assignments) {
                    return false;
                }
                $sql = 'EXPLAIN UPDATE `' . $table_name . '` SET ' . implode(',', $assignments) . ' WHERE 0';
            } else {
                return false;
            }
            return $db->query($sql) !== false;
        } catch (\Throwable $error) {
            return false;
        }
    }

    public function acquire_operation_lock(array $schemas): string
    {
        $this->check_readiness($schemas);
        $lock_name = 'items_blindsheet_' . substr(hash('sha256', $schemas[0]), 0, 32);
        $query = $this->db->query('SELECT GET_LOCK(?, 10) AS acquired', [$lock_name]);
        if ($query === false) {
            throw new \RuntimeException('Unable to acquire blindsheet operation lock.');
        }
        if ((int) ($query->getRowArray()['acquired'] ?? 0) !== 1) {
            throw new \InvalidArgumentException('Another blindsheet operation is running. Please retry.');
        }
        return $lock_name;
    }

    public function release_operation_lock(?string $lock_name): void
    {
        if ($lock_name === null) {
            return;
        }
        try {
            if ($this->db->query('SELECT RELEASE_LOCK(?) AS released', [$lock_name]) === false) {
                throw new \RuntimeException('Unable to release blindsheet operation lock.');
            }
        } catch (\Throwable $error) {
            log_message('error', 'Unable to release Items blindsheet lock.');
        }
    }

    /** A pending selection in any mirror must prevent another submission. */
    public function get_pending($branch_item_id, $branch_id)
    {
        $groups = self::database_groups();
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $source_id) {
            $model = new self(db_connect($groups[$source_id]));
            $row = $model->select('', [
                'branch_item_id' => $branch_item_id,
                'branch_id' => $branch_id,
                'status' => 'pending',
                'is_deleted' => 0,
            ], 1);
            if (!$row && (int) ($model->db_error()['code'] ?? 0) !== 0) {
                throw new \RuntimeException('Pending blindsheet lookup failed.');
            }
            if ($row) {
                return $row;
            }
        }
        return null;
    }

    public function get_inventory_mirrors(int $branch_item_id, int $branch_id, int $item_id): array
    {
        $rows = [];
        $groups = self::database_groups();
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $source_id) {
            $db = db_connect($groups[$source_id]);
            $row = (new Branch_item($db))->select('', [
                'id' => $branch_item_id,
                'branch_id' => $branch_id,
                'item_id' => $item_id,
                'is_deleted' => 0,
            ], 1);
            $item = (new Item($db))->select('', ['id' => $item_id, 'is_deleted' => 0], 1);
            if (!$row || !$item) {
                throw new \OutOfBoundsException('Active branch item and item must exist in every inventory mirror.');
            }
            $rows[] = (array) $row;
        }
        return $rows;
    }

    public function get_selection_mirrors(int $blindsheet_id, int $branch_id): array
    {
        $rows = [];
        $groups = self::database_groups();
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $source_id) {
            $row = (new self(db_connect($groups[$source_id])))->select('', [
                'id' => $blindsheet_id,
                'branch_id' => $branch_id,
                'is_deleted' => 0,
            ], 1);
            if (!$row) {
                throw new \OutOfBoundsException('Branch item blindsheet is not found in every inventory mirror.');
            }
            $rows[] = $row;
        }
        return $rows;
    }

    public function retire_item(int $item_id, array $values, int $audit_source): bool
    {
        if (!$this->db->tableExists('branch_item_blindsheet')) {
            return true;
        }
        if ($this->db->fieldExists('updated_by_branch_id', 'branch_item_blindsheet')) {
            $values['updated_by_branch_id'] = $audit_source;
        }
        return $this->update(['item_id' => $item_id], $values) !== false;
    }
}
