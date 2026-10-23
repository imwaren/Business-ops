<?php

namespace App\Controllers\Traits;

/**
 * Shared cleanup / check / fix consistency APIs for multi-DB catalog entities.
 * Controllers must implement the abstract config hooks.
 */
trait Entity_consistency
{
    abstract protected function _consistency_table(): string;

    abstract protected function _consistency_duplicate_keys(): array;

    abstract protected function _consistency_compare_fields(): array;

    /**
     * @return array<int, array{table:string,fk:string}>
     */
    protected function _consistency_child_tables(): array
    {
        return [];
    }

    protected function _consistency_label_fields(): array
    {
        return $this->_consistency_duplicate_keys();
    }

    protected function _consistency_db_ids(): array
    {
        return [0, 1, 2, 3, 4, 5, 6, 7];
    }

    protected function _consistency_db_map(): array
    {
        return [
            0 => 'master',
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            5 => 'warehouse',
            6 => 'pharmacy',
            7 => 'mantlewood',
        ];
    }

    protected function _consistency_diagnostic_branches(): array
    {
        $map = $this->_consistency_db_map();
        $branches = [];
        foreach ($this->_consistency_db_ids() as $id) {
            if ((int)$id === 0) {
                continue;
            }
            if (isset($map[$id])) {
                $branches[$id] = $map[$id];
            }
        }
        return $branches;
    }

    /**
     * Soft-delete duplicate active rows (keep MIN(id) per key group).
     * POST /{resource}/cleanup_duplicate_items
     */
    public function cleanup_duplicate_items()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        @set_time_limit(120);
        @ini_set('max_execution_time', '120');

        $limit = (int)($this->payload['limit'] ?? 25);
        if ($limit < 1) {
            $limit = 25;
        }

        $table = $this->_consistency_table();
        $keys = $this->_consistency_duplicate_keys();
        if (empty($keys)) {
            return $this->_create_response(FAIL, ['Duplicate key fields are not configured.']);
        }

        $selectParts = [];
        $groupParts = [];
        foreach ($keys as $key) {
            $col = $this->_consistency_quote_ident($key);
            $selectParts[] = $col;
            $groupParts[] = $col;
        }
        $selectSql = implode(",\n    ", $selectParts);
        $groupSql = implode(', ', $groupParts);
        $tableSql = $this->_consistency_quote_ident($table);

        $masterDb = db_connect($this->_consistency_db_map()[0]);
        $sql = <<<EOT
SELECT
    {$selectSql},
    is_deleted,
    MIN(id) AS keep_item_id,
    GROUP_CONCAT(id ORDER BY id) AS all_ids,
    COUNT(*) AS duplicate_count
FROM {$tableSql}
WHERE is_deleted = 0
GROUP BY {$groupSql}, is_deleted
HAVING COUNT(*) > 1
ORDER BY duplicate_count DESC
EOT;

        $query = $masterDb->query($sql);
        if ($query === false) {
            return $this->_create_response(FAIL, [
                'Failed to query duplicate ' . $table . ' rows.',
            ]);
        }

        $groups = $query->getResultArray() ?: [];
        $queue = [];
        foreach ($groups as $group) {
            $keepId = (int)$group['keep_item_id'];
            $allIds = array_map('intval', explode(',', $group['all_ids']));
            foreach ($allIds as $id) {
                if ($id === $keepId) {
                    continue;
                }
                $entry = [
                    'keep_item_id' => $keepId,
                    'delete_id'    => $id,
                    'is_deleted'   => (int)$group['is_deleted'],
                ];
                foreach ($keys as $key) {
                    $entry[$key] = $group[$key] ?? null;
                }
                $queue[] = $entry;
            }
        }

        if (empty($queue)) {
            return $this->_create_response(SUCCESS, [
                'groups_cleaned'         => 0,
                'deleted_count'          => 0,
                'deleted'                => [],
                'remaining_delete_count' => 0,
                'done'                   => true,
                'message'                => 'all databases rows is consistent',
            ]);
        }

        $chunk = array_slice($queue, 0, $limit);
        $remaining = count($queue) - count($chunk);

        $softDeleteValues = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        $deletedByKeep = [];
        $deletedCount = 0;

        foreach ($chunk as $entry) {
            $deleteId = (int)$entry['delete_id'];
            if (!$this->_consistency_soft_delete_cascade($deleteId, $softDeleteValues)) {
                return $this->_create_response(FAIL, [
                    'Failed to soft-delete duplicate ' . $table . '_id ' . $deleteId . '. ' . ($this->error_message ?? ''),
                    'partial' => [
                        'deleted_count'          => $deletedCount,
                        'deleted'                => array_values($deletedByKeep),
                        'remaining_delete_count' => $remaining + (count($chunk) - $deletedCount),
                        'done'                   => false,
                    ],
                ]);
            }

            $keepKey = (int)$entry['keep_item_id'];
            if (!isset($deletedByKeep[$keepKey])) {
                $row = [
                    'is_deleted'       => $entry['is_deleted'],
                    'keep_item_id'     => $keepKey,
                    'deleted_item_ids' => [],
                ];
                foreach ($keys as $key) {
                    $row[$key] = $entry[$key] ?? null;
                }
                $deletedByKeep[$keepKey] = $row;
            }
            $deletedByKeep[$keepKey]['deleted_item_ids'][] = $deleteId;
            $deletedCount++;
        }

        $done = ($remaining === 0);

        return $this->_create_response(SUCCESS, [
            'groups_cleaned'         => count($deletedByKeep),
            'deleted_count'          => $deletedCount,
            'deleted'                => array_values($deletedByKeep),
            'remaining_delete_count' => $remaining,
            'done'                   => $done,
            'message'                => $done
                ? 'all databases rows is consistent'
                : 'Processed ' . $deletedCount . ' duplicate item(s). Call again until done.',
        ]);
    }

    /**
     * Compare rows between master and each diagnostic DB.
     * GET /{resource}/check_consistency
     */
    public function check_consistency()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        @set_time_limit(120);
        @ini_set('max_execution_time', '120');

        $isDeletedFilter = $this->payload['is_deleted'] ?? '0';
        $includeAll = (is_string($isDeletedFilter) && strtolower($isDeletedFilter) === 'all');
        $compareFields = $this->_consistency_compare_fields();

        $masterMap = $this->_consistency_load_map(0, $includeAll, $isDeletedFilter);
        if ($masterMap === false) {
            return $this->_create_response(FAIL, [
                'Failed to load ' . $this->_consistency_table() . ' from master. ' . ($this->error_message ?? ''),
            ]);
        }

        $databases = [];
        $allConsistent = true;

        foreach ($this->_consistency_diagnostic_branches() as $branchId => $dbName) {
            $branchMap = $this->_consistency_load_map($branchId, $includeAll, $isDeletedFilter);
            if ($branchMap === false) {
                return $this->_create_response(FAIL, [
                    "Failed to load {$this->_consistency_table()} from {$dbName}. " . ($this->error_message ?? ''),
                ]);
            }

            $comparison = $this->_consistency_compare_maps($masterMap, $branchMap, $compareFields);
            $dbConsistent = empty($comparison['missing_on_branch'])
                && empty($comparison['extra_on_branch'])
                && empty($comparison['field_diffs']);

            if (!$dbConsistent) {
                $allConsistent = false;
            }

            $databases[] = [
                'branch_id'         => $branchId,
                'db'                => $dbName,
                'branch_count'      => count($branchMap),
                'consistent'        => $dbConsistent,
                'missing_on_branch' => $comparison['missing_on_branch'],
                'extra_on_branch'   => $comparison['extra_on_branch'],
                'field_diffs'       => $comparison['field_diffs'],
            ];
        }

        $response = [
            'consistent'   => $allConsistent,
            'filter'       => [
                'is_deleted' => $includeAll ? 'all' : (int)$isDeletedFilter,
            ],
            'master_count' => count($masterMap),
            'databases'    => $databases,
            'message'      => $allConsistent
                ? 'All diagnostic databases are consistent with master.'
                : 'Data inconsistency detected between master and one or more diagnostic databases.',
        ];

        return $this->_create_response(
            $allConsistent ? SUCCESS : FAIL,
            $response
        );
    }

    /**
     * Repair row inconsistencies between master and diagnostic DBs.
     * POST /{resource}/fix_consistency
     */
    public function fix_consistency()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        @set_time_limit(120);
        @ini_set('max_execution_time', '120');

        $limit = (int)($this->payload['limit'] ?? 25);
        if ($limit < 1) {
            $limit = 25;
        }

        $targetBranches = $this->_consistency_resolve_target_branches(
            $this->payload['branch_id'] ?? null
        );
        if ($targetBranches === false) {
            return $this->_create_response(FAIL, [
                $this->error_message ?? 'Invalid branch_id for fix_consistency.',
            ]);
        }

        $filterBranchId = array_key_exists('branch_id', $this->payload ?? [])
            && $this->payload['branch_id'] !== null
            && $this->payload['branch_id'] !== ''
            ? (int)$this->payload['branch_id']
            : null;
        $filterMeta = [
            'limit'     => $limit,
            'branch_id' => $filterBranchId,
        ];

        $table = $this->_consistency_table();
        $masterMap = $this->_consistency_load_map(0, true, 'all');
        if ($masterMap === false) {
            return $this->_create_response(FAIL, [
                'Failed to load ' . $table . ' from master. ' . ($this->error_message ?? ''),
            ]);
        }

        $branchMaps = [];
        foreach ($targetBranches as $branchId => $dbName) {
            $map = $this->_consistency_load_map($branchId, true, 'all');
            if ($map === false) {
                return $this->_create_response(FAIL, [
                    "Failed to load {$table} from {$dbName}. " . ($this->error_message ?? ''),
                ]);
            }
            $branchMaps[$branchId] = [
                'db'  => $dbName,
                'map' => $map,
            ];
        }

        $work = [];
        $compareFields = $this->_consistency_compare_fields();
        $softDeleteBranchKeys = [];

        // Soft-delete extras: master deleted, branch still active
        foreach ($branchMaps as $branchId => $info) {
            foreach ($info['map'] as $id => $branchRow) {
                if (!isset($masterMap[$id])) {
                    continue;
                }
                $masterRow = $masterMap[$id];
                if ((int)($masterRow['is_deleted'] ?? 0) !== 1) {
                    continue;
                }
                if ((int)($branchRow['is_deleted'] ?? 0) !== 0) {
                    continue;
                }
                $softDeleteBranchKeys[$branchId . ':' . $id] = true;
                $work[] = array_merge([
                    'action'           => 'soft_delete_branch',
                    'id'               => (int)$id,
                    'target_branch_id' => (int)$branchId,
                    'db'               => $info['db'],
                ], $this->_consistency_row_label($masterRow));
            }
        }

        // Sync field diffs: copy master compare-field values onto branch
        foreach ($branchMaps as $branchId => $info) {
            foreach ($info['map'] as $id => $branchRow) {
                if (!isset($masterMap[$id])) {
                    continue;
                }
                if (isset($softDeleteBranchKeys[$branchId . ':' . $id])) {
                    continue;
                }

                $masterRow = $masterMap[$id];
                $values = [];
                $fields = [];
                foreach ($compareFields as $field) {
                    $masterVal = $this->_consistency_normalize_value($masterRow[$field] ?? null);
                    $branchVal = $this->_consistency_normalize_value($branchRow[$field] ?? null);
                    if ($masterVal !== $branchVal) {
                        $values[$field] = $masterRow[$field] ?? null;
                        $fields[] = $field;
                    }
                }

                if (empty($values)) {
                    continue;
                }

                $work[] = array_merge([
                    'action'           => 'sync_fields',
                    'id'               => (int)$id,
                    'target_branch_id' => (int)$branchId,
                    'db'               => $info['db'],
                    'values'           => $values,
                    'fields'           => $fields,
                ], $this->_consistency_row_label($masterRow));
            }
        }

        // Soft-delete: master active, any diagnostic deleted
        foreach ($masterMap as $id => $masterRow) {
            if ((int)($masterRow['is_deleted'] ?? 0) !== 0) {
                continue;
            }
            foreach ($branchMaps as $info) {
                if (!isset($info['map'][$id])) {
                    continue;
                }
                if ((int)($info['map'][$id]['is_deleted'] ?? 0) === 1) {
                    $work[] = array_merge([
                        'action' => 'soft_delete',
                        'id'     => (int)$id,
                    ], $this->_consistency_row_label($masterRow));
                    break;
                }
            }
        }

        // Extra on diagnostic, missing on master
        $seenExtra = [];
        foreach ($branchMaps as $branchId => $info) {
            foreach ($info['map'] as $id => $branchRow) {
                if (isset($masterMap[$id]) || isset($seenExtra[$id])) {
                    continue;
                }
                $seenExtra[$id] = true;
                $work[] = array_merge([
                    'action'    => 'insert',
                    'id'        => (int)$id,
                    'source_db' => $info['db'],
                    'source_id' => (int)$branchId,
                ], $this->_consistency_row_label($branchRow));
            }
        }

        // Master missing on any diagnostic — push from master
        $seenPush = [];
        foreach ($masterMap as $id => $masterRow) {
            if (isset($seenPush[$id])) {
                continue;
            }
            foreach ($branchMaps as $info) {
                if (!isset($info['map'][$id])) {
                    $seenPush[$id] = true;
                    $work[] = array_merge([
                        'action'    => 'push',
                        'id'        => (int)$id,
                        'source_db' => 'master',
                        'source_id' => 0,
                    ], $this->_consistency_row_label($masterRow));
                    break;
                }
            }
        }

        if (empty($work)) {
            return $this->_create_response(
                SUCCESS,
                $this->_consistency_fix_done_payload(
                    [],
                    [],
                    [],
                    [],
                    0,
                    $targetBranches,
                    $filterMeta
                )
            );
        }

        $chunk = array_slice($work, 0, $limit);
        $remaining = count($work) - count($chunk);

        $now = date('Y-m-d H:i:s');
        $requester = $this->payload['requester'] ?? null;
        $softDeleteValues = [
            'is_deleted' => 1,
            'updated_by' => $requester,
            'updated_on' => $now,
        ];

        $softDeleted = [];
        $fieldSynced = [];
        $inserted = [];
        $pushed = [];

        $partialPayload = function () use (&$softDeleted, &$fieldSynced, &$inserted, &$pushed, $remaining, $filterMeta) {
            return [
                'filter'          => $filterMeta,
                'fixed_count'     => count($softDeleted) + count($fieldSynced) + count($inserted) + count($pushed),
                'soft_deleted'    => $softDeleted,
                'field_synced'    => $fieldSynced,
                'inserted'        => $inserted,
                'pushed'          => $pushed,
                'remaining_count' => $remaining,
                'done'            => false,
            ];
        };

        foreach ($chunk as $job) {
            if ($job['action'] === 'soft_delete_branch') {
                if (!$this->_consistency_soft_delete_branch_cascade(
                    (int)$job['id'],
                    (int)$job['target_branch_id'],
                    $softDeleteValues
                )) {
                    return $this->_create_response(FAIL, [
                        'Failed to soft-delete ' . $table . '_id ' . $job['id']
                            . ' on ' . ($job['db'] ?? '') . '. ' . ($this->error_message ?? ''),
                        'partial' => $partialPayload(),
                    ]);
                }
                $softDeleted[] = array_merge(
                    [
                        'id'        => (int)$job['id'],
                        'branch_id' => (int)$job['target_branch_id'],
                        'db'        => $job['db'],
                    ],
                    $this->_consistency_job_labels($job)
                );
                continue;
            }

            if ($job['action'] === 'sync_fields') {
                $values = $job['values'];
                $values['updated_by'] = $requester;
                $values['updated_on'] = $now;
                if (!$this->_consistency_update_branch(
                    $table,
                    (int)$job['target_branch_id'],
                    ['id' => (int)$job['id']],
                    $values
                )) {
                    return $this->_create_response(FAIL, [
                        'Failed to sync fields for ' . $table . '_id ' . $job['id']
                            . ' on ' . ($job['db'] ?? '') . '. ' . ($this->error_message ?? ''),
                        'partial' => $partialPayload(),
                    ]);
                }
                $fieldSynced[] = array_merge(
                    [
                        'id'        => (int)$job['id'],
                        'branch_id' => (int)$job['target_branch_id'],
                        'db'        => $job['db'],
                        'fields'    => $job['fields'],
                    ],
                    $this->_consistency_job_labels($job)
                );
                continue;
            }

            if ($job['action'] === 'soft_delete') {
                if (!$this->_consistency_soft_delete_cascade((int)$job['id'], $softDeleteValues)) {
                    return $this->_create_response(FAIL, [
                        'Failed to soft-delete ' . $table . '_id ' . $job['id'] . '. ' . ($this->error_message ?? ''),
                        'partial' => $partialPayload(),
                    ]);
                }
                $softDeleted[] = array_merge(
                    ['id' => (int)$job['id']],
                    $this->_consistency_job_labels($job)
                );
                continue;
            }

            if ($job['action'] === 'insert' || $job['action'] === 'push') {
                if (!$this->_consistency_mirror_insert_row((int)$job['id'], (int)$job['source_id'])) {
                    return $this->_create_response(FAIL, [
                        'Failed to sync ' . $table . '_id ' . $job['id'] . ' from ' . ($job['source_db'] ?? '') . '. ' . ($this->error_message ?? ''),
                        'partial' => $partialPayload(),
                    ]);
                }
                $entry = array_merge(
                    [
                        'id'        => (int)$job['id'],
                        'source_db' => $job['source_db'],
                    ],
                    $this->_consistency_job_labels($job)
                );
                if ($job['action'] === 'insert') {
                    $inserted[] = $entry;
                } else {
                    $pushed[] = $entry;
                }
            }
        }

        $fixedCount = count($softDeleted) + count($fieldSynced) + count($inserted) + count($pushed);

        if ($remaining > 0) {
            return $this->_create_response(SUCCESS, [
                'filter'          => $filterMeta,
                'fixed_count'     => $fixedCount,
                'soft_deleted'    => $softDeleted,
                'field_synced'    => $fieldSynced,
                'inserted'        => $inserted,
                'pushed'          => $pushed,
                'remaining_count' => $remaining,
                'done'            => false,
                'message'         => 'Processed ' . $fixedCount . ' consistency fix(es). Call again until done.',
            ]);
        }

        return $this->_create_response(
            SUCCESS,
            $this->_consistency_fix_done_payload(
                $softDeleted,
                $inserted,
                $pushed,
                $fieldSynced,
                $fixedCount,
                $targetBranches,
                $filterMeta
            )
        );
    }

    private function _consistency_soft_delete_cascade(int $id, array $values): bool
    {
        if (!$this->_consistency_mirror_update($this->_consistency_table(), ['id' => $id], $values)) {
            return false;
        }

        foreach ($this->_consistency_child_tables() as $child) {
            $table = $child['table'] ?? null;
            $fk = $child['fk'] ?? null;
            if (!$table || !$fk) {
                continue;
            }
            if (!$this->_consistency_mirror_update($table, [$fk => $id], $values)) {
                return false;
            }
        }

        return true;
    }

    private function _consistency_soft_delete_branch_cascade(int $id, int $branchId, array $values): bool
    {
        if (!$this->_consistency_update_branch($this->_consistency_table(), $branchId, ['id' => $id], $values)) {
            return false;
        }

        foreach ($this->_consistency_child_tables() as $child) {
            $table = $child['table'] ?? null;
            $fk = $child['fk'] ?? null;
            if (!$table || !$fk) {
                continue;
            }
            if (!$this->_consistency_update_branch($table, $branchId, [$fk => $id], $values)) {
                return false;
            }
        }

        return true;
    }

    private function _consistency_update_branch(string $table, int $branchId, array $where, array $values): bool
    {
        $dbMap = $this->_consistency_db_map();
        if (!isset($dbMap[$branchId])) {
            $this->error_message = 'Invalid branch id for consistency update.';
            return false;
        }

        $db = db_connect($dbMap[$branchId]);
        $builder = $db->table($table);
        foreach ($where as $col => $val) {
            $builder->where($col, $val);
        }
        if ($builder->update($values) === false) {
            $err = $db->error();
            $this->error_message = $err['message'] ?? ('Failed update on ' . $dbMap[$branchId] . '.' . $table);
            return false;
        }

        return true;
    }

    private function _consistency_mirror_update(string $table, array $where, array $values): bool
    {
        $dbMap = $this->_consistency_db_map();
        foreach ($this->_consistency_db_ids() as $branchId) {
            if (!isset($dbMap[$branchId])) {
                continue;
            }
            $db = db_connect($dbMap[$branchId]);
            $builder = $db->table($table);
            foreach ($where as $col => $val) {
                $builder->where($col, $val);
            }
            if ($builder->update($values) === false) {
                $err = $db->error();
                $this->error_message = $err['message'] ?? ('Failed update on ' . $dbMap[$branchId] . '.' . $table);
                return false;
            }
        }
        return true;
    }

    private function _consistency_mirror_insert_row(int $rowId, int $sourceBranchId): bool
    {
        $dbMap = $this->_consistency_db_map();
        $table = $this->_consistency_table();

        if (!isset($dbMap[$sourceBranchId])) {
            $this->error_message = 'Invalid source branch for insert.';
            return false;
        }

        $sourceDb = db_connect($dbMap[$sourceBranchId]);
        $sourceQuery = $sourceDb->table($table)->where('id', $rowId)->get();
        if ($sourceQuery === false) {
            $err = $sourceDb->error();
            $this->error_message = $err['message'] ?? 'Failed to read source row.';
            return false;
        }
        $row = $sourceQuery->getRowArray();
        if (!$row) {
            $this->error_message = 'Source row not found.';
            return false;
        }

        foreach ($this->_consistency_db_ids() as $branchId) {
            $db = db_connect($dbMap[$branchId]);
            $existsQuery = $db->table($table)->select('id')->where('id', $rowId)->get(1);
            if ($existsQuery === false) {
                $err = $db->error();
                $this->error_message = $err['message'] ?? ('Failed exists check on DB ' . $dbMap[$branchId]);
                return false;
            }
            if ($existsQuery->getRow()) {
                continue;
            }

            if ($db->table($table)->insert($row) === false) {
                $err = $db->error();
                $this->error_message = $err['message'] ?? ('Failed insert on DB ' . $dbMap[$branchId]);
                return false;
            }
        }

        return true;
    }

    private function _consistency_load_map(int $branchId, bool $includeAll, $isDeletedFilter)
    {
        $dbMap = $this->_consistency_db_map();
        if (!isset($dbMap[$branchId])) {
            $this->error_message = 'Invalid branch id for consistency check.';
            return false;
        }

        $fields = array_unique(array_merge(
            ['id', 'is_deleted'],
            $this->_consistency_compare_fields(),
            $this->_consistency_label_fields()
        ));

        $db = db_connect($dbMap[$branchId]);
        $builder = $db->table($this->_consistency_table())->select(implode(', ', $fields));

        if (!$includeAll) {
            $builder->where('is_deleted', (int)$isDeletedFilter);
        }

        $query = $builder->get();
        if ($query === false) {
            $err = $db->error();
            $this->error_message = $err['message'] ?? 'Query failed.';
            return false;
        }

        $map = [];
        foreach ($query->getResultArray() as $row) {
            $map[(int)$row['id']] = $row;
        }

        return $map;
    }

    private function _consistency_compare_maps(array $masterMap, array $branchMap, array $compareFields): array
    {
        $missingOnBranch = [];
        $extraOnBranch = [];
        $fieldDiffs = [];

        foreach ($masterMap as $id => $masterRow) {
            if (!isset($branchMap[$id])) {
                $missingOnBranch[] = array_merge(
                    ['id' => $id],
                    $this->_consistency_row_label($masterRow)
                );
                continue;
            }

            $branchRow = $branchMap[$id];
            $differences = [];
            foreach ($compareFields as $field) {
                $masterVal = $this->_consistency_normalize_value($masterRow[$field] ?? null);
                $branchVal = $this->_consistency_normalize_value($branchRow[$field] ?? null);
                if ($masterVal !== $branchVal) {
                    $differences[$field] = [
                        'master' => $masterRow[$field] ?? null,
                        'branch' => $branchRow[$field] ?? null,
                    ];
                }
            }

            if (!empty($differences)) {
                $fieldDiffs[] = array_merge(
                    [
                        'id'          => $id,
                        'differences' => $differences,
                    ],
                    $this->_consistency_row_label($masterRow)
                );
            }
        }

        foreach ($branchMap as $id => $branchRow) {
            if (!isset($masterMap[$id])) {
                $extraOnBranch[] = array_merge(
                    ['id' => $id],
                    $this->_consistency_row_label($branchRow)
                );
            }
        }

        return [
            'missing_on_branch' => $missingOnBranch,
            'extra_on_branch'   => $extraOnBranch,
            'field_diffs'       => $fieldDiffs,
        ];
    }

    private function _consistency_normalize_value($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_numeric($value)) {
            return (string)(0 + $value);
        }

        return trim((string)$value);
    }

    private function _consistency_row_label(array $row): array
    {
        $label = [];
        foreach ($this->_consistency_label_fields() as $field) {
            $label[$field] = $row[$field] ?? null;
        }
        return $label;
    }

    private function _consistency_job_labels(array $job): array
    {
        $label = [];
        foreach ($this->_consistency_label_fields() as $field) {
            $label[$field] = $job[$field] ?? null;
        }
        return $label;
    }

    /**
     * @param mixed $branchIdFilter
     * @return array<int, string>|false
     */
    private function _consistency_resolve_target_branches($branchIdFilter)
    {
        $allBranches = $this->_consistency_diagnostic_branches();

        if ($branchIdFilter === null || $branchIdFilter === '') {
            return $allBranches;
        }

        $branchId = (int)$branchIdFilter;
        if ($branchId < 1 || !isset($allBranches[$branchId])) {
            $allowed = implode(', ', array_keys($allBranches));
            $this->error_message = 'Invalid branch_id. Allowed diagnostic branch ids: ' . $allowed . '.';
            return false;
        }

        return [$branchId => $allBranches[$branchId]];
    }

    private function _consistency_fix_done_payload(
        array $softDeleted,
        array $inserted,
        array $pushed,
        array $fieldSynced = [],
        int $fixedCount = 0,
        ?array $targetBranches = null,
        ?array $filterMeta = null
    ): array {
        $status = $this->_consistency_evaluate_active($targetBranches);

        $payload = [
            'filter'          => $filterMeta ?? [
                'limit'     => null,
                'branch_id' => null,
            ],
            'fixed_count'     => $fixedCount,
            'soft_deleted'    => $softDeleted,
            'field_synced'    => $fieldSynced,
            'inserted'        => $inserted,
            'pushed'          => $pushed,
            'remaining_count' => 0,
            'done'            => true,
        ];

        if ($status['consistent']) {
            $payload['message'] = $filterMeta['branch_id'] ?? null
                ? 'Selected branch is consistent with master.'
                : 'all databases rows is consistent';
            return $payload;
        }

        $payload['message'] = 'Consistency fixes applied, but differences remain. Run check_consistency for details.';
        $payload['remaining_field_diff_count'] = $status['field_diff_count'];
        $payload['remaining_missing_count'] = $status['missing_count'];
        $payload['remaining_extra_count'] = $status['extra_count'];

        return $payload;
    }

    private function _consistency_evaluate_active(?array $targetBranches = null): array
    {
        $compareFields = $this->_consistency_compare_fields();
        $masterMap = $this->_consistency_load_map(0, false, 0);
        if ($masterMap === false) {
            return [
                'consistent'       => false,
                'field_diff_count' => 0,
                'missing_count'    => 0,
                'extra_count'      => 0,
            ];
        }

        $branches = $targetBranches ?? $this->_consistency_diagnostic_branches();
        $fieldDiffCount = 0;
        $missingCount = 0;
        $extraCount = 0;
        $consistent = true;

        foreach ($branches as $branchId => $dbName) {
            $branchMap = $this->_consistency_load_map($branchId, false, 0);
            if ($branchMap === false) {
                return [
                    'consistent'       => false,
                    'field_diff_count' => 0,
                    'missing_count'    => 0,
                    'extra_count'      => 0,
                ];
            }

            $comparison = $this->_consistency_compare_maps($masterMap, $branchMap, $compareFields);
            $missingCount += count($comparison['missing_on_branch']);
            $extraCount += count($comparison['extra_on_branch']);
            $fieldDiffCount += count($comparison['field_diffs']);

            if (
                !empty($comparison['missing_on_branch'])
                || !empty($comparison['extra_on_branch'])
                || !empty($comparison['field_diffs'])
            ) {
                $consistent = false;
            }
        }

        return [
            'consistent'       => $consistent,
            'field_diff_count' => $fieldDiffCount,
            'missing_count'    => $missingCount,
            'extra_count'      => $extraCount,
        ];
    }

    private function _consistency_quote_ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
