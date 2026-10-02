<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
define('NOLOGIN',1); define('NOCSRFCHECK',1);
require '/var/www/html/main.inc.php';
require_once '/var/www/html/custom/hwoscore/core/modules/modHwosCore.class.php';
require_once '/var/www/html/custom/hwoslexware/core/modules/modHwosLexware.class.php';
require_once '/var/www/html/custom/hwoslexware/class/LexwareSync.php';
$input = json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
putenv('LEXWARE_TEST_API_KEY_READ_ONLY='.(string) ($input['read_only_secret'] ?? ''));
unset($input['read_only_secret']);
$api = LexwareClient::fromEnvironment();
$s = new LexwareStore($db,(int) $conf->entity);
$module = new modHwosLexware($db); $core = new modHwosCore($db); $activationAttempted=false;
$active=[]; $error=null;
try {
    (new LexwareSync($s,$api))->testConnection(); echo "PASS: GET-only profile confirms Holger Testzentrum and expected organization UUID\n";
    if (($input['mode'] ?? '') === 'smoke') { exit(0); }
    if ($s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX."const WHERE name IN ('MAIN_MODULE_HWOSCORE','MAIN_MODULE_HWOSLEXWARE') AND entity=0")) { throw new DomainException('GLOBAL_ACTIVATION_BLOCKS_DEV_TEST'); }
    foreach ($s->rows('SELECT name,value FROM '.MAIN_DB_PREFIX."const WHERE name IN ('MAIN_MODULE_HWOSCORE','MAIN_MODULE_HWOSLEXWARE') AND entity=".$s->entity) as $r) { $active[$r['name']]=$r['value']==='1'; }
    $activationAttempted=true;
    $user->fetch(1); $user->getrights();
    if ($core->init()!==1 || $module->init()!==1) { throw new RuntimeException('DEV_ACTIVATION_FAILED'); }
    $user->fetch(1); $user->getrights();
    $checksums = function (array $tables) use ($s) {
        $result=[];
        foreach ($tables as $table) {
            $r=$s->rows('CHECKSUM TABLE '.MAIN_DB_PREFIX.$table);
            if (!isset($r[0]['Checksum'])) { throw new RuntimeException('BUSINESS_CHECKSUM_UNAVAILABLE'); }
            $result[$table]=(string) $r[0]['Checksum'];
        }
        return $result;
    };
    $invariantTables=['stock_mouvement','product_stock','bank','bank_account','paiement','accounting_bookkeeping'];
    if ($input['mode']==='dry') { $invariantTables=array_merge($invariantTables,['societe','socpeople','product','propal','propaldet','commande','commandedet','facture','facturedet','expedition','expeditiondet']); }
    $before=$checksums($invariantTables);
    $sync = new LexwareSync($s,$api);
    $run = (int) ($input['run'] ?? 0) ?: $sync->start($user,$input['mode'],'dev-cli');
    echo 'RUN '.$run."\n";
    for ($i=0;$i<10000;$i++) {
        $result=$sync->batch($run,$user,5);
        echo 'BATCH '.($i+1).' STATUS '.$result['status']."\n"; flush();
        if ($result['status']!=='pending') {
            echo 'STATS '.$result['stats_json']."\n";
            if ($result['status']!=='complete') {
                $cp=json_decode($result['checkpoint_json'],true);
                foreach ($cp['queue'] as $task) { if ($task['state']==='error') { echo 'ERROR '.$task['type'].' '.$task['error']."\n"; } }
                $error='DEV_RUN_HAS_ERRORS';
            }
            break;
        }
    }
    if ($result['status']==='pending') { $error='DEV_RUN_BATCH_BUDGET_REACHED_RESUME_WITH_RUN_ID'; }
    if ($checksums($invariantTables)!==$before) { $error='BUSINESS_INVARIANT_CHANGED'; }
    else { echo $input['mode']==='dry' ? "PASS: dry-run business table checksums unchanged\n" : "PASS: stock, bank, payments and accounting checksums unchanged\n"; }
} catch (Throwable $e) {
    $error=preg_match('/^[A-Z_0-9]+$/',$e->getMessage()) ? $e->getMessage() : 'LEXWARE_DEV_OPERATION_FAILED';
} finally {
    putenv('LEXWARE_TEST_API_KEY_READ_ONLY');
    if ($activationAttempted) {
        $restored=($active['MAIN_MODULE_HWOSLEXWARE'] ?? false) ? $module->init() : $module->remove();
        $restoredCore=($active['MAIN_MODULE_HWOSCORE'] ?? false) ? $core->init() : $core->remove();
        if ($restored!==1 || $restoredCore!==1) { $error='DEV_ACTIVATION_RESTORATION_FAILED'; }
    }
}
if ($error) { fwrite(STDERR,$error."\n"); exit(1); }
