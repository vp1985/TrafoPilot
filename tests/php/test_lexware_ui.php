<?php
declare(strict_types=1);
define('NOLOGIN',1); define('NOCSRFCHECK',1);
require '/var/www/html/main.inc.php';
$root=getenv('HWOS_MODULE_ROOT') ?: '/var/www/html/custom';
require_once $root.'/hwoslexware/class/LexwareSync.php';
$conf->modules['hwoslexware']='hwoslexware';
$user->fetch(1); $user->getrights(); $user->rights->hwoslexware=new stdClass();
foreach (['read','sync','mapping','retry','admin'] as $right) { $user->rights->hwoslexware->$right=1; }
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['QUERY_STRING']=''; $_SERVER['REMOTE_ADDR']='127.0.0.1'; $_SERVER['HTTP_USER_AGENT']='TrafoPilot DEV CLI test';
$_SESSION += ['dol_authmode'=>'dolibarr','dol_tz'=>0,'dol_dst'=>0,'dol_tz_string'=>'UTC','dol_screenwidth'=>1920,'dol_screenheight'=>1080];
$conf->theme='eldy';
$conf->browser=(object) ['name'=>'','version'=>'','layout'=>'classic','stringforfirstkey'=>''];
$fixtureEntity = random_int(1000000000, 2000000000);
$fixtureStore = new LexwareStore($db, $fixtureEntity);
$fixtureRunIds = []; $fixtureResourceId = 0;
try {
    $base = "INSERT INTO ".$fixtureStore->table('run')." (entity,organization_id,mode,status,fk_user,context,checkpoint_json,stats_json,date_creation,date_finished,last_error) VALUES ";
    $fixtureStore->query($base."($fixtureEntity,'00000000-0000-0000-0000-000000000001','full','complete',NULL,'ui-test','{}','{}','2001-01-02 03:04:05','2001-01-02 03:04:06',NULL)");
    $fixtureRunIds[] = (int) $db->last_insert_id($fixtureStore->table('run'));
    $fixtureStore->query($base."($fixtureEntity,'00000000-0000-0000-0000-000000000001','full','errors',NULL,'ui-test','{}','{}','2002-01-02 03:04:05','2002-01-02 03:04:06','synthetic')");
    $fixtureRunIds[] = (int) $db->last_insert_id($fixtureStore->table('run'));
    foreach (['index.php','resources.php','issues.php'] as $page) {
        if ($page === 'issues.php') { $lxStore = $fixtureStore; }
        $_GET=[]; $_POST=[]; ob_start();
        try { require $root.'/hwoslexware/'.$page; $html=ob_get_contents(); } finally { ob_end_clean(); }
        if (!str_contains($html,'TrafoPilot') || !str_contains($html,'</html>')) { throw new RuntimeException('UI render '.$page); }
        if ($page === 'issues.php') {
            if (!str_contains($html, 'Keine offenen Lexware-Konflikte')) { throw new RuntimeException('issues empty state missing'); }
            if (!str_contains($html, 'Letzter erfolgreicher Abgleich: 2001-01-02 03:04:06.')) { throw new RuntimeException('issues latest successful run'); }
        }
    }
    $fixtureResource = $fixtureStore->put($fixtureRunIds[0], 'invoices', 'ui-history-'.bin2hex(random_bytes(8)), '{"remark":"<script>fixture</script>","voucherStatus":"open","version":1}', (int) $user->id);
    $fixtureResourceId = (int) $fixtureResource['rowid'];
    $fixtureStore->map($fixtureResource, 'invoice', 0, '{}');
    $fixtureStore->status($fixtureResourceId, 'conflict');
    $fixtureStore->issue($fixtureResourceId, 'conflict', ['fixture'=>'<script>issue</script>']);
    $lxStore = $fixtureStore;
    $_GET = ['id'=>$fixtureResourceId]; $_POST = []; ob_start();
    try { require $root.'/hwoslexware/resource.php'; $detailHtml = ob_get_contents(); } finally { ob_end_clean(); }
    foreach (['payload.php?version=1','Payload-Versionen','Dateiversionen','Statushistorie','Konfliktlösungen','value="Lexware"','value="Dolibarr"','name="checksum"','method="POST"'] as $indicator) {
        if (!str_contains($detailHtml, $indicator)) { throw new RuntimeException('resource rendered history/form '.$indicator); }
    }
    foreach ([true,false] as $administrator) {
        $principal=$user; $user=clone $user; $user->admin=$administrator ? 1 : 0;
        try {
            foreach (['issues.php','resource.php'] as $page) {
                $_GET=['id'=>$fixtureResourceId]; $_POST=[]; $lxStore=$fixtureStore; ob_start();
                try { require $root.'/hwoslexware/'.$page; $mappingHtml=ob_get_contents(); } finally { ob_end_clean(); }
                foreach (['value="Lexware"','value="Dolibarr"','name="checksum"','name="token"','method="POST"'] as $indicator) {
                    if (!str_contains($mappingHtml,$indicator)) { throw new RuntimeException(($administrator ? 'admin' : 'delegated mapping').' protected '.$page.' resolution form '.$indicator); }
                }
            }
        } finally { $user=$principal; }
    }
    if (str_contains($detailHtml, '<script>fixture</script>') || !str_contains($detailHtml, '&lt;script&gt;fixture&lt;/script&gt;')) { throw new RuntimeException('payload history must escape dynamic output'); }
    $principal = $user; $user = clone $user; $user->admin = 0; $user->rights = new stdClass(); $user->rights->hwoslexware = (object) ['read'=>1];
    try {
        $_GET = ['id'=>$fixtureResourceId]; $_POST = []; ob_start();
        try { require $root.'/hwoslexware/resource.php'; $readerHtml = ob_get_contents(); } finally { ob_end_clean(); }
        foreach (['Workflow: open','Projektion: conflict','role="alert"','Konflikt prüfen'] as $indicator) { if (!str_contains($readerHtml, $indicator)) { throw new RuntimeException('read-authorized conflict visibility '.$indicator); } }
        if (str_contains($readerHtml, 'value="Lexware"') || !str_contains($readerHtml, 'Payload-Versionen')) { throw new RuntimeException('reader may view history but cannot resolve'); }
        $fixtureStore->status($fixtureResourceId, 'pending');
        ob_start();
        try { require $root.'/hwoslexware/resource.php'; $pendingHtml = ob_get_contents(); } finally { ob_end_clean(); }
        if (!str_contains($pendingHtml, 'role="alert"') || !str_contains($pendingHtml, 'Projektion: pending')) { throw new RuntimeException('open conflict warning survives pending mirror refresh'); }

    } finally { $user = $principal; }
} finally {
    if ($fixtureResourceId) {
        foreach (['mapping','issue','payload_version'] as $table) { $fixtureStore->query('DELETE FROM '.$fixtureStore->table($table).' WHERE entity='.$fixtureEntity.' AND fk_resource='.$fixtureResourceId); }
        $fixtureStore->query('DELETE FROM '.$fixtureStore->table('resource').' WHERE entity='.$fixtureEntity.' AND rowid='.$fixtureResourceId);
        $fixtureStore->query('DELETE FROM '.MAIN_DB_PREFIX.'hwoscore_audit_event WHERE entity='.$fixtureEntity);
    }
    if ($fixtureRunIds) {
        $fixtureStore->query('DELETE FROM '.$fixtureStore->table('run').' WHERE entity='.$fixtureEntity.' AND rowid IN ('.implode(',', array_map('intval', $fixtureRunIds)).')');
    }
}

$store=new LexwareStore($db,(int) $conf->entity);
$lxStore=$store;
$first=$store->rows('SELECT rowid FROM '.$store->table('resource').' WHERE entity='.$store->entity.' LIMIT 1');
if ($first) {
    $_GET=['id'=>(int) $first[0]['rowid']]; ob_start();
    try { require $root.'/hwoslexware/resource.php'; $html=ob_get_contents(); } finally { ob_end_clean(); }
    if (!str_contains($html,'Geschützter vollständiger API-Payload')) { throw new RuntimeException('resource UI'); }
}
$resourceSource = file_get_contents($root.'/hwoslexware/resource.php');
foreach (['Payload-Versionen','Dateiversionen','Statushistorie','Konfliktlösungen','Auditprotokoll','resolve'] as $indicator) {
    if (!str_contains($resourceSource, $indicator)) { throw new RuntimeException('history UI missing '.$indicator); }
}
$issuesSource = file_get_contents($root.'/hwoslexware/issues.php');
if (!str_contains($issuesSource, 'resolve') || !str_contains($issuesSource, 'lxConflictForm')) { throw new RuntimeException('case-by-case conflict UI missing'); }
foreach (['index.php','resource.php','issues.php'] as $page) {
    $source=file_get_contents($root.'/hwoslexware/'.$page);
    if (!str_contains($source,'lxPost()') || !str_contains($source,'newToken()') || str_contains($source,'NOCSRFCHECK')) { throw new RuntimeException('web CSRF contract'); }
}
echo "PASS: DEV UI renders overview, mirror, issues and resource; web actions use POST and native CSRF\n";
