<?php
require '/var/www/html/main.inc.php';
if (!str_starts_with((string) $user->login,'lx-http-fixture-')) { accessforbidden(); }
header('Content-Type: application/json');
echo json_encode(['entity'=>(int) $conf->entity,'module_enabled'=>isModEnabled('hwoslexware'),'permission_read'=>(bool) $user->hasRight('hwoslexware','read')]);
