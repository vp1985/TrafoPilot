<?php
require __DIR__.'/lib/bootstrap.php';
$version = $lxStore->one('payload_version', GETPOSTINT('id'));
if (!$version) { accessforbidden(); }
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="lexware-payload-'.(int) $version['rowid'].'.json"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
print $version['payload_json'];
exit;
