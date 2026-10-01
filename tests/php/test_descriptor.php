<?php

declare(strict_types=1);

define('NOLOGIN', 1);
define('NOCSRFCHECK', 1);
require '/var/www/html/main.inc.php';

$moduleRoot = getenv('HWOS_MODULE_ROOT') ?: '/tmp/hwos/modules';
$descriptor = $moduleRoot.'/hwoscore/core/modules/modHwosCore.class.php';

if (!is_file($descriptor)) {
    fwrite(STDERR, "FAIL: hwoscore descriptor is missing\n");
    exit(1);
}

require_once $descriptor;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, sprintf("FAIL: %s (expected %s, got %s)\n", $message, var_export($expected, true), var_export($actual, true)));
        exit(1);
    }
}

$module = new modHwosCore($db);

assertSameValue(700100, $module->numero, 'stable module id');
assertSameValue('HwosCore', $module->name, 'module name');
assertSameValue('hwoscore', $module->rights_class, 'rights class');
assertSameValue('other', $module->family, 'module family');
assertSameValue('0.1.0', $module->version, 'semantic version');
assertSameValue('MAIN_MODULE_HWOSCORE', $module->const_name, 'activation constant');
assertSameValue(array(8, 1), $module->phpmin, 'minimum PHP version');
assertSameValue(array(24, 0), $module->need_dolibarr_version, 'minimum Dolibarr version');
assertSameValue(array('hwoscore@hwoscore'), $module->langfiles, 'language file declaration');
assertSameValue(2, count($module->rights), 'permission count');
assertSameValue(700101, $module->rights[0][0], 'read permission id');
assertSameValue('read', $module->rights[0][4], 'read permission key');
assertSameValue(700102, $module->rights[1][0], 'admin permission id');
assertSameValue('admin', $module->rights[1][4], 'admin permission key');

echo "PASS: hwoscore descriptor contract\n";
