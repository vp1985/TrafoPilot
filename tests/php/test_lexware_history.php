<?php
declare(strict_types=1);
define('NOLOGIN', 1); define('NOCSRFCHECK', 1);
require '/var/www/html/main.inc.php';
$root = getenv('HWOS_MODULE_ROOT') ?: '/var/www/html/custom';
$conf->file->dol_document_root = ['hwos_history'=>$root] + $conf->file->dol_document_root;
$conf->file->dol_url_root['hwos_history'] = '/hwos-history';
require_once $root.'/hwoslexware/class/LexwareSync.php';
function historyCheck(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
if ($claim = getenv('HWOS_APPROVAL_CLAIM')) {
    [$entity, $resource, $target] = json_decode($claim, true);
    $conf->entity = $entity; $user->fetch(1); $user->getrights();
    $s = new LexwareStore($db, $entity);
    $s->query('SET SESSION innodb_lock_wait_timeout=1');
    try { (new LexwareProjection($s))->approve($resource, 'thirdparty', $target, $user, true); echo "CLAIM_SUCCESS\n"; }
    catch (Throwable $e) { echo 'CLAIM_REJECTED:'.$e->getMessage()."\n"; }
    exit;
}
foreach ((getenv('HWOS_HISTORY_CASE') ? [getenv('HWOS_HISTORY_CASE')] : ['mapping-concurrent','cron-superseded','conflict-case-Lexware','conflict-case-Dolibarr','mapping-collision','contact-person-extra','line-association-readback','product-readback','csrf-forced','ordinary-rollback','locked-current-view','stale-full','related-dunning-presence','unsupported-relationship-absence','retry-lock','relationship-positive','contradictory-pagination','pagination-total-drift','transaction-return-values','conflict-source-fields','source-contact-product-fields','conflict-dependency-remap','conflict-tax-resolution','conflict-native-dependency-resolution','conflict-line-input-resolution','conflict-dependent-invoice','conflict-dependent-shipping','conflict-upgrade-shipping','payload','raw-distinct','file','file-fetch','file-audit','file-limit','file-rollback','payload-rollback','retry-rollback','removal','removed-dunning','partial','reappearance','full-snapshot','invalid-page','invalid-page-object','legacy-snapshot','relationship','migration','contact-resolution','conflict-remote','conflict-stale-project','conflict-audit','conflict-dolibarr','conflict-return','conflict-lexware','conflict-structure','conflict-shipping','conflict-retry','conflict-rollback','conflict-permissions','conflict-local','conflict-line-extra','conflict-reresolve']) as $case) {
$entity = random_int(1100000000, 1900000000);
$conf->entity = $entity;
$conf->modules['hwoslexware'] = 'hwoslexware';
$s = new LexwareStore($db, $entity);
$user->fetch(1); $user->getrights();

$ids = []; $runs = []; $native = [];
$protectedSql = 'CHECKSUM TABLE '.implode(',', array_map(fn($t)=>MAIN_DB_PREFIX.$t, ['stock_mouvement','bank','bank_url','paiement','paiement_facture','accounting_bookkeeping','accounting_bookkeeping_tmp']));
$protectedBefore = $s->rows($protectedSql);
try {
    foreach (explode(';', file_get_contents($root.'/hwoslexware/sql/llx_hwoslexware.sql')) as $sql) {
        if (trim($sql)) { $s->query(str_replace('llx_', MAIN_DB_PREFIX, trim($sql))); }
    }
    $s->migrateHistory();
    $s->query('INSERT INTO '.$s->table('run').' (entity,organization_id,mode,status,fk_user,context,checkpoint_json,stats_json,date_creation) VALUES ('.$entity.','.$s->q(LexwareClient::ORGANIZATION).",'full','pending',1,'history-test','{}','{}',NOW())");
    $run = (int) $db->last_insert_id($s->table('run')); $runs[] = $run;
    $id = 'history-'.bin2hex(random_bytes(10));
    $raw1 = '{"id":"'.$id.'","version":1,"title":"first","empty":{},"large":9223372036854775808123}';
    $raw2 = str_replace('first', 'second', $raw1);
    $r = $s->put($run, 'articles', $id, $raw1, (int) $user->id); $ids[] = (int) $r['rowid'];
    if ($case === 'mapping-concurrent' && !function_exists('proc_open')) {
        echo "SKIP: history mapping-concurrent requires proc_open; covered by DEV suite\n";
        continue;
    }
    if ($case === 'mapping-concurrent') {
        $projector = new LexwareProjection($s); $contacts = [];
        foreach (['a','b','target'] as $suffix) {
            $c = $s->put($run, 'contacts', $id.$suffix, json_encode(['company'=>['name'=>$id.$suffix],'roles'=>['customer'=>[]]]), 1);
            historyCheck($projector->project($c, $user) === 'new', 'concurrent approval fixture');
            $contacts[] = $c; $native[] = $s->mapping((int) $c['rowid']);
        }
        $target = (int) $native[2]['object_id'];
        $s->query('DELETE FROM '.$s->table('mapping').' WHERE entity='.$entity.' AND fk_resource='.(int) $contacts[2]['rowid']);
        $loser = (int) $contacts[1]['rowid'];
        $s->issue($loser, 'conflict', ['reason'=>'concurrent approval fixture'], 1);
        $state = fn()=>[$s->mapping($loser), $s->one('resource', $loser), $s->rows('SELECT * FROM '.$s->table('issue').' WHERE entity='.$entity.' AND fk_resource='.$loser), $s->rows('SELECT * FROM '.$s->table('resolution').' WHERE entity='.$entity.' AND fk_resource='.$loser), $s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'hwoscore_audit_event WHERE entity='.$entity.' AND object_id='.$s->q($contacts[1]['remote_id']))];
        $prior = $state();
        $s->db = new class($db, $entity, $loser, $target) {
            public bool $hit = false; public string $result = '';
            public function __construct(private $real, private int $entity, private int $loser, private int $target) {}
            public function __get($name) { return $this->real->$name; }
            public function __set($name,$value) { $this->real->$name=$value; }
            public function __call($name,$args) { return $this->real->$name(...$args); }
            public function query($sql) {
                $result = $this->real->query($sql);
                if (!$this->hit && str_contains($sql, 'SELECT fk_resource FROM') && str_contains($sql, 'object_id='.$this->target)) {
                    $this->hit = true;
                    $env = getenv(); $env['HWOS_APPROVAL_CLAIM'] = json_encode([$this->entity,$this->loser,$this->target]);
                    $process = proc_open([PHP_BINARY, __FILE__], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null, $env);
                    historyCheck(is_resource($process), 'second connection process launched');
                    fclose($pipes[0]); $this->result = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                    historyCheck(proc_close($process) === 0 && $errors === '', 'second connection exited cleanly');
                    historyCheck(str_contains($this->result, 'CLAIM_REJECTED:'), 'simultaneous replace loser must fail while winner owns target lock');
                }
                return $result;
            }
        };
        $fault = $s->db;
        try { $projector->approve((int) $contacts[0]['rowid'], 'thirdparty', $target, $user, true); }
        finally { $s->db = $db; }
        historyCheck($fault->hit && $state() === $prior, 'losing replace preserves prior mapping, issue, status, history and audit');
        $winner = $s->mapping((int) $contacts[0]['rowid']);
        historyCheck((int) $winner['object_id'] === $target && $winner['remote_checksum'] === $contacts[0]['checksum'] && $winner['snapshot_json'] === $projector->snapshot('thirdparty', $target), 'winner exact ownership and baseline readback');
        try { $projector->approve($loser, 'thirdparty', $target, $user, true); throw new RuntimeException('sequential collision accepted'); }
        catch (DomainException $e) { historyCheck($e->getMessage()==='NATIVE_OBJECT_ALREADY_LINKED', 'sequential replace collision rejected'); }
        historyCheck($state() === $prior && $s->mapping((int) $contacts[0]['rowid']) === $winner, 'sequential loser cannot overwrite winner or its own history');
        $projector->approve((int) $contacts[0]['rowid'], 'thirdparty', $target, $user, true);
        historyCheck($s->mapping((int) $contacts[0]['rowid'])['snapshot_json'] === $winner['snapshot_json'], 'same owner replacement preserves exact baseline');
    }
    if ($case === 'cron-superseded') {
        require_once $root.'/hwoslexware/class/LexwareJobs.php';
        $s->query('INSERT INTO '.$s->table('run').' (entity,organization_id,mode,status,fk_user,context,checkpoint_json,stats_json,date_creation) VALUES ('.$entity.','.$s->q(LexwareClient::ORGANIZATION).",'incremental','pending',1,'history-test','{}','{}',NOW())");
        $next = (int) $db->last_insert_id($s->table('run')); $runs[] = $next;
        $jobs = new LexwareJobs($db);
        historyCheck($jobs->pendingRun($s, $user) === $next, 'worker skips oldest superseded pending run');
        $old = $s->one('run', $run);
        historyCheck($old['status']==='superseded' && $old['last_error']==='LEXWARE_RUN_SUPERSEDED', 'worker marks superseded run deterministically');
        $audit = $s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'hwoscore_audit_event WHERE entity='.$entity);
        historyCheck($jobs->pendingRun($s, $user) === $next && $s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'hwoscore_audit_event WHERE entity='.$entity) === $audit, 'repeat selection makes progress without duplicate audit');
        historyCheck((new ReflectionMethod(LexwareJobs::class, '__construct'))->getNumberOfParameters() === 2, 'worker must accept local test transport without environment or live API');
        $calls = 0; $eligible = 0;
        $api = new LexwareClient('fixture', function($path) use ($s, $entity, &$calls, &$eligible, &$runs) {
            historyCheck($path === '/v1/profile', 'worker must only use local profile transport for empty queue');
            if (++$calls === 1) {
                // Deterministic supersession between selection and batch fencing.
                $s->query('INSERT INTO '.$s->table('run').' (entity,organization_id,mode,status,fk_user,context,checkpoint_json,stats_json,date_creation) VALUES ('.$entity.','.$s->q(LexwareClient::ORGANIZATION).",'incremental','pending',1,'history-test','{\"queue\":[]}','{}',NOW())");
                $eligible = (int) $s->db->last_insert_id($s->table('run')); $runs[] = $eligible;
            }
            return [200,json_encode(['organizationId'=>LexwareClient::ORGANIZATION,'companyName'=>'Holger Testzentrum']),[]];
        }, fn()=>10000.0, fn()=>null);
        $jobs = new LexwareJobs($db, $api);
        $jobResult = $jobs->run();
        historyCheck($jobResult === 0 && $calls === 2 && $jobs->output === 'TrafoPilot Lexware Lauf '.$eligible.': complete', 'worker handles batch supersession and continues next eligible run without busy loop (result='.$jobResult.',error='.$jobs->error.',calls='.$calls.',output='.$jobs->output.',eligible='.$eligible.')');
        historyCheck($s->one('run',$next)['status'] === 'superseded' && $s->one('run',$eligible)['status'] === 'complete', 'worker records rejected run and completes eligible run');

        historyCheck($jobs->pendingRun($s, $user) === null, 'completed newer run never revives superseded pending');
        // A newer run can finish before the rejected batch reselects. Never
        // interpret that transition as permission to start an unrelated cron run.
        $s->query('INSERT INTO '.$s->table('run').' (entity,organization_id,mode,status,fk_user,context,checkpoint_json,stats_json,date_creation) VALUES ('.$entity.','.$s->q(LexwareClient::ORGANIZATION).",'incremental','pending',1,'history-test','{\"queue\":[]}','{}',NOW())");
        $transition = (int) $db->last_insert_id($s->table('run')); $runs[] = $transition;
        $transitionCalls = 0;
        $api = new LexwareClient('fixture', function($path) use ($s, $entity, &$runs, &$transitionCalls) {
            historyCheck($path === '/v1/profile', 'transition only verifies profile');
            ++$transitionCalls;
            $s->query('INSERT INTO '.$s->table('run').' (entity,organization_id,mode,status,fk_user,context,checkpoint_json,stats_json,date_creation) VALUES ('.$entity.','.$s->q(LexwareClient::ORGANIZATION).",'incremental','complete',1,'history-test','{\"queue\":[]}','{}',NOW())");
            $runs[] = (int) $s->db->last_insert_id($s->table('run'));
            return [200,json_encode(['organizationId'=>LexwareClient::ORGANIZATION,'companyName'=>'Holger Testzentrum']),[]];
        }, fn()=>10000.0, fn()=>null);
        $jobs = new LexwareJobs($db, $api);
        historyCheck($jobs->run() === 0 && $transitionCalls === 1 && $jobs->output === 'LEXWARE_RUN_SELECTION_DEFERRED', 'completed supersession must defer without starting an unintended run');
        historyCheck($s->one('run', $transition)['status'] === 'superseded', 'completed transition marks rejected pending run');
        $afterTransition = $s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'hwoscore_audit_event WHERE entity='.$entity);
        historyCheck($jobs->pendingRun($s, $user) === null && $jobs->pendingRun($s, $user) === null && $s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'hwoscore_audit_event WHERE entity='.$entity) === $afterTransition, 'repeated transition selection never duplicates supersession');
        historyCheck(count($s->rows('SELECT rowid FROM '.$s->table('run').' WHERE entity='.$entity)) === count($runs), 'worker never inserts an unintended transition run');
        // Supersession can already be visible at selection, before any batch.
        foreach (['pending','complete'] as $status) {
            $s->query('INSERT INTO '.$s->table('run').' (entity,organization_id,mode,status,fk_user,context,checkpoint_json,stats_json,date_creation) VALUES ('.$entity.','.$s->q(LexwareClient::ORGANIZATION).",'incremental',".$s->q($status).",1,'history-test','{\"queue\":[]}','{}',NOW())");
            $runs[] = (int) $s->db->last_insert_id($s->table('run'));
        }
        $selectionCalls = 0;
        $api = new LexwareClient('fixture', function() use (&$selectionCalls) {
            ++$selectionCalls;
            return [200,json_encode(['organizationId'=>LexwareClient::ORGANIZATION,'companyName'=>'Holger Testzentrum']),[]];
        }, fn()=>10000.0, fn()=>null);
        $jobs = new LexwareJobs($db, $api);
        historyCheck($jobs->run() === 0 && $selectionCalls === 0 && $jobs->output === 'LEXWARE_RUN_SELECTION_DEFERRED', 'selection reconciliation must not start a run when newer run already completed');
        historyCheck(count($s->rows('SELECT rowid FROM '.$s->table('run').' WHERE entity='.$entity)) === count($runs), 'selection transition creates no unintended run');
        $churnCalls = 0;
        $insertPending = function() use ($s, $entity, &$runs) {
            $s->query('INSERT INTO '.$s->table('run').' (entity,organization_id,mode,status,fk_user,context,checkpoint_json,stats_json,date_creation) VALUES ('.$entity.','.$s->q(LexwareClient::ORGANIZATION).",'incremental','pending',1,'history-test','{\"queue\":[]}','{}',NOW())");
            $runs[] = (int) $s->db->last_insert_id($s->table('run'));
        };
        $insertPending();
        $api = new LexwareClient('fixture', function($path) use ($insertPending, &$churnCalls) {
            historyCheck($path === '/v1/profile', 'churn only verifies profile');
            ++$churnCalls; $insertPending();
            return [200,json_encode(['organizationId'=>LexwareClient::ORGANIZATION,'companyName'=>'Holger Testzentrum']),[]];
        }, fn()=>10000.0, fn()=>null);
        $jobs = new LexwareJobs($db, $api);
        foreach ([5,10] as $expectedCalls) {
            historyCheck($jobs->run() === 0 && $churnCalls === $expectedCalls && $jobs->output === 'LEXWARE_RUN_SELECTION_DEFERRED', 'repeated worker invocation bounds supersession churn to five batches');
            $current = end($runs);
            historyCheck($jobs->pendingRun($s, $user) === $current, 'deferred worker preserves newest pending run');
            $audit = $s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'hwoscore_audit_event WHERE entity='.$entity);
            historyCheck($jobs->pendingRun($s, $user) === $current && $s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'hwoscore_audit_event WHERE entity='.$entity) === $audit, 'repeated churn reconciliation audits once');
            historyCheck(count($s->rows('SELECT rowid FROM '.$s->table('run').' WHERE entity='.$entity)) === count($runs), 'bounded worker does not create a cron run');
        }


    }
    if ($case === 'mapping-collision') {
        $projector = new LexwareProjection($s);
        $contacts = [];
        foreach (['a','b'] as $suffix) {
            $c = $s->put($run, 'contacts', $id.$suffix, json_encode(['company'=>['name'=>$id.$suffix],'roles'=>['customer'=>[]]]), 1);
            historyCheck($projector->project($c, $user) === 'new', 'collision contact fixture');
            $contacts[] = $c; $native[] = $s->mapping((int) $c['rowid']);
        }
        $before = $s->rows('SELECT * FROM '.$s->table('mapping').' WHERE entity='.$entity.' ORDER BY rowid');
        try {
            $s->map($contacts[1], 'thirdparty', (int) $native[0]['object_id'], '{"collision":true}');
            throw new RuntimeException('cross-resource collision was accepted');
        } catch (DomainException $e) { historyCheck($e->getMessage()==='MAPPING_OWNERSHIP_CONFLICT', 'collision fails closed'); }
        historyCheck($s->rows('SELECT * FROM '.$s->table('mapping').' WHERE entity='.$entity.' ORDER BY rowid') === $before, 'collision preserves both mappings');
    }
    if ($case === 'contact-person-extra') {
        $projector = new LexwareProjection($s);
        $payload = ['company'=>['name'=>$id,'contactPersons'=>[['firstName'=>'Local','lastName'=>'Person']]],'roles'=>['customer'=>[]]];
        $c = $s->put($run, 'contacts', $id.'-c', json_encode($payload), 1);
        historyCheck($projector->project($c, $user) === 'new', 'person extra fixture');
        $m = $s->mapping((int) $c['rowid']); $native[] = $m;
        $person = (int) $s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'socpeople WHERE entity='.$entity)[0]['rowid'];
        // Released snapshots did not include person extras. Empty added state is
        // compatible; nonempty added state cannot be silently trusted.
        $legacy = json_decode($m['snapshot_json'], true); unset($legacy['contact_extra']);
        $legacyJson = json_encode($legacy);
        $s->query('UPDATE '.$s->table('mapping').' SET snapshot_json='.$s->q($legacyJson).',snapshot_checksum='.$s->q(LexwareResources::checksum($legacyJson)).' WHERE rowid='.(int) $m['rowid']);
        historyCheck($projector->project($c, $user) === 'unchanged', 'released empty person extra format compatible');
        $s->query('INSERT INTO '.MAIN_DB_PREFIX.'socpeople_extrafields (fk_object,import_key) VALUES ('.$person.",'local-extra')");
        historyCheck($projector->project($c, $user) === 'conflict', 'local contact-person extrafield edit must conflict');
        $priorExtra = $s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'socpeople_extrafields WHERE fk_object='.$person);
        $before = $projector->snapshot('thirdparty',(int) $m['object_id']);
        $baseline = $s->mapping((int) $c['rowid']);
        $s->db = new class($db,$person) {
            public bool $hit=false;
            public function __construct(private $real,private int $person) {}
            public function __get($name) { return $this->real->$name; }
            public function __set($name,$value) { $this->real->$name=$value; }
            public function __call($name,$args) { return $this->real->$name(...$args); }
            public function query($sql) {
                $result=$this->real->query($sql);
                if (preg_match('/^UPDATE\s+'.MAIN_DB_PREFIX.'socpeople\s+SET/i',$sql)) {
                    $this->hit=true;
                    $this->real->query('UPDATE '.MAIN_DB_PREFIX.'socpeople_extrafields SET import_key=\'fault-extra\' WHERE fk_object='.$this->person);
                }
                return $result;
            }
        };
        $faultDb=$s->db;
        try { $projector->resolve((int) $c['rowid'],'Lexware',$c['checksum'],$user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $c['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0)); throw new RuntimeException('contact extra loss accepted'); }
        catch (RuntimeException $e) { historyCheck($faultDb->hit && $e->getMessage()==='NATIVE_CONTACT_PERSON_EXTRAFIELDS_CHANGED','contact extras must read back unchanged or fail closed'); }
        finally { $s->db=$db; }
        historyCheck($projector->snapshot('thirdparty',(int) $m['object_id'])===$before && $s->mapping((int) $c['rowid'])===$baseline && !$s->rows('SELECT rowid FROM '.$s->table('resolution').' WHERE entity='.$entity),'contact extra fault rolls back native, baseline and prior snapshot insert');
        $projector->resolve((int) $c['rowid'], 'Lexware', $c['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $c['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0));
        $prior = json_decode($s->rows('SELECT prior_native_json FROM '.$s->table('resolution').' WHERE entity='.$entity.' ORDER BY rowid DESC')[0]['prior_native_json'],true);
        historyCheck(($prior['contact_extra'] ?? null) === $priorExtra, 'resolution prior immutable snapshot includes all contact-person extras');
        historyCheck($s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'socpeople_extrafields WHERE fk_object='.$person) === $priorExtra, 'resolution preserves native contact-person extras');
        $other = getDoliDBInstance($conf->db->type, $conf->db->host, $conf->db->user, $dolibarr_main_db_pass, $conf->db->name, $conf->db->port);
        $s->db = new class($db, $other, $person) {
            public bool $injected = false;
            public function __construct(private $real, private $other, private int $person) {}
            public function __get($name) { return $this->real->$name; }
            public function __set($name,$value) { $this->real->$name=$value; }
            public function __call($name,$args) { return $this->real->$name(...$args); }
            public function query($sql) {
                if (!$this->injected && str_contains($sql, 'socpeople_extrafields') && str_contains($sql, 'FOR UPDATE')) {
                    $this->injected=true;
                    historyCheck((bool) $this->other->query('UPDATE '.MAIN_DB_PREFIX.'socpeople_extrafields SET import_key=\'concur-extra\' WHERE fk_object='.$this->person), 'concurrent person extra write');
                }
                return $this->real->query($sql);
            }
        };
        try {
            historyCheck($projector->project($c, $user) === 'conflict' && $s->db->injected, 'concurrent committed contact extra edit detected before read view');
            $s->db->injected=false;
            $projector->resolve((int) $c['rowid'], 'Dolibarr', $c['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $c['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0));
            $prior = json_decode($s->rows('SELECT prior_native_json FROM '.$s->table('resolution').' WHERE entity='.$entity.' ORDER BY rowid DESC')[0]['prior_native_json'],true);
            historyCheck($prior['contact_extra'][0]['import_key'] === 'concur-extra', 'resolution snapshot contains actually locked committed person extra state');
        } finally { $s->db=$db; $other->close(); }
    }
    if ($case === 'line-association-readback') {
        $projector = new LexwareProjection($s);
        $c = $s->put($run, 'contacts', $id.'-c', json_encode(['company'=>['name'=>$id],'roles'=>['customer'=>[]]]), 1);
        historyCheck($projector->project($c, $user) === 'new', 'line contact fixture'); $native[] = $s->mapping((int) $c['rowid']);
        $products = [];
        foreach (['a','b'] as $suffix) {
            $a = $s->put($run, 'articles', $id.'-'.$suffix, json_encode(['articleNumber'=>$id.'-'.$suffix,'title'=>$suffix,'price'=>['netPrice'=>10,'taxRate'=>19]]), 1);
            historyCheck($projector->project($a, $user) === 'new', 'association product fixture');
            $products[$suffix] = $s->mapping((int) $a['rowid']); $native[] = $products[$suffix];
        }
        foreach (['quotations'=>'propal','order-confirmations'=>'order','invoices'=>'invoice'] as $resourceType=>$type) {
            $line = ['name'=>'associated','type'=>'material','quantity'=>1,'unitPrice'=>['netAmount'=>10,'taxRatePercentage'=>19]];
            $payload = ['voucherNumber'=>substr($id,0,18).'-'.$type,'voucherStatus'=>'open','voucherDate'=>'2020-01-01','address'=>['contactId'=>$id.'-c'],'lineItems'=>[ ['id'=>$id.'-a']+$line, ['id'=>$id.'-b']+$line ],'totalPrice'=>['totalGrossAmount'=>23.8]];
            $doc = $s->put($run, $resourceType, $id.'-'.$type, json_encode($payload), 1);
            historyCheck($projector->project($doc, $user) === 'new', $type.' line fixture');
            $m = $s->mapping((int) $doc['rowid']); $native[] = $m;
            [,,$table,$detail,$fk] = LexwareProjection::OBJECTS[$type];
            $original = $s->rows('SELECT * FROM '.MAIN_DB_PREFIX.$detail.' WHERE '.$fk.'='.(int) $m['object_id'].' ORDER BY rang,rowid');
            foreach ([['b','b'],['b','a']] as $selected) {
                foreach ($selected as $i=>$key) { $payload['lineItems'][$i]['id'] = $id.'-'.$key; }
                $doc = $s->put($run, $resourceType, $id.'-'.$type, json_encode($payload), 1);
                historyCheck($projector->project($doc, $user) === 'conflict', $type.' association change conflict');
                $projector->resolve((int) $doc['rowid'], 'Lexware', $doc['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $doc['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0));
                $rows = $s->rows('SELECT * FROM '.MAIN_DB_PREFIX.$detail.' WHERE '.$fk.'='.(int) $m['object_id'].' ORDER BY rang,rowid');
                foreach ($selected as $i=>$key) { historyCheck((int) $rows[$i]['fk_product'] === (int) $products[$key]['object_id'] && (int) $rows[$i]['rang'] === $i+1 && $rows[$i]['rowid'] === $original[$i]['rowid'], $type.' persisted selected product and ordering must match source and retain line identities'); }
            }
            $before = $projector->snapshot($type, (int) $m['object_id']); $baseline = $s->mapping((int) $doc['rowid']);
            $payload['lineItems'][0]['id'] = $id.'-a';
            $doc = $s->put($run, $resourceType, $id.'-'.$type, json_encode($payload), 1);
            historyCheck($projector->project($doc, $user) === 'conflict', 'line fault conflict');
            $nativeType = ['propal'=>'propal','order'=>'commande','invoice'=>'facture'][$type];
            $s->query('INSERT INTO '.MAIN_DB_PREFIX.'element_element (sourcetype,fk_source,targettype,fk_target) VALUES ('.$s->q($nativeType).','.(int) $m['object_id'].",'fixture',2147483647)");
            try {
                try { $projector->resolve((int) $doc['rowid'], 'Lexware', $doc['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $doc['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0)); throw new RuntimeException('dependent '.$type.' link accepted'); }
                catch (DomainException $e) { historyCheck($e->getMessage() === 'NATIVE_DEPENDENT_ASSOCIATIONS_REQUIRE_REVIEW', 'dependent document association blocks replacement'); }
                historyCheck($projector->snapshot($type, (int) $m['object_id']) === $before && $s->mapping((int) $doc['rowid']) === $baseline, 'dependent conflict preserves native and mapping');
            } finally { $s->query('DELETE FROM '.MAIN_DB_PREFIX.'element_element WHERE sourcetype='.$s->q($nativeType).' AND fk_source='.(int) $m['object_id']." AND targettype='fixture'"); }
            foreach (['association','readback'] as $fault) {
                $s->db = new class($db, $detail, $fault) extends DoliDBMysqli {
                    public bool $hit = false;
                    public function __construct($real, private string $detail, private string $fault) {
                        foreach (get_object_vars($real) as $k=>$v) { $this->$k = $v; }
                        $this->transaction_opened =& $real->transaction_opened;
                    }
                    public function query($sql, $usesavepoint=0, $type='auto', $result_mode=0) {
                        if (str_contains($sql, $this->detail) && ($this->fault === 'association' ? str_contains($sql, 'SET fk_product =') : str_contains($sql, 'SELECT fk_product'))) { $this->hit=true; return false; }
                        return parent::query($sql, $usesavepoint, $type, $result_mode);
                    }
                };
                $faultDb = $s->db;
                try { $projector->resolve((int) $doc['rowid'], 'Lexware', $doc['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $doc['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0)); throw new RuntimeException('line fault accepted'); }
                catch (Throwable $e) { historyCheck($faultDb->hit, 'intended '.$type.' '.$fault.' fault reached'); }
                finally { $s->db = $db; }
                historyCheck($projector->snapshot($type, (int) $m['object_id']) === $before && $s->mapping((int) $doc['rowid']) === $baseline, $type.' failure restores native and mapping exactly');
                historyCheck(count($s->rows('SELECT rowid FROM '.$s->table('resolution').' WHERE entity='.$entity.' AND fk_resource='.(int) $doc['rowid'])) === 2 && $s->one('resource', (int) $doc['rowid'])['projection_status'] === 'conflict', 'failed line resolution remains open without resolution record');
            }
        }
    }
    if ($case === 'product-readback') {
        $projector = new LexwareProjection($s);
        $payload = ['articleNumber'=>$id,'title'=>$id,'type'=>'PRODUCT','price'=>['netPrice'=>10,'taxRate'=>19]];
        $r = $s->put($run, 'articles', $id, json_encode($payload), 1);
        historyCheck($projector->project($r, $user) === 'new', 'product readback fixture');
        $m = $s->mapping((int) $r['rowid']); $native[] = $m;
        $payload['type'] = 'SERVICE'; $payload['price'] = ['netPrice'=>23,'taxRate'=>7];
        $r = $s->put($run, 'articles', $id, json_encode($payload), 1);
        historyCheck($projector->project($r, $user) === 'conflict', 'product changed source conflict');
        $projector->resolve((int) $r['rowid'], 'Lexware', $r['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $r['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0));
        $row = $s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'product WHERE rowid='.(int) $m['object_id'])[0];
        historyCheck((float) $row['price'] === 23.0 && abs((float) $row['price_ttc'] - 24.61) < 0.000001 && (float) $row['tva_tx'] === 7.0 && $row['price_base_type'] === 'HT' && (int) $row['fk_product_type'] === 1, 'persisted selling price, TTC, tax/base and service type must match chosen source');
        $before = $projector->snapshot('product', (int) $m['object_id']); $baseline = $s->mapping((int) $r['rowid']);
        $prices = $s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'product_price WHERE fk_product='.(int) $m['object_id'].' ORDER BY rowid');
        $payload['type'] = 'PRODUCT'; $payload['price']['netPrice'] = 31;
        $r = $s->put($run, 'articles', $id, json_encode($payload), 1);
        historyCheck($projector->project($r, $user) === 'conflict', 'product fault fixture conflict');
        foreach (['price','type','readback'] as $fault) {
            $s->db = new class($db, $fault) {
                public bool $hit = false;
                public function __construct(private $real, private string $fault) {}
                public function __get($name) { return $this->real->$name; }
                public function __set($name, $value) { $this->real->$name = $value; }
                public function __call($name, $args) { return $this->real->$name(...$args); }
                public function query($sql) {
                    $target = $this->fault === 'price' ? str_contains($sql, 'INSERT INTO '.MAIN_DB_PREFIX.'product_price') : ($this->fault === 'type' ? preg_match('/fk_product_type\s*=/', $sql) : str_contains($sql, 'SELECT * FROM '.MAIN_DB_PREFIX.'product WHERE'));
                    if ($target) { $this->hit = true; return false; }
                    return $this->real->query($sql);
                }
            };
            $faultDb = $s->db;
            ob_start();
            try { $projector->resolve((int) $r['rowid'], 'Lexware', $r['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $r['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0)); throw new RuntimeException('product fault accepted'); }
            catch (Throwable $e) { historyCheck($faultDb->hit, 'intended product '.$fault.' fault reached'); }
            finally { ob_end_clean(); $s->db = $db; }
            historyCheck($projector->snapshot('product', (int) $m['object_id']) === $before && $s->mapping((int) $r['rowid']) === $baseline, 'failed product '.$fault.' restores native and baseline exactly');
            historyCheck($s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'product_price WHERE fk_product='.(int) $m['object_id'].' ORDER BY rowid') === $prices, 'failed price write rolls back price history');
            historyCheck(count($s->rows('SELECT rowid FROM '.$s->table('resolution').' WHERE entity='.$entity)) === 1 && $s->one('resource', (int) $r['rowid'])['projection_status'] === 'conflict', 'failed product resolution leaves open conflict and no new resolution');
        }
        // A swallowed native price-log failure must also fail when the last
        // stored price already equals the selected source.
        $payload['type']='SERVICE'; $payload['price']['netPrice']=23;
        $r=$s->put($run,'articles',$id,json_encode($payload),1);
        $s->query('UPDATE '.MAIN_DB_PREFIX.'product SET description=\'local price review\' WHERE rowid='.(int)$m['object_id']);
        historyCheck($projector->project($r,$user)==='conflict','same-price local review fixture');
        $before=$projector->snapshot('product',(int)$m['object_id']); $baseline=$s->mapping((int)$r['rowid']);
        $s->db=new class($db) {
            public bool $hit=false;
            public function __construct(private $real) {}
            public function __get($name) { return $this->real->$name; }
            public function __set($name,$value) { $this->real->$name=$value; }
            public function __call($name,$args) { return $this->real->$name(...$args); }
            public function query($sql) {
                if (str_contains($sql,'INSERT INTO '.MAIN_DB_PREFIX.'product_price')) { $this->hit=true; return false; }
                return $this->real->query($sql);
            }
        };
        $faultDb=$s->db;
        ob_start();
        try { $projector->resolve((int)$r['rowid'],'Lexware',$r['checksum'],$user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int)$r['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0)); throw new RuntimeException('same-price log failure accepted'); }
        catch (RuntimeException $e) { historyCheck($faultDb->hit && $e->getMessage()==='NATIVE_PRODUCT_PRICE_READBACK_FAILED','swallowed same-price native history failure must abort resolution'); }
        finally { ob_end_clean(); $s->db=$db; }
        historyCheck($projector->snapshot('product',(int)$m['object_id'])===$before && $s->mapping((int)$r['rowid'])===$baseline,'same-price history failure rolls back local state and baseline');
    }
    if ($case === 'csrf-forced') {
        $bootstrap = file_get_contents($root.'/hwoslexware/lib/bootstrap.php');
        historyCheck(strpos($bootstrap, "define('CSRFCHECK_WITH_TOKEN'") !== false && strpos($bootstrap, "define('CSRFCHECK_WITH_TOKEN'") < strpos($bootstrap, 'require_once $main'), 'native CSRF must be forced before main even with global setting disabled');
    }
    if ($case === 'ordinary-rollback') {
        $projector = new LexwareProjection($s);
        $payload = ['company'=>['name'=>$id,'contactPersons'=>[['lastName'=>'Injected failure']]],'roles'=>['customer'=>[]]];
        $c = $s->put($run, 'contacts', $id, json_encode($payload), (int) $user->id);
        $s->db = new class($db) {
            public function __construct(private $real) {}
            public function __call($name, $args) { return $this->real->$name(...$args); }
            public function __get($name) { return $this->real->$name; }
            public function __set($name, $value) { $this->real->$name = $value; }
            public int $nativeWrites = 0;
            public function query($sql) {
                if (preg_match('/^(?:INSERT INTO|UPDATE)\s+\S*(?:societe|socpeople)\b/i', $sql)) { $this->nativeWrites++; }
                return str_contains($sql, "'lexware.projection'") ? false : $this->real->query($sql);
            }
        };
        $faultDb = $s->db;
        try { historyCheck($projector->project($c, $user) === 'conflict', 'native fault becomes visible conflict'); }
        finally { $s->db = $db; }
        $partial = $s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'societe WHERE entity='.$entity);
        foreach ($partial as $v) { $native[] = ['object_type'=>'thirdparty','object_id'=>$v['rowid']]; }
        historyCheck(!$partial && !$s->mapping((int) $c['rowid']), 'outer owner must roll back native creation and mapping before persisting conflict');
        historyCheck($s->one('resource', (int) $c['rowid'])['projection_status'] === 'conflict', 'conflict survives separate transaction');
        historyCheck(!$s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'socpeople WHERE entity='.$entity), 'failed creation leaves no native contact-person writes');
        historyCheck($projector->project($c, $user) === 'new', 'existing-update rollback fixture');
        $existingMap = $s->mapping((int) $c['rowid']); $native[] = $existingMap;
        $beforeNative = $projector->snapshot('thirdparty', (int) $existingMap['object_id']);
        $payload['version'] = 2; // Metadata-only updates retain ordinary update behavior.
        $faultDb->nativeWrites = 0;
        $changed = $s->put($run, 'contacts', $id, json_encode($payload), 1);
        $s->db = $faultDb;
        try { historyCheck($projector->project($changed, $user) === 'conflict', 'post-update audit fault becomes conflict'); }
        finally { $s->db = $db; }
        historyCheck($faultDb->nativeWrites >= 2, 'fault occurs after actual native parent/person update statements');
        historyCheck($projector->snapshot('thirdparty', (int) $existingMap['object_id']) === $beforeNative, 'outer owner rolls back native parent/person update');
        historyCheck($s->mapping((int) $c['rowid']) === $existingMap, 'outer owner rolls back mapping update');
        historyCheck($s->one('resource', (int) $c['rowid'])['checksum'] === $changed['checksum'], 'source mirror remains current after projection rollback');

    }
    if ($case === 'locked-current-view') {
        $projector = new LexwareProjection($s);
        $c = $s->put($run, 'contacts', $id, json_encode(['company'=>['name'=>$id],'roles'=>['customer'=>[]]]), (int) $user->id);
        historyCheck($projector->project($c, $user) === 'new', 'current-view fixture');
        $m = $s->mapping((int) $c['rowid']); $native[] = $m;
        $other = getDoliDBInstance($conf->db->type, $conf->db->host, $conf->db->user, $dolibarr_main_db_pass, $conf->db->name, $conf->db->port);
        $s->db = new class($db, $other, $entity, (int) $m['object_id']) {
            public bool $injected = false;
            public string $message = 'committed before lock';
            public function __construct(private $real, private $other, private int $entity, private int $id) {}
            public function __get($name) { return $this->real->$name; }
            public function __set($name, $value) { $this->real->$name = $value; }
            public function __call($name, $args) { return $this->real->$name(...$args); }
            public function query($sql) {
                historyCheck(!str_contains($sql, 'READ COMMITTED'), 'lock/read-view regression must run under repeatable read');
                if (!$this->injected && str_contains($sql, 'societe WHERE') && str_contains($sql, 'FOR UPDATE')) {
                    $this->injected = true;
                    if (!$this->other->query('UPDATE '.MAIN_DB_PREFIX.'societe SET note_private="'.$this->message.'" WHERE entity='.$this->entity.' AND rowid='.$this->id)) { throw new RuntimeException('fixture concurrent write failed'); }
                }
                return $this->real->query($sql);
            }
        };
        try {
            historyCheck($projector->project($c, $user) === 'conflict', 'edit committed before native lock must be detected under repeatable read');
            $s->db->injected = false; $s->db->message = 'committed before resolution lock';
            $projector->resolve((int) $c['rowid'], 'Lexware', $c['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $c['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0));
            $prior = $s->rows('SELECT prior_native_json FROM '.$s->table('resolution').' WHERE entity='.$entity)[0]['prior_native_json'];
            historyCheck(str_contains($prior, 'committed before resolution lock'), 'explicit resolution snapshots actually locked latest state');
        } finally { $s->db = $db; $other->close(); }
        historyCheck(str_contains($projector->snapshot('thirdparty', (int) $m['object_id']), 'committed before resolution lock'), 'latest edit retained');
    }
    if ($case === 'stale-full') {
        $s->query('INSERT INTO '.$s->table('run').' (entity,organization_id,mode,status,fk_user,context,checkpoint_json,stats_json,date_creation) VALUES ('.$entity.','.$s->q(LexwareClient::ORGANIZATION).",'refresh','complete',1,'history-test','{}','{}',NOW())");
        $newRun = (int) $db->last_insert_id($s->table('run')); $runs[] = $newRun;
        $fresh = $s->put($newRun, 'articles', $id, $raw2, (int) $user->id);
        $cp = ['authoritative'=>true,'coverage'=>['articles'=>true],'queue'=>[], 'seen'=>[]];
        $s->query('UPDATE '.$s->table('run')." SET status='complete',checkpoint_json=".$s->q(json_encode($cp)).' WHERE rowid='.$run);
        $s->reconcile($run, (int) $user->id);
        historyCheck((int) $s->find('articles', $id)['missing'] === 0, 'older full cannot tombstone newer refresh observation');
        $s->put($run, 'articles', $id, $raw1, (int) $user->id);
        historyCheck($s->find('articles', $id)['checksum'] === $fresh['checksum'], 'older batch cannot overwrite newer observation');
        $s->query('UPDATE '.$s->table('run')." SET status='pending' WHERE rowid=".$run);
        $api = new LexwareClient('fixture', fn($path)=>$path === '/v1/profile' ? [200,json_encode(['organizationId'=>LexwareClient::ORGANIZATION,'companyName'=>'Holger Testzentrum']),[]] : throw new RuntimeException('stale batch must fetch no source'), fn()=>10000.0, fn()=>null);
        try { (new LexwareSync($s, $api))->batch($run, $user); throw new RuntimeException('superseded full batch continued'); }
        catch (DomainException $e) { historyCheck($e->getMessage() === 'LEXWARE_RUN_SUPERSEDED', 'newer run fences older full batches including file work'); }

    }
    if ($case === 'retry-lock') {
        $other = getDoliDBInstance($conf->db->type, $conf->db->host, $conf->db->user, $dolibarr_main_db_pass, $conf->db->name, $conf->db->port);
        $name = $s->q('trafopilot-lexware-'.LexwareClient::ORGANIZATION);
        historyCheck((int) $other->fetch_array($other->query('SELECT GET_LOCK('.$name.',0) AS acquired'))['acquired'] === 1, 'batch organization lock fixture');
        $s->query('UPDATE '.$s->table('run')." SET checkpoint_json='{\"queue\":[]}' WHERE rowid=".$run);
        $beforeRun = $s->one('run', $run);
        try {
            try { (new LexwareSync($s, new LexwareClient('fixture', fn()=>throw new RuntimeException('retry must not call API'))))->retry($run, $user); throw new RuntimeException('retry ignored organization lock'); }
            catch (RuntimeException $e) { historyCheck($e->getMessage() === 'LEXWARE_RUN_ALREADY_ACTIVE', 'retry and batch must share organization lock'); }
            historyCheck($s->one('run', $run) === $beforeRun, 'racing retry cannot overwrite checkpoint');
        } finally { $other->query('SELECT RELEASE_LOCK('.$name.')'); $other->close(); }
    }
    if ($case === 'relationship-positive') {
        $s->query('INSERT INTO '.$s->table('relation').' (entity,fk_resource,related_id,related_type) VALUES ('.$entity.','.(int) $r['rowid'].",'old-related','dunnings')");
        $rel = (int) $db->last_insert_id($s->table('relation'));
        $s->stateEvent($r, $run, 'removed', (int) $user->id, $rel);
        $sync = new LexwareSync($s, new LexwareClient('fixture', fn()=>throw new RuntimeException('no live request')));
        $children = new ReflectionMethod($sync, 'children');
        $queue = []; $snapshots = [];
        $args = [['type'=>'invoices','id'=>$id], ['relatedVouchers'=>[['id'=>'old-related','voucherType'=>'dunning']]], &$queue, (int) $r['rowid'], false, &$snapshots, $run, (int) $user->id];
        $children->invokeArgs($sync, $args);
        historyCheck($s->relationState($rel) === 'reappeared', 'positive incremental relationship observation reactivates immediately');
        $events = $s->rows('SELECT * FROM '.$s->table('state_event').' WHERE entity='.$entity.' AND fk_relation='.$rel.' ORDER BY rowid');
        historyCheck(count($events) === 2 && (int) $events[1]['fk_run'] === $run, 'relationship reappearance has audit provenance');
    }
    if ($case === 'contradictory-pagination') {
        $sync = new LexwareSync($s, new LexwareClient('fixture', fn()=>throw new RuntimeException('no live request')));
        $page = new ReflectionMethod($sync, 'page');
        $task = ['type'=>'articles','id'=>'page-0','path'=>'/v1/articles?page=0&size=25','priority'=>1];
        foreach ([['content'=>[],'last'=>true,'number'=>0,'totalPages'=>2,'totalElements'=>42], ['content'=>[],'last'=>false,'number'=>0,'totalPages'=>0,'totalElements'=>0], ['content'=>[],'last'=>true,'number'=>0,'totalPages'=>1,'totalElements'=>1], ['content'=>[],'last'=>true,'number'=>0,'totalPages'=>'1','totalElements'=>0], ['content'=>[],'last'=>true,'number'=>0,'totalPages'=>-1,'totalElements'=>-1]] as $payload) {
            $queue = []; $seen = []; $args = [$task, $payload, &$queue, &$seen];
            try { $page->invokeArgs($sync, $args); throw new RuntimeException('contradictory metadata accepted'); }
            catch (RuntimeException $e) { historyCheck($e->getMessage() === 'PAGINATION_CONTRACT_INVALID', 'contradictory typed metadata cannot establish authoritative completion'); }
        }
    }
    if ($case === 'transaction-return-values') {
        foreach (['begin','commit'] as $fault) {
            $beforeResource = $s->one('resource', (int) $r['rowid']);
            $s->db = new class($db, $fault) {
                public function __construct(private $real, private string $fault) {}
                public function __call($name, $args) { return $name === $this->fault ? 0 : $this->real->$name(...$args); }
            };
            try {
                try { $s->put($run, 'articles', $id, $raw2, (int) $user->id); throw new RuntimeException('failed transaction return ignored'); }
                catch (RuntimeException $e) { historyCheck($e->getMessage() === 'LEXWARE_TRANSACTION_FAILED', 'transaction '.$fault.' failure must abort writes'); }
            } finally { $s->db = $db; }
            historyCheck($s->one('resource', (int) $r['rowid']) === $beforeResource, 'transaction failure preserves current row');
        }
        foreach (['LexwareStore.php','LexwareSync.php'] as $file) {
            historyCheck(!str_contains(file_get_contents($root.'/hwoslexware/class/'.$file), 'INSERT IGNORE'), 'immutable dedupe must not suppress non-duplicate database errors');
        }
    }
    if ($case === 'related-dunning-presence') {
        $dunningId = '00000000-0000-0000-0000-000000000002';
        $invoiceId = '00000000-0000-0000-0000-000000000003';
        $dunning = $s->put($run, 'dunnings', $dunningId, json_encode(['id'=>$dunningId,'voucherStatus'=>'draft']), 1);
        $s->query('UPDATE '.$s->table('resource').' SET missing=1 WHERE rowid='.(int) $dunning['rowid']);
        $queue = [];
        foreach ([['invoices',$invoiceId,'resource','/v1/invoices/'.$invoiceId,1],['voucherlist','page-0','page','/v1/voucherlist?page=0&size=25',40],['articles','page-0','page','/v1/articles?page=0&size=25',60]] as [$type,$remote,$kind,$path,$priority]) { $queue[] = ['type'=>$type,'id'=>$remote,'kind'=>$kind,'path'=>$path,'priority'=>$priority,'state'=>'pending','accept'=>'application/json']; }
        $cp = ['queue'=>$queue,'authoritative'=>true,'seen'=>[],'coverage'=>[]];
        $s->query('UPDATE '.$s->table('run').' SET checkpoint_json='.$s->q(json_encode($cp)).' WHERE rowid='.$run);
        $api = new LexwareClient('fixture', function ($path) use ($dunningId, $invoiceId) {
            if ($path === '/v1/profile') { $p = ['organizationId'=>LexwareClient::ORGANIZATION,'companyName'=>'Holger Testzentrum']; }
            elseif (str_contains($path, '?page=')) { $p = ['content'=>[],'number'=>0,'last'=>true,'totalPages'=>0,'totalElements'=>0]; }
            elseif ($path === '/v1/invoices/'.$invoiceId) { $p = ['voucherStatus'=>'draft','relatedVouchers'=>[['id'=>$dunningId,'voucherType'=>'dunning']]]; }
            else { $p = ['id'=>$dunningId,'voucherStatus'=>'draft']; }
            return [200,json_encode($p),[]];
        }, fn()=>10000.0, fn()=>null);
        $done = (new LexwareSync($s, $api))->batch($run, $user, 25);
        historyCheck($done['status'] === 'complete', 'related-dunning full fixture completes');
        $finished = json_decode($done['checkpoint_json'], true);
        historyCheck(!isset($finished['coverage']['dunnings']), 'positive dunning detail grants no absence authority');
        historyCheck((int) $s->find('dunnings', $dunningId)['missing'] === 0, 'related fetched dunning reappears and stays active');
        historyCheck((int) $s->find('articles', $id)['missing'] === 1, 'supported missing articles tombstone in same scan');
        historyCheck(count($s->rows('SELECT rowid FROM '.$s->table('state_event').' WHERE entity='.$entity.' AND fk_resource='.(int) $dunning['rowid']." AND state='reappeared'")) === 1, 'positive detail reappearance audited once');
    }
    if ($case === 'pagination-total-drift') {
        $sync = new LexwareSync($s, new LexwareClient('fixture', fn()=>throw new RuntimeException('no live request')));
        $page = new ReflectionMethod($sync, 'page'); $queue = []; $seen = []; $pagination = [];
        $items = array_map(fn($i)=>['id'=>sprintf('00000000-0000-0000-0000-%012d',$i)], range(1,27));
        $task = ['type'=>'articles','id'=>'page-0','path'=>'/v1/articles?page=0&size=25','priority'=>1];
        $first = ['content'=>array_slice($items,0,25),'last'=>false,'number'=>0,'totalPages'=>2,'totalElements'=>26];
        $args = [$task,$first,&$queue,&$seen,&$pagination]; $page->invokeArgs($sync,$args);
        $task['id'] = 'page-1'; $task['path'] = '/v1/articles?page=1&size=25';
        $second = ['content'=>array_slice($items,25),'last'=>true,'number'=>1,'totalPages'=>2,'totalElements'=>27];
        $args = [$task,$second,&$queue,&$seen,&$pagination];
        try { $page->invokeArgs($sync,$args); throw new RuntimeException('changed page totals accepted'); }
        catch (RuntimeException $e) { historyCheck($e->getMessage() === 'PAGINATION_CONTRACT_INVALID', 'page totals changing across scan cannot establish absence authority'); }
    }
    if ($case === 'unsupported-relationship-absence') {
        $owner = $s->put($run, 'dunnings', $id, $raw1, 1);
        $s->observeRelation((int) $owner['rowid'], 'retained-related', 'invoices', $run, 1);
        $relation = (int) $s->rows('SELECT rowid FROM '.$s->table('relation').' WHERE entity='.$entity.' AND fk_resource='.(int) $owner['rowid'])[0]['rowid'];
        $cp = ['authoritative'=>true,'coverage'=>['articles'=>true],'queue'=>[],'seen'=>['dunnings'=>[$id=>true]],'relations'=>[$owner['rowid']=>[]]];
        $s->query('UPDATE '.$s->table('run')." SET status='complete',checkpoint_json=".$s->q(json_encode($cp)).' WHERE rowid='.$run);
        $s->reconcile($run, 1);
        historyCheck($s->relationState($relation) === 'active', 'unsupported owner detail must not create relationship absence authority');
        $cp['coverage']['dunnings'] = true;
        $s->query('UPDATE '.$s->table('run').' SET checkpoint_json='.$s->q(json_encode($cp)).' WHERE rowid='.$run);
        $s->reconcile($run, 1);
        historyCheck($s->relationState($relation) === 'active', 'unsupported resource type cannot be granted absence authority by checkpoint flag');
    }
    if ($case === 'source-contact-product-fields') {
        $projector = new LexwareProjection($s);
        $sources = [
            ['contacts', ['company'=>['name'=>$id],'roles'=>['customer'=>[]]], ['company'=>['name'=>$id.' revised']]],
            ['articles', ['title'=>$id,'articleNumber'=>$id,'price'=>['netPrice'=>10,'taxRate'=>19]], ['title'=>$id.' revised','price'=>['netPrice'=>11,'taxRate'=>19]]]
        ];
        foreach ($sources as [$type,$payload,$delta]) {
            $remote = $id.'-'.substr($type,0,3);
            $source = $s->put($run, $type, $remote, json_encode($payload), 1);
            historyCheck($projector->project($source, $user) === 'new', 'owned source-field fixture '.$type);
            $m = $s->mapping((int) $source['rowid']); $native[] = $m;
            $beforeNative = $projector->snapshot($m['object_type'], (int) $m['object_id']);
            $changed = $s->put($run, $type, $remote, json_encode(array_replace($payload,$delta)), 1);
            historyCheck($projector->project($changed, $user) === 'conflict', 'material source change cannot silently rewrite owned '.$type);
            historyCheck($projector->snapshot($m['object_type'], (int) $m['object_id']) === $beforeNative, 'material source conflict preserves '.$type);
            $projector->resolve((int) $source['rowid'], 'Lexware', $changed['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $source['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0));
            historyCheck($projector->project($changed, $user) === 'unchanged', 'explicit source choice updates owned '.$type);
            if ($type === 'articles') {
                $badPayload = array_replace($payload,$delta); $badPayload['price']['taxRate'] = 1000;
                $bad = $s->put($run, $type, $remote, json_encode($badPayload), 1);
                historyCheck($projector->project($bad, $user) === 'conflict', 'unsupported existing product tax is a conflict');
                $beforeBadTax = $projector->snapshot($m['object_type'], (int) $m['object_id']);
                try { $projector->resolve((int) $source['rowid'], 'Lexware', $bad['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $source['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0)); throw new RuntimeException('unsupported product tax accepted'); }
                catch (DomainException $e) { historyCheck($e->getMessage() === 'TAX_MAPPING_REQUIRED', 'product tax is validated before explicit native application'); }
                historyCheck($projector->snapshot($m['object_type'], (int) $m['object_id']) === $beforeBadTax, 'unsupported product tax preserves native state');
            }

        }
    }
    if ($case === 'payload') {
        $s->put($run, 'articles', $id, $raw2, (int) $user->id);
        $s->put($run, 'articles', $id, $raw2, (int) $user->id);
        $versions = $s->rows('SELECT payload_json FROM '.$s->table('payload_version').' WHERE entity='.$entity.' AND fk_resource='.(int) $r['rowid'].' ORDER BY rowid');
        historyCheck(array_column($versions, 'payload_json') === [$raw1, $raw2], 'every distinct raw version immutable and repeats deduped');
    }
    if ($case === 'raw-distinct') {
        $different = str_replace('9223372036854775808123', '"9223372036854775808123"', $raw1);
        $s->put($run, 'articles', $id, $different, (int) $user->id);
        $versions = $s->rows('SELECT payload_json FROM '.$s->table('payload_version').' WHERE entity='.$entity.' AND fk_resource='.(int) $r['rowid'].' ORDER BY rowid');
        historyCheck(array_column($versions, 'payload_json') === [$raw1, $different], 'raw numeric/string distinction must remain recoverable despite semantic checksum collision');
    }
    if ($case === 'file') {
        $sync = new LexwareSync($s, new LexwareClient('fixture', fn()=>[200,'{}',[]]));
        $method = new ReflectionMethod($sync, 'file'); $method->setAccessible(true);
        $task = ['owner'=>$r['rowid'],'id'=>'same-file','accept'=>'application/pdf'];
        foreach (['%PDF-first','%PDF-second','%PDF-second'] as $body) { $method->invoke($sync, $task, ['body'=>$body,'headers'=>['content-type'=>'application/pdf']]); }
        $versions = $s->rows('SELECT content_blob FROM '.$s->table('file_version').' WHERE entity='.$entity.' AND fk_resource='.(int) $r['rowid'].' ORDER BY rowid');
        historyCheck(array_column($versions, 'content_blob') === ['%PDF-first','%PDF-second'], 'immutable file versions and checksum dedupe');
        $current = $s->rows('SELECT content_blob FROM '.$s->table('file').' WHERE entity='.$entity.' AND fk_resource='.(int) $r['rowid']);
        historyCheck($current[0]['content_blob'] === '%PDF-second', 'current file lookup');
    }
    if ($case === 'file-audit') {
        $sync = new LexwareSync($s, new LexwareClient('fixture', fn()=>[200,'{}',[]]));
        $file = new ReflectionMethod($sync, 'file'); $file->setAccessible(true);
        $file->invoke($sync, ['owner'=>$r['rowid'],'id'=>'audit-file','accept'=>'application/pdf'], ['body'=>'%PDF-first','headers'=>[]]);
        $events = $s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'hwoscore_audit_event WHERE entity='.$entity." AND event_type='lexware.file'");
        historyCheck(count($events) === 1, 'critical file write has audit event');
    }
    if ($case === 'file-fetch') {
        $sync = new LexwareSync($s, new LexwareClient('fixture', fn()=>[200,'{}',[]]));
        $file = new ReflectionMethod($sync, 'file'); $file->setAccessible(true);
        $file->invoke($sync, ['owner'=>$r['rowid'],'id'=>'same-file','accept'=>'application/pdf'], ['body'=>'%PDF-first','headers'=>[]]);
        $enqueue = new ReflectionMethod($sync, 'enqueueFile'); $enqueue->setAccessible(true);
        $queue = []; $args = [&$queue, '/v1/invoices/00000000-0000-0000-0000-000000000001/file', 'same-file', 'application/pdf', (int) $r['rowid'], false];
        $enqueue->invokeArgs($sync, $args);
        historyCheck(count($queue) === 1, 'same remote identity must be fetched to detect changed bytes');
    }
    if ($case === 'file-limit') {
        $sync = new LexwareSync($s, new LexwareClient('fixture', fn()=>[200,'{}',[]]));
        $file = new ReflectionMethod($sync, 'file'); $file->setAccessible(true);
        try { $file->invoke($sync, ['owner'=>$r['rowid'],'id'=>'limit-file','accept'=>'application/pdf'], ['body'=>str_repeat('x',32*1024*1024+1),'headers'=>[]]); throw new RuntimeException('oversize file accepted'); }
        catch (RuntimeException $e) { historyCheck($e->getMessage() === 'FILE_TOO_LARGE', '32 MiB limit unchanged'); }
        historyCheck(!$s->rows('SELECT rowid FROM '.$s->table('file_version').' WHERE entity='.$entity), 'oversize rejection creates no file version');
    }
    if (in_array($case, ['file-rollback','payload-rollback'], true)) {
        $original = $s->one('resource', (int) $r['rowid']);
        $s->db = new class($db) {
            public function __construct(private $real) {}
            public function __call($name, $arguments) { return $this->real->$name(...$arguments); }
            public function query($sql) { return str_contains($sql, 'hwoscore_audit_event') ? false : $this->real->query($sql); }
        };
        try {
            if ($case === 'payload-rollback') { $s->put($run, 'articles', $id, $raw2, (int) $user->id); }
            else {
                $sync = new LexwareSync($s, new LexwareClient('fixture', fn()=>[200,'{}',[]]));
                $file = new ReflectionMethod($sync, 'file'); $file->setAccessible(true);
                $file->invoke($sync, ['owner'=>$r['rowid'],'id'=>'rollback-file','accept'=>'application/pdf'], ['body'=>'%PDF-first','headers'=>[]]);
            }
            throw new RuntimeException('audit failure ignored');
        } catch (RuntimeException $e) { historyCheck($e->getMessage() === 'LEXWARE_DATABASE_ERROR', 'expected injected audit failure'); }
        finally { $s->db = $db; }
        historyCheck($s->one('resource', (int) $r['rowid']) === $original, 'raw current rolled back');
        historyCheck(count($s->rows('SELECT rowid FROM '.$s->table('payload_version').' WHERE entity='.$entity)) === 1, 'failed write adds no raw version');
        historyCheck(!$s->rows('SELECT rowid FROM '.$s->table('file_version').' WHERE entity='.$entity), 'failed file write adds no immutable version');
        historyCheck(!$s->rows('SELECT rowid FROM '.$s->table('file').' WHERE entity='.$entity), 'failed file write adds no current cache');
    }
    if ($case === 'retry-rollback') {
        $s->query('UPDATE '.$s->table('run')." SET status='errors',checkpoint_json='{".'"queue":[{"state":"error"}]'."}' WHERE rowid=".$run);
        $beforeRun = $s->one('run', $run);
        $s->db = new class($db) {
            public function __construct(private $real) {}
            public function __call($name, $arguments) { return $this->real->$name(...$arguments); }
            public function query($sql) { return str_contains($sql, 'hwoscore_audit_event') ? false : $this->real->query($sql); }
        };
        try {
            (new LexwareSync($s, new LexwareClient('fixture', fn()=>[200,'{}',[]])))->retry($run, $user);
            throw new RuntimeException('audit failure ignored');
        } catch (RuntimeException $e) { historyCheck($e->getMessage() === 'LEXWARE_DATABASE_ERROR', 'expected injected audit failure'); }
        finally { $s->db = $db; }
        historyCheck($s->one('run', $run) === $beforeRun, 'retry rolls back checkpoint when audit fails');
    }
    if (in_array($case, ['removal','removed-dunning','dunning-positive'], true)) {
        if (in_array($case, ['removed-dunning','dunning-positive'], true)) { $r = $s->put($run, 'dunnings', $id, $raw1, (int) $user->id); $ids[] = (int) $r['rowid']; }
        // Pending, failed and incremental runs cannot tombstone an unseen resource.
        foreach (['pending','errors','complete'] as $status) {
            $s->query('UPDATE '.$s->table('run').' SET status='.$s->q($status).",mode='incremental' WHERE rowid=".$run);
            $s->reconcile($run, (int) $user->id);
            historyCheck((int) $s->one('resource', (int) $r['rowid'])['missing'] === 0, 'non-authoritative run retains active state');
        }
        $s->query('UPDATE '.$s->table('run')." SET mode='full',status='complete',checkpoint_json='{".'"authoritative":true,"coverage":{"articles":true},"queue":[],"seen":[]'."}' WHERE rowid=".$run);
        $s->reconcile($run, (int) $user->id);
        if ($case !== 'removal') {
            historyCheck((int) $s->one('resource', (int) $r['rowid'])['missing'] === 0, 'dunnings have no authoritative public absence enumeration');
            historyCheck($s->rows($protectedSql) === $protectedBefore, 'protected tables unchanged');
            echo 'PASS: history '.$case."\n";
            continue;
        }
        historyCheck((int) $s->one('resource', (int) $r['rowid'])['missing'] === 1, 'complete full snapshot tombstones absent resource');
        $states = $s->rows('SELECT * FROM '.$s->table('state_event').' WHERE entity='.$entity.' AND fk_resource='.(int) $r['rowid']);
        historyCheck(count($states) === 1 && (int) $states[0]['fk_run'] === $run && $states[0]['state'] === 'removed', 'removal run/time provenance');
        $s->reconcile($run, (int) $user->id);
        historyCheck(count($s->rows('SELECT * FROM '.$s->table('state_event').' WHERE entity='.$entity.' AND fk_resource='.(int) $r['rowid'])) === 1, 'removal dedupe');
    }
    if ($case === 'partial') {
        $remote = '00000000-0000-0000-0000-000000000001';
        $r = $s->put($run, 'articles', $remote, $raw1, (int) $user->id); $ids[] = (int) $r['rowid'];
        $cp = ['authoritative'=>true,'queue'=>[['path'=>'/v1/articles/'.$remote,'accept'=>'application/json','type'=>'articles','id'=>$remote,'kind'=>'resource','priority'=>1,'state'=>'pending']], 'seen'=>[]];
        $s->query('UPDATE '.$s->table('run').' SET checkpoint_json='.$s->q(json_encode($cp)).' WHERE rowid='.$run);
        $api = new LexwareClient('fixture', fn($path)=>$path === '/v1/profile' ? [200,json_encode(['organizationId'=>LexwareClient::ORGANIZATION,'companyName'=>'Holger Testzentrum']),[]] : [404,'',[]], fn()=>10000.0, fn()=>null);
        $sync = new LexwareSync($s, $api); $result = $sync->batch($run, $user);
        historyCheck($result['status'] === 'errors', 'failed detail is incomplete');
        historyCheck((int) $s->one('resource', (int) $r['rowid'])['missing'] === 0, '404 in failed full scan must not tombstone');
    }
    if ($case === 'reappearance') {
        $s->query('UPDATE '.$s->table('resource').' SET missing=1 WHERE rowid='.(int) $r['rowid']);
        $s->put($run, 'articles', $id, $raw1, (int) $user->id);
        $s->put($run, 'articles', $id, $raw1, (int) $user->id);
        historyCheck((int) $s->find('articles', $id)['missing'] === 0, 'reappearance clears removed state');
        $events = $s->rows('SELECT * FROM '.$s->table('state_event').' WHERE entity='.$entity.' AND fk_resource='.(int) $r['rowid']);
        historyCheck(count($events) === 1 && $events[0]['state'] === 'reappeared' && (int) $events[0]['fk_run'] === $run, 'reappearance audited once with provenance');
    }
    if ($case === 'legacy-snapshot') {
        $cp = ['queue'=>[], 'seen'=>[]];
        $s->query('UPDATE '.$s->table('run')." SET status='complete',checkpoint_json=".$s->q(json_encode($cp)).' WHERE rowid='.$run);
        $s->reconcile($run, (int) $user->id);
        historyCheck((int) $s->find('articles', $id)['missing'] === 0, 'legacy resumed checkpoints are not authoritative absence');
    }
    if (in_array($case, ['full-snapshot','invalid-page','invalid-page-object'], true)) {
        $cp = ['authoritative'=>true,'queue'=>[['path'=>'/v1/articles?page=0&size=25','accept'=>'application/json','type'=>'articles','id'=>'page-0','kind'=>'page','priority'=>1,'state'=>'pending']], 'seen'=>[]];
        $s->query('UPDATE '.$s->table('run').' SET checkpoint_json='.$s->q(json_encode($cp)).' WHERE rowid='.$run);
        $api = new LexwareClient('fixture', fn($path)=>[200,json_encode($path === '/v1/profile' ? ['organizationId'=>LexwareClient::ORGANIZATION,'companyName'=>'Holger Testzentrum'] : ['content'=>$case === 'invalid-page-object' ? new stdClass() : [],'last'=>$case === 'invalid-page' ? 'false' : true]),[]], fn()=>10000.0, fn()=>null);
        $result = (new LexwareSync($s, $api))->batch($run, $user);
        if (in_array($case, ['invalid-page','invalid-page-object'], true)) {
            historyCheck($result['status'] === 'errors', 'invalid pagination cannot become authoritative success');
            historyCheck((int) $s->find('articles', $id)['missing'] === 0, 'invalid pagination never tombstones');
        } else {
        historyCheck($result['status'] === 'complete', 'successful full completes');
        historyCheck((int) $s->find('articles', $id)['missing'] === 1, 'completed full uses authoritative membership rather than previous fetch run');
        }
    }
    if ($case === 'relationship') {
        $s->query('INSERT INTO '.$s->table('relation').' (entity,fk_resource,related_id,related_type) VALUES ('.$entity.','.(int) $r['rowid'].",'old-related','invoices')");
        $relation = (int) $db->last_insert_id($s->table('relation'));
        $cp = ['authoritative'=>true,'coverage'=>['articles'=>true],'queue'=>[], 'seen'=>['articles'=>[$id=>true]], 'relations'=>[$r['rowid']=>[]]];
        $s->query('UPDATE '.$s->table('run')." SET status='complete',checkpoint_json=".$s->q(json_encode($cp)).' WHERE rowid='.$run);
        $s->reconcile($run, (int) $user->id);
        $events = $s->rows('SELECT * FROM '.$s->table('state_event').' WHERE entity='.$entity.' AND fk_relation='.$relation);
        historyCheck(count($events) === 1 && $events[0]['state'] === 'removed', 'absent relationship is retained and tombstoned');
        historyCheck($s->one('relation', $relation) !== null, 'relationship history never deleted');
        $cp['relations'][$r['rowid']] = ['invoices|old-related'=>true];
        $s->query('UPDATE '.$s->table('run').' SET checkpoint_json='.$s->q(json_encode($cp)).' WHERE rowid='.$run);
        $s->reconcile($run, (int) $user->id);
        $events = $s->rows('SELECT * FROM '.$s->table('state_event').' WHERE entity='.$entity.' AND fk_relation='.$relation.' ORDER BY rowid');
        historyCheck(count($events) === 2 && $events[1]['state'] === 'reappeared', 'relationship reappearance audited');
    }
    if ($case === 'contact-resolution') {
        $projector = new LexwareProjection($s);
        $contactPayload = ['company'=>['name'=>$id,'contactPersons'=>[['firstName'=>'First','lastName'=>'Fixture']]],'roles'=>['customer'=>['number'=>1]]];
        $contact = $s->put($run, 'contacts', $id, json_encode($contactPayload), (int) $user->id); $ids[] = (int) $contact['rowid'];
        historyCheck($projector->project($contact, $user) === 'new', 'contact with person fixture'); $m = $s->mapping((int) $contact['rowid']); $native[] = $m;
        $contactPayload['company']['contactPersons'] = [];
        $contact = $s->put($run, 'contacts', $id, json_encode($contactPayload), (int) $user->id);
        historyCheck($projector->project($contact, $user) === 'conflict', 'contact person removal requires review');
        $projector->resolve((int) $contact['rowid'], 'Lexware', $contact['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $contact['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0));
        $people = $s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'socpeople WHERE entity='.$entity.' AND fk_soc='.(int) $m['object_id']);
        historyCheck(count($people) === 1 && (int) $people[0]['statut'] === 0, 'explicit contact resolution retains removed native person inactive');
        $contactPayload['version'] = 2;
        $contact = $s->put($run, 'contacts', $id, json_encode($contactPayload), 1);
        historyCheck($projector->project($contact, $user) === 'update', 'metadata update retains inactive historical persons without structural conflict');
        $retained = $s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'socpeople WHERE entity='.$entity.' AND fk_soc='.(int) $m['object_id']);
        historyCheck($retained === $people, 'metadata update does not reactivate or rewrite historical persons');

    }
    if (str_starts_with($case, 'conflict')) {
        $projector = new LexwareProjection($s);
        $contactId = 'history-c-'.bin2hex(random_bytes(8));
        $contact = $s->put($run, 'contacts', $contactId, json_encode(['company'=>['name'=>$contactId],'roles'=>['customer'=>['number'=>1]]]), (int) $user->id); $ids[] = (int) $contact['rowid'];
        historyCheck($projector->project($contact, $user) === 'new', 'fixture native contact'); $native[] = $s->mapping((int) $contact['rowid']);
        $payload = ['id'=>$id,'version'=>1,'voucherNumber'=>$id,'voucherStatus'=>'open','voucherDate'=>'2020-01-01','address'=>['contactId'=>$contactId], 'lineItems'=>[['name'=>'first','type'=>'custom','quantity'=>1,'unitPrice'=>['netAmount'=>10,'taxRatePercentage'=>19]]], 'totalPrice'=>['totalGrossAmount'=>11.9]];
        $document = $s->put($run, 'invoices', $id, json_encode($payload), (int) $user->id); $ids[] = (int) $document['rowid'];
        historyCheck($projector->project($document, $user) === 'new', 'fixture native invoice');
        $mapping = $s->mapping((int) $document['rowid']); $native[] = $mapping;
        $before = $projector->snapshot('invoice', (int) $mapping['object_id']);
        if ($case === 'conflict-dependency-remap') {
            $alternate = $projector->object('thirdparty'); $alternate->name = $id.' alternate'; $alternate->client = 1; $alternate->code_client = '-1';
            historyCheck($alternate->create($user, 1) > 0, 'alternate contact fixture');
            $native[] = ['object_type'=>'thirdparty','object_id'=>$alternate->id];
            $projector->approve((int) $contact['rowid'], 'thirdparty', (int) $alternate->id, $user, true);
            $metadataOnly = $payload; $metadataOnly['version'] = 2;
            $candidate = $s->put($run, 'invoices', $id, json_encode($metadataOnly), (int) $user->id);
            historyCheck($projector->project($candidate, $user) === 'conflict', 'changed contact mapping dependency must not silently relink existing invoice');
            historyCheck($projector->snapshot('invoice', (int) $mapping['object_id']) === $before, 'dependency remap preserves existing invoice');
        }
        if ($case === 'conflict-source-fields') {
            foreach (['address'=>['contactId'=>'unmapped'],'voucherDate'=>'2021-01-01','createdDate'=>'2021-01-01','dueDate'=>'2021-02-01','voucherNumber'=>'changed-number','introduction'=>'changed intro','remark'=>'changed remark','taxConditions'=>['taxType'=>'gross'],'totalPrice'=>['totalGrossAmount'=>999],'shippingConditions'=>['shippingDate'=>'2021-01-01'],'deliveryDate'=>'2021-01-01'] as $field=>$value) {
                $changed = $payload; $changed[$field] = $value;
                $candidate = $s->put($run, 'invoices', $id, json_encode($changed), (int) $user->id);
                historyCheck($projector->project($candidate, $user) === 'conflict', 'material source field requires review: '.$field);
                historyCheck($projector->snapshot('invoice', (int) $mapping['object_id']) === $before, 'material change preserves native: '.$field);
            }
        }
        $payload['version'] = 2; $payload['lineItems'][0]['name'] = 'remote changed position';
        $revised = $s->put($run, 'invoices', $id, json_encode($payload), (int) $user->id);
        historyCheck($projector->project($revised, $user) === 'conflict', 'remote positions must open conflict');
        historyCheck($projector->snapshot('invoice', (int) $mapping['object_id']) === $before, 'native document preserved');
        historyCheck($s->one('resource', (int) $document['rowid'])['checksum'] === $revised['checksum'], 'mirror remains authoritative');
        if ($case === 'conflict-dependent-invoice') {
            $lineId = (int) $projector->object('invoice', (int) $mapping['object_id'])->lines[0]->id;
            foreach (['societe_remise_except','element_time'] as $association) {
                if ($association === 'societe_remise_except') { $s->query('INSERT INTO '.MAIN_DB_PREFIX.'societe_remise_except (entity,fk_soc,amount_ht,fk_user,fk_facture_line,description) VALUES ('.$entity.','.(int) $native[0]['object_id'].',0,1,'.$lineId.",'fixture')"); }
                else { $s->query('INSERT INTO '.MAIN_DB_PREFIX.'element_time (fk_element,elementtype,invoice_id,invoice_line_id) VALUES (1,\'fixture\','.(int) $mapping['object_id'].','.$lineId.')'); }
                $assocId = (int) $db->last_insert_id(MAIN_DB_PREFIX.$association);
                $assocBefore = $s->rows('SELECT * FROM '.MAIN_DB_PREFIX.$association.' WHERE rowid='.$assocId);
                try {
                    try { $projector->resolve((int) $document['rowid'], 'Lexware', $revised['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $document['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0)); throw new RuntimeException('dependent association accepted'); }
                    catch (DomainException $e) { historyCheck($e->getMessage() === 'NATIVE_DEPENDENT_ASSOCIATIONS_REQUIRE_REVIEW', 'dependent native invoice associations must fail closed'); }
                    historyCheck($projector->snapshot('invoice', (int) $mapping['object_id']) === $before, 'dependent invoice unchanged');
                    historyCheck($s->rows('SELECT * FROM '.MAIN_DB_PREFIX.$association.' WHERE rowid='.$assocId) === $assocBefore, 'dependent link unchanged');
                    historyCheck(!$s->rows('SELECT rowid FROM '.$s->table('resolution').' WHERE entity='.$entity), 'blocked resolution has no false accepted snapshot');
                } finally { $s->query('DELETE FROM '.MAIN_DB_PREFIX.$association.' WHERE rowid='.$assocId); }
            }
        }
        if ($case === 'conflict-tax-resolution') {
            $invalid = $payload; $invalid['taxConditions'] = ['taxType'=>'reverseCharge'];
            $bad = $s->put($run, 'invoices', $id, json_encode($invalid), (int) $user->id);
            historyCheck($projector->project($bad, $user) === 'conflict', 'existing unsupported tax change fails closed');
            try { $projector->resolve((int) $document['rowid'], 'Lexware', $bad['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $document['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0)); throw new RuntimeException('unsupported tax resolution accepted'); }
            catch (DomainException $e) { historyCheck($e->getMessage() === 'TAX_MAPPING_REQUIRED', 'explicit source resolution validates tax before native writes'); }
            historyCheck($projector->snapshot('invoice', (int) $mapping['object_id']) === $before && $s->mapping((int) $document['rowid']) === $mapping, 'unsupported tax retains native and mapping');
            historyCheck(!$s->rows('SELECT rowid FROM '.$s->table('resolution').' WHERE entity='.$entity), 'unsupported tax creates no accepted resolution');
            $projector->resolve((int) $document['rowid'], 'Dolibarr', $bad['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $document['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0));
            historyCheck($projector->project($bad, $user) === 'unchanged', 'explicit Dolibarr acceptance still suppresses an unsupported tax source without native writes');
            historyCheck($projector->snapshot('invoice', (int) $mapping['object_id']) === $before, 'accepted unsupported source preserves native state');

        }
        if ($case === 'conflict-native-dependency-resolution') {
            $contactMap = $s->mapping((int) $contact['rowid']);
            $s->query('UPDATE '.$s->table('mapping').' SET object_id=2147483647 WHERE entity='.$entity.' AND fk_resource='.(int) $contact['rowid']);
            try {
                historyCheck($projector->project($revised, $user) === 'conflict', 'missing native dependency is a conflict');
                try { $projector->resolve((int) $document['rowid'], 'Lexware', $revised['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $document['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0)); throw new RuntimeException('missing native dependency accepted'); }
                catch (DomainException $e) { historyCheck($e->getMessage() === 'NATIVE_OBJECT_MISSING', 'resolution validates physical native dependencies before writing'); }
                historyCheck($projector->snapshot('invoice', (int) $mapping['object_id']) === $before, 'missing dependency preserves native invoice');
                historyCheck(!$s->rows('SELECT rowid FROM '.$s->table('resolution').' WHERE entity='.$entity), 'missing dependency creates no accepted resolution');
            } finally { $s->query('UPDATE '.$s->table('mapping').' SET object_id='.(int) $contactMap['object_id'].' WHERE entity='.$entity.' AND fk_resource='.(int) $contact['rowid']); }
        }
        if ($case === 'conflict-line-input-resolution') {
            $invalid = $payload; $invalid['lineItems'][0]['quantity'] = 'not-a-number'; $invalid['totalPrice']['totalGrossAmount'] = 0;
            $bad = $s->put($run, 'invoices', $id, json_encode($invalid), 1);
            historyCheck($projector->project($bad, $user) === 'conflict', 'malformed existing line input is a conflict');
            try { $projector->resolve((int) $document['rowid'], 'Lexware', $bad['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $document['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0)); throw new RuntimeException('malformed line quantity accepted'); }
            catch (DomainException $e) { historyCheck($e->getMessage() === 'NATIVE_LINE_INPUT_INVALID', 'resolution validates source numeric line data before writing'); }
            historyCheck($projector->snapshot('invoice', (int) $mapping['object_id']) === $before, 'malformed line data preserves native invoice');
        }
        if ($case === 'conflict-stale-project') {
            historyCheck($projector->project($document, $user) === 'conflict', 'stale projection input must use the locked current mirror');
            historyCheck($s->one('resource', (int) $document['rowid'])['projection_status'] === 'conflict', 'stale input cannot hide the current conflict');
        }
        if ($case === 'conflict-audit') {
            $projector->project($revised, $user);
            $events = $s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'hwoscore_audit_event WHERE entity='.$entity." AND event_type='lexware.conflict'");
            historyCheck(count($events) === 1, 'conflict opening audited and identical repeat deduped');
        }
        if ($case === 'conflict-rollback') {
            $payload['totalPrice']['totalGrossAmount'] = 999;
            $invalid = $s->put($run, 'invoices', $id, json_encode($payload), (int) $user->id); $projector->project($invalid, $user);
            try { $projector->resolve((int) $document['rowid'], 'Lexware', $invalid['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $document['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0)); throw new RuntimeException('invalid totals accepted'); }
            catch (DomainException $e) { historyCheck($e->getMessage() === 'TOTALS_MAPPING_CONFLICT', 'expected totals failure'); }
            historyCheck($projector->snapshot('invoice', (int) $mapping['object_id']) === $before, 'failed resolution rolls back native writes');
            historyCheck($s->mapping((int) $document['rowid']) === $mapping, 'failed resolution rolls back mapping');
            historyCheck(!$s->rows('SELECT rowid FROM '.$s->table('resolution').' WHERE entity='.$entity.' AND fk_resource='.(int) $document['rowid']), 'failed resolution leaves no accepted history');
            historyCheck($s->rows('SELECT status FROM '.$s->table('issue').' WHERE entity='.$entity.' AND fk_resource='.(int) $document['rowid'])[0]['status'] === 'open', 'failed resolution leaves conflict open');
        }
        if ($case === 'conflict-permissions') {
            $reader = clone $user; $reader->admin = 0; $reader->rights = new stdClass(); $reader->rights->hwoslexware = (object) ['read'=>1,'sync'=>1];
            foreach (['Lexware','Dolibarr'] as $choice) {
                try { $projector->resolve((int) $document['rowid'], $choice, $revised['checksum'], $reader, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $document['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0)); throw new RuntimeException('unauthorized resolution'); }
                catch (DomainException $e) { historyCheck($e->getMessage() === 'LEXWARE_PERMISSION_DENIED', 'mapping authorization before writes'); }
            }
            try { $projector->resolve((int) $document['rowid'], 'Dolibarr', $document['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $document['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0)); throw new RuntimeException('stale resolution accepted'); }
            catch (DomainException $e) { historyCheck($e->getMessage() === 'CONFLICT_SOURCE_CHANGED', 'stale checksum rejected'); }
            $reader->rights->hwoslexware->mapping = 1;
            $projector->resolve((int) $document['rowid'], 'Dolibarr', $revised['checksum'], $reader, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $document['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0));
            historyCheck($projector->snapshot('invoice', (int) $mapping['object_id']) === $before, 'mapping user can accept native');
        }
        if (str_starts_with($case, 'conflict-case-')) {
            $choice = substr($case, strlen('conflict-case-'));
            $caseA = (int) $s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$entity.' AND fk_resource='.(int) $document['rowid']." AND issue_type='conflict' AND status='open'")[0]['rowid'];
            $projector->resolve((int) $document['rowid'], 'Lexware', $revised['checksum'], $user, $caseA);
            $s->query('UPDATE '.MAIN_DB_PREFIX.'facture SET note_private=\'later local edit\' WHERE entity='.$entity.' AND rowid='.(int) $mapping['object_id']);
            historyCheck($projector->project($revised, $user) === 'conflict', 'same source creates distinct case B');
            $caseB = (int) $s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$entity.' AND fk_resource='.(int) $document['rowid']." AND issue_type='conflict' AND status='open'")[0]['rowid'];
            historyCheck($caseA !== $caseB, 'immutable case identity');
            $state = fn()=>[$projector->snapshot('invoice', (int) $mapping['object_id']), $s->mapping((int) $document['rowid']), $s->rows('SELECT * FROM '.$s->table('issue').' WHERE entity='.$entity.' ORDER BY rowid'), $s->rows('SELECT * FROM '.$s->table('resolution').' WHERE entity='.$entity.' ORDER BY rowid'), $s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'hwoscore_audit_event WHERE entity='.$entity.' ORDER BY rowid')];
            $prior = $state();
            try { $projector->resolve((int) $document['rowid'], $choice, $revised['checksum'], $user, $caseA); throw new RuntimeException('stale case A accepted for B'); }
            catch (DomainException $e) { historyCheck($e->getMessage()==='CONFLICT_CASE_CHANGED', 'stale case rejected'); }
            historyCheck($state() === $prior, 'stale form preserves native, mapping, B, history and audit');
            $projector->resolve((int) $document['rowid'], $choice, $revised['checksum'], $user, $caseB);
            historyCheck(count($s->rows('SELECT rowid FROM '.$s->table('resolution').' WHERE entity='.$entity)) === 2, 'explicit case B succeeds');
        }
        if ($case === 'conflict-reresolve') {
            $projector->resolve((int) $document['rowid'], 'Lexware', $revised['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $document['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0));
            $s->query('UPDATE '.MAIN_DB_PREFIX.'facture SET note_private=\'another local edit\' WHERE entity='.$entity.' AND rowid='.(int) $mapping['object_id']);
            historyCheck($projector->project($revised, $user) === 'conflict', 'new local edit creates another conflict case');
            $projector->resolve((int) $document['rowid'], 'Lexware', $revised['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $document['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0));
            $resolutions = $s->rows('SELECT rowid FROM '.$s->table('resolution').' WHERE entity='.$entity.' AND fk_resource='.(int) $document['rowid']);
            historyCheck(count($resolutions) === 2, 'same source may resolve a distinct later local conflict');
        }
        if ($case === 'conflict-line-extra') {
            $projector->resolve((int) $document['rowid'], 'Lexware', $revised['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $document['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0));
            $line = $projector->object('invoice', (int) $mapping['object_id'])->lines[0];
            $s->query('INSERT INTO '.MAIN_DB_PREFIX.'facturedet_extrafields (fk_object) VALUES ('.(int) $line->id.')');
            historyCheck($projector->project($revised, $user) === 'conflict', 'local line extrafield edit detected');
        }
        if ($case === 'conflict-local') {
            $s->query('UPDATE '.MAIN_DB_PREFIX.'facture SET note_private=\'local edit\' WHERE entity='.$entity.' AND rowid='.(int) $mapping['object_id']);
            $local = $projector->snapshot('invoice', (int) $mapping['object_id']);
            historyCheck($projector->project($document, $user) === 'conflict', 'local edit conflicts on identical remote source');
            historyCheck($projector->snapshot('invoice', (int) $mapping['object_id']) === $local, 'local edit preserved');
        }
        if ($case === 'conflict-retry') {
            $retryUser = clone $user; $retryUser->admin = 0; $retryUser->rights = new stdClass(); $retryUser->rights->hwoslexware = (object) ['retry'=>1];
            historyCheck($projector->project($revised, $retryUser) === 'conflict', 'explicit retry user may retry projection without sync right');
        }
        if (in_array($case, ['conflict-shipping','conflict-dependent-shipping','conflict-upgrade-shipping'], true)) {
            $productId = 'history-p-'.bin2hex(random_bytes(8));
            $product = $s->put($run, 'articles', $productId, json_encode(['title'=>$productId,'articleNumber'=>$productId,'type'=>'PRODUCT','price'=>['netPrice'=>10,'taxRate'=>19]]), (int) $user->id); $ids[] = (int) $product['rowid'];
            historyCheck($projector->project($product, $user) === 'new', 'shipment product fixture'); $native[] = $s->mapping((int) $product['rowid']);
            $orderId = 'history-o-'.bin2hex(random_bytes(8));
            $payload['lineItems'][0]['id'] = $productId; $payload['voucherNumber'] = $orderId;
            $order = $s->put($run, 'order-confirmations', $orderId, json_encode($payload), (int) $user->id); $ids[] = (int) $order['rowid'];
            historyCheck($projector->project($order, $user) === 'new', 'shipment order fixture'); $native[] = $s->mapping((int) $order['rowid']);
            $shippingId = 'history-s-'.bin2hex(random_bytes(8)); $payload['voucherNumber'] = $shippingId; $payload['relatedVouchers'] = [['id'=>$orderId,'voucherType'=>'orderconfirmation']];
            $shipment = $s->put($run, 'delivery-notes', $shippingId, json_encode($payload), (int) $user->id); $ids[] = (int) $shipment['rowid'];
            historyCheck($projector->project($shipment, $user) === 'new', 'shipment fixture'); $shippingMap = $s->mapping((int) $shipment['rowid']); $native[] = $shippingMap;
            $shippingBefore = $projector->snapshot('shipping', (int) $shippingMap['object_id']);
            if ($case === 'conflict-upgrade-shipping') {
                $fixtures = getenv('HWOS_FIXTURE_ROOT') ?: '/tmp/hwos-review-fixtures';
                require_once $fixtures.'/lexware-0.1/snapshot.php';
                $emptyId = 'history-empty-'.bin2hex(random_bytes(8));
                $emptyPayload = $payload; $emptyPayload['lineItems'] = []; $emptyPayload['voucherNumber'] = $emptyId;
                $emptyShipment = $s->put($run, 'delivery-notes', $emptyId, json_encode($emptyPayload), 1);
                historyCheck($projector->project($emptyShipment, $user) === 'new', 'empty released shipment fixture');
                $emptyMap = $s->mapping((int) $emptyShipment['rowid']); $native[] = $emptyMap;
                $legacyFile = '%PDF-released-0.1';
                $s->query('INSERT INTO '.$s->table('file').' (entity,fk_resource,remote_id,representation,content_hash,mime_type,content_blob,date_sync) VALUES ('.$entity.','.(int) $shipment['rowid'].",'released-file','application/pdf',".$s->q(hash('sha256',$legacyFile)).",'application/pdf',".$s->q($legacyFile).',NOW())');
                $released = (new LexwareReleasedSnapshot(new LexwareReleasedStore($db, $entity)))->snapshot('shipping', (int) $shippingMap['object_id']);
                $tables = ['run','resource','mapping','issue','file','relation','payload_version','file_version','state_event','resolution'];
                $captured = [];
                foreach (array_slice($tables, 0, 6) as $table) { $captured[$table] = array_map(fn($row)=>array_filter($row, fn($k)=>is_string($k), ARRAY_FILTER_USE_KEY), $s->rows('SELECT * FROM '.$s->table($table).' WHERE entity='.$entity)); }
                foreach ($captured['mapping'] as &$oldMap) {
                    if ($oldMap['object_type'] === 'shipping') { $oldSnapshot = (new LexwareReleasedSnapshot(new LexwareReleasedStore($db, $entity)))->snapshot('shipping', (int) $oldMap['object_id']); $oldMap['snapshot_json'] = $oldSnapshot; $oldMap['snapshot_checksum'] = LexwareResources::checksum($oldSnapshot); }
                } unset($oldMap);
                try {
                    foreach (explode(';', file_get_contents($fixtures.'/lexware-0.1/schema.sql')) as $sql) { if (trim($sql)) { $s->query(str_replace(['CREATE TABLE IF NOT EXISTS','llx_'], ['CREATE TEMPORARY TABLE',MAIN_DB_PREFIX], trim($sql))); } }
                    foreach ($captured as $table=>$rows) { foreach ($rows as $row) { $row = array_filter($row, fn($k)=>is_string($k), ARRAY_FILTER_USE_KEY); $s->query('INSERT INTO '.$s->table($table).' ('.implode(',', array_keys($row)).') VALUES ('.implode(',', array_map(fn($v)=>$v === null ? 'NULL' : $s->q((string) $v), $row)).')'); } }
                    require_once $root.'/hwoslexware/core/modules/modHwosLexware.class.php';
                    $upgradeDb = new class($db) {
                        public function __construct(private $real) {}
                        public function __get($name) { return $this->real->$name; }
                        public function __set($name, $value) { $this->real->$name = $value; }
                        public function __call($name, $args) { return $this->real->$name(...$args); }
                        public function query($sql, ...$args) {
                            if (preg_match('/^CREATE TABLE.*hwoslexware_(payload_version|file_version|state_event|resolution)\s/i', $sql)) { $sql = preg_replace('/^CREATE TABLE/i', 'CREATE TEMPORARY TABLE', $sql); }
                            return $this->real->query($sql, ...$args);
                        }
                    };
                    $descriptor = new class($db) extends modHwosLexware {
                        public $schemaDb;
                        protected function _load_tables($reldir, $onlywithsuffix = '') {
                            $real = $this->db; $globalDb = $GLOBALS['db']; $this->db = $this->schemaDb; $GLOBALS['db'] = $this->schemaDb;
                            try { return parent::_load_tables($reldir, $onlywithsuffix); }
                            finally { $this->db = $real; $GLOBALS['db'] = $globalDb; }
                        }
                    };
                    $descriptor->schemaDb = $upgradeDb;
                    historyCheck($descriptor->init() === 1 && $descriptor->init() === 1, 'released 0.1 schema activates 0.2 idempotently');
                    historyCheck(count($s->rows('SELECT rowid FROM '.$s->table('payload_version').' WHERE entity='.$entity)) === count($captured['resource']), 'released payloads backfilled once by descriptor');
                    historyCheck($s->rows('SELECT content_blob FROM '.$s->table('file_version').' WHERE entity='.$entity)[0]['content_blob'] === $legacyFile, 'released file bytes backfilled by descriptor');
                    historyCheck(array_map(fn($row)=>array_filter($row, fn($k)=>is_string($k), ARRAY_FILTER_USE_KEY), $s->rows('SELECT * FROM '.$s->table('mapping').' WHERE entity='.$entity)) === $captured['mapping'], 'descriptor preserves existing mappings');
                    historyCheck($projector->project($shipment, $user) === 'unchanged', 'unchanged released 0.1 shipment must not conflict after real schema upgrade');
                    historyCheck($projector->project($emptyShipment, $user) === 'unchanged', 'unchanged released empty shipment must not conflict after real schema upgrade');
                    $s->query('UPDATE '.MAIN_DB_PREFIX."element_element SET fk_source=0 WHERE targettype='shipping' AND fk_target=".(int) $emptyMap['object_id']);
                    historyCheck($projector->project($emptyShipment, $user) === 'conflict', 'released empty shipment still detects a genuine local origin-link edit');
                    $orderNativeId = (int) $s->mapped('order-confirmations', $orderId)['object_id'];
                    $s->query('UPDATE '.MAIN_DB_PREFIX."element_element SET fk_source=".$orderNativeId." WHERE targettype='shipping' AND fk_target=".(int) $emptyMap['object_id']);

                    $s->query('UPDATE '.MAIN_DB_PREFIX.'expedition SET note_private=\'genuine upgrade local edit\' WHERE rowid='.(int) $shippingMap['object_id']);
                    historyCheck($projector->project($shipment, $user) === 'conflict', 'genuine released-mapping local edit still conflicts');
                    $s->query('UPDATE '.MAIN_DB_PREFIX.'expedition SET note_private='.$s->q(json_decode($shippingBefore,true)['row']['note_private']).' WHERE rowid='.(int) $shippingMap['object_id']);
                } finally { foreach (array_reverse($tables) as $table) { $s->query('DROP TEMPORARY TABLE IF EXISTS '.$s->table($table)); } if (isset($descriptor)) { $descriptor->remove(); } }
            }
            $payload['lineItems'][0]['quantity'] = 0.5; $payload['shippingConditions'] = ['shippingDate'=>'2020-02-01'];
            $shipment = $s->put($run, 'delivery-notes', $shippingId, json_encode($payload), (int) $user->id);
            historyCheck($projector->project($shipment, $user) === 'conflict', 'shipping changes conflict');
            historyCheck($projector->snapshot('shipping', (int) $shippingMap['object_id']) === $shippingBefore, 'shipping preserved');
            if ($case === 'conflict-dependent-shipping') {
                $lineId = (int) $projector->object('shipping', (int) $shippingMap['object_id'])->lines[0]->id;
                $s->query('INSERT INTO '.MAIN_DB_PREFIX.'expeditiondet_batch (fk_expeditiondet,qty,fk_origin_stock,batch) VALUES ('.$lineId.',1,0,\'fixture\')');
                $assocId = (int) $db->last_insert_id(MAIN_DB_PREFIX.'expeditiondet_batch');
                try {
                    try { $projector->resolve((int) $shipment['rowid'], 'Lexware', $shipment['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $shipment['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0)); throw new RuntimeException('shipment batch accepted'); }
                    catch (DomainException $e) { historyCheck($e->getMessage() === 'NATIVE_DEPENDENT_ASSOCIATIONS_REQUIRE_REVIEW', 'shipment batch associations must fail closed'); }
                    historyCheck($projector->snapshot('shipping', (int) $shippingMap['object_id']) === $shippingBefore, 'shipment with batch remains unchanged');
                    historyCheck(count($s->rows('SELECT * FROM '.MAIN_DB_PREFIX.'expeditiondet_batch WHERE rowid='.$assocId)) === 1, 'shipment batch preserved');
                } finally { $s->query('DELETE FROM '.MAIN_DB_PREFIX.'expeditiondet_batch WHERE rowid='.$assocId); }
            }
            $projector->resolve((int) $shipment['rowid'], 'Lexware', $shipment['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $shipment['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0));
            $ship = $projector->object('shipping', (int) $shippingMap['object_id']);
            historyCheck((float) $ship->lines[0]->qty === 0.5, 'shipment resolution updates positions without creating another shipment');
            $nextOrderId = 'history-o-'.bin2hex(random_bytes(8));
            $payload['voucherNumber'] = $nextOrderId; $payload['relatedVouchers'] = []; $payload['totalPrice']['totalGrossAmount'] = 5.95;
            $nextOrder = $s->put($run, 'order-confirmations', $nextOrderId, json_encode($payload), (int) $user->id); $ids[] = (int) $nextOrder['rowid'];
            historyCheck($projector->project($nextOrder, $user) === 'new', 'new shipment origin fixture'); $nextMap = $s->mapping((int) $nextOrder['rowid']); $native[] = $nextMap;
            $payload['voucherNumber'] = $shippingId; $payload['relatedVouchers'] = [['id'=>$nextOrderId,'voucherType'=>'orderconfirmation']];
            $shipment = $s->put($run, 'delivery-notes', $shippingId, json_encode($payload), (int) $user->id); $projector->project($shipment, $user);
            $projector->resolve((int) $shipment['rowid'], 'Lexware', $shipment['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $shipment['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0));
            historyCheck((int) $projector->object('shipping', (int) $shippingMap['object_id'])->origin_id === (int) $nextMap['object_id'], 'shipment resolution updates native order relationship');

        }
        if ($case === 'conflict-structure') {
            $payload['lineItems'][] = $payload['lineItems'][0]; $payload['totalPrice']['totalGrossAmount'] = 23.8;
            $revised = $s->put($run, 'invoices', $id, json_encode($payload), (int) $user->id);
            $projector->project($revised, $user);
            $projector->resolve((int) $document['rowid'], 'Lexware', $revised['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $document['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0));
            historyCheck(count($projector->object('invoice', (int) $mapping['object_id'])->lines) === 2, 'explicit resolution accepts additional positions');
            $payload['lineItems'] = []; $payload['totalPrice']['totalGrossAmount'] = 0;
            $revised = $s->put($run, 'invoices', $id, json_encode($payload), (int) $user->id);
            $projector->project($revised, $user);
            $projector->resolve((int) $document['rowid'], 'Lexware', $revised['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $document['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0));
            historyCheck(count($projector->object('invoice', (int) $mapping['object_id'])->lines) === 0, 'explicit resolution accepts removed positions with prior immutable snapshot');
        }
        if ($case === 'conflict-lexware') {
            $projector->resolve((int) $document['rowid'], 'Lexware', $revised['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $document['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0));
            $applied = $projector->snapshot('invoice', (int) $mapping['object_id']);
            historyCheck($applied !== $before && str_contains($applied, 'remote changed position'), 'Lexware resolution applies source');
            $resolution = $s->rows('SELECT * FROM '.$s->table('resolution').' WHERE entity='.$entity.' AND fk_resource='.(int) $document['rowid']);
            historyCheck(count($resolution) === 1 && $resolution[0]['prior_native_json'] === $before && $resolution[0]['choice'] === 'Lexware', 'prior native state immutably snapshotted before apply');
            historyCheck($projector->project($revised, $user) === 'unchanged', 'resolved Lexware mapping baseline');
        }
        if (in_array($case, ['conflict-dolibarr','conflict-return'], true)) {
            $projector->resolve((int) $document['rowid'], 'Dolibarr', $revised['checksum'], $user, (int) ($s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$s->entity.' AND fk_resource='.(int) ((int) $document['rowid']) ." AND issue_type='conflict' AND status='open'")[0]['rowid'] ?? 0));
            historyCheck($projector->snapshot('invoice', (int) $mapping['object_id']) === $before, 'Dolibarr resolution preserves native state');
            historyCheck($projector->project($revised, $user) === 'unchanged', 'accepted exact source suppressed');
            $payload['version'] = 3; $payload['lineItems'][0]['name'] = 'third source';
            $third = $s->put($run, 'invoices', $id, json_encode($payload), (int) $user->id);
            historyCheck($projector->project($third, $user) === 'conflict', 'new source reopens conflict');
            if ($case === 'conflict-return') {
                $returned = $s->put($run, 'invoices', $id, $revised['payload_json'], (int) $user->id);
                historyCheck($projector->project($returned, $user) === 'unchanged', 'return to accepted exact revision remains suppressed');
                historyCheck(!$s->rows('SELECT rowid FROM '.$s->table('issue').' WHERE entity='.$entity.' AND fk_resource='.(int) $document['rowid']." AND status='open' AND issue_type='conflict'"), 'superseded source conflict must close on return to accepted source');
            }
            $resolution = $s->rows('SELECT * FROM '.$s->table('resolution').' WHERE entity='.$entity.' AND fk_resource='.(int) $document['rowid']);
            historyCheck(count($resolution) === 1 && $resolution[0]['source_checksum'] === $revised['checksum'], 'acceptance exact checksum immutable');
        }

    }
    if ($case === 'migration') {
        $s->query('DELETE FROM '.$s->table('payload_version').' WHERE entity='.$entity.' AND fk_resource='.(int) $r['rowid']);
        $s->query('INSERT INTO '.$s->table('file').' (entity,fk_resource,remote_id,representation,content_hash,mime_type,content_blob,date_sync) VALUES ('.$entity.','.(int) $r['rowid'].",'released-file','application/pdf',".$s->q(hash('sha256','%PDF-released')).",'application/pdf','%PDF-released',NOW())");
        $currentFile = $s->rows('SELECT * FROM '.$s->table('file').' WHERE entity='.$entity);
        $s->migrateHistory(); $s->migrateHistory();
        $versions = $s->rows('SELECT * FROM '.$s->table('payload_version').' WHERE entity='.$entity.' AND fk_resource='.(int) $r['rowid']);
        historyCheck(count($versions) === 1 && $versions[0]['payload_json'] === $raw1, 'released current payload backfilled idempotently');
        $fileVersions = $s->rows('SELECT * FROM '.$s->table('file_version').' WHERE entity='.$entity);
        historyCheck(count($fileVersions) === 1 && $fileVersions[0]['content_blob'] === '%PDF-released', 'released file backfilled idempotently');
        historyCheck($s->rows('SELECT * FROM '.$s->table('file').' WHERE entity='.$entity) === $currentFile, 'migration never changes released current file');
    }
    historyCheck($s->rows($protectedSql) === $protectedBefore, 'no stock/bank/payment/bookkeeping changes in '.$case);
    echo 'PASS: history '.$case."\n";
} finally {
    $s->db = $db;
    $db->rollback();
    require_once __DIR__.'/lexware_history_cleanup.php';
    cleanupHistoryEntity($s);

}
}
