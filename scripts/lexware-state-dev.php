<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit(1); }
define('NOLOGIN',1); define('NOCSRFCHECK',1);
require '/var/www/html/main.inc.php';
require_once '/var/www/html/custom/hwoslexware/class/LexwareStore.php';
$s=new LexwareStore($db,(int) $conf->entity);
$duplicates=$s->rows('SELECT organization_id,resource_type,remote_id,COUNT(*) AS n FROM '.$s->table('resource').' WHERE entity='.$s->entity.' GROUP BY organization_id,resource_type,remote_id HAVING COUNT(*)>1');
if ($duplicates) { throw new RuntimeException('MIRROR_DUPLICATES_FOUND'); }
$resources=$s->rows('SELECT organization_id,payload_json,checksum FROM '.$s->table('resource').' WHERE entity='.$s->entity);
foreach ($resources as $r) {
    if ($r['organization_id']!==LexwareClient::ORGANIZATION || LexwareResources::checksum($r['payload_json'])!==$r['checksum']) { throw new RuntimeException('MIRROR_INTEGRITY_FAILED'); }
}
if (isModEnabled('hwoslexware')) { throw new RuntimeException('BASELINE_MODULE_STATE_NOT_RESTORED'); }
if ($s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX."user WHERE login LIKE 'lx-http-fixture-%'")) { throw new RuntimeException('HTTP_FIXTURE_USER_NOT_REMOVED'); }
echo 'PASS: '.count($resources).' protected mirror records; all payload checksums valid; no duplicate identities; new module inactive; temporary HTTP users removed'."\n";
