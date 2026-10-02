<?php

declare(strict_types=1);

define('NOLOGIN', 1);
define('NOCSRFCHECK', 1);
require '/var/www/html/main.inc.php';

$moduleRoot = getenv('HWOS_MODULE_ROOT') ?: '/tmp/hwos/modules';
$conf->file->dol_document_root = ['hwos_test'=>$moduleRoot] + $conf->file->dol_document_root;
$conf->file->dol_url_root['hwos_test'] = '/hwos-test';
require_once $moduleRoot.'/hwoscore/core/modules/modHwosCore.class.php';
if (($conf->file->dol_document_root['hwos_test'] ?? null) !== $moduleRoot) {
    throw new RuntimeException('staged module root is not registered before activation');
}

function activationQuery($db, string $sql): string
{
    $result = $db->query($sql);
    if (!$result) {
        throw new RuntimeException($db->lasterror());
    }
    $row = $db->fetch_row($result);
    return (string) ($row[0] ?? '');
}

function assertActivationSame(string $expected, string $actual, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($message.' (expected '.$expected.', got '.$actual.')');
    }
}

$module = new modHwosCore($db);
$entity = (int) $conf->entity;
$globalActivationPresent = activationQuery(
    $db,
    "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."const".
    " WHERE name = 'MAIN_MODULE_HWOSCORE' AND entity = 0"
) !== '0';
$originallyActive = activationQuery(
    $db,
    "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."const".
    " WHERE name = 'MAIN_MODULE_HWOSCORE' AND value = '1' AND entity = ".$entity
) !== '0';
$lifecycleAttempted = false;
$errorMessage = null;

try {
    if ($globalActivationPresent) {
        throw new RuntimeException('refusing activation test while a global MAIN_MODULE_HWOSCORE constant exists');
    }

    if (!method_exists($module, 'init')) {
        throw new RuntimeException('module has no activation method');
    }

    $lifecycleAttempted = true;
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        if ($module->init() !== 1) {
            throw new RuntimeException('module activation attempt '.$attempt.' failed: '.$module->error);
        }
    }

    assertActivationSame(
        'rowid|bigint(20)|NO|<NULL>|auto_increment|PRI,'.
        'entity|int(11)|NO|1||MUL,'.
        'event_uuid|varchar(36)|NO|<NULL>||,'.
        'event_type|varchar(128)|NO|<NULL>||,'.
        'object_type|varchar(64)|YES|NULL||,'.
        'object_id|varchar(128)|YES|NULL||,'.
        'fk_user_author|int(11)|YES|NULL||,'.
        'date_event|datetime|NO|<NULL>||,'.
        'payload_json|longtext|YES|NULL||,'.
        'idempotency_key|varchar(191)|YES|NULL||,'.
        'date_creation|datetime|NO|<NULL>||,'.
        'tms|timestamp|YES|current_timestamp()|on update current_timestamp()|',
        activationQuery(
            $db,
            "SELECT GROUP_CONCAT(CONCAT(column_name, '|', column_type, '|', is_nullable, '|',".
            " IF(column_default IS NULL, '<NULL>', column_default), '|', extra, '|', column_key)".
            " ORDER BY ordinal_position SEPARATOR ',')".
            " FROM information_schema.columns WHERE table_schema = DATABASE()".
            " AND table_name = '".MAIN_DB_PREFIX."hwoscore_audit_event'"
        ),
        'audit-event table column metadata does not match the migration'
    );

    assertActivationSame(
        'idx_hwoscore_audit_date|1|entity,date_event;'.
        'idx_hwoscore_audit_object|1|entity,object_type,object_id;'.
        'PRIMARY|0|rowid;'.
        'uk_hwoscore_audit_event_uuid|0|entity,event_uuid;'.
        'uk_hwoscore_audit_idempotency|0|entity,idempotency_key',
        activationQuery(
            $db,
            "SELECT GROUP_CONCAT(CONCAT(index_name, '|', non_unique, '|', index_columns)".
            " ORDER BY index_name SEPARATOR ';') FROM (".
            " SELECT index_name, non_unique,".
            " GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS index_columns".
            " FROM information_schema.statistics WHERE table_schema = DATABASE()".
            " AND table_name = '".MAIN_DB_PREFIX."hwoscore_audit_event'".
            " GROUP BY index_name, non_unique".
            ") AS audit_indexes"
        ),
        'audit-event indexes do not match the migration'
    );

    assertActivationSame(
        '1',
        activationQuery(
            $db,
            "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."const".
            " WHERE name = 'MAIN_MODULE_HWOSCORE' AND value = '1' AND entity = ".$entity
        ),
        'activation constant was not stored'
    );

    assertActivationSame(
        '0.1.0',
        activationQuery(
            $db,
            "SELECT value FROM ".MAIN_DB_PREFIX."const".
            " WHERE name = 'HWOSCORE_SCHEMA_VERSION' AND entity = ".$entity.
            " ORDER BY rowid DESC LIMIT 1"
        ),
        'schema version constant is missing'
    );

    assertActivationSame(
        '700101:hwoscore:read::w:1,700102:hwoscore:admin::w:0',
        activationQuery(
            $db,
            "SELECT GROUP_CONCAT(CONCAT(id, ':', module, ':', perms, ':', COALESCE(subperms, ''), ':', type, ':', bydefault)".
            " ORDER BY id SEPARATOR ',') FROM ".MAIN_DB_PREFIX."rights_def".
            " WHERE entity = ".$entity." AND id IN (700101, 700102)"
        ),
        'permission definitions do not match the Dolibarr rights class and actions'
    );
} catch (Throwable $exception) {
    $errorMessage = $exception->getMessage();
} finally {
    if ($lifecycleAttempted) {
        try {
            $restoreResult = $originallyActive ? $module->init() : $module->remove();
            if ($restoreResult !== 1) {
                throw new RuntimeException('module activation state restoration failed: '.$module->error);
            }
        } catch (Throwable $restoreException) {
            $restoreMessage = $restoreException->getMessage();
            $errorMessage = $errorMessage === null ? $restoreMessage : $errorMessage.'; '.$restoreMessage;
        }
    }
}

if ($errorMessage !== null) {
    fwrite(STDERR, "FAIL: {$errorMessage}\n");
    exit(1);
}

echo "PASS: hwoscore activation and migration\n";
