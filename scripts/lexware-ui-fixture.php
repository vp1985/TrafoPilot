<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit(1); }
define('NOLOGIN',1); define('NOCSRFCHECK',1);
require '/var/www/html/main.inc.php';
require_once '/var/www/html/custom/hwoscore/core/modules/modHwosCore.class.php';
require_once '/var/www/html/custom/hwoslexware/core/modules/modHwosLexware.class.php';
require_once '/var/www/html/custom/hwoslexware/class/LexwareStore.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$s=new LexwareStore($db,(int) $conf->entity); $user->fetch(1); $user->getrights();
$module=new modHwosLexware($db); $core=new modHwosCore($db);
function fixtureGlobalActivationPresent($s): bool {
    return (bool) $s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX."const WHERE name IN ('MAIN_MODULE_HWOSCORE','MAIN_MODULE_HWOSLEXWARE') AND entity=0");
}
function restoreFixtureModules(array $active, $module, $core, $s): void {
    if (fixtureGlobalActivationPresent($s)) { throw new RuntimeException('UI_FIXTURE_GLOBAL_ACTIVATION'); }
    $failed=false;
    foreach ([[$module,'MAIN_MODULE_HWOSLEXWARE'],[$core,'MAIN_MODULE_HWOSCORE']] as [$object,$name]) {
        try {
            if ((($active[$name] ?? false) ? $object->init() : $object->remove())!==1) { $failed=true; }
        } catch (Throwable $e) { $failed=true; }
    }
    if ($failed) { throw new RuntimeException('UI_FIXTURE_RESTORE_FAILED'); }
}
if (($input['action'] ?? '')==='create') {
    if (fixtureGlobalActivationPresent($s)) { throw new RuntimeException('UI_FIXTURE_GLOBAL_ACTIVATION'); }
    $active=[];
    foreach ($s->rows('SELECT name,value FROM '.MAIN_DB_PREFIX."const WHERE name IN ('MAIN_MODULE_HWOSCORE','MAIN_MODULE_HWOSLEXWARE') AND entity=".$s->entity) as $r) { $active[$r['name']]=$r['value']==='1'; }
    if (!preg_match('/^lx-http-fixture-[a-f0-9]{16}$/',$input['login'] ?? '')) { throw new RuntimeException('INVALID_FIXTURE_LOGIN'); }
    $id=0; $fixture=null;
    try {
        if ($core->init()!==1 || $module->init()!==1) { throw new RuntimeException('UI_FIXTURE_ACTIVATION_FAILED'); }
        $fixture=new User($db); $fixture->login=$input['login']; $fixture->firstname='TrafoPilot'; $fixture->lastname='HTTP Fixture'; $fixture->admin=0; $fixture->entity=$s->entity;
        $id=$fixture->create($user,1);
        if ($id<=0 || !$fixture->setPassword($user,$input['password'],0,1,1)) { throw new RuntimeException('UI_FIXTURE_USER_FAILED'); }
        foreach (range(700201,700205) as $right) {
            $s->query('INSERT INTO '.MAIN_DB_PREFIX.'user_rights (entity,fk_user,fk_id) VALUES ('.$s->entity.','.(int) $id.','.$right.')');
        }
        $fixture->getrights();
        echo json_encode(['id'=>(int) $id,'active'=>$active,'permission_read'=>$fixture->hasRight('hwoslexware','read'),'module_enabled'=>isModEnabled('hwoslexware')],JSON_THROW_ON_ERROR);
    } catch (Throwable $failure) {
        $cleanupFailed=false;
        try { if ($id>0 && $fixture->delete($user)<=0) { $cleanupFailed=true; } }
        catch (Throwable $e) { $cleanupFailed=true; }
        try { restoreFixtureModules($active,$module,$core,$s); }
        catch (Throwable $e) { $cleanupFailed=true; }
        if ($cleanupFailed) { throw new RuntimeException('UI_FIXTURE_INCOMPLETE_RECOVERY'); }
        throw $failure;
    }
} elseif (($input['action'] ?? '')==='restrict') {
    $fixture=new User($db); $id=(int) ($input['id'] ?? 0);
    if ($id<=1 || $fixture->fetch($id)<=0 || $fixture->login!==$input['login'] || !str_starts_with($fixture->login,'lx-http-fixture-')) { throw new RuntimeException('REFUSING_NON_FIXTURE_USER'); }
    $minimum=empty($input['read']) ? 700201 : 700202;
    $s->query('DELETE FROM '.MAIN_DB_PREFIX.'user_rights WHERE entity='.$s->entity.' AND fk_user='.$id.' AND fk_id BETWEEN '.$minimum.' AND 700205');
    $checksums=[];
    foreach (['run','resource','mapping'] as $table) { $checksums[$table]=$s->rows('CHECKSUM TABLE '.$s->table($table))[0]['Checksum']; }
    $resource=$s->rows('SELECT rowid FROM '.$s->table('resource').' WHERE entity='.$s->entity.' ORDER BY rowid LIMIT 1');
    echo json_encode(['checksums'=>$checksums,'resource_id'=>(int) ($resource[0]['rowid'] ?? 0)],JSON_THROW_ON_ERROR);
} elseif (($input['action'] ?? '')==='cleanup') {
    $fixture=new User($db); $id=(int) ($input['id'] ?? 0);
    if ($id<=1 || $fixture->fetch($id)<=0 || $fixture->login!==$input['login'] || !str_starts_with($fixture->login,'lx-http-fixture-')) { throw new RuntimeException('REFUSING_NON_FIXTURE_USER'); }
    $failures=[];
    try {
        if ($fixture->delete($user)<=0) { $failures[]='UI_FIXTURE_CLEANUP_FAILED'; }
    } catch (Throwable $e) { $failures[]='UI_FIXTURE_CLEANUP_FAILED'; }
    finally {
        try { restoreFixtureModules($input['active'],$module,$core,$s); }
        catch (Throwable $e) { $failures[]='UI_FIXTURE_RESTORE_FAILED'; }
    }
    if ($failures) { throw new RuntimeException('UI_FIXTURE_INCOMPLETE_RECOVERY: '.implode('; ',$failures)); }
    echo '{"cleaned":true}';
}
