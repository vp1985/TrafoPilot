<?php
declare(strict_types=1);
define('NOLOGIN',1); define('NOCSRFCHECK',1);
require '/var/www/html/main.inc.php';
// Isolate fixtures from real DEV mirrors and native business records.
$conf->entity = random_int(1100000000, 1900000000);
$root = getenv('HWOS_MODULE_ROOT') ?: '/var/www/html/custom';
$conf->file->dol_document_root = ['hwos_test'=>$root] + $conf->file->dol_document_root;
$conf->file->dol_url_root['hwos_test'] = '/hwos-test';
require_once $root.'/hwoscore/core/modules/modHwosCore.class.php';
require_once $root.'/hwoslexware/core/modules/modHwosLexware.class.php';
require_once $root.'/hwoslexware/class/LexwareSync.php';
if (($conf->file->dol_document_root['hwos_test'] ?? null) !== $root) {
    throw new RuntimeException('staged module root is not registered before Lexware activation');
}
function checkLx(bool $ok,string $m): void { if (!$ok) { throw new RuntimeException($m); } }
$s = new LexwareStore($db,(int) $conf->entity); $module = new modHwosLexware($db); $core = new modHwosCore($db);
$original = $s->rows('SELECT name,value FROM '.MAIN_DB_PREFIX."const WHERE name IN ('MAIN_MODULE_HWOSCORE','MAIN_MODULE_HWOSLEXWARE') AND entity=".$s->entity);
$active = []; foreach ($original as $c) { $active[$c['name']] = $c['value'] === '1'; }
$user->fetch(1); $user->getrights();
$user->rights->hwoslexware = new stdClass();
// Explicit test principal: production services still call server-side permissions.
foreach (['read','sync','mapping','retry','admin'] as $right) { $user->rights->hwoslexware->$right = 1; }
$fixtureIds = []; $runs = []; $native = []; $error = null; $lifecycleEntered = false;
$globalActivation = $s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX."const WHERE name IN ('MAIN_MODULE_HWOSCORE','MAIN_MODULE_HWOSLEXWARE') AND entity=0");
if ($globalActivation) {
    fwrite(STDERR, "FAIL: global activation blocks lifecycle test\n");
    exit(1);
}
try {
    $lifecycleEntered = true;
    checkLx($core->init() === 1,'core activation');
    checkLx($module->init() === 1 && $module->init() === 1,'double module activation: '.$module->error);
    // CLI activation does not refresh module parts; emulate the next web request
    // without replacing DEV's native-module configuration with the fixture entity.
    $triggerPart=$s->rows('SELECT value FROM '.MAIN_DB_PREFIX."const WHERE name='MAIN_MODULE_HWOSLEXWARE_TRIGGERS' AND entity=".$s->entity);
    checkLx(count($triggerPart)===1 && $triggerPart[0]['value']==='1','single trigger registration after double activation');
    $conf->modules_parts['triggers']['hwoslexware']='/hwoslexware/core/triggers/';
    $columns = [
        'run'=>'rowid,entity,organization_id,mode,status,fk_user,context,checkpoint_json,stats_json,date_creation,date_finished,last_error',
        'resource'=>'rowid,entity,organization_id,resource_type,remote_id,revision,remote_created,remote_updated,archived,missing,payload_json,checksum,date_sync,fk_run,projection_status,last_error',
        'mapping'=>'rowid,entity,fk_resource,object_type,object_id,snapshot_json,snapshot_checksum,remote_checksum,projection_policy,date_creation',
        'issue'=>'rowid,entity,fk_resource,issue_type,details_json,status,date_creation',
        'file'=>'rowid,entity,fk_resource,remote_id,representation,content_hash,mime_type,content_blob,date_sync',
        'relation'=>'rowid,entity,fk_resource,related_id,related_type'];
    $indexes = ['run'=>['idx_lx_run'=>'entity,organization_id,status'],
        'resource'=>['uk_lx_resource'=>'entity,organization_id,resource_type,remote_id','idx_lx_projection'=>'entity,projection_status','idx_lx_seen'=>'entity,fk_run'],
        'mapping'=>['uk_lx_mapping_resource'=>'entity,fk_resource','uk_lx_mapping_object'=>'entity,object_type,object_id'],
        'issue'=>['uk_lx_issue'=>'entity,fk_resource,issue_type'], 'file'=>['uk_lx_file'=>'entity,fk_resource,remote_id,representation'],
        'relation'=>['uk_lx_relation'=>'entity,fk_resource,related_id,related_type']];
    $columns += [
        'payload_version'=>'rowid,entity,fk_resource,checksum,raw_checksum,revision,payload_json,fk_run,date_creation',
        'file_version'=>'rowid,entity,fk_resource,remote_id,representation,content_hash,mime_type,content_blob,date_sync',
        'state_event'=>'rowid,entity,fk_resource,fk_relation,state,fk_run,date_creation',
        'resolution'=>'rowid,entity,fk_resource,fk_issue,source_checksum,revision,choice,prior_native_json,fk_user,date_creation'];
    $indexes += [
        'payload_version'=>['uk_lx_payload_version'=>'entity,fk_resource,raw_checksum','idx_lx_payload_source'=>'entity,fk_resource,checksum'],
        'file_version'=>['uk_lx_file_version'=>'entity,fk_resource,remote_id,representation,content_hash'],
        'state_event'=>['idx_lx_state'=>'entity,fk_resource,fk_relation,rowid'],
        'resolution'=>['uk_lx_resolution'=>'entity,fk_issue,source_checksum,choice']];
    checkLx(count($columns) === 10, 'schema contract covers all ten module tables');
    foreach ($columns as $table=>$expected) {
        $actual = $s->rows('SHOW COLUMNS FROM '.$s->table($table));
        $actualNames = array_column($actual,'Field'); $expectedNames = explode(',', $expected); sort($actualNames); sort($expectedNames);
        checkLx($actualNames === $expectedNames,'complete columns '.$table);
        $sizes=['state'=>24,'choice'=>24,'raw_checksum'=>64,'source_checksum'=>64,'organization_id'=>36,'mode'=>16,'status'=>24,'context'=>24,'last_error'=>128,'resource_type'=>64,'remote_id'=>128,'revision'=>64,'remote_created'=>64,'remote_updated'=>64,'checksum'=>64,'projection_status'=>24,'object_type'=>64,'snapshot_checksum'=>64,'remote_checksum'=>64,'projection_policy'=>24,'issue_type'=>32,'representation'=>32,'content_hash'=>64,'mime_type'=>128,'related_id'=>128,'related_type'=>64];
        $nullable=['run'=>['fk_user','date_finished','last_error'],'resource'=>['revision','remote_created','remote_updated','last_error']];
        foreach ($actual as $c) {
            $name=$c['Field'];
            $expectedType=isset($sizes[$name]) ? 'varchar('.$sizes[$name].')' : (str_ends_with($name,'_json') ? 'longtext' : ($name==='content_blob' ? 'longblob' : (str_starts_with($name,'date_') ? 'datetime' : (in_array($name,['rowid','fk_run','fk_resource','fk_issue','fk_relation'],true) ? 'bigint(20)' : 'int(11)'))));
            checkLx($c['Type']===$expectedType,'column type '.$table.'.'.$name);
            checkLx($c['Null']===(in_array($name,$nullable[$table] ?? [],true) ? 'YES' : 'NO'),'column nullability '.$table.'.'.$name);
            $default=in_array($name,['archived','missing','fk_relation'],true) ? '0' : ($name==='projection_status' ? 'pending' : ($name==='projection_policy' ? 'owned' : ($table==='issue' && $name==='status' ? 'open' : null)));
            checkLx($c['Default']===$default,'column default '.$table.'.'.$name);
            checkLx($name === 'rowid' ? $c['Extra'] === 'auto_increment' : $c['Extra'] === '', 'column extra '.$table);
        }
        $actualIndexes = [];
        foreach ($s->rows('SHOW INDEX FROM '.$s->table($table)) as $i) { $actualIndexes[$i['Key_name']][] = $i['Column_name']; checkLx((int) $i['Non_unique'] === (str_starts_with($i['Key_name'],'idx_') ? 1 : 0),'index uniqueness'); }
        $sequence = [];
        foreach ($s->rows('SHOW INDEX FROM '.$s->table($table)) as $index) {
            $sequence[$index['Key_name']] = ($sequence[$index['Key_name']] ?? 0) + 1;
            checkLx((int) $index['Seq_in_index'] === $sequence[$index['Key_name']], 'index ordered positions '.$table);
            checkLx($index['Collation'] === 'A' && $index['Sub_part'] === null && $index['Packed'] === null && $index['Null'] === '' && $index['Index_type'] === 'BTREE', 'complete index structure '.$table);
            checkLx($index['Comment'] === '' && $index['Index_comment'] === '', 'index comments '.$table);
            if (isset($index['Ignored'])) { checkLx($index['Ignored'] === 'NO', 'index enabled '.$table); }
            if (isset($index['Visible'])) { checkLx($index['Visible'] === 'YES', 'index visible '.$table); }
            checkLx($index['Cardinality'] === null || (int) $index['Cardinality'] >= 0, 'index statistics '.$table);
        }
        checkLx($s->rows('SHOW TABLE STATUS WHERE Name='.$s->q($s->table($table)))[0]['Engine'] === 'InnoDB', 'transactional table engine '.$table);
        $expectedIndexes = ['PRIMARY'=>'rowid'] + $indexes[$table];
        checkLx(count($actualIndexes) === count($expectedIndexes),'complete index count');
        foreach ($expectedIndexes as $name=>$cols) { checkLx(implode(',',$actualIndexes[$name] ?? []) === $cols,'index '.$name); }
    }
    checkLx($module->depends === ['modHwosCore'] && count($module->rights) === 5,'descriptor permissions and dependency');
    foreach (['societe','product','propal','commande','facture','expedition'] as $t) {
        $c=$s->rows('SHOW COLUMNS FROM '.MAIN_DB_PREFIX.$t."_extrafields LIKE 'hwoslexware_uuid'");
        checkLx(count($c)===1 && $c[0]['Type']==='varchar(36)','UUID extrafield '.$t);
    }
    $prefix = 'lx-test-'.bin2hex(random_bytes(5));
    $uuid = fn()=>sprintf('%s-%s-%s-%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(6)));
    $cid=$uuid(); $pid=$uuid(); $qid=$uuid(); $oid=$uuid(); $iid=$uuid(); $gid=$uuid(); $did=$uuid();
    $contact = ['id'=>$cid,'organizationId'=>LexwareClient::ORGANIZATION,'version'=>1,'company'=>['name'=>$prefix,'contactPersons'=>[['firstName'=>'Fixture','lastName'=>'Contact']]],'roles'=>['customer'=>['number'=>1],'vendor'=>['number'=>2]],'addresses'=>['billing'=>[['street'=>'Testweg 1','zip'=>'12345','city'=>'Teststadt','countryCode'=>'DE']]]];
    $product = ['id'=>$pid,'organizationId'=>LexwareClient::ORGANIZATION,'version'=>1,'title'=>$prefix,'articleNumber'=>$prefix,'type'=>'PRODUCT','price'=>['netPrice'=>10,'taxRate'=>19]];
    $base = ['organizationId'=>LexwareClient::ORGANIZATION,'version'=>1,'voucherStatus'=>'open','voucherDate'=>'2020-01-01T00:00:00+01:00','address'=>['contactId'=>$cid],'taxConditions'=>['taxType'=>'net'],'lineItems'=>[['id'=>$pid,'type'=>'material','name'=>'Fixture','quantity'=>2,'unitPrice'=>['netAmount'=>10,'taxRatePercentage'=>19]]],'totalPrice'=>['totalGrossAmount'=>23.8]];
    $documents = ['quotations'=>[$qid,$base+['id'=>$qid,'voucherNumber'=>$prefix.'-Q']], 'order-confirmations'=>[$oid,$base+['id'=>$oid,'voucherNumber'=>$prefix.'-O']],
        'invoices'=>[$iid,$base+['id'=>$iid,'voucherNumber'=>$prefix.'-I']], 'credit-notes'=>[$gid,$base+['id'=>$gid,'voucherNumber'=>$prefix.'-G']],
        'delivery-notes'=>[$did,$base+['id'=>$did,'voucherNumber'=>$prefix.'-D','relatedVouchers'=>[['id'=>$oid,'voucherType'=>'orderconfirmation']]]]];
    $voucherTypes = ['quotations'=>'quotation','order-confirmations'=>'orderconfirmation','invoices'=>'invoice','credit-notes'=>'creditnote','delivery-notes'=>'deliverynote'];
    $responses = ['/v1/profile'=>['organizationId'=>LexwareClient::ORGANIZATION,'companyName'=>'Holger Testzentrum'], '/v1/contacts/'.$cid=>$contact, '/v1/articles/'.$pid=>$product];
    $page = fn($items,$number=0,$last=true)=>['content'=>$items,'number'=>$number,'last'=>$last];
    $responses['/v1/contacts?page=0&size=25']=$page([['id'=>$cid]],0,false);
    $responses['/v1/contacts?page=1&size=25']=$page([],1,true);
    $responses['/v1/articles?page=0&size=25']=$page([['id'=>$pid]]);
    $responses['/v1/recurring-templates?page=0&size=25']=$page([]);
    $items=[]; foreach ($documents as $type=>[$id,$p]) { $responses['/v1/'.$type.'/'.$id]=$p; $items[]=['id'=>$id,'voucherType'=>$voucherTypes[$type]]; }
    $responses['/v1/voucherlist?voucherType=any&voucherStatus=any&page=0&size=25&sort=updatedDate,ASC']=$page($items);
    foreach (['countries','payment-conditions','posting-categories','print-layouts','event-subscriptions'] as $t) { $responses['/v1/'.$t]=[]; }
    $fileRequests=0;
    $profileRaw = '{"organizationId":"'.LexwareClient::ORGANIZATION.'","companyName":"Holger Testzentrum","empty":{},"large":9223372036854775808123}';
    $referenceRaw = '{"id":"raw-reference-fixture","empty":{},"large":9223372036854775808123}';
    $transport = function ($path,$accept) use (&$responses,$iid,$gid,&$fileRequests,$profileRaw,$referenceRaw) {
        if ($path === '/v1/profile') { return [200,$profileRaw,[]]; }
        if ($path === '/v1/countries') { return [200,'['.$referenceRaw.']',[]]; }
        if (isset($responses[$path]['fixtureFailure'])) { return [503,'fixture-only failure',[]]; }
        if (str_ends_with($path,'/file')) {
            if ($accept === 'application/xml') { return [404,'',[]]; }
            $fileRequests++; return [200,'%PDF-fixture',['content-type'=>'application/pdf']];
        }
        if (str_starts_with($path,'/v1/payments/')) { return [200,'{"paymentStatus":"balanced","paymentItems":[{"paymentItemType":"cashDiscount","amount":1}]}',[]]; }
        return isset($responses[$path]) ? [200,json_encode($responses[$path],JSON_THROW_ON_ERROR),[]] : [404,'',[]];
    };
    $clock=0.0; $api = fn()=>new LexwareClient('fixture-secret',$transport,fn()=>10000.0,fn($s)=>null);
    $counts = function () use ($s) { $a=[]; foreach (['societe','socpeople','product','propal','commande','facture','expedition','stock_mouvement','bank','paiement'] as $t) { $a[$t]=$s->rows('SELECT COUNT(*) AS n FROM '.MAIN_DB_PREFIX.$t)[0]['n']; } return $a; };
    $before=$counts(); $sync=new LexwareSync($s,$api()); $dry=$sync->start($user,'dry','test'); $runs[]=$dry;
    for ($j=0;$j<100;$j++) { $r=$sync->batch($dry,$user,3); if ($r['status'] !== 'pending') { break; } }
    checkLx($r['status']==='complete','dry completes'); checkLx($before===$counts(),'dry changes no business data');
    $full=$sync->start($user,'full','test'); $runs[]=$full; $sync->batch($full,$user,2);
    $sync=new LexwareSync($s,$api());
    for ($j=0;$j<100;$j++) { $r=$sync->batch($full,$user,3); if ($r['status'] !== 'pending') { break; } }
    checkLx($r['status']==='complete','resumed full completes');
    checkLx($s->find('profile',LexwareClient::ORGANIZATION)['payload_json'] === $profileRaw,'persisted profile is byte-exact');
    checkLx($s->find('countries','raw-reference-fixture')['payload_json'] === $referenceRaw,'persisted reference is byte-exact');
    foreach ([['contacts',$cid],['articles',$pid],...array_map(fn($t)=>[$t,$documents[$t][0]],array_keys($documents))] as [$type,$id]) {
        $resource=$s->find($type,$id); $fixtureIds[]=(int) $resource['rowid'];
        checkLx($resource['projection_status']==='projected','native projection '.$type.' '.$resource['last_error']);
        $m=$s->mapping((int) $resource['rowid']); $native[]=$m;
    }
    $after=$counts(); checkLx($before['stock_mouvement']===$after['stock_mouvement'] && $before['bank']===$after['bank'] && $before['paiement']===$after['paiement'],'no stock, bank or payment side effects');
    $firstFileRequests=$fileRequests;
    $mappedContact=$s->mapped('contacts',$cid);
    $nativeContact=(new LexwareProjection($s))->object('thirdparty',(int) $mappedContact['object_id']);
    checkLx((int) $nativeContact->client===1 && (int) $nativeContact->fournisseur===1,'combined contact roles');
    foreach ($documents as $type=>[$id,$payload]) {
        $mapped=$s->mapped($type,$id); $object=(new LexwareProjection($s))->object($mapped['object_type'],(int) $mapped['object_id']);
        checkLx($object->ref===$payload['voucherNumber'],'original document number');
        checkLx(($object->array_options['options_hwoslexware_uuid'] ?? '')===$id,'native UUID extrafield');
    }
    $repeat=$sync->start($user,'full','test'); $runs[]=$repeat;
    for ($j=0;$j<100;$j++) { $r=$sync->batch($repeat,$user,5); if ($r['status'] !== 'pending') { break; } }
    checkLx($after===$counts(),'repeat creates no duplicates');
    checkLx($fileRequests===$firstFileRequests+count($documents),'repeat fetch detects new file content under unchanged remote identity');
    // Native local copies must not inherit the remote identity or modify originals.
    // Enable native capabilities only in this CLI process; persist no module flags.
    foreach (['propal','commande','facture'] as $capability) {
        $conf->modules[$capability]=$capability;
        $user->rights->$capability ??= new stdClass();
        $user->rights->$capability->creer=1;
    }
    $projector = new LexwareProjection($s);
    $originalStates = [];
    foreach (['quotations','order-confirmations','invoices'] as $type) {
        $remoteId = $documents[$type][0]; $mapping = $s->mapped($type,$remoteId);
        $originalStates[$type] = [$mapping, $s->find($type,$remoteId), $projector->snapshot($mapping['object_type'],(int) $mapping['object_id'])];
        $source = $projector->object($mapping['object_type'],(int) $mapping['object_id']);
        $cloneId = $type === 'invoices' ? $source->createFromClone($user,$source->id,$s->entity) : $source->createFromClone($user);
        checkLx($cloneId > 0,'native clone '.$type.' '.$source->error.' '.json_encode($source->errors));
        $native[] = ['object_type'=>$mapping['object_type'],'object_id'=>$cloneId];
        $copy = $projector->object($mapping['object_type'],(int) $cloneId);
        checkLx(empty($copy->array_options['options_hwoslexware_uuid']), 'local clone has no remote UUID '.$type);
        checkLx($copy->ref_ext !== $remoteId,'local clone has no remote ref_ext '.$type);
        checkLx((int) $copy->status === 0 && count($copy->lines) === 1,'local clone is usable draft '.$type);
    }
    $proposalMapping = $s->mapped('quotations',$qid);
    $proposal = $projector->object('propal',(int) $proposalMapping['object_id']);
    $localOrder = new Commande($db);
    checkLx($localOrder->createFromProposal($proposal,$user)>0,'native local order from imported proposal '.$localOrder->error);
    $native[] = ['object_type'=>'order','object_id'=>$localOrder->id];
    checkLx(empty($projector->object('order',(int) $localOrder->id)->array_options['options_hwoslexware_uuid']), 'follow-up order has no remote UUID');
    $orderMapping = $s->mapped('order-confirmations',$oid);
    $sourceOrder = $projector->object('order',(int) $orderMapping['object_id']);
    $localInvoice = new Facture($db);
    checkLx($localInvoice->createFromOrder($sourceOrder,$user)>0,'native local invoice from imported order '.$localInvoice->error);
    $native[] = ['object_type'=>'invoice','object_id'=>$localInvoice->id];
    checkLx(empty($projector->object('invoice',(int) $localInvoice->id)->array_options['options_hwoslexware_uuid']), 'follow-up invoice has no remote UUID');
    foreach ($originalStates as $type=>[$mapping,$resource,$snapshot]) {
        checkLx($s->mapping((int) $resource['rowid']) === $mapping && $s->find($type,$resource['remote_id']) === $resource, 'original mirror and mapping unchanged '.$type);
        checkLx($projector->snapshot($mapping['object_type'],(int) $mapping['object_id']) === $snapshot,'original native document unchanged '.$type);
    }
    $after=$counts();
    $changed = $documents['invoices'][1]; $changed['version']=2; $changed['remark']='Remote revision fixture';
    $changedResource=$s->put((int) $s->find('invoices',$iid)['fk_run'],'invoices',$iid,json_encode($changed),(int) $user->id);
    checkLx((new LexwareProjection($s))->project($changedResource,$user)==='conflict','material remark revision requires explicit review');
    checkLx($after===$counts(),'revision preserves native identity');
    $savedProduct=$responses['/v1/articles/'.$pid]; $responses['/v1/articles/'.$pid]=['fixtureFailure'=>true];
    $failed=$sync->start($user,'full','test'); $runs[]=$failed;
    for ($j=0;$j<100;$j++) { $r=$sync->batch($failed,$user,5); if ($r['status'] !== 'pending') { break; } }
    checkLx($r['status']==='errors','isolated task failure persists');
    checkLx($s->find('invoices',$iid)['fk_run']==$failed,'failure does not block later documents');
    $responses['/v1/articles/'.$pid]=$savedProduct; $sync->retry($failed,$user);
    $r=$sync->batch($failed,$user,5); checkLx($r['status']==='complete','persisted retry succeeds');
    $serviceId=$uuid(); $service=$product; $service['id']=$serviceId; $service['type']='SERVICE'; $service['articleNumber']=$prefix.'-SERVICE';
    $serviceResource=$s->put($full,'articles',$serviceId,json_encode($service),(int) $user->id);
    checkLx((new LexwareProjection($s))->project($serviceResource,$user)==='new','service projection');
    $serviceMapping=$s->mapping((int) $serviceResource['rowid']);
    checkLx((int) (new LexwareProjection($s))->object('product',(int) $serviceMapping['object_id'])->type===1,'native service type');
    $m=$s->mapped('contacts',$cid); $s->query('UPDATE '.MAIN_DB_PREFIX.'societe SET note_private=\'local fixture addition\' WHERE rowid='.(int) $m['object_id']);
    $resource=$s->find('contacts',$cid); checkLx((new LexwareProjection($s))->project($resource,$user)==='conflict','local edit conflict');
    $projector=new LexwareProjection($s); $localSnapshot=$projector->snapshot('thirdparty',(int) $m['object_id']);
    $projector->approve((int) $resource['rowid'],'thirdparty',(int) $m['object_id'],$user,true);
    checkLx($projector->snapshot('thirdparty',(int) $m['object_id'])===$localSnapshot,'manual reassignment preserves local fields');
    $contact['version']=9;
    $revised=$s->put($failed,'contacts',$cid,json_encode($contact),(int) $user->id);
    checkLx($projector->project($revised,$user)==='conflict','linked existing contact is not overwritten on remote changes');
    echo "PASS: byte-exact profile/reference storage, native document clones and local follow-ups preserve originals and remote mappings\n";
    echo "PASS: double migration, full schema/indexes, dry-run, pagination, resume, native projections/revisions, idempotency, isolated errors/retry, conflicts and stock/bank invariants\n";
} catch (Throwable $e) { $error=$e->getMessage().' '.$db->lasterror(); }
finally {
    try {
    if ($error) { fwrite(STDERR,'TEST ASSERTION: '.$error."\n"); }
    try {
    $s->db = $db; $db->rollback();
    require_once __DIR__.'/lexware_history_cleanup.php';
    cleanupHistoryEntity($s);
    } finally {
    if ($lifecycleEntered && isset($original)) {
        try {
        checkLx(($active['MAIN_MODULE_HWOSLEXWARE'] ?? false) ? $module->init()===1 : $module->remove()===1,'restore Lexware activation');
        } finally {
        // Dolibarr removal disables jobs instead of deleting them. Remove only the
        // inactive synthetic entity's Lexware row, even if native cleanup failed.
        try {
        if (!($active['MAIN_MODULE_HWOSLEXWARE'] ?? false)) {
            $s->query('DELETE FROM '.MAIN_DB_PREFIX."cronjob WHERE entity=".$s->entity." AND module_name='hwoslexware'");
        }
        } finally {
        checkLx(($active['MAIN_MODULE_HWOSCORE'] ?? false) ? $core->init()===1 : $core->remove()===1,'restore core activation');
        }
        }
    }
    }
    } finally {
        $s->db = $db; $db->rollback();
        require_once __DIR__.'/lexware_history_cleanup.php';
        cleanupHistoryEntity($s);
    }
}

checkLx(($active['MAIN_MODULE_HWOSLEXWARE'] ?? false) || !$s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX."cronjob WHERE entity=".$s->entity." AND module_name='hwoslexware'"), 'no disabled Lexware fixture cron remains');
echo "PASS: fixture cron cleanup and prior activation restoration\n";
if ($error) { fwrite(STDERR,'FAIL: '.$error."\n"); exit(1); }
