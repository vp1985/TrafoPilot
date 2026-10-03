<?php
function cleanupHistoryEntity(LexwareStore $s): void {
    $entity=$s->entity;
    if ($entity < 1100000000 || $entity > 1900000000) { throw new RuntimeException('HISTORY_CLEANUP_ENTITY_DENIED'); }
    // Discover committed fixtures from the entity, including inserts whose
    // assertion failed before they reached the local tracking arrays.
    $native = [];
    foreach (LexwareProjection::OBJECTS as $type=>$spec) {
        foreach ($s->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.$spec[2].' WHERE entity='.$entity) as $row) {
            $native[] = ['object_type'=>$type,'object_id'=>(int) $row['rowid']];
        }
    }
    $cleanupOrder = ['shipping'=>0,'invoice'=>1,'order'=>2,'propal'=>3,'product'=>4,'thirdparty'=>5];
    usort($native, fn($a,$b)=>$cleanupOrder[$a['object_type']] <=> $cleanupOrder[$b['object_type']]);
    foreach ($native as $m) {
        [,,$table,$detail,$fk] = LexwareProjection::OBJECTS[$m['object_type']]; $objectId = (int) $m['object_id'];
        if ($table === 'facture') {
            $s->query('DELETE FROM '.MAIN_DB_PREFIX.'element_time WHERE invoice_id='.$objectId);
            $s->query('DELETE r FROM '.MAIN_DB_PREFIX.'societe_remise_except r JOIN '.MAIN_DB_PREFIX.'facturedet l ON r.fk_facture_line=l.rowid WHERE l.fk_facture='.$objectId.' AND r.entity='.$entity);
        }
        if ($table === 'expedition') {
            $s->query('DELETE b FROM '.MAIN_DB_PREFIX.'expeditiondet_batch b JOIN '.MAIN_DB_PREFIX.'expeditiondet l ON b.fk_expeditiondet=l.rowid WHERE l.fk_expedition='.$objectId);
        }
        if ($detail) {
            $s->query('DELETE e FROM '.MAIN_DB_PREFIX.$detail.'_extrafields e JOIN '.MAIN_DB_PREFIX.$detail.' l ON l.rowid=e.fk_object WHERE l.'.$fk.'='.$objectId);
            $s->query('DELETE FROM '.MAIN_DB_PREFIX.$detail.' WHERE '.$fk.'='.$objectId); }
        $nativeType = ['order'=>'commande','invoice'=>'facture','thirdparty'=>'societe'][$m['object_type']] ?? $m['object_type'];
        $s->query('DELETE FROM '.MAIN_DB_PREFIX.'element_element WHERE (sourcetype='.$s->q($nativeType).' AND fk_source='.$objectId.') OR (targettype='.$s->q($nativeType).' AND fk_target='.$objectId.')');
        if ($table === 'product') { $s->query('DELETE FROM '.MAIN_DB_PREFIX.'product_price WHERE fk_product='.$objectId); }
        if ($table === 'societe') {
            $s->query('DELETE e FROM '.MAIN_DB_PREFIX.'socpeople_extrafields e JOIN '.MAIN_DB_PREFIX.'socpeople p ON p.rowid=e.fk_object WHERE p.entity='.$entity.' AND p.fk_soc='.$objectId);
            $s->query('DELETE FROM '.MAIN_DB_PREFIX.'socpeople WHERE entity='.$entity.' AND fk_soc='.$objectId);
            $s->query('DELETE FROM '.MAIN_DB_PREFIX.'societe_commerciaux WHERE fk_soc='.$objectId);
        }
        $s->query('DELETE FROM '.MAIN_DB_PREFIX.$table.'_extrafields WHERE fk_object='.$objectId);
        $s->query('DELETE FROM '.MAIN_DB_PREFIX.$table.' WHERE entity='.$entity.' AND rowid='.$objectId);
    }
    foreach (['resolution','state_event','payload_version','file_version','native_version','mapping','issue','file','relation','resource','run'] as $table) {
        if (!$s->rows('SHOW TABLES LIKE '.$s->q($s->table($table)))) { continue; }
        $s->query('DELETE FROM '.$s->table($table).' WHERE entity='.$entity);
        if ($s->rows('SELECT rowid FROM '.$s->table($table).' WHERE entity='.$entity)) { throw new RuntimeException('HISTORY_CLEANUP_RESIDUE'); }
    }
    foreach (['actioncomm_resources','actioncomm_extrafields'] as $table) {
        $s->query('DELETE d FROM '.MAIN_DB_PREFIX.$table.' d JOIN '.MAIN_DB_PREFIX.'actioncomm a ON d.'.($table === 'actioncomm_resources' ? 'fk_actioncomm' : 'fk_object').'=a.id WHERE a.entity='.$entity);
    }
    $s->query('DELETE FROM '.MAIN_DB_PREFIX.'actioncomm WHERE entity='.$entity);
    $s->query('DELETE FROM '.MAIN_DB_PREFIX."extrafields WHERE entity=".$entity." AND name='hwoslexware_uuid'");
    $s->query('DELETE FROM '.MAIN_DB_PREFIX."menu WHERE entity=".$entity." AND module='hwoslexware'");
    $s->query('DELETE FROM '.MAIN_DB_PREFIX."user_rights WHERE entity=".$entity." AND fk_id IN (700101,700102,700201,700202,700203,700204,700205)");
    $s->query('DELETE FROM '.MAIN_DB_PREFIX."rights_def WHERE entity=".$entity." AND module IN ('hwoscore','hwoslexware')");
    $s->query('DELETE FROM '.MAIN_DB_PREFIX.'hwoscore_audit_event WHERE entity='.$entity);
    $s->query('DELETE FROM '.MAIN_DB_PREFIX."cronjob WHERE entity=".$entity." AND module_name='hwoslexware'");
    $s->query('DELETE FROM '.MAIN_DB_PREFIX."const WHERE entity=".$entity." AND (name LIKE 'MAIN_MODULE_HWOSLEXWARE%' OR name LIKE 'MAIN_MODULE_HWOSCORE%' OR name IN ('HWOSLEXWARE_SCHEMA_VERSION','HWOSCORE_SCHEMA_VERSION'))");
}

// Read-only counts include detached rows in the declared protected test range.
function historyResidueCounts(LexwareStore $s): array {
    $range='entity BETWEEN 1100000000 AND 1900000000';
    $checks=[
        'history_runs'=>'SELECT COUNT(*) n FROM '.$s->table('run')." WHERE context='history-test'",
        'fixture_crons'=>'SELECT COUNT(*) n FROM '.MAIN_DB_PREFIX."cronjob WHERE $range AND module_name='hwoslexware'",
        'fixture_constants'=>'SELECT COUNT(*) n FROM '.MAIN_DB_PREFIX."const WHERE $range AND (name LIKE 'MAIN_MODULE_HWOSLEXWARE%' OR name LIKE 'MAIN_MODULE_HWOSCORE%' OR name IN ('HWOSLEXWARE_SCHEMA_VERSION','HWOSCORE_SCHEMA_VERSION'))",
        'fixture_users'=>'SELECT COUNT(*) n FROM '.MAIN_DB_PREFIX."user WHERE login REGEXP '^lx-http-fixture-[a-f0-9]{16}$'",
    ];
    foreach (['run','resource','mapping','issue','file','relation','payload_version','file_version','state_event','resolution'] as $table) {
        $checks['history_'.$table]='SELECT COUNT(*) n FROM '.$s->table($table).' WHERE '.$range;
    }
    foreach (['societe','socpeople','product','propal','commande','facture','expedition','actioncomm'] as $table) {
        $checks['native_'.$table]='SELECT COUNT(*) n FROM '.MAIN_DB_PREFIX.$table.' WHERE '.$range;
    }
    $checks['fixture_audit']='SELECT COUNT(*) n FROM '.MAIN_DB_PREFIX.'hwoscore_audit_event WHERE '.$range;
    $counts=[];
    foreach ($checks as $label=>$sql) { $counts[$label]=(int)$s->rows($sql)[0]['n']; }
    return $counts;
}
