<?php
// Failure path: fixtures created before bookkeeping or assertion must be removed.
define('NOLOGIN', 1); define('NOCSRFCHECK', 1);
require '/var/www/html/main.inc.php';
$root = getenv('HWOS_MODULE_ROOT') ?: '/var/www/html/custom';
require_once $root.'/hwoslexware/class/LexwareSync.php';
require_once __DIR__.'/lexware_history_cleanup.php';
$s = new LexwareStore($db, random_int(1100000000,1900000000));
try {
    $s->query('INSERT INTO '.$s->table('run').' (entity,organization_id,mode,status,fk_user,context,checkpoint_json,stats_json,date_creation) VALUES ('.$s->entity.','.$s->q(LexwareClient::ORGANIZATION).",'full','pending',1,'history-test','{}','{}',NOW())");
    $untrackedRun = (int) $db->last_insert_id($s->table('run'));
    $conf->entity = $s->entity; $user->fetch(1); $user->getrights();
    $contact = $s->put($untrackedRun, 'contacts', 'history-'.bin2hex(random_bytes(10)), json_encode(['company'=>['name'=>'History cleanup fixture'],'roles'=>['customer'=>[]]]), 1);
    if ((new LexwareProjection($s))->project($contact, $user) !== 'new') { throw new RuntimeException('cleanup native fixture failed'); }
    $s->query('INSERT INTO '.MAIN_DB_PREFIX.'cronjob (entity,module_name,status,label,jobtype) VALUES ('.$s->entity.",'hwoslexware',0,'History cleanup fixture','method')");
    $s->query('INSERT INTO '.MAIN_DB_PREFIX.'const (entity,name,value,type) VALUES ('.$s->entity.",'MAIN_MODULE_HWOSLEXWARE','1','chaine')");
    // Deliberately detach the native/audit rows from their run/resources.
    foreach (['mapping','resource','run'] as $table) { $s->query('DELETE FROM '.$s->table($table).' WHERE entity='.$s->entity); }
    $residue=historyResidueCounts($s);
    foreach (['native_societe','history_payload_version','fixture_audit'] as $label) {
        if (($residue[$label] ?? 0)===0) { throw new RuntimeException('orphan invariant missed '.$label); }
    }
    throw new RuntimeException('injected assertion before fixture tracking');
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'injected assertion before fixture tracking') { throw $e; }
} finally { cleanupHistoryEntity($s); }
cleanupHistoryEntity($s);
if (array_sum(historyResidueCounts($s))) { throw new RuntimeException('post-cleanup residue invariant failed'); }
foreach (['societe','socpeople','const','cronjob','hwoscore_audit_event'] as $table) {
    if ($s->rows('SELECT * FROM '.MAIN_DB_PREFIX.$table.' WHERE entity='.$s->entity)) { throw new RuntimeException('untracked fixture cleanup failed'); }
}
try { cleanupHistoryEntity(new LexwareStore($db,1)); throw new RuntimeException('entity one cleanup accepted'); }
catch (RuntimeException $e) { if ($e->getMessage() !== 'HISTORY_CLEANUP_ENTITY_DENIED') { throw $e; } }
echo "PASS: history assertion failure cleanup and repeated cleanup\n";
