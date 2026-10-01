<?php

declare(strict_types=1);

define('NOLOGIN', 1);
define('NOCSRFCHECK', 1);
require '/var/www/html/main.inc.php';

$moduleRoot = getenv('HWOS_MODULE_ROOT') ?: '/var/www/html/custom';
require_once $moduleRoot.'/hwoscore/core/modules/modHwosCore.class.php';

function deactivationQuery($db, string $sql): string
{
    $result = $db->query($sql);
    if (!$result) {
        throw new RuntimeException($db->lasterror());
    }
    $row = $db->fetch_row($result);
    return (string) ($row[0] ?? '');
}

function assertDeactivationSame(string $expected, string $actual, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($message.' (expected '.$expected.', got '.$actual.')');
    }
}

$module = new modHwosCore($db);
$entity = (int) $conf->entity;
$globalActivationPresent = deactivationQuery(
    $db,
    "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."const".
    " WHERE name = 'MAIN_MODULE_HWOSCORE' AND entity = 0"
) !== '0';
$originallyActive = deactivationQuery(
    $db,
    "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."const".
    " WHERE name = 'MAIN_MODULE_HWOSCORE' AND value = '1' AND entity = ".$entity
) !== '0';
$uuid = sprintf('test-%s', bin2hex(random_bytes(12)));
$auditInserted = false;
$lifecycleAttempted = false;
$errorMessage = null;

try {
    if ($globalActivationPresent) {
        throw new RuntimeException('refusing deactivation test while a global MAIN_MODULE_HWOSCORE constant exists');
    }

    $lifecycleAttempted = true;
    if ($module->init() !== 1) {
        throw new RuntimeException('precondition activation failed: '.$module->error);
    }

    $sql = "INSERT INTO ".MAIN_DB_PREFIX."hwoscore_audit_event".
        " (entity, event_uuid, event_type, date_event, date_creation) VALUES".
        " (".$entity.", '".$db->escape($uuid)."', 'test.deactivation', NOW(), NOW())";
    if (!$db->query($sql)) {
        throw new RuntimeException($db->lasterror());
    }
    $auditInserted = true;

    if ($module->remove() !== 1) {
        throw new RuntimeException('module deactivation failed: '.$module->error);
    }

    assertDeactivationSame(
        '0',
        deactivationQuery(
            $db,
            "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."const".
            " WHERE name = 'MAIN_MODULE_HWOSCORE' AND entity = ".$entity
        ),
        'activation constant remains after deactivation'
    );

    assertDeactivationSame(
        '0',
        deactivationQuery(
            $db,
            "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."rights_def".
            " WHERE entity = ".$entity." AND id IN (700101, 700102)"
        ),
        'permission definitions remain after deactivation'
    );

    assertDeactivationSame(
        '1',
        deactivationQuery(
            $db,
            "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."hwoscore_audit_event".
            " WHERE entity = ".$entity." AND event_uuid = '".$db->escape($uuid)."'"
        ),
        'audit data was deleted during deactivation'
    );
} catch (Throwable $exception) {
    $errorMessage = $exception->getMessage();
} finally {
    if ($auditInserted && !$db->query(
        "DELETE FROM ".MAIN_DB_PREFIX."hwoscore_audit_event".
        " WHERE entity = ".$entity." AND event_uuid = '".$db->escape($uuid)."'"
    )) {
        $cleanupMessage = 'audit fixture cleanup failed: '.$db->lasterror();
        $errorMessage = $errorMessage === null ? $cleanupMessage : $errorMessage.'; '.$cleanupMessage;
    }

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

echo "PASS: hwoscore deactivation preserves data\n";
