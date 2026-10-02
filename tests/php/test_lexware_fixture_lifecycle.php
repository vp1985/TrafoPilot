<?php
declare(strict_types=1);
// Execute the real helper against fault-injecting domain doubles, without DB writes.
const MAIN_DB_PREFIX='llx_';
class FakeDB { public function begin() {} public function commit() {} public function rollback() {} }
class LexwareStore {
    public int $entity=1;
    public function __construct($db,$entity) {}
    public function rows($sql) {
        if (str_contains($sql, 'entity=0')) { return getenv('FIXTURE_GLOBAL_ACTIVE') ? [['rowid'=>1]] : []; }
        return [['name'=>'MAIN_MODULE_HWOSCORE','value'=>'0'],['name'=>'MAIN_MODULE_HWOSLEXWARE','value'=>'0']];
    }
    public function query($sql) {}
}
class modHwosCore { public static array $events=[]; public function __construct($db) {} public function init() { static::$events[]='init'; return 1; } public function remove() { static::$events[]='remove'; return getenv('FIXTURE_FAULT')==='both' ? -1 : 1; } }
class modHwosLexware extends modHwosCore { public static array $events=[]; }
class User {
    public static int $deleted=0;
    public string $login='lx-http-fixture-0123456789abcdef';
    public function fetch($id) { return 1; } public function getrights() {}
    public function create($user,$x) { return 42; }
    public function setPassword(...$args) { return false; }
    public function delete($user) { self::$deleted++; if (getenv('FIXTURE_FAULT')==='throw' || getenv('FIXTURE_FAULT')==='both') { throw new RuntimeException('synthetic delete failure'); } return getenv('FIXTURE_FAULT')==='return' ? -1 : 1; }
}
$db=new FakeDB(); $conf=(object)['entity'=>1]; $user=new User();
$input=['action'=>'create','login'=>'lx-http-fixture-0123456789abcdef','password'=>'synthetic'];
if (getenv('FIXTURE_FAULT')) { $input=['action'=>'cleanup','id'=>42,'login'=>'lx-http-fixture-0123456789abcdef','active'=>[]]; }
$source=file_get_contents(getenv('HWOS_SCRIPT_ROOT').'/lexware-ui-fixture.php');
$source=preg_replace('/^require(?:_once)? .*;$/m','',$source);
$source=preg_replace('/^\$input=json_decode.*;$/m','',$source);
$source=str_replace("define('NOLOGIN',1); define('NOCSRFCHECK',1);",'',$source);
$source=str_replace(['<?php','declare(strict_types=1);'],'',$source);

putenv('FIXTURE_GLOBAL_ACTIVE=1');
modHwosCore::$events=[]; modHwosLexware::$events=[]; User::$deleted=0;
$globalScenario=str_replace(
    ['restoreFixtureModules','fixtureGlobalActivationPresent'],
    ['restoreFixtureModules_global','fixtureGlobalActivationPresent_global'],
    $source
);
try { eval($globalScenario); throw new RuntimeException('expected global activation refusal'); }
catch (RuntimeException $e) { if ($e->getMessage()!=='UI_FIXTURE_GLOBAL_ACTIVATION') { throw $e; } }
if (User::$deleted!==0 || modHwosCore::$events!==[] || modHwosLexware::$events!==[]) { throw new RuntimeException('global activation refusal mutated fixture state'); }
putenv('FIXTURE_GLOBAL_ACTIVE');
echo "PASS: global activation refusal performs zero lifecycle mutations\n";

try { eval($source); throw new RuntimeException('expected failure'); }
catch (RuntimeException $e) { if (getenv('FIXTURE_FAULT') ? !str_contains($e->getMessage(),'UI_FIXTURE_CLEANUP_FAILED') || (getenv('FIXTURE_FAULT')==='both' && !str_contains($e->getMessage(),'UI_FIXTURE_RESTORE_FAILED')) : $e->getMessage()!=='UI_FIXTURE_USER_FAILED') { throw $e; } }
if (User::$deleted!==1 || !in_array('remove',modHwosCore::$events,true) || !in_array('remove',modHwosLexware::$events,true)) { throw new RuntimeException('creation failure leaked fixture or module state'); }
echo "PASS: UI fixture creation failure deletes user and restores both modules\n";

foreach (['return','throw','both'] as $fault) {
    putenv('FIXTURE_FAULT='.$fault);
    User::$deleted=0; modHwosCore::$events=[]; modHwosLexware::$events=[];
    $input=['action'=>'cleanup','id'=>42,'login'=>'lx-http-fixture-0123456789abcdef','active'=>[]];
    $scenario=str_replace(
        ['restoreFixtureModules','fixtureGlobalActivationPresent'],
        ['restoreFixtureModules_'.$fault,'fixtureGlobalActivationPresent_'.$fault],
        str_replace("define('NOLOGIN',1); define('NOCSRFCHECK',1);",'',$source)
    );
    try { eval($scenario); throw new RuntimeException('expected cleanup failure'); }
    catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(),'UI_FIXTURE_CLEANUP_FAILED') || ($fault==='both' && !str_contains($e->getMessage(),'UI_FIXTURE_RESTORE_FAILED'))) { throw $e; }
    }
    if (User::$deleted!==1 || modHwosCore::$events!==['remove'] || modHwosLexware::$events!==['remove']) { throw new RuntimeException('ordinary cleanup leaked module state'); }
}
putenv('FIXTURE_FAULT');
echo "PASS: ordinary cleanup restores both modules on delete failure/throw and reports combined recovery failures\n";
