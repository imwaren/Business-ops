<?php

/**
 * Request/costing/quotation/endorsement service-line add-on helpers.
 * package_ids stores parent service-row ids, not catalog type_id.
 */

function service_line_key(array $service): ?string
{
    if (array_key_exists('key', $service) && $service['key'] !== '' && $service['key'] !== null) {
        return (string) $service['key'];
    }
    if (array_key_exists('id', $service) && $service['id'] !== '' && $service['id'] !== null) {
        return (string) $service['id'];
    }

    return null;
}

function service_line_normalize_package_ids($raw): ?string
{
    if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
        return null;
    }

    $value = trim((string) $raw);
    if ($value === '' || strcasecmp($value, 'not_applicable') === 0) {
        return null;
    }

    return $value;
}

function service_line_pending_add_on_fields(): array
{
    return [
        'package_ids' => null,
        'is_add_on' => 0,
    ];
}

function service_line_register_ids(array &$keyToId, array $service, $dbId): void
{
    $dbId = (string) $dbId;
    $key = service_line_key($service);
    if ($key !== null) {
        $keyToId[$key] = $dbId;
    }
    $keyToId[$dbId] = $dbId;
    if (array_key_exists('id', $service) && $service['id'] !== '' && $service['id'] !== null) {
        $keyToId[(string) $service['id']] = $dbId;
    }
}

function service_line_resolve_package_ids($raw, array $keyToId, $selfId = null): array
{
    $normalized = service_line_normalize_package_ids($raw);
    if ($normalized === null) {
        return ['ok' => true, 'package_ids' => null];
    }

    $resolved = [];
    foreach (explode(',', $normalized) as $token) {
        $token = trim($token);
        if ($token === '') {
            continue;
        }
        if (!isset($keyToId[$token])) {
            return ['ok' => false, 'error' => 'Unable to resolve add-on parent key ' . $token . '.'];
        }
        $parentId = (string) $keyToId[$token];
        if ($selfId !== null && (string) $selfId === $parentId) {
            return ['ok' => false, 'error' => 'A service cannot be an add-on to itself.'];
        }
        $resolved[] = $parentId;
    }

    if (count($resolved) === 0) {
        return ['ok' => true, 'package_ids' => null];
    }

    return ['ok' => true, 'package_ids' => implode(',', array_values(array_unique($resolved)))];
}

function service_line_collect_rewrites(array $services, array $keyToId, array $indexToId = []): array
{
    $updates = [];
    foreach ($services as $index => $service) {
        $key = service_line_key($service);
        $dbId = null;
        if ($key !== null && isset($keyToId[$key])) {
            $dbId = $keyToId[$key];
        } elseif (isset($indexToId[$index])) {
            $dbId = $indexToId[$index];
        }

        if ($dbId === null) {
            return ['ok' => false, 'error' => 'Unable to resolve service line key for add-on rewrite.'];
        }

        $result = service_line_resolve_package_ids($service['package_ids'] ?? null, $keyToId, $dbId);
        if (empty($result['ok'])) {
            return $result;
        }

        $updates[] = [
            'id' => $dbId,
            'package_ids' => $result['package_ids'],
            'is_add_on' => $result['package_ids'] !== null ? 1 : 0,
        ];
    }

    return ['ok' => true, 'updates' => $updates];
}

function service_line_remap_copied_package_ids($raw, array $sourceIdToDestId): ?string
{
    $normalized = service_line_normalize_package_ids($raw);
    if ($normalized === null) {
        return null;
    }

    $resolved = [];
    foreach (explode(',', $normalized) as $token) {
        $token = trim($token);
        if ($token === '' || !isset($sourceIdToDestId[$token])) {
            continue;
        }
        $resolved[] = (string) $sourceIdToDestId[$token];
    }

    return count($resolved) ? implode(',', array_values(array_unique($resolved))) : null;
}

function service_line_collect_copied_rewrites(array $sourceServices, array $sourceIdToDestId): array
{
    $updates = [];
    foreach ($sourceServices as $service) {
        if (!isset($service['id']) || $service['id'] === '' || $service['id'] === null) {
            continue;
        }
        $sourceId = (string) $service['id'];
        if (!isset($sourceIdToDestId[$sourceId])) {
            continue;
        }

        $packageIds = service_line_remap_copied_package_ids($service['package_ids'] ?? null, $sourceIdToDestId);
        $updates[] = [
            'id' => $sourceIdToDestId[$sourceId],
            'package_ids' => $packageIds,
            'is_add_on' => $packageIds !== null ? 1 : 0,
        ];
    }

    return $updates;
}

function consulta_head_count(array $consulta)
{
    if (array_key_exists('head_count', $consulta) && $consulta['head_count'] !== '' && $consulta['head_count'] !== null) {
        return $consulta['head_count'];
    }
    if (array_key_exists('headcount', $consulta) && $consulta['headcount'] !== '' && $consulta['headcount'] !== null) {
        return $consulta['headcount'];
    }

    return null;
}
