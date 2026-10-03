<?php
require __DIR__.'/lib/bootstrap.php';
$file = $lxStore->one(GETPOSTINT('version') === 1 ? 'file_version' : 'file', GETPOSTINT('id')); if (!$file) { accessforbidden(); }
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="lexware-'.(int) $file['rowid'].'.'.(['application/pdf'=>'pdf','application/xml'=>'xml','image/jpeg'=>'jpg','image/png'=>'png'][$file['mime_type']] ?? 'bin').'"');
header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store');
print $file['content_blob']; exit;
