<?php
// Exact fixture ownership, never general old/pending historical data.
define('NOLOGIN', 1); define('NOCSRFCHECK', 1);
require '/var/www/html/main.inc.php';
require_once getenv('HWOS_MODULE_ROOT').'/hwoslexware/class/LexwareStore.php';
$s = new LexwareStore($db, 1);
require_once __DIR__.'/lexware_history_cleanup.php';
$counts=historyResidueCounts($s);
echo 'History residue: '.json_encode($counts)."\n";
if (array_sum($counts)) { throw new RuntimeException('HISTORY_SUITE_RESIDUE'); }
