<?php
declare(strict_types=1);
define('NOLOGIN',1); define('NOCSRFCHECK',1);
require '/var/www/html/main.inc.php';
$root=getenv('HWOS_MODULE_ROOT');
require_once $root.'/hwoslexware/class/LexwareStore.php';
function recoveryCheck($ok,$message) { if (!$ok) { throw new RuntimeException($message); } }
$dir=sys_get_temp_dir().'/hwos-recovery-'.bin2hex(random_bytes(8)); mkdir($dir,0700);
$helper=file_get_contents(getenv('HWOS_SCRIPT_ROOT').'/lexware-ui-fixture.php');
$helper=str_replace("require '/var/www/html/main.inc.php';", "require '/var/www/html/main.inc.php';\n\$conf->entity=(int) getenv('HWOS_RECOVERY_TEST_ENTITY');",$helper);
file_put_contents($dir.'/fixture.php',$helper); chmod($dir.'/fixture.php',0600);
$entity=random_int(1100000000,1900000000); $s=new LexwareStore($db,$entity);
function recoveryLifecycle($object,string $method) {
    $GLOBALS['recoveryLifecycleCalls']++;
    return $object->$method();
}
function recoveryQuery($store,string $sql) {
    if (preg_match('/^\s*(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|REPLACE)\b/i',$sql)) { $GLOBALS['recoveryWrites']++; }
    return $store->query($sql);
}
function invokeRecovery(array $input,string $fault=''): array {
    global $dir,$entity,$db,$conf,$user;
    $conf->entity=$entity;
    putenv('HWOS_FIXTURE_RECOVERY='.$dir.'/recovery.json'); putenv('HWOS_FIXTURE_FAULT='.$fault);
    $source=file_get_contents($dir.'/fixture.php');
    $source=preg_replace('/^require \'\/var\/www\/html\/main.inc.php\';$/m','',$source);
    $source=preg_replace('/^\$conf->entity=.*;$/m','',$source);
    $source=preg_replace('/^\$input=json_decode.*;$/m','',$source);
    $source=str_replace('$s->query(', 'recoveryQuery($s,', $source);
    $source=preg_replace('/\$(core|module|object)->(init|remove)\(\)/', 'recoveryLifecycle($$1,"$2")', $source);
    $GLOBALS['recoveryLifecycleCalls']=0; $GLOBALS['recoveryWrites']=0;
    $source=str_replace(["<?php","declare(strict_types=1);","define('NOLOGIN',1); define('NOCSRFCHECK',1);"],'',$source);
    static $sequence=0; $sequence++;
    preg_match_all('/function ([a-zA-Z_]+)\(/',$source,$names);
    foreach ($names[1] as $name) { $source=preg_replace('/\b'.$name.'\b/',$name.'_'.$sequence,$source); }
    ob_start();
    try { eval($source); return [0,ob_get_clean(),'']; }
    catch (Throwable $e) { return [1,ob_get_clean(),$e->getMessage()]; }
    finally { putenv('HWOS_FIXTURE_FAULT'); }
}
$phases=['csrf','history','user','lexware','core','helper'];
try {
    foreach (['recovery-fopen','recovery-write','recovery-fsync','recovery-rename','recovery-publication','no-baseline','invalid-baseline'] as $fault) {
        $login='lx-http-fixture-'.bin2hex(random_bytes(8));
        @unlink($dir.'/recovery.json');
        $s->query('DELETE FROM '.MAIN_DB_PREFIX.'const WHERE entity='.$entity);
        foreach (['MAIN_MODULE_HWOSCORE','MAIN_MODULE_HWOSLEXWARE','MAIN_SECURITY_CSRF_WITH_TOKEN'] as $name) {
            $s->query('INSERT INTO '.MAIN_DB_PREFIX.'const (name,value,type,visible,entity) VALUES ('.$s->q($name).",'1','chaine',0,".$entity.')');
        }
        $prior=$s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'const WHERE entity='.$entity.' ORDER BY name');
        if (str_starts_with($fault,'recovery-')) {
            [$code,$out,$err]=invokeRecovery(['action'=>'create','login'=>$login,'password'=>'synthetic-only'],$fault);
            recoveryCheck($code!==0 && str_contains($err,'UI_FIXTURE_INJECTED_'.$fault),'initial publication fault reached '.$fault);
            recoveryCheck($GLOBALS['recoveryLifecycleCalls']===0 && $GLOBALS['recoveryWrites']===0,'publication failure performs zero lifecycle calls or database writes');
            recoveryCheck(!is_file($dir.'/recovery.json'),'failed initial publication leaves no journal');
        }
        $input=['action'=>'cleanup','login'=>$login];
        if ($fault==='invalid-baseline') { $input+=['active'=>[], 'active_rows'=>[], 'prior_csrf'=>[]]; }
        [$code,$out,$err]=invokeRecovery($input);
        recoveryCheck($code!==0 && str_contains($err,'UI_FIXTURE_INCOMPLETE_RECOVERY'),'unknown baseline reports incomplete recovery');
        recoveryCheck($GLOBALS['recoveryLifecycleCalls']===0 && $GLOBALS['recoveryWrites']===0,'unknown baseline performs zero lifecycle calls or database writes');
        $report=json_decode($out,true,512,JSON_THROW_ON_ERROR);
        recoveryCheck($report['attempted']===[], 'unverified cleanup performs zero mutation phases or lifecycle calls');
        recoveryCheck($s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'const WHERE entity='.$entity.' ORDER BY name')===$prior,'active modules and CSRF remain exactly unchanged');
        recoveryCheck(!$s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE login='.$s->q($login)),'no fixture user created before publication');
        recoveryCheck(!$s->rows('SELECT rowid FROM '.$s->table('run').' WHERE entity='.$entity),'no fixture run created before publication');
        echo 'PASS: fail-closed initial recovery '.$fault."\n";
    }
    foreach (['populate-csrf','populate-before-run-journal','populate-run','populate-product','populate-history', ...array_map(fn($p)=>'cleanup-'.$p,$phases), ...array_map(fn($p)=>'cleanup-'.$p.'-before',$phases), 'cleanup-lexware-native','cleanup-core-native','cleanup-all'] as $n=>$fault) {
        $login='lx-http-fixture-'.bin2hex(random_bytes(8));
        $s->query('DELETE FROM '.MAIN_DB_PREFIX.'const WHERE entity='.$entity);
        $prior=[];
        foreach (['MAIN_SECURITY_CSRF_WITH_TOKEN','MAIN_MODULE_HWOSCORE','MAIN_MODULE_HWOSLEXWARE'] as $i=>$name) {
            // Exercise missing, zero, one, and a literal non-boolean CSRF value.
            if (($n+$i)%3 === 0) { continue; }
            $value=$i===0 ? ['0','1','exact-prior'][$n%3] : (string)(($n+$i)%2);
            $s->query('INSERT INTO '.MAIN_DB_PREFIX.'const (name,value,type,visible,entity) VALUES ('.$s->q($name).','.$s->q($value).",'chaine',0,".$entity.')');
        }
        $prior=$s->rows('SELECT * FROM '.MAIN_DB_PREFIX."const WHERE name IN ('MAIN_SECURITY_CSRF_WITH_TOKEN','MAIN_MODULE_HWOSCORE','MAIN_MODULE_HWOSLEXWARE') AND entity=".$entity.' ORDER BY name');
        [$code]=invokeRecovery(['action'=>'create','login'=>$login,'password'=>bin2hex(random_bytes(24))]);
        recoveryCheck($code===0,'recovery fixture create');
        [$code,$out,$err]=invokeRecovery(['action'=>'populate','login'=>$login],str_starts_with($fault,'populate-')?$fault:'');
        recoveryCheck(is_file($dir.'/recovery.json'), 'recovery metadata must survive populate without normal JSON');
        if (str_starts_with($fault,'populate-')) { recoveryCheck($code!==0 && str_contains($err,'UI_FIXTURE_INJECTED'), 'intended populate fault reached'); }
        else { recoveryCheck($code===0,'populate cleanup fixture'); }
        $journal=json_decode(file_get_contents($dir.'/recovery.json'),true,512,JSON_THROW_ON_ERROR);
        recoveryCheck((fileperms($dir.'/recovery.json') & 0777)===0600 && !isset($journal['password']),'recovery journal is private and password-free');
        recoveryCheck($journal['login']===$login && isset($journal['active_rows'],$journal['prior_csrf']), 'recovery records exact prior states before mutations');
        [$stateCode]=invokeRecovery(['action'=>'state']);
        recoveryCheck($stateCode===0,'read-only state checks must not require fixture login recovery merge');
        [$code,$out,$err]=invokeRecovery(['action'=>'cleanup','login'=>$login],str_starts_with($fault,'cleanup-')?$fault:'');
        if (str_starts_with($fault,'cleanup-')) {
            recoveryCheck($code!==0,'intended cleanup fault reported');
            foreach ($phases as $phase) { recoveryCheck(str_contains($out,'"'.$phase.'"'), 'cleanup attempts and reports '.$phase.' despite '.$fault); }
            if (str_ends_with($fault,'-native')) {
                recoveryCheck($s->rows('SELECT * FROM '.MAIN_DB_PREFIX."const WHERE name IN ('MAIN_SECURITY_CSRF_WITH_TOKEN','MAIN_MODULE_HWOSCORE','MAIN_MODULE_HWOSLEXWARE') AND entity=".$entity.' ORDER BY name')===$prior,'native module failure must not suppress exact prior constants restoration');
            }
            [$code]=invokeRecovery(['action'=>'cleanup','login'=>$login]);
        }
        recoveryCheck($code===0,'repeatable recovery completes');
        recoveryCheck($s->rows('SELECT * FROM '.MAIN_DB_PREFIX."const WHERE name IN ('MAIN_SECURITY_CSRF_WITH_TOKEN','MAIN_MODULE_HWOSCORE','MAIN_MODULE_HWOSLEXWARE') AND entity=".$entity.' ORDER BY name')===$prior,'exact prior CSRF and both module constants restored');
        recoveryCheck(!$s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE login='.$s->q($login)),'no fixture users remain');
        foreach (['run','resource','mapping','issue','file','relation','payload_version','file_version','state_event','resolution'] as $table) { recoveryCheck(!$s->rows('SELECT * FROM '.$s->table($table).' WHERE entity='.$entity),'no fixture '.$table.' rows remain'); }
        recoveryCheck(!$s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'product WHERE entity='.$entity),'no fixture products remain');
        echo 'PASS: fixture recovery '.$fault."\n";
    }
} finally {
    $fallbackRun=$s->rows('SELECT rowid FROM '.$s->table('run').' WHERE entity='.$entity." AND context='ui-history'")[0]['rowid'] ?? 0;
    invokeRecovery(['action'=>'cleanup','login'=>$login,'fixture_run'=>(int)$fallbackRun]);
    $s->query('DELETE FROM '.MAIN_DB_PREFIX.'const WHERE entity='.$entity);
    require_once __DIR__.'/lexware_history_cleanup.php';
    require_once $root.'/hwoslexware/class/LexwareProjection.php';
    cleanupHistoryEntity($s);
    foreach (glob($dir.'/*') as $file) { unlink($file); } rmdir($dir);
}
