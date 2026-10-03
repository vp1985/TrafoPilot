<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit(1); }
define('NOLOGIN',1); define('NOCSRFCHECK',1);
require '/var/www/html/main.inc.php';
$root = getenv('HWOS_MODULE_ROOT') ?: '/var/www/html/custom';
$conf->file->dol_document_root = ['hwos_review'=>$root] + $conf->file->dol_document_root;
$conf->modules_parts['triggers']['hwoslexware'] = '/hwoslexware/core/triggers/';
$conf->file->dol_url_root['hwos_review'] = '/hwos-review-tdd';
require_once $root.'/hwoscore/core/modules/modHwosCore.class.php';
require_once $root.'/hwoslexware/core/modules/modHwosLexware.class.php';
require_once $root.'/hwoslexware/class/LexwareStore.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$s=new LexwareStore($db,(int) $conf->entity); $user->fetch(1); $user->getrights();
$module=new modHwosLexware($db); $core=new modHwosCore($db);
function fixtureGlobalActivationPresent($s): bool {
    return (bool) $s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX."const WHERE name IN ('MAIN_MODULE_HWOSCORE','MAIN_MODULE_HWOSLEXWARE') AND entity=0");
}
function restoreFixtureModules(array $active, $module, $core, $s): void {
    foreach (['MAIN_MODULE_HWOSCORE','MAIN_MODULE_HWOSLEXWARE'] as $name) {
        if (!array_key_exists($name,$active) || !is_bool($active[$name])) { throw new RuntimeException('UI_FIXTURE_INCOMPLETE_RECOVERY: UNVERIFIED_BASELINE'); }
    }
    if (fixtureGlobalActivationPresent($s)) { throw new RuntimeException('UI_FIXTURE_GLOBAL_ACTIVATION'); }
    $failed=false;
    foreach ([[$module,'MAIN_MODULE_HWOSLEXWARE'],[$core,'MAIN_MODULE_HWOSCORE']] as [$object,$name]) {
        try {
            if (($active[$name] ? $object->init() : $object->remove())!==1) { $failed=true; }
        } catch (Throwable $e) { $failed=true; }
    }
    if ($failed) { throw new RuntimeException('UI_FIXTURE_RESTORE_FAILED'); }
}
function fixtureFault(string $phase): void {
    $fault = getenv('HWOS_FIXTURE_FAULT');
    if ($fault === $phase || ($fault === 'cleanup-all' && str_starts_with($phase, 'cleanup-'))) { throw new RuntimeException('UI_FIXTURE_INJECTED_'.$phase); }
}
function fixtureRecovery(array $metadata): void {
    $path = getenv('HWOS_FIXTURE_RECOVERY');
    if (!$path) { return; }
    $temporary = $path.'.new-'.bin2hex(random_bytes(8));
    fixtureFault('recovery-fopen');
    $handle = fopen($temporary, 'x');
    if (!$handle) { throw new RuntimeException('UI_FIXTURE_RECOVERY_WRITE_FAILED'); }
    try {
        chmod($temporary, 0600);
        fixtureFault('recovery-write');
        $body = json_encode($metadata, JSON_THROW_ON_ERROR);
        if (fwrite($handle, $body) !== strlen($body) || !fflush($handle)) { throw new RuntimeException('UI_FIXTURE_RECOVERY_WRITE_FAILED'); }
        fixtureFault('recovery-fsync');
        if (!fsync($handle)) { throw new RuntimeException('UI_FIXTURE_RECOVERY_WRITE_FAILED'); }
        fixtureFault('recovery-rename');
        fixtureFault('recovery-publication');
        if (!rename($temporary, $path)) { throw new RuntimeException('UI_FIXTURE_RECOVERY_WRITE_FAILED'); }
    } finally { fclose($handle); if (is_file($temporary)) { unlink($temporary); } }
}
function fixtureRestoreConstants($s, string $name, array $rows): void {
    $s->begin();
    try {
        $s->query('DELETE FROM '.MAIN_DB_PREFIX.'const WHERE name='.$s->q($name).' AND entity='.$s->entity);
        foreach ($rows as $row) {
            $row = array_filter($row, fn($k)=>is_string($k), ARRAY_FILTER_USE_KEY);
            $s->query('INSERT INTO '.MAIN_DB_PREFIX.'const ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_map(fn($v)=>$v===null ? 'NULL' : $s->q((string) $v),$row)).')');
        }
        $s->commit();
    } catch (Throwable $e) { $s->db->rollback(); throw $e; }
}
function fixtureVerifiedBaseline(array $metadata, string $login, int $entity): bool {
    if (($metadata['baseline_version'] ?? null)!==1 || ($metadata['baseline_entity'] ?? null)!==$entity || ($metadata['login'] ?? null)!==$login) { return false; }
    foreach (['active','active_rows','prior_csrf'] as $key) { if (!isset($metadata[$key]) || !is_array($metadata[$key])) { return false; } }
    foreach (['MAIN_MODULE_HWOSCORE','MAIN_MODULE_HWOSLEXWARE'] as $name) {
        if (!array_key_exists($name,$metadata['active']) || !is_bool($metadata['active'][$name])) { return false; }
        $rows=array_values(array_filter($metadata['active_rows'],fn($r)=>is_array($r) && ($r['name'] ?? null)===$name));
        if (count($rows)>1 || $metadata['active'][$name]!==(!empty($rows) && $rows[0]['value']==='1')) { return false; }
    }
    foreach (['active_rows'=>['MAIN_MODULE_HWOSCORE','MAIN_MODULE_HWOSLEXWARE'],'prior_csrf'=>['MAIN_SECURITY_CSRF_WITH_TOKEN']] as $key=>$names) {
        foreach ($metadata[$key] as $row) {
            if (!is_array($row) || !isset($row['name'],$row['value'],$row['entity']) || !in_array($row['name'],$names,true) || (int)$row['entity']!==$entity) { return false; }
        }
    }
    return true;
}
$verifiedBaseline=false;
$recoveryPath = getenv('HWOS_FIXTURE_RECOVERY');
if (in_array($input['action'] ?? '', ['populate','cleanup'], true) && $recoveryPath && is_file($recoveryPath)) {
    $recovery = json_decode(file_get_contents($recoveryPath), true, 512, JSON_THROW_ON_ERROR);
    if (($recovery['login'] ?? '') !== ($input['login'] ?? '')) { throw new RuntimeException('REFUSING_NON_FIXTURE_RECOVERY'); }
    if (!fixtureVerifiedBaseline($recovery,$input['login'] ?? '',$s->entity)) { throw new RuntimeException('UI_FIXTURE_INCOMPLETE_RECOVERY: UNVERIFIED_BASELINE'); }
    // The private journal owns recovery state; caller output must not override it.
    $input = array_replace($input, $recovery, ['action'=>$input['action']]);
    $verifiedBaseline=true;
}
if (($input['action'] ?? '')==='create') {
    if (fixtureGlobalActivationPresent($s)) { throw new RuntimeException('UI_FIXTURE_GLOBAL_ACTIVATION'); }
    $active=['MAIN_MODULE_HWOSCORE'=>false,'MAIN_MODULE_HWOSLEXWARE'=>false];
    foreach ($s->rows('SELECT name,value FROM '.MAIN_DB_PREFIX."const WHERE name IN ('MAIN_MODULE_HWOSCORE','MAIN_MODULE_HWOSLEXWARE') AND entity=".$s->entity) as $r) { $active[$r['name']]=$r['value']==='1'; }
    if (!preg_match('/^lx-http-fixture-[a-f0-9]{16}$/',$input['login'] ?? '')) { throw new RuntimeException('INVALID_FIXTURE_LOGIN'); }
    $input['baseline_version']=1; $input['baseline_entity']=$s->entity;
    $input['active']=$active;
    $input['active_rows']=$s->rows('SELECT * FROM '.MAIN_DB_PREFIX."const WHERE name IN ('MAIN_MODULE_HWOSCORE','MAIN_MODULE_HWOSLEXWARE') AND entity=".$s->entity);
    $input['prior_csrf']=$s->rows('SELECT * FROM '.MAIN_DB_PREFIX."const WHERE name='MAIN_SECURITY_CSRF_WITH_TOKEN' AND entity=".$s->entity);
    // Persist recovery state before any module, user, history or security mutation.
    $metadata=$input; unset($metadata['password'],$metadata['action']); fixtureRecovery($metadata);
    $id=0; $fixture=null;
    try {
        if ($core->init()!==1 || $module->init()!==1) { throw new RuntimeException('UI_FIXTURE_ACTIVATION_FAILED'); }
        $fixture=new User($db); $fixture->login=$input['login']; $fixture->firstname='TrafoPilot'; $fixture->lastname='HTTP Fixture'; $fixture->admin=0; $fixture->entity=$s->entity;
        $id=$fixture->create($user,1);
        $metadata['id']=(int) $id; fixtureRecovery($metadata);
        if ($id<=0 || !$fixture->setPassword($user,$input['password'],0,1,1)) { throw new RuntimeException('UI_FIXTURE_USER_FAILED'); }
        foreach (range(700201,700205) as $right) {
            $s->query('INSERT INTO '.MAIN_DB_PREFIX.'user_rights (entity,fk_user,fk_id) VALUES ('.$s->entity.','.(int) $id.','.$right.')');
        }
        $fixture->getrights();
        echo json_encode($metadata+['permission_read'=>$fixture->hasRight('hwoslexware','read'),'module_enabled'=>isModEnabled('hwoslexware')],JSON_THROW_ON_ERROR);
    } catch (Throwable $failure) {
        $cleanupFailed=false;
        try { if ($id>0 && $fixture->delete($user)<=0) { $cleanupFailed=true; } }
        catch (Throwable $e) { $cleanupFailed=true; }
        try { restoreFixtureModules($active,$module,$core,$s); }
        catch (Throwable $e) { $cleanupFailed=true; }
        if ($cleanupFailed) { throw new RuntimeException('UI_FIXTURE_INCOMPLETE_RECOVERY'); }
        throw $failure;
    }
 } elseif (($input['action'] ?? '')==='populate') {
    require_once $root.'/hwoslexware/class/LexwareSync.php';
    if (!preg_match('/^lx-http-fixture-[a-f0-9]{16}$/', $input['login'] ?? '')) { throw new RuntimeException('INVALID_FIXTURE_LOGIN'); }
    if (!array_key_exists('prior_csrf', $input)) { $input['prior_csrf']=$s->rows('SELECT * FROM '.MAIN_DB_PREFIX."const WHERE name='MAIN_SECURITY_CSRF_WITH_TOKEN' AND entity=".$s->entity); }
    fixtureRecovery($input);
    require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
    if (dolibarr_set_const($db, 'MAIN_SECURITY_CSRF_WITH_TOKEN', '0', 'chaine', 0, '', $s->entity) <= 0) { throw new RuntimeException('CSRF_FIXTURE_SETTING_FAILED'); }
    fixtureFault('populate-csrf');
    $s->query('INSERT INTO '.$s->table('run').' (entity,organization_id,mode,status,fk_user,context,checkpoint_json,stats_json,date_creation) VALUES ('.$s->entity.','.$s->q(LexwareClient::ORGANIZATION).",'refresh','complete',1,"."'ui-history',".$s->q(json_encode(['fixture_login'=>$input['login']],JSON_THROW_ON_ERROR)).",'{}',NOW())");
    $run = (int) $db->last_insert_id($s->table('run'));
    fixtureFault('populate-before-run-journal');
    $input['fixture_run']=$run; fixtureRecovery($input); fixtureFault('populate-run');
    $remote = $input['login'];
    $payload = ['articleNumber'=>$remote,'title'=>'Synthetic historical payload','archived'=>true,'version'=>1,'voucherStatus'=>'open','price'=>['netPrice'=>10,'taxRate'=>19]];
    $r = $s->put($run, 'articles', $remote, json_encode($payload), 1);
    $projector = new LexwareProjection($s);
    if ($projector->project($r, $user) !== 'new') { throw new RuntimeException('POPULATED_FIXTURE_PROJECTION_FAILED'); }
    $m = $s->mapping((int) $r['rowid']);
    fixtureFault('populate-product');
    $s->query('UPDATE '.MAIN_DB_PREFIX.'product SET description=\'historical local state\' WHERE rowid='.(int) $m['object_id']);
    $projector->project($r, $user);
    $projector->resolve((int) $r['rowid'], 'Dolibarr', $r['checksum'], $user, (int) $s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) $r['rowid']." AND issue_type='conflict' AND status='open'")[0]['rowid']);
    $payload['version'] = 2; $payload['title'] = 'Synthetic current payload';
    $r = $s->put($run, 'articles', $remote, json_encode($payload), 1);
    $projector->project($r, $user);
    $sync = new LexwareSync($s, new LexwareClient('fixture', fn()=>throw new RuntimeException('NO_LIVE_REQUEST')));
    $file = new ReflectionMethod($sync, 'file');
    foreach (['%PDF-historical-file','%PDF-current-file'] as $body) { $file->invoke($sync, ['owner'=>$r['rowid'],'id'=>'historical-file','accept'=>'application/pdf'], ['body'=>$body,'headers'=>['content-type'=>'application/pdf']], $run, 1); }
    $s->observeRelation((int) $r['rowid'], 'retained-related', 'dunnings', $run, 1);
    $rel = $s->rows('SELECT rowid FROM '.$s->table('relation').' WHERE entity='.$s->entity.' AND fk_resource='.(int) $r['rowid'])[0];
    $s->stateEvent($r, $run, 'removed', 1, (int) $rel['rowid']);
    $s->query('UPDATE '.$s->table('resource').' SET missing=1 WHERE rowid='.(int) $r['rowid']);
    $s->stateEvent($r, $run, 'removed', 1);
    $priorCsrf=$input['prior_csrf'];
    fixtureFault('populate-history');
    echo json_encode(['resource_id'=>(int) $r['rowid'],'native_id'=>(int) $m['object_id'],'fixture_run'=>$run,'historical_payload_id'=>(int) $s->rows('SELECT rowid FROM '.$s->table('payload_version').' WHERE entity='.$s->entity.' AND fk_resource='.(int) $r['rowid'].' ORDER BY rowid')[0]['rowid'],'historical_payload'=>json_encode(['articleNumber'=>$remote,'title'=>'Synthetic historical payload','archived'=>true,'version'=>1,'voucherStatus'=>'open','price'=>['netPrice'=>10,'taxRate'=>19]]),'source_checksum'=>$r['checksum'],'historical_file_id'=>(int) $s->rows('SELECT rowid FROM '.$s->table('file_version').' WHERE entity='.$s->entity.' AND fk_resource='.(int) $r['rowid'].' ORDER BY rowid')[0]['rowid'],'prior_csrf'=>$priorCsrf], JSON_THROW_ON_ERROR);
} elseif (in_array($input['action'] ?? '', ['case-next','case-state'], true)) {
    require_once $root.'/hwoslexware/class/LexwareProjection.php';
    $resourceId = (int) ($input['resource_id'] ?? 0);
    $r = $s->one('resource', $resourceId);
    if (!$r || !preg_match('/^lx-http-fixture-[a-f0-9]{16}$/', $input['login'] ?? '') || $r['remote_id'] !== $input['login']) { throw new RuntimeException('REFUSING_NON_FIXTURE_CASE'); }
    $projector = new LexwareProjection($s); $m = $s->mapping($resourceId);
    if ($input['action'] === 'case-next') {
        $s->query('UPDATE '.MAIN_DB_PREFIX.'product SET description='.$s->q('Synthetic later local state '.bin2hex(random_bytes(8))).' WHERE entity='.$s->entity.' AND rowid='.(int) $m['object_id']);
        // Synthetic explicit case setup also works after a Dolibarr acceptance,
        // whose approved source-suppression behavior remains unchanged.
        $s->issue($resourceId, 'conflict', ['reason'=>'SYNTHETIC_CASE','native'=>$projector->snapshot('product',(int) $m['object_id'])], 1);
        $s->status($resourceId, 'conflict');
    }
    echo json_encode([$projector->snapshot('product',(int) $m['object_id']), $s->mapping($resourceId), $s->one('resource',$resourceId), $s->rows('SELECT * FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.$resourceId.' ORDER BY rowid'), $s->rows('SELECT * FROM '.$s->table('resolution').' WHERE entity='.$s->entity.' AND fk_resource='.$resourceId.' ORDER BY rowid'), $s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'hwoscore_audit_event WHERE entity='.$s->entity.' AND object_id='.$s->q($r['remote_id']).' ORDER BY rowid')], JSON_THROW_ON_ERROR);
} elseif (($input['action'] ?? '')==='state') {
    $checksums = [];
    foreach (['run','resource','mapping','issue','file','relation','payload_version','file_version','state_event','resolution'] as $table) { $checksums[$table] = $s->rows('CHECKSUM TABLE '.$s->table($table))[0]['Checksum']; }
    echo json_encode($checksums, JSON_THROW_ON_ERROR);
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
    if (!preg_match('/^lx-http-fixture-[a-f0-9]{16}$/', $input['login'] ?? '')) { throw new RuntimeException('INVALID_FIXTURE_LOGIN'); }
    if (!$verifiedBaseline && !fixtureVerifiedBaseline($input,$input['login'],$s->entity)) {
        echo json_encode(['cleaned'=>false,'attempted'=>[],'failures'=>['baseline:UNVERIFIED_BASELINE']],JSON_THROW_ON_ERROR);
        throw new RuntimeException('UI_FIXTURE_INCOMPLETE_RECOVERY: UNVERIFIED_BASELINE');
    }
    $failures=[]; $attempted=[];
    $phase = function (string $name, callable $operation) use (&$failures,&$attempted): void {
        $attempted[]=$name;
        try { fixtureFault('cleanup-'.$name.'-before'); $operation(); fixtureFault('cleanup-'.$name); }
        catch (Throwable $e) { $failures[]=$name.':'.($name==='user' ? 'UI_FIXTURE_CLEANUP_FAILED:' : '').$e->getMessage(); }
    };
    $phase('csrf', function () use ($s,$input) {
        if (array_key_exists('prior_csrf',$input)) { fixtureRestoreConstants($s,'MAIN_SECURITY_CSRF_WITH_TOKEN',$input['prior_csrf']); }
    });
    $phase('history', function () use ($s,$input) {
        $runId=(int) ($input['fixture_run'] ?? 0);
        if (!$runId) {
            // The login identity is journaled before INSERT, so an interrupted
            // post-insert journal update cannot orphan the newly allocated run.
            $runs=$s->rows('SELECT rowid FROM '.$s->table('run').' WHERE entity='.$s->entity." AND context='ui-history' AND checkpoint_json=".$s->q(json_encode(['fixture_login'=>$input['login']],JSON_THROW_ON_ERROR)));
            if (count($runs)>1) { throw new RuntimeException('AMBIGUOUS_FIXTURE_RUN'); }
            $runId=(int) ($runs[0]['rowid'] ?? 0);
        }
        if (!$runId) { return; }
        $owned=$s->one('run',$runId);
        if (!$owned) { return; } // Recovery can be repeated after partial cleanup.
        if ($owned['context']!=='ui-history') { throw new RuntimeException('REFUSING_NON_FIXTURE_RUN'); }
        $resources=$s->rows('SELECT * FROM '.$s->table('resource').' WHERE entity='.$s->entity.' AND fk_run='.$runId);
        foreach ($resources as $resource) {
            if ($resource['remote_id']!==$input['login']) { throw new RuntimeException('REFUSING_NON_FIXTURE_RESOURCE'); }
            $rid=(int) $resource['rowid']; $m=$s->mapping($rid);
            if ($m && $m['object_type']==='product') {
                foreach (['product_price'=>'fk_product','product_extrafields'=>'fk_object','product'=>'rowid'] as $table=>$key) { $s->query('DELETE FROM '.MAIN_DB_PREFIX.$table.' WHERE '.$key.'='.(int) $m['object_id']); fixtureFault('cleanup-history'); }
            }
            foreach (['resolution','state_event','payload_version','file_version','mapping','issue','file','relation'] as $table) { $s->query('DELETE FROM '.$s->table($table).' WHERE entity='.$s->entity.' AND fk_resource='.$rid); }
            $s->query('DELETE FROM '.$s->table('resource').' WHERE entity='.$s->entity.' AND rowid='.$rid);
        }
        $s->query('DELETE FROM '.MAIN_DB_PREFIX.'hwoscore_audit_event WHERE entity='.$s->entity.' AND (object_id='.$s->q($input['login']).' OR object_id='.$s->q((string) $runId).')');
        $s->query('DELETE FROM '.$s->table('run').' WHERE entity='.$s->entity.' AND rowid='.$runId);
    });
    $phase('user', function () use ($db,$s,$input,$user) {
        $fixture=new User($db); $id=(int) ($input['id'] ?? 0);
        if ($id<=1) {
            $id=(int) ($s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE entity='.$s->entity.' AND login='.$s->q($input['login']))[0]['rowid'] ?? 0);
        }
        if (!$id || $fixture->fetch($id)<=0) { return; }
        if ($id<=1 || $fixture->login!==$input['login'] || (int) $fixture->entity!==$s->entity) { throw new RuntimeException('REFUSING_NON_FIXTURE_USER'); }
        if ($fixture->delete($user)<=0) { throw new RuntimeException('UI_FIXTURE_CLEANUP_FAILED'); }
    });
    foreach ([['lexware',$module,'MAIN_MODULE_HWOSLEXWARE'],['core',$core,'MAIN_MODULE_HWOSCORE']] as [$name,$object,$constant]) {
        $phase($name, function () use ($s,$input,$object,$constant,$name) {
            if (fixtureGlobalActivationPresent($s)) { throw new RuntimeException('UI_FIXTURE_GLOBAL_ACTIVATION'); }
            $errors=[];
            try {
                if (($input['active'][$constant] ? $object->init() : $object->remove())!==1) { throw new RuntimeException('UI_FIXTURE_RESTORE_FAILED'); }
                fixtureFault('cleanup-'.$name.'-native');
            } catch (Throwable $e) { $errors[]=$e->getMessage(); }
            try {
                if (isset($input['active_rows'])) { fixtureRestoreConstants($s,$constant,array_values(array_filter($input['active_rows'],fn($r)=>$r['name']===$constant))); }
            } catch (Throwable $e) { $errors[]=$e->getMessage(); }
            if ($errors) { throw new RuntimeException(implode(';',$errors)); }
        });
    }
    // Retain the journal for repeat recovery until the caller removes its private
    // helper directory, which is independently attempted even on PHP failure.
    $phase('helper', function () use ($input) { fixtureRecovery($input); });
    echo json_encode(['cleaned'=>!$failures,'attempted'=>$attempted,'failures'=>$failures],JSON_THROW_ON_ERROR);
    if ($failures) { throw new RuntimeException('UI_FIXTURE_INCOMPLETE_RECOVERY: '.implode('; ',$failures)); }
}
